<?php
/*
 * GlobeWatch — sources added in v1.12.0.
 * Phase A (no key): NWS + international CAP alerts (filtered to Severe/Extreme and grouped),
 * USGS HANS elevated volcanoes, NGA ASAM maritime attacks, FAO desert locusts,
 * NOAA SWPC space weather (banner), extra HDX HAPI context (conflict detail).
 * Phase B (free key): alerts.in.ua (Ukraine air-raid alerts), Cloudflare Radar outages, IDMC IDU displacement.
 */

/* ============================= CAP helpers ============================= */
/** "lat,lon lat,lon …" (CAP polygon) or "lat,lon radius" (circle) → [lat, lng] centre. */
function capAreaCentre(SimpleXMLElement $area): ?array {
    foreach ($area->polygon as $pg) {
        $pts = [];
        foreach (preg_split('/\s+/', trim((string)$pg)) as $pair) {
            $p = explode(',', $pair);
            if (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) $pts[] = [(float)$p[0], (float)$p[1]];
        }
        if ($pts) return [array_sum(array_column($pts, 0)) / count($pts), array_sum(array_column($pts, 1)) / count($pts)];
    }
    foreach ($area->circle as $c) {
        if (preg_match('/^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)/', (string)$c, $m)) return [(float)$m[1], (float)$m[2]];
    }
    return null;
}
/** One CAP <alert> → compact summary, or null when it is not an actual, current, severe/extreme alert. */
function capSummarize(SimpleXMLElement $a): ?array {
    if (strtolower((string)$a->status) !== 'actual' || strtolower((string)$a->msgType) === 'cancel') return null;
    $info = null;
    foreach ($a->info as $i) { if (!$info || stripos((string)$i->language, 'en') === 0) $info = $i; }
    if (!$info) return null;
    $sev = strtolower((string)$info->severity);
    if (!in_array($sev, ['severe', 'extreme'], true)) return null;
    $exp = strtotime((string)$info->expires) ?: null;
    if ($exp && $exp < time()) return null;
    $centre = null; $areas = [];
    foreach ($info->area as $ar) {
        $centre = $centre ?? capAreaCentre($ar);
        $d = trim((string)$ar->areaDesc);
        if ($d !== '') $areas[] = $d;
    }
    return ['sev' => $sev, 'event' => trim((string)$info->event) ?: trim((string)$info->headline), 'areas' => array_slice($areas, 0, 3),
        'centre' => $centre, 'onset' => (string)($info->onset ?: $info->effective ?: $a->sent), 'expires' => $exp,
        'sender' => trim((string)($info->senderName ?: $a->sender))];
}
/** Groups alert summaries into one marker per group key (country or US state). */
function capGroup(array $alerts, string $source, callable $placeOf, string $url): array {
    $groups = [];
    foreach ($alerts as $al) {
        [$key, $label, $country, $fallback] = $placeOf($al);
        if ($key === null) continue;
        $g = &$groups[$key];
        $g['label'] = $label; $g['country'] = $country; $g['fallback'] = $fallback;
        $g[$al['sev']] = ($g[$al['sev']] ?? 0) + 1;
        if ($al['event'] !== '') $g['events'][$al['event']] = ($g['events'][$al['event']] ?? 0) + 1;
        foreach ($al['areas'] as $ar) if (count($g['areas'] ?? []) < 6) $g['areas'][$ar] = true;
        if ($al['centre']) $g['pts'][] = $al['centre'];
        $on = strtotime($al['onset']) ?: null;
        if ($on) $g['first'] = isset($g['first']) ? min($g['first'], $on) : $on;
        unset($g);
    }
    $out = [];
    foreach ($groups as $key => $g) {
        if (!empty($g['pts'])) { $lat = array_sum(array_column($g['pts'], 0)) / count($g['pts']); $lng = array_sum(array_column($g['pts'], 1)) / count($g['pts']); }
        elseif ($g['fallback']) { [$lat, $lng] = $g['fallback']; }
        else continue;
        $ev = $g['events'] ?? []; arsort($ev);
        $out[] = gwPt(['id' => $source . '-' . $key, 'source' => $source, 'subtype' => 'weather_warning',
            'severity_level' => !empty($g['extreme']) ? 'red' : 'orange', 'country' => $g['country'],
            'event_date' => gmdate('Y-m-d', max($g['first'] ?? time(), time() - 86400)), 'lat' => $lat, 'lng' => $lng,
            'title' => $g['label'], 'url' => $url,
            'extra' => ['red' => (int)($g['extreme'] ?? 0), 'orange' => (int)($g['severe'] ?? 0),
                'events' => array_slice(array_keys($ev), 0, 6), 'areas' => array_keys($g['areas'] ?? []), 'ongoing' => true,
                'approx_location' => empty($g['pts'])]]);
    }
    return $out;
}

/* ============================= NWS (United States) ============================= */
const US_STATES = [
    'AL' => ['Alabama', 32.8, -86.8], 'AK' => ['Alaska', 64.2, -152.5], 'AZ' => ['Arizona', 34.3, -111.7], 'AR' => ['Arkansas', 34.9, -92.4],
    'CA' => ['California', 37.2, -119.4], 'CO' => ['Colorado', 39.0, -105.5], 'CT' => ['Connecticut', 41.6, -72.7], 'DE' => ['Delaware', 39.0, -75.5],
    'FL' => ['Florida', 28.6, -82.4], 'GA' => ['Georgia (US)', 32.7, -83.4], 'HI' => ['Hawaii', 20.3, -156.4], 'ID' => ['Idaho', 44.4, -114.6],
    'IL' => ['Illinois', 40.0, -89.2], 'IN' => ['Indiana', 39.9, -86.3], 'IA' => ['Iowa', 42.1, -93.5], 'KS' => ['Kansas', 38.5, -98.4],
    'KY' => ['Kentucky', 37.5, -85.3], 'LA' => ['Louisiana', 31.1, -92.0], 'ME' => ['Maine', 45.4, -69.2], 'MD' => ['Maryland', 39.0, -76.8],
    'MA' => ['Massachusetts', 42.3, -71.8], 'MI' => ['Michigan', 44.3, -85.4], 'MN' => ['Minnesota', 46.3, -94.3], 'MS' => ['Mississippi', 32.7, -89.7],
    'MO' => ['Missouri', 38.4, -92.5], 'MT' => ['Montana', 47.0, -109.6], 'NE' => ['Nebraska', 41.5, -99.8], 'NV' => ['Nevada', 39.3, -116.6],
    'NH' => ['New Hampshire', 43.7, -71.6], 'NJ' => ['New Jersey', 40.2, -74.7], 'NM' => ['New Mexico', 34.4, -106.1], 'NY' => ['New York', 42.9, -75.5],
    'NC' => ['North Carolina', 35.6, -79.4], 'ND' => ['North Dakota', 47.5, -100.5], 'OH' => ['Ohio', 40.3, -82.8], 'OK' => ['Oklahoma', 35.6, -97.5],
    'OR' => ['Oregon', 43.9, -120.6], 'PA' => ['Pennsylvania', 40.9, -77.8], 'RI' => ['Rhode Island', 41.7, -71.5], 'SC' => ['South Carolina', 33.9, -80.9],
    'SD' => ['South Dakota', 44.4, -100.2], 'TN' => ['Tennessee', 35.9, -86.4], 'TX' => ['Texas', 31.5, -99.3], 'UT' => ['Utah', 39.3, -111.7],
    'VT' => ['Vermont', 44.1, -72.7], 'VA' => ['Virginia', 37.5, -78.8], 'WA' => ['Washington', 47.4, -120.5], 'WV' => ['West Virginia', 38.6, -80.6],
    'WI' => ['Wisconsin', 44.6, -89.9], 'WY' => ['Wyoming', 43.0, -107.6], 'DC' => ['District of Columbia', 38.9, -77.0], 'PR' => ['Puerto Rico', 18.2, -66.5],
    'GU' => ['Guam', 13.4, 144.8], 'VI' => ['US Virgin Islands', 18.3, -64.9], 'AS' => ['American Samoa', -14.3, -170.7],
];
function nwsFetch(): ?array {
    $raw = httpGet('https://api.weather.gov/alerts/active?' . http_build_query(['status' => 'actual', 'severity' => 'Extreme,Severe']),
        ['Accept: application/geo+json'], 25);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['features'])) { GwHttp::$lastError = 'NWS: unexpected response'; return null; }
    $alerts = [];
    foreach ($j['features'] as $f) {
        $p = $f['properties'] ?? [];
        if (strtolower($p['messageType'] ?? '') === 'cancel') continue;
        $exp = strtotime((string)($p['ends'] ?? $p['expires'] ?? '')) ?: null;
        if ($exp && $exp < time()) continue;
        $centre = null;
        $ring = $f['geometry']['coordinates'][0] ?? null;               // GeoJSON polygon: [[lng,lat],…]
        if (($f['geometry']['type'] ?? '') === 'Polygon' && is_array($ring) && $ring) {
            $centre = [array_sum(array_column($ring, 1)) / count($ring), array_sum(array_column($ring, 0)) / count($ring)];
        }
        $ugc = $p['geocode']['UGC'][0] ?? '';
        $alerts[] = ['sev' => strtolower((string)($p['severity'] ?? '')), 'event' => (string)($p['event'] ?? ''),
            'areas' => array_slice(array_map('trim', explode(';', (string)($p['areaDesc'] ?? ''))), 0, 3), 'centre' => $centre,
            'onset' => (string)($p['onset'] ?? $p['effective'] ?? ''), 'expires' => $exp, 'state' => strtoupper(substr($ugc, 0, 2))];
    }
    return capGroup($alerts, 'nws', function ($a) {
        $st = US_STATES[$a['state']] ?? null;
        if (!$st) return [null, null, null, null];
        return [$a['state'], $st[0], 'United States', [$st[1], $st[2]]];
    }, 'https://www.weather.gov/alerts');
}
function nwsEvents(): array { return gwSwrBg('nws:active', 'nws', fn() => nwsFetch(), 3); }

/* ============================= International CAP feeds ============================= */
/** ISO3 => feed URL. Feeds can embed the CAP alerts or link to them (links are followed, then cached per document). */
function capFeeds(): array {
    $feeds = [
        'CAN' => 'https://rss.naad-adna.pelmorex.com/',
        'NZL' => 'https://alerts.metservice.com/cap/rss',
        'PHL' => 'https://publicalert.pagasa.dost.gov.ph/feeds/',
    ];
    foreach ((array)CAP_EXTRA_FEEDS as $iso => $url) if (is_string($url) && preg_match('#^https?://#', $url)) $feeds[strtoupper($iso)] = $url;
    return $feeds;
}
function capFetch(): ?array {
    $feeds = capFeeds();
    $bodies = httpGetMulti(array_map(fn($u) => ['url' => $u], $feeds), 20);
    if (!array_filter($bodies)) { GwHttp::$lastError = GwHttp::$lastError ?: 'CAP: no feed reachable'; return null; }
    $unprefix = fn($raw) => is_string($raw) ? preg_replace('/<(\/?)cap:/i', '<$1', $raw) : $raw;   // <cap:alert> → <alert>
    $alerts = []; $toFetch = [];
    foreach ($bodies as $iso => $raw) {
        $x = gwXml($unprefix($raw));
        if (!$x) continue;
        $entries = [];
        foreach ([$x->entry, $x->channel->item ?? []] as $list) foreach ($list as $e) $entries[] = $e;
        foreach ($entries as $e) {
            if (isset($e->alert)) {                                                 // CAP embedded in the feed
                $s = capSummarize($e->alert);
                if ($s) $alerts[] = $s + ['iso' => $iso];
                continue;
            }
            $link = '';
            foreach ($e->link as $l) {
                $h = (string)($l['href'] ?? $l);
                if ($h !== '' && ($link === '' || stripos((string)$l['type'], 'cap') !== false || preg_match('/\.xml($|\?)/i', $h))) $link = $h;
            }
            if ($link === '' && isset($e->guid) && preg_match('#^https?://#', (string)$e->guid)) $link = (string)$e->guid;
            if ($link === '') continue;
            $cached = kvGet('capdoc:' . md5($link));
            if (is_array($cached)) { if ($cached) $alerts[] = $cached + ['iso' => $iso]; }
            else $toFetch[$link] = $iso;
        }
    }
    // new CAP documents: a bounded number per run, each cached for 3 days (CAP documents never change)
    $toFetch = array_slice($toFetch, 0, max(5, (int)CAP_MAX_DOCS_PER_RUN), true);
    if ($toFetch) {
        $req = []; foreach (array_keys($toFetch) as $i => $u) $req[$i] = ['url' => $u];
        $docs = httpGetMulti($req, 15);
        foreach (array_keys($toFetch) as $i => $u) {
            $x = gwXml($unprefix($docs[$i] ?? null));
            $s = $x ? capSummarize($x) : null;
            $store = $s ? ['sev' => $s['sev'], 'event' => $s['event'], 'areas' => $s['areas'], 'centre' => $s['centre'],
                'onset' => $s['onset'], 'expires' => $s['expires'], 'sender' => $s['sender']] : [];
            try { kvSet('capdoc:' . md5($u), $store, 3 * 86400); } catch (Throwable $e) {}
            if ($s) $alerts[] = $s + ['iso' => $toFetch[$u]];
        }
    }
    $alerts = array_filter($alerts, fn($a) => !$a['expires'] || $a['expires'] >= time());
    return capGroup($alerts, 'cap', function ($a) {
        $c = countryInfo($a['iso']);
        return $c ? [$a['iso'], null, $c['name'], [$c['lat'], $c['lng']]] : [null, null, null, null];
    }, 'https://severeweather.wmo.int/');
}
function capEvents(): array { return gwSwrBg('cap:intl', 'cap', fn() => capFetch(), 5); }

/* ============================= USGS HANS — elevated US volcanoes ============================= */
function hansFetch(): ?array {
    $raw = httpGet('https://volcanoes.usgs.gov/hans-public/api/volcano/getElevatedVolcanoes', ['Accept: application/json'], 20);
    if ($raw === null) return null;
    $list = json_decode($raw, true);
    if (!is_array($list)) { GwHttp::$lastError = 'HANS: unexpected response'; return null; }
    if (isset($list['volcanoes'])) $list = $list['volcanoes'];
    // coordinates live in the monitored-volcano list: cached for a day
    $coords = kvGet('hans:coords');
    if (!is_array($coords)) {
        $coords = [];
        foreach ((array)json_decode((string)httpGet('https://volcanoes.usgs.gov/hans-public/api/volcano/getMonitoredVolcanoes', ['Accept: application/json'], 20), true) as $v) {
            if (!is_array($v)) continue;
            $vn = (string)($v['vnum'] ?? $v['volcano_number'] ?? '');
            $la = $v['latitude'] ?? $v['lat'] ?? null; $lo = $v['longitude'] ?? $v['long'] ?? $v['lng'] ?? null;
            if ($vn !== '' && is_numeric($la) && is_numeric($lo)) $coords[$vn] = [(float)$la, (float)$lo];
        }
        kvSet('hans:coords', $coords, $coords ? 86400 : 3600);
    }
    $out = [];
    foreach ($list as $v) {
        if (!is_array($v)) continue;
        $vn = (string)($v['vnum'] ?? $v['volcano_number'] ?? '');
        $la = $v['latitude'] ?? $v['lat'] ?? ($coords[$vn][0] ?? null); $lo = $v['longitude'] ?? $v['long'] ?? ($coords[$vn][1] ?? null);
        if (!is_numeric($la) || !is_numeric($lo)) continue;
        $alert = strtoupper((string)($v['alert_level'] ?? $v['alertLevel'] ?? ''));
        $color = strtoupper((string)($v['color_code'] ?? $v['aviationColorCode'] ?? ''));
        $out[] = gwPt(['id' => 'hans-' . ($vn ?: md5(json_encode($v))), 'source' => 'hans', 'subtype' => 'volcano',
            'subtype_raw' => $alert, 'severity_level' => ($alert === 'WARNING' || $color === 'RED') ? 'red' : (($alert === 'WATCH' || $color === 'ORANGE') ? 'orange' : 'green'),
            'country' => 'United States', 'event_date' => gwDate($v['sent_utc'] ?? $v['sentUtc'] ?? null) ?? gmdate('Y-m-d'),
            'lat' => (float)$la, 'lng' => (float)$lo, 'title' => $v['volcano_name'] ?? $v['volcanoName'] ?? null,
            'url' => $v['notice_url'] ?? $v['noticeUrl'] ?? 'https://volcanoes.usgs.gov/',
            'extra' => ['alert_level' => $alert ?: null, 'color_code' => $color ?: null, 'observatory' => $v['obs_fullname'] ?? $v['observatory'] ?? ($v['obs_abbr'] ?? null), 'ongoing' => true]]);
    }
    return $out;
}
function hansEvents(): array { return gwSwrBg('hans:elevated', 'hans', fn() => hansFetch(), 4); }

/* ============================= NGA ASAM — attacks on ships ============================= */
function asamFetch(): ?array {
    $raw = httpGet('https://msi.nga.mil/api/publications/asam?' . http_build_query([
        'sort' => 'date', 'output' => 'json', 'minOccurDate' => isoDaysAgo(400), 'maxOccurDate' => gmdate('Y-m-d')]), ['Accept: application/json'], 30);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    $rows = is_array($j) ? ($j['asam'] ?? (array_is_list($j) ? $j : null)) : null;
    if (!is_array($rows)) { GwHttp::$lastError = 'ASAM: unexpected response'; return null; }
    $cut = isoDaysAgo(400); $out = [];
    foreach ($rows as $r) {
        $date = gwDate($r['date'] ?? $r['occurrenceDate'] ?? null);
        if (!$date || $date < $cut) continue;
        $la = $r['latitude'] ?? null; $lo = $r['longitude'] ?? null;
        if (!is_numeric($la) || !is_numeric($lo)) continue;
        $desc = trim((string)($r['description'] ?? ''));
        $host = trim((string)($r['hostility'] ?? $r['hostility_d'] ?? ''));
        $sev = preg_match('/hijack|kidnap|fired|missile|drone|explos|killed|attack/i', $host . ' ' . $desc) ? 'red'
            : (preg_match('/board|robbery|pirat|approach/i', $host . ' ' . $desc) ? 'orange' : 'green');
        $ref = (string)($r['reference'] ?? md5($desc . $date));
        $out[] = gwPt(['id' => 'asam-' . $ref, 'category' => 'conflict', 'source' => 'asam', 'subtype' => 'maritime_attack',
            'subtype_raw' => $host ?: null, 'severity_level' => $sev, 'event_date' => $date, 'lat' => (float)$la, 'lng' => (float)$lo,
            'title' => $host ?: null, 'url' => 'https://msi.nga.mil/Piracy',
            'extra' => ['reference' => $ref, 'victim' => $r['victim'] ?? null, 'nav_area' => $r['navArea'] ?? null, 'summary' => mb_substr($desc, 0, 500)]]);
    }
    return $out;
}
function asamEvents(): array { return gwSwrBg('asam:400', 'asam', fn() => asamFetch(), 7); }

/* ============================= FAO Desert Locust ============================= */
/** Layer URL: FAO_LOCUST_LAYER_URL, or discovered once from the FAO Locust Hub catalogue. */
function locustLayerUrl(): ?string {
    if (FAO_LOCUST_LAYER_URL !== '') return rtrim(FAO_LOCUST_LAYER_URL, '/');
    $u = kvGet('locust:layer');
    if (is_string($u) && $u !== '') return $u;
    $raw = httpGet('https://locust-hub-hqfao.hub.arcgis.com/api/search/v1/collections/dataset/items?' . http_build_query(['q' => 'swarms', 'limit' => 20]),
        ['Accept: application/geo+json, application/json'], 20);
    $j = json_decode((string)$raw, true);
    foreach (($j['features'] ?? []) as $f) {
        $p = $f['properties'] ?? [];
        $url = (string)($p['url'] ?? '');
        if (preg_match('#/FeatureServer(/\d+)?$#i', $url) && stripos(($p['title'] ?? '') . ' ' . $url, 'swarm') !== false) {
            if (!preg_match('#/\d+$#', $url)) $url .= '/0';
            kvSet('locust:layer', $url, 7 * 86400);
            return $url;
        }
    }
    GwHttp::$lastError = 'FAO locusts: layer not found in the Locust Hub catalogue — set FAO_LOCUST_LAYER_URL';
    return null;
}
function locustFetch(): ?array {
    $layer = locustLayerUrl();
    if (!$layer) return null;
    $raw = httpGet($layer . '/query?' . http_build_query(['where' => '1=1', 'outFields' => '*', 'f' => 'geojson',
        'resultRecordCount' => 1000, 'orderByFields' => 'OBJECTID DESC']), ['Accept: application/geo+json'], 30);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['features'])) { GwHttp::$lastError = 'FAO locusts: ' . (is_array($j) ? ($j['error']['message'] ?? 'unexpected response') : 'unexpected response'); return null; }
    $cut = isoDaysAgo(120); $out = [];
    foreach ($j['features'] as $f) {
        $c = $f['geometry']['coordinates'] ?? null;
        if (($f['geometry']['type'] ?? '') !== 'Point' || !is_array($c)) continue;
        $p = $f['properties'] ?? [];
        $date = null;
        foreach ($p as $k => $v) if (preg_match('/date|start|obs/i', $k) && $v) { $date = gwDate(is_numeric($v) && $v > 1e11 ? (int)($v / 1000) : $v); if ($date) break; }
        if ($date && $date < $cut) continue;
        $country = null;
        foreach (['COUNTRYNAME', 'COUNTRY', 'Country', 'country', 'CTRY'] as $k) if (!empty($p[$k])) { $country = countryInfo($p[$k])['name'] ?? $p[$k]; break; }
        $out[] = gwPt(['id' => 'locust-' . ($p['OBJECTID'] ?? md5(json_encode($c) . $date)), 'source' => 'locust', 'subtype' => 'insect_infestation',
            'subtype_raw' => $p['CAT'] ?? $p['CATEGORY'] ?? null, 'severity_level' => 'orange', 'country' => $country, 'event_date' => $date,
            'lat' => (float)$c[1], 'lng' => (float)$c[0], 'title' => null, 'url' => 'https://locust-hub-hqfao.hub.arcgis.com/',
            'extra' => ['locality' => $p['LOCNAME'] ?? $p['LOCALITY'] ?? null]]);
    }
    return $out;
}
function locustEvents(): array { return gwSwrBg('locust:swarms', 'locust', fn() => locustFetch(), 8); }

/* ============================= NOAA SWPC — space weather (banner) ============================= */
function swpcFetch(): ?array {
    $raw = httpGet('https://services.swpc.noaa.gov/products/noaa-scales.json', ['Accept: application/json'], 15);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    $now = is_array($j) ? ($j['0'] ?? null) : null;
    if (!is_array($now)) { GwHttp::$lastError = 'SWPC: unexpected response'; return null; }
    $lvl = fn($k) => (int)($now[$k]['Scale'] ?? 0);
    return ['G' => $lvl('G'), 'S' => $lvl('S'), 'R' => $lvl('R'),
        'at' => trim(($now['DateStamp'] ?? '') . ' ' . ($now['TimeStamp'] ?? '')) ?: null, 'url' => 'https://www.swpc.noaa.gov/'];
}

/* ============================= HDX HAPI — humanitarian context (conflict detail) ============================= */
function hapiGetRows(string $path, array $params): ?array {
    [$raw, $status] = httpRequest('https://hapi.humdata.org/api/v2/' . $path . '?' . http_build_query($params + [
        'output_format' => 'json', 'limit' => 10000, 'app_identifier' => hapiIdentifier()]), ['Accept: application/json'], 25);
    if ($status !== 200) return null;
    return jsonArray($raw, 'data');
}
/** Keeps national rows (no admin1) if any, else all; then only the latest reference period. */
function hapiLatest(array $rows): array {
    $nat = array_values(array_filter($rows, fn($r) => empty($r['admin1_code']) && empty($r['admin1_name'])));
    if ($nat) $rows = $nat;
    $latest = max(array_map(fn($r) => (string)($r['reference_period_start'] ?? ''), $rows ?: [['reference_period_start' => '']]));
    return array_values(array_filter($rows, fn($r) => (string)($r['reference_period_start'] ?? '') === $latest));
}
function hapiContextFetch(string $iso3): ?array {
    $out = []; $any = false;
    // IPC food insecurity, phase 3+ (current analysis)
    $r = hapiGetRows('food-security-nutrition-poverty/food-security', ['location_code' => $iso3, 'ipc_phase' => '3+', 'ipc_type' => 'current']);
    if ($r !== null) { $any = true; if ($r = hapiLatest($r)) $out['ipc3'] = ['people' => (int)array_sum(array_column($r, 'population_in_phase')), 'from' => substr($r[0]['reference_period_start'] ?? '', 0, 7)]; }
    // internally displaced people
    $r = hapiGetRows('affected-people/idps', ['location_code' => $iso3]);
    if ($r !== null) { $any = true; if ($r = hapiLatest($r)) $out['idps'] = ['people' => (int)array_sum(array_column($r, 'population')), 'from' => substr($r[0]['reference_period_start'] ?? '', 0, 7)]; }
    // refugees originating from the country (all asylum countries, totals only)
    $r = hapiGetRows('affected-people/refugees-persons-of-concern', ['origin_location_code' => $iso3, 'population_group' => 'REF']);
    if ($r !== null) {
        $any = true;
        $r = array_filter($r, fn($x) => in_array(strtolower((string)($x['gender'] ?? 'all')), ['all', ''], true) && in_array(strtolower((string)($x['age_range'] ?? 'all')), ['all', ''], true));
        if ($r) {
            $latest = max(array_map(fn($x) => (string)($x['reference_period_start'] ?? ''), $r));
            $r = array_filter($r, fn($x) => (string)($x['reference_period_start'] ?? '') === $latest);
            $out['refugees'] = ['people' => (int)array_sum(array_column($r, 'population')), 'from' => substr($latest, 0, 4)];
        }
    }
    // people in need (humanitarian needs overview, intersectoral)
    $r = hapiGetRows('affected-people/humanitarian-needs', ['location_code' => $iso3, 'population_status' => 'INN']);
    if ($r !== null) {
        $any = true;
        $r = hapiLatest(array_values(array_filter($r, fn($x) => stripos((string)($x['sector_code'] ?? $x['sector_name'] ?? ''), 'intersect') !== false
            && in_array(strtolower((string)($x['category'] ?? 'total')), ['', 'total', 'all'], true))));
        if ($r) $out['in_need'] = ['people' => (int)max(array_column($r, 'population')), 'from' => substr($r[0]['reference_period_start'] ?? '', 0, 4)];
    }
    // humanitarian funding (latest appeal)
    $r = hapiGetRows('coordination-context/funding', ['location_code' => $iso3]);
    if ($r !== null) {
        $any = true;
        usort($r, fn($a, $b) => strcmp($b['reference_period_start'] ?? '', $a['reference_period_start'] ?? ''));
        if ($f = $r[0] ?? null) $out['funding'] = ['appeal' => $f['appeal_name'] ?? null, 'required_usd' => isset($f['requirements_usd']) ? (float)$f['requirements_usd'] : null,
            'funded_usd' => isset($f['funding_usd']) ? (float)$f['funding_usd'] : null, 'pct' => isset($f['funding_pct']) ? round((float)$f['funding_pct'], 1) : null,
            'year' => substr($f['reference_period_start'] ?? '', 0, 4)];
    }
    if (!$any) { GwHttp::$lastError = GwHttp::$lastError ?: 'HAPI context: no endpoint answered'; return null; }
    return $out;
}
function hapiContext(string $iso3): array { return gwSwrBg('hapictx:' . $iso3, 'hapi_context', fn() => hapiContextFetch($iso3), 8); }

/* ============================= alerts.in.ua — Ukraine air-raid alerts ============================= */
/** Oblast name stems (Ukrainian) → [English name, lat, lng]. "м. Київ" is checked first. */
const UA_OBLASTS = [
    'м. Київ' => ['Kyiv (city)', 50.45, 30.52], 'Севастопол' => ['Sevastopol', 44.60, 33.52], 'Крим' => ['Crimea', 45.0, 34.1],
    'Вінниц' => ['Vinnytsia', 49.23, 28.47], 'Волин' => ['Volyn', 50.75, 25.33], 'Дніпропетров' => ['Dnipropetrovsk', 48.46, 35.05],
    'Донец' => ['Donetsk', 48.0, 37.8], 'Житомир' => ['Zhytomyr', 50.25, 28.66], 'Закарпат' => ['Zakarpattia', 48.62, 22.29],
    'Запоріз' => ['Zaporizhzhia', 47.84, 35.14], 'Івано-Франків' => ['Ivano-Frankivsk', 48.92, 24.71], 'Київськ' => ['Kyiv oblast', 50.30, 30.30],
    'Кіровоград' => ['Kirovohrad', 48.51, 32.26], 'Луган' => ['Luhansk', 48.57, 39.31], 'Львів' => ['Lviv', 49.84, 24.03],
    'Миколаїв' => ['Mykolaiv', 46.97, 31.99], 'Одес' => ['Odesa', 46.48, 30.72], 'Полтав' => ['Poltava', 49.59, 34.55],
    'Рівнен' => ['Rivne', 50.62, 26.25], 'Сум' => ['Sumy', 50.91, 34.80], 'Терноп' => ['Ternopil', 49.55, 25.59],
    'Харків' => ['Kharkiv', 49.99, 36.23], 'Херсон' => ['Kherson', 46.64, 32.61], 'Хмельниц' => ['Khmelnytskyi', 49.42, 26.99],
    'Черкас' => ['Cherkasy', 49.44, 32.06], 'Чернівец' => ['Chernivtsi', 48.29, 25.94], 'Чернігів' => ['Chernihiv', 51.49, 31.29],
];
function uaOblast(string $name): ?array {
    foreach (UA_OBLASTS as $stem => $o) if ($name !== '' && mb_strpos($name, $stem) !== false) return $o;
    return null;
}
function alertsUaFetch(): ?array {
    $raw = httpGet('https://api.alerts.in.ua/v1/alerts/active.json', ['Authorization: Bearer ' . ALERTS_UA_TOKEN, 'Accept: application/json'], 15);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['alerts'])) { GwHttp::$lastError = 'alerts.in.ua: unexpected response'; return null; }
    $by = [];
    foreach ($j['alerts'] as $a) {
        $obl = uaOblast((string)($a['location_oblast'] ?? $a['location_title'] ?? ''));
        if (!$obl) continue;
        $g = &$by[$obl[0]];
        $g['o'] = $obl;
        $type = (string)($a['alert_type'] ?? 'air_raid');
        $g['types'][$type] = ($g['types'][$type] ?? 0) + 1;
        $g['whole'] = ($g['whole'] ?? false) || (($a['location_type'] ?? '') === 'oblast');
        $st = strtotime((string)($a['started_at'] ?? '')) ?: null;
        if ($st) $g['since'] = isset($g['since']) ? min($g['since'], $st) : $st;
        unset($g);
    }
    $out = [];
    foreach ($by as $name => $g) {
        $types = $g['types'];
        $grave = array_intersect(array_keys($types), ['artillery_shelling', 'urban_fights', 'chemical', 'nuclear']);
        $out[] = gwPt(['id' => 'uaalert-' . md5($name), 'category' => 'conflict', 'source' => 'alerts_ua', 'subtype' => 'air_raid',
            'severity_level' => $grave ? 'red' : 'orange', 'country' => 'Ukraine', 'event_date' => gmdate('Y-m-d', $g['since'] ?? time()),
            'lat' => $g['o'][1], 'lng' => $g['o'][2], 'title' => $name, 'url' => 'https://alerts.in.ua/en',
            'extra' => ['types' => array_keys($types), 'alerts' => array_sum($types), 'whole_oblast' => $g['whole'],
                'since' => isset($g['since']) ? gmdate('c', $g['since']) : null, 'ongoing' => true]]);
    }
    return $out;
}
function alertsUaEvents(): array { return gwSwrBg('alertsua:active', 'alerts_ua', fn() => alertsUaFetch(), 2); }

/* ============================= Cloudflare Radar — curated Internet outages ============================= */
function cfRadarFetch(int $days): ?array {
    $range = $days <= 1 ? '1d' : ($days <= 2 ? '2d' : ($days <= 7 ? '7d' : ($days <= 28 ? '28d' : '12w')));
    [$raw, $status] = httpRequest('https://api.cloudflare.com/client/v4/radar/annotations/outages?' . http_build_query([
        'dateRange' => $range, 'limit' => 200, 'format' => 'json']), ['Authorization: Bearer ' . CLOUDFLARE_RADAR_TOKEN, 'Accept: application/json'], 20);
    $j = json_decode((string)$raw, true);
    if ($status !== 200 || empty($j['success'])) {
        GwHttp::$lastError = 'Cloudflare Radar: ' . ($j['errors'][0]['message'] ?? ('HTTP ' . $status)) . ($status === 400 ? ' (token needs "Account › Radar › Read")' : '');
        return null;
    }
    $out = [];
    foreach (($j['result']['annotations'] ?? []) as $a) {
        $info = countryByIso2($a['locations'][0] ?? null);
        if (!$info) continue;
        $cause = strtolower((string)($a['outage']['outageCause'] ?? ''));
        $open = empty($a['endDate']);
        [$lat, $lng] = gwCentroidPoint($info, 'cf' . ($a['id'] ?? $a['startDate'] ?? ''), 0.6);
        $out[] = gwPt(['id' => 'cfr-' . md5(($a['id'] ?? '') . ($a['startDate'] ?? '') . $info['iso3']), 'category' => 'emergency', 'source' => 'cfradar',
            'subtype' => 'internet_outage', 'subtype_raw' => $cause ?: null,
            'severity_level' => $cause === 'government_directed' || ($a['outage']['outageType'] ?? '') === 'NATIONWIDE' ? 'red' : ($open ? 'orange' : 'green'),
            'country' => $info['name'], 'event_date' => gwDate($a['startDate'] ?? null), 'lat' => $lat, 'lng' => $lng,
            'title' => $a['description'] ?? null, 'url' => $a['linkedUrl'] ?? ('https://radar.cloudflare.com/outage-center'),
            'extra' => ['cause' => $cause ?: null, 'scope' => $a['scope'] ?? null, 'type' => strtolower((string)($a['outage']['outageType'] ?? '')) ?: null,
                'ongoing' => $open, 'end' => $a['endDate'] ?? null, 'approx_location' => true]]);
    }
    return $out;
}
function cfRadarEvents(int $days): array {
    $d = $days <= 1 ? 1 : ($days <= 2 ? 2 : ($days <= 7 ? 7 : ($days <= 28 ? 28 : 84)));
    return gwSwrBg("cfradar:{$d}", 'cfradar', fn() => cfRadarFetch($d), 5);
}

/* ============================= IDMC — Internal Displacement Updates ============================= */
function idmcFetch(): ?array {
    $raw = httpGet('https://helix-tools-api.idmcdb.org/external-api/idus/last-180-days/?' . http_build_query(['client_id' => IDMC_CLIENT_ID]),
        ['Accept: application/json'], 40);
    if ($raw === null) return null;
    $rows = json_decode($raw, true);
    if (is_array($rows) && isset($rows['results'])) $rows = $rows['results'];
    if (!is_array($rows) || !array_is_list($rows)) { GwHttp::$lastError = 'IDMC: unexpected response (client_id?)'; return null; }
    $out = [];
    foreach ($rows as $r) {
        $la = $r['latitude'] ?? null; $lo = $r['longitude'] ?? null;
        $info = countryInfo($r['iso3'] ?? null) ?? countryInfo($r['country'] ?? null);
        if (!is_numeric($la) || !is_numeric($lo)) { if (!$info) continue; [$la, $lo] = gwCentroidPoint($info, 'idmc' . ($r['id'] ?? '')); }
        $fig = (int)($r['figure'] ?? 0);
        if ($fig <= 0) continue;
        $cause = strtolower((string)($r['displacement_type'] ?? ''));
        $out[] = gwPt(['id' => 'idmc-' . ($r['id'] ?? md5(json_encode($r))), 'category' => 'emergency', 'source' => 'idmc', 'subtype' => 'displacement',
            'subtype_raw' => $r['type'] ?? ($r['category'] ?? null), 'severity_level' => $fig >= 10000 ? 'red' : ($fig >= 1000 ? 'orange' : 'green'),
            'country' => $info['name'] ?? ($r['country'] ?? null), 'event_date' => gwDate($r['displacement_date'] ?? $r['displacement_start_date'] ?? $r['event_start_date'] ?? null),
            'lat' => (float)$la, 'lng' => (float)$lo, 'title' => $r['event_name'] ?? null,
            'url' => $r['source_url'] ?? $r['event_url'] ?? 'https://www.internal-displacement.org/global-displacement-map',
            'extra' => ['figure' => $fig, 'cause' => $cause ?: null, 'qualifier' => $r['qualifier'] ?? null,
                'summary' => isset($r['standard_popup_text']) ? mb_substr(trim(strip_tags((string)$r['standard_popup_text'])), 0, 400) : null]]);
    }
    usort($out, fn($a, $b) => $b['extra']['figure'] <=> $a['extra']['figure']);
    return array_slice($out, 0, 1500);
}
function idmcEvents(): array { return gwSwrBg('idmc:idu', 'idmc', fn() => idmcFetch(), 7); }
