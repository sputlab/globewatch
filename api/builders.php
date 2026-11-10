<?php
/*
 * GlobeWatch — response builders (used by the endpoints and by refresh.php).
 * They only read per-source caches (see swr() in lib.php): composing the
 * response is cheap, so there is no second "whole response" cache any more —
 * that old extra layer is what made data up to 2×TTL old and made the
 * refresh button useless.
 */
require_once __DIR__ . '/lib.php';

/* ---------------- helpers ---------------- */
function gwLoadConflicts(?int $id = null): array {
    return dbRetry(function (PDO $pdo) use ($id) {
        if ($id !== null) {
            $s = $pdo->prepare("SELECT * FROM globewatch_conflicts WHERE id = ? AND active = 1");
            $s->execute([$id]);
            return $s->fetchAll();
        }
        return $pdo->query("SELECT * FROM globewatch_conflicts WHERE active = 1 ORDER BY name")->fetchAll();
    });
}
function gwCountriesOf(array $c): array {
    return array_values(array_filter(array_map('trim', explode(',', (string)($c['countries'] ?? '')))));
}
function gwIsosOf(array $c): array {
    $out = [];
    foreach (gwCountriesOf($c) as $name) { $i = countryInfo($name); if ($i) $out[$i['iso3']] = $i; }
    return $out;
}
function gwConflictI18n(array $c): array {
    $it = ['name' => $c['name'], 'region' => $c['region'], 'actors' => $c['actors'], 'desc' => $c['description']];
    $en = [
        'name' => ($c['name_en'] ?? '') ?: $c['name'], 'region' => ($c['region_en'] ?? '') ?: $c['region'],
        'actors' => ($c['actors_en'] ?? '') ?: $c['actors'], 'desc' => ($c['description_en'] ?? '') ?: $c['description'],
    ];
    return ['en' => $en, 'it' => $it];
}

/** Aggregates the state of one or more swr() results for the same source. */
function gwMetaAdd(array &$meta, string $src, array $res, int $count = 0): void {
    $m = $meta[$src] ?? ['n' => 0, 'with_data' => 0, 'pending' => 0, 'stale' => 0, 'oldest' => null, 'count' => 0];
    if (isset($m['state']) && in_array($m['state'], ['disabled', 'unconfigured'], true)) return;
    $m['n']++;
    if ($res['data'] !== null && $res['fetched_at']) {
        $m['with_data']++;
        $m['oldest'] = $m['oldest'] === null ? $res['fetched_at'] : min($m['oldest'], $res['fetched_at']);
    }
    if ($res['state'] === 'pending') $m['pending']++;
    if ($res['state'] === 'stale') $m['stale']++;
    $m['count'] += $count;
    $meta[$src] = $m;
}
function gwMetaOff(array &$meta, string $src): bool {
    if (!isSourceEnabled($src)) { $meta[$src] = ['state' => 'disabled']; return true; }
    if (!sourceConfigured($src)) { $meta[$src] = ['state' => 'unconfigured']; return true; }
    return false;
}
function gwMetaFinalize(array $meta): array {
    $out = [];
    foreach ($meta as $src => $m) {
        if (isset($m['state'])) { $out[$src] = ['state' => $m['state']]; continue; }
        $state = $m['with_data'] === $m['n'] ? ($m['stale'] ? 'stale' : 'ok')
            : ($m['with_data'] > 0 ? 'partial' : ($m['pending'] ? 'pending' : 'error'));
        $out[$src] = ['state' => $state, 'fetched_at' => $m['oldest'], 'count' => $m['count']];
    }
    return $out;
}

function severityFromRate(float $ratePer30d): string {
    if ($ratePer30d >= 150) return 'high';
    if ($ratePer30d >= 30) return 'medium';
    return 'low';
}

/* ============================= conflicts list ============================= */
function build_conflicts(int $days): array {
    $conflicts = gwLoadConflicts();
    $meta = [];

    // GDELT: one cached 90-day daily series per conflict, refreshed in the background (rate limit)
    $media = [];
    if (!gwMetaOff($meta, 'gdelt')) {
        $withKw = array_filter($conflicts, fn($c) => trim((string)$c['gdelt_keywords']) !== '');
        cachePrefetch(array_map(fn($c) => gdeltKeyFor($c, 'vol'), $withKw));
        foreach ($withKw as $c) {
            $r = gdeltVolume($c);
            $media[$c['id']] = gdeltWindowSum($r['data'], $days);
            gwMetaAdd($meta, 'gdelt', $r, (int)($media[$c['id']] ?? 0));
        }
    }

    // HDX HAPI: monthly ACLED-derived aggregates per country
    $hapi = [];
    if (!gwMetaOff($meta, 'hapi')) {
        $isos = [];
        foreach ($conflicts as $c) foreach (gwIsosOf($c) as $iso => $_) $isos[$iso] = true;
        cachePrefetch(array_map(fn($i) => 'hapi:ce:' . $i, array_keys($isos)));
        foreach (array_keys($isos) as $iso) {
            $r = hapiCountry($iso);
            $hapi[$iso] = $r['data'];
            gwMetaAdd($meta, 'hapi', $r);
        }
    }

    // ACLED live (paid plan)
    $acled = null;
    if (!gwMetaOff($meta, 'acled')) {
        $all = [];
        foreach ($conflicts as $c) foreach (gwCountriesOf($c) as $co) $all[] = $co;
        $r = acledCountries(array_values(array_unique($all)), $days);
        $acled = $r['data'];
        gwMetaAdd($meta, 'acled', $r);
    }

    // UCDP Candidate (monthly, geolocated): third basis for intensity, before media coverage
    $ucdpc = null;
    if ($days <= 90 && !gwMetaOff($meta, 'ucdp_candidate')) { $r = ucdpCandidate($days); $ucdpc = $r['data']; gwMetaAdd($meta, 'ucdp_candidate', $r); }

    $archiveSince = isoDaysAgo($days);
    $archiveUntil = isoDaysAgo(366);
    $out = [];
    $mediaOnly = [];
    foreach ($conflicts as $c) {
        $countries = gwCountriesOf($c);
        $fat = 0; $ev = 0; $basis = null; $covered = min($days, 365); $period = null;

        if (is_array($acled)) {
            $rows = 0;
            foreach ($countries as $co) foreach (($acled[$co] ?? []) as $row) {
                if (acledCategory($row['event_type'] ?? null) === 'unrest') continue;   // protests aren't armed conflict
                $fat += (int)($row['fatalities'] ?? 0); $ev++; $rows++;
            }
            if ($rows > 0) $basis = 'acled';
        }
        if ($basis === null) {
            $has = false; $months = [];
            foreach (gwIsosOf($c) as $iso => $_) {
                if (empty($hapi[$iso]['months'])) continue;
                $w = hapiWindow($hapi[$iso], $days);
                if (!$w['months']) continue;
                $has = true;
                $fat += $w['political_violence'][1]; $ev += $w['political_violence'][0];
                $months = array_unique(array_merge($months, $w['months']));
            }
            if ($has) {
                sort($months);
                $basis = 'hapi'; $covered = max(30, count($months) * 30);
                $period = [reset($months), end($months)];
            }
        }
        if ($basis === null && $ucdpc) {
            $w = ucdpCandidateWindow($ucdpc, array_filter(array_map(fn($x) => $x['gw'], gwIsosOf($c))), $days);
            if ($w && $w[0] > 0) { [$ev, $fat] = $w; $basis = 'ucdp'; $covered = max(30, min($days, 90)); }
        }
        if ($days > 365 && $basis !== null) {
            $arc = archivedConflictAggregate($countries, $archiveSince, $archiveUntil);
            if ($arc['count'] > 0) { $fat += $arc['fatalities']; $ev += $arc['count']; $covered = $days; }
        }

        $mediaCount = $media[$c['id']] ?? null;
        $mediaPerDay = $mediaCount !== null ? round($mediaCount / max(1, min($days, 90)), 1) : null;

        if ($c['manual_severity'] !== 'auto') { $sev = $c['manual_severity']; $sevBasis = 'manual'; }
        elseif ($basis !== null) { $sev = severityFromRate((2 * $fat + $ev) * 30 / max(30, $covered)); $sevBasis = $basis; }
        elseif ($mediaPerDay !== null) { $sev = 'low'; $sevBasis = 'media'; $mediaOnly[] = count($out); }
        else { $sev = 'low'; $sevBasis = 'none'; }

        $out[] = [
            'id' => (int)$c['id'], 'slug' => $c['slug'], 'lat' => (float)$c['lat'], 'lng' => (float)$c['lng'],
            'since' => (int)$c['since_year'], 'sev' => $sev, 'sev_basis' => $sevBasis,
            'i18n' => gwConflictI18n($c), 'countries' => $countries,
            'stats' => ['media_articles' => $mediaCount, 'media_per_day' => $mediaPerDay,
                'fatalities' => $basis ? $fat : null, 'events' => $basis ? $ev : null,
                'stats_source' => $basis, 'period' => $period],
        ];
    }

    // Media-only conflicts: GDELT volumes aren't calibrated in absolute terms, so they are
    // ranked relative to each other (top third high, middle medium) — labelled as such in the UI.
    if ($mediaOnly) {
        usort($mediaOnly, fn($a, $b) => ($out[$b]['stats']['media_per_day'] ?? 0) <=> ($out[$a]['stats']['media_per_day'] ?? 0));
        $n = count($mediaOnly);
        foreach ($mediaOnly as $rank => $i) {
            if (($out[$i]['stats']['media_per_day'] ?? 0) <= 0) { $out[$i]['sev'] = 'low'; continue; }
            $out[$i]['sev'] = $n < 3 ? 'medium' : ($rank < ceil($n / 3) ? 'high' : ($rank < ceil(2 * $n / 3) ? 'medium' : 'low'));
        }
    }

    return ['generated_at' => gmdate('c'), 'days' => $days, 'count' => count($out), 'conflicts' => $out,
        'sources' => gwMetaFinalize($meta)];
}

/* ============================= point events ============================= */
function build_events(string $category, int $days): array {
    $wantCats = $category === 'all' ? ['conflict', 'unrest', 'atrocity', 'disaster', 'emergency'] : [$category];
    $want = fn(string $cat) => in_array($cat, $wantCats, true);
    $out = []; $meta = [];

    if ($want('unrest') || $want('atrocity')) {
        $conflicts = gwLoadConflicts();
        $acledUsed = false;
        if (!gwMetaOff($meta, 'acled')) {
            $countries = [];
            foreach ($conflicts as $c) foreach (gwCountriesOf($c) as $co) $countries[] = $co;
            $r = acledCountries(array_values(array_unique($countries)), $days);
            $n = 0;
            foreach (($r['data'] ?? []) as $country => $rows) foreach ($rows as $row) {
                $cat = acledCategory($row['event_type'] ?? null);
                if ($cat === 'conflict' || !$want($cat)) continue;
                $n++; $acledUsed = true;
                $out[] = [
                    'id' => 'acled-' . ($row['event_id_cnty'] ?? md5(json_encode($row))), 'category' => $cat, 'source' => 'acled',
                    'subtype' => null, 'subtype_raw' => $row['sub_event_type'] ?? null, 'severity_level' => null,
                    'country' => $row['country'] ?? $country, 'event_date' => $row['event_date'] ?? null,
                    'lat' => isset($row['latitude']) ? (float)$row['latitude'] : null,
                    'lng' => isset($row['longitude']) ? (float)$row['longitude'] : null,
                    'fatalities' => isset($row['fatalities']) ? (int)$row['fatalities'] : null,
                    'title' => $row['notes'] ?? null, 'url' => null, 'extra' => ['source_text' => $row['source'] ?? null],
                ];
            }
            gwMetaAdd($meta, 'acled', $r, $n);
        }
        // Without live ACLED, HDX HAPI gives monthly country-level aggregates of the same data.
        if (!$acledUsed && !gwMetaOff($meta, 'hapi')) {
            $isos = [];
            foreach ($conflicts as $c) foreach (gwIsosOf($c) as $iso => $info) $isos[$iso] = $info;
            cachePrefetch(array_map(fn($i) => 'hapi:ce:' . $i, array_keys($isos)));
            $n = 0;
            foreach ($isos as $iso => $info) {
                $r = hapiCountry($iso);
                $w = hapiWindow($r['data'], $days);
                if ($w['months']) {
                    foreach (['civilian_targeting' => 'atrocity', 'demonstration' => 'unrest'] as $type => $cat) {
                        [$events, $fat] = $w[$type];
                        if (!$want($cat) || $events <= 0) continue;
                        $sev = $cat === 'atrocity' ? ($fat >= 100 ? 'red' : ($fat >= 10 ? 'orange' : 'green'))
                            : ($events >= 200 ? 'red' : ($events >= 30 ? 'orange' : 'green'));
                        $off = $cat === 'atrocity' ? [-0.6, 0.6] : [0.6, -0.6];
                        $lastDay = gmdate('Y-m-t', strtotime($w['last'] . '-01'));
                        $n++;
                        $out[] = [
                            'id' => "hapi-{$iso}-{$type}", 'category' => $cat, 'source' => 'hapi', 'subtype' => $type,
                            'subtype_raw' => null, 'severity_level' => $sev, 'country' => $info['name'],
                            'event_date' => min($lastDay, gmdate('Y-m-d')),
                            'lat' => $info['lat'] + $off[0], 'lng' => $info['lng'] + $off[1], 'fatalities' => $fat,
                            'title' => null, 'url' => 'https://data.humdata.org/dataset/hdx-hapi-conflict-event',
                            'extra' => ['events' => $events, 'period_start' => $w['first'], 'period_end' => $w['last'],
                                'aggregate' => true, 'approx_location' => true],
                        ];
                    }
                }
                gwMetaAdd($meta, 'hapi', $r, 0);
            }
            if (isset($meta['hapi']['count'])) $meta['hapi']['count'] = $n;
        }
        if ($days > 365) {
            $cats = array_values(array_filter(['unrest', 'atrocity'], $want));
            foreach (archivedPointEvents($cats, isoDaysAgo($days), isoDaysAgo(366), 400) as $a) $out[] = $a;
        }
    }

    if ($want('disaster')) {
        foreach ([['gdacs', fn() => gdacsEvents($days)], ['usgs', fn() => usgsEvents($days)],
                  ['firms', fn() => firmsEvents($days)], ['eonet', fn() => eonetEvents($days)]] as [$src, $get]) {
            if (gwMetaOff($meta, $src)) continue;
            $r = $get();
            $rows = is_array($r['data']) ? $r['data'] : [];
            foreach ($rows as $e) $out[] = $e;
            gwMetaAdd($meta, $src, $r, count($rows));
        }
    }

    if (($want('disaster') || $want('emergency')) && !gwMetaOff($meta, 'reliefweb_disasters')) {
        $r = reliefwebDisasters();
        $n = 0;
        foreach ((is_array($r['data']) ? $r['data'] : []) as $e) {
            if (!$want($e['category'])) continue;
            $out[] = $e; $n++;
        }
        gwMetaAdd($meta, 'reliefweb_disasters', $r, $n);
    }

    /* ---- v1.11 sources: [source, categories it can feed, getter, minimum window in days] ----
       "live" sources (current alerts: NHC, Meteoalarm, IODA, ACAPS…) ignore the period; dated ones are
       filtered by it, with a floor for slow publishers (weekly/monthly) so short periods are not always empty. */
    $extra = [
        ['emsc', ['disaster'], fn() => emscEvents($days), 0],
        ['nhc', ['disaster'], fn() => nhcEvents(), null],
        ['tsunami', ['disaster'], fn() => tsunamiEvents(), 2],
        ['meteoalarm', ['disaster'], fn() => meteoalarmEvents(), null],
        ['gvp', ['disaster'], fn() => gvpEvents(), 7],
        ['cems', ['disaster', 'emergency'], fn() => cemsEvents(), 0],
        ['ifrc', ['disaster', 'emergency', 'unrest'], fn() => ifrcEvents($days), 0],
        ['who_don', ['emergency'], fn() => whoDonEvents(), 30],
        ['ioda', ['emergency'], fn() => iodaEvents($days), null],
        ['acaps', ['emergency'], fn() => acapsData(), null],
        ['ucdp_candidate', ['conflict', 'atrocity'], fn() => ucdpCandidate($days), 0],
        // v1.12
        ['nws', ['disaster'], fn() => nwsEvents(), null],
        ['cap', ['disaster'], fn() => capEvents(), null],
        ['hans', ['disaster'], fn() => hansEvents(), null],
        ['asam', ['conflict'], fn() => asamEvents(), 30],
        ['locust', ['disaster'], fn() => locustEvents(), 30],
        ['alerts_ua', ['conflict'], fn() => alertsUaEvents(), null],
        ['cfradar', ['emergency'], fn() => cfRadarEvents($days), 0],
        ['idmc', ['emergency'], fn() => idmcEvents(), 7],
    ];
    $usgsRows = array_values(array_filter($out, fn($e) => $e['source'] === 'usgs'));
    foreach ($extra as [$src, $cats, $get, $minDays]) {
        if (!array_filter($cats, $want) || gwMetaOff($meta, $src)) continue;
        $r = $get();
        $rows = match ($src) {
            'acaps' => acapsPoints($r['data']),
            'ucdp_candidate' => is_array($r['data']) ? ($r['data']['points'] ?? []) : [],
            default => is_array($r['data']) ? $r['data'] : [],
        };
        if ($src === 'emsc') $rows = emscDedupe($rows, $usgsRows);
        $since = $minDays === null ? null : isoDaysAgo(max($days, $minDays));
        $n = 0;
        foreach ($rows as $e) {
            if (!$want($e['category'])) continue;
            if ($since !== null && ($e['event_date'] ?? '') < $since && empty($e['extra']['ongoing'])) continue;
            $out[] = $e; $n++;
        }
        gwMetaAdd($meta, $src, $r, $n);
    }

    usort($out, fn($a, $b) => strcmp($b['event_date'] ?? '', $a['event_date'] ?? ''));
    $out = array_slice($out, 0, 2500);
    $bySource = [];
    foreach ($out as $e) $bySource[$e['source']] = ($bySource[$e['source']] ?? 0) + 1;

    return ['generated_at' => gmdate('c'), 'days' => $days, 'category' => $category, 'count' => count($out),
        'events' => $out, 'counts_by_source' => $bySource, 'sources' => gwMetaFinalize($meta)];
}

/* ============================= conflict detail ============================= */
function build_conflict_detail(int $id, int $days): ?array {
    $rows = gwLoadConflicts($id);
    if (!$rows) return null;
    $c = $rows[0];
    $countries = gwCountriesOf($c);
    $meta = [];

    $vol = null; $articles = [];
    if (!gwMetaOff($meta, 'gdelt') && trim((string)$c['gdelt_keywords']) !== '') {
        $rv = gdeltVolume($c); $vol = $rv['data']; gwMetaAdd($meta, 'gdelt', $rv);
        $ra = gdeltArticles($c); $articles = is_array($ra['data']) ? $ra['data'] : []; gwMetaAdd($meta, 'gdelt', $ra, count($articles));
    }

    $hapiBreak = null; $monthly = [];
    if (!gwMetaOff($meta, 'hapi')) {
        $sum = ['political_violence' => [0, 0], 'civilian_targeting' => [0, 0], 'demonstration' => [0, 0]];
        $allMonths = []; $has = false; $first = null; $last = null;
        foreach (gwIsosOf($c) as $iso => $_) {
            $r = hapiCountry($iso); gwMetaAdd($meta, 'hapi', $r);
            if (empty($r['data']['months'])) continue;
            $w = hapiWindow($r['data'], $days);
            if ($w['months']) {
                $has = true;
                foreach ($sum as $t => $_v) { $sum[$t][0] += $w[$t][0]; $sum[$t][1] += $w[$t][1]; }
                $first = $first === null ? $w['first'] : min($first, $w['first']);
                $last = $last === null ? $w['last'] : max($last, $w['last']);
            }
            foreach ($r['data']['months'] as $m => $types) {
                $allMonths[$m][0] = ($allMonths[$m][0] ?? 0) + ($types['political_violence'][0] ?? 0);
                $allMonths[$m][1] = ($allMonths[$m][1] ?? 0) + ($types['political_violence'][1] ?? 0);
            }
        }
        if ($has) $hapiBreak = $sum + ['period' => [$first, $last]];
        ksort($allMonths);
        foreach (array_slice($allMonths, -12, 12, true) as $m => [$e, $f]) $monthly[] = ['month' => $m, 'events' => $e, 'fatalities' => $f];
    }

    $acledRows = [];
    if (!gwMetaOff($meta, 'acled') && $countries) {
        $r = acledCountries($countries, $days);
        foreach (($r['data'] ?? []) as $rowsC) foreach ($rowsC as $row) $acledRows[] = $row;
        gwMetaAdd($meta, 'acled', $r, count($acledRows));
    }
    $archived = [];
    if ($days > 365) $archived = archivedConflictEvents((int)$c['id'], isoDaysAgo($days), isoDaysAgo(366), 200);

    // v1.11: INFORM Risk (HAPI), ACAPS INFORM Severity, UCDP Candidate recent fatalities
    $isoList = array_keys(gwIsosOf($c));
    $inform = null;
    if ($isoList && !gwMetaOff($meta, 'inform')) {
        foreach ($isoList as $iso) {
            $r = informRisk($iso); gwMetaAdd($meta, 'inform', $r);
            if (!empty($r['data']['overall']) && (!$inform || $r['data']['overall'] > $inform['overall'])) $inform = $r['data'] + ['iso3' => $iso];
        }
    }
    $acaps = null;
    if ($isoList && !gwMetaOff($meta, 'acaps')) { $r = acapsData(); gwMetaAdd($meta, 'acaps', $r); $acaps = acapsForIsos($r['data'], $isoList); }
    $ucdpRecent = null;
    if (!gwMetaOff($meta, 'ucdp_candidate')) {
        $r = ucdpCandidate($days); gwMetaAdd($meta, 'ucdp_candidate', $r);
        $w = ucdpCandidateWindow($r['data'], array_filter(array_map(fn($x) => $x['gw'], gwIsosOf($c))), $days);
        if ($w) $ucdpRecent = ['events' => $w[0], 'fatalities' => $w[1], 'days' => min($days, 90)];
    }

    // v1.12: humanitarian context from HDX HAPI (IPC, IDPs, refugees, people in need, funding) — first country
    $hctx = null;
    if ($isoList && !gwMetaOff($meta, 'hapi_context')) { $r = hapiContext($isoList[0]); gwMetaAdd($meta, 'hapi_context', $r); $hctx = $r['data'] ? $r['data'] + ['iso3' => $isoList[0]] : null; }

    $ucdp = null;
    if (!gwMetaOff($meta, 'ucdp') && $countries) { $r = ucdpCountries($countries, $days); $ucdp = $r['data']; gwMetaAdd($meta, 'ucdp', $r); }

    // ReliefWeb reports: query by country names (English titles), not by the Italian conflict name as in v1.9
    $reports = [];
    if (!gwMetaOff($meta, 'reliefweb') && $countries) {
        $q = implode(' OR ', array_map(fn($x) => '"' . (countryInfo($x)['name'] ?? $x) . '"', $countries));
        $r = reliefwebReports($q); $reports = is_array($r['data']) ? $r['data'] : []; gwMetaAdd($meta, 'reliefweb', $r, count($reports));
    }

    $feed = [];
    foreach ($articles as $a) $feed[] = ['source' => 'gdelt', 'event_date' => $a['event_date'], 'title' => $a['title'],
        'url' => $a['url'], 'fatalities' => null, 'domain' => $a['domain'] ?? null];
    foreach ($acledRows as $row) $feed[] = ['source' => 'acled', 'event_date' => $row['event_date'] ?? null,
        'title' => $row['notes'] ?? null, 'url' => null, 'fatalities' => isset($row['fatalities']) ? (int)$row['fatalities'] : null];
    foreach ($archived as $a) $feed[] = $a;
    usort($feed, fn($a, $b) => strcmp($b['event_date'] ?? '', $a['event_date'] ?? ''));
    $feed = array_slice($feed, 0, 25);

    // Daily sparkline (last 30 days): ACLED events if available, otherwise GDELT article volume
    $byDay = [];
    $sparkSource = null;
    if ($acledRows) { $sparkSource = 'acled'; foreach ($acledRows as $row) if (!empty($row['event_date'])) $byDay[$row['event_date']] = ($byDay[$row['event_date']] ?? 0) + 1; }
    elseif (!empty($vol['days'])) { $sparkSource = 'gdelt'; $byDay = $vol['days']; }
    $spark = [];
    for ($i = 29; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime("-{$i} days")); $spark[] = ['day' => $d, 'value' => $byDay[$d] ?? 0]; }

    $fat = null; $ev = null; $statSrc = null;
    if ($acledRows) {
        $statSrc = 'acled'; $fat = 0; $ev = 0;
        foreach ($acledRows as $row) { if (acledCategory($row['event_type'] ?? null) === 'unrest') continue; $fat += (int)($row['fatalities'] ?? 0); $ev++; }
        foreach ($archived as $a) { $fat += (int)($a['fatalities'] ?? 0); $ev++; }
    } elseif ($hapiBreak) {
        $statSrc = 'hapi'; $fat = $hapiBreak['political_violence'][1]; $ev = $hapiBreak['political_violence'][0];
    }

    return [
        'generated_at' => gmdate('c'), 'days' => $days,
        'id' => (int)$c['id'], 'since' => (int)$c['since_year'], 'i18n' => gwConflictI18n($c), 'countries' => $countries,
        'stats' => ['media_articles' => gdeltWindowSum($vol, $days), 'fatalities' => $fat, 'events' => $ev, 'stats_source' => $statSrc,
            'ucdp_fatalities' => $ucdp['fatalities'] ?? null, 'ucdp_year' => $ucdp['year'] ?? null, 'hapi' => $hapiBreak,
            'ucdp_recent' => $ucdpRecent, 'inform' => $inform, 'acaps' => $acaps, 'context' => $hctx],
        'sparkline' => ['source' => $sparkSource, 'points' => $spark],
        'monthly' => $monthly,
        'feed' => $feed,
        'related_reports' => $reports,
        'sources' => gwMetaFinalize($meta),
    ];
}
