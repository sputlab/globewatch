<?php
/*
 * GlobeWatch — data sources.
 *
 * Convention: every *Fetch() function talks to the upstream and returns an
 * array on success (possibly empty) or NULL on failure. NULL is important:
 * it tells the cache layer "don't overwrite the last good data with nothing".
 *
 * Normalized point event:
 *   id, category (unrest|atrocity|disaster|emergency), source, subtype (code
 *   translated by the UI, e.g. 'earthquake'), subtype_raw (upstream text, if
 *   any), severity_level (green|orange|red|null), country, event_date (Y-m-d),
 *   lat, lng, fatalities, title (upstream text or null — the UI composes a
 *   localized title from `extra` when null), url, extra (source-specific data).
 */

/* ============================= GDELT DOC 2.0 ============================= */
/* No key. Strict pacing (~1 request / 5 s per IP, blocks escalate if ignored):
   calls are serialized across processes with a MySQL lock, spaced by
   GDELT_MIN_INTERVAL, and after an HTTP 429 GDELT is paused for
   GDELT_BACKOFF_SECONDS. The DOC API only covers the last 3 months. */

/** Builds the DOC query. Terms are exact phrases (so "Israel Gaza" only matches those two words
 *  side by side — keep keywords as real phrases); GDELT rejects phrases shorter than ~5 chars. */
function gdeltQuery(string $keywordsCsv): ?string {
    $terms = array_values(array_filter(array_map('trim', explode(',', $keywordsCsv)), fn($t) => mb_strlen($t) >= 5));
    if (!$terms) return null;
    $q = implode(' OR ', array_map(fn($t) => '"' . str_replace('"', '', $t) . '"', $terms));
    return count($terms) > 1 ? '(' . $q . ')' : $q;   // GDELT rejects OR'd terms not wrapped in ()
}

function gdeltCall(array $params, int $timeout = 20): ?array {
    $backoff = (int)(kvGet('gdelt:backoff_until') ?? 0);
    if (time() < $backoff) throw new GwSkip('gdelt backoff');
    if (!dbLock('gdelt', 0)) throw new GwSkip('gdelt busy');
    try {
        $wait = GDELT_MIN_INTERVAL - (microtime(true) - (float)(kvGet('gdelt:last_call') ?? 0));
        if ($wait > 0) usleep((int)(min($wait, GDELT_MIN_INTERVAL) * 1e6));
        $qs = http_build_query($params + ['format' => 'json']);
        $https = stripos(GDELT_DOC_ENDPOINT, 'https://') === 0;
        $httpUrl = 'http://' . preg_replace('#^https?://#i', '', GDELT_DOC_ENDPOINT) . '?' . $qs;
        // Once the http fallback has worked, stick to it for a day instead of waiting for the TLS timeout every time.
        $preferHttp = $https && GDELT_HTTP_FALLBACK && kvGet('gdelt:prefer_http');
        if ($preferHttp) {
            [$raw, $status] = httpRequest($httpUrl, [], $timeout, null, [CURLOPT_CONNECTTIMEOUT => 10]);
        } else {
            [$raw, $status] = httpRequest(GDELT_DOC_ENDPOINT . '?' . $qs, [], $timeout + (int)GDELT_CONNECT_TIMEOUT, null,
                [CURLOPT_CONNECTTIMEOUT => (int)GDELT_CONNECT_TIMEOUT]);
            // "SSL connection timeout" / connect timeout (curl 7, 28, 35, 56): GDELT's https front end is often slow to
            // complete the TLS handshake from shared hosting. The same API is served over plain http (public news
            // metadata only, no credentials involved), so retry there.
            if ($status === 0 && $https && GDELT_HTTP_FALLBACK && in_array(GwHttp::$lastErrno, [7, 28, 35, 56], true)) {
                $firstErr = GwHttp::$lastError;
                [$raw, $status] = httpRequest($httpUrl, [], $timeout, null, [CURLOPT_CONNECTTIMEOUT => 10]);
                if ($status === 200) kvSet('gdelt:prefer_http', 1, 86400);
                else GwHttp::$lastError = 'https: ' . $firstErr . ' | http: ' . GwHttp::$lastError;
            }
        }
        kvSet('gdelt:last_call', microtime(true), 3600);
        if ($status === 429 || ($raw !== null && stripos($raw, 'limit requests') !== false)) {
            kvSet('gdelt:backoff_until', time() + GDELT_BACKOFF_SECONDS, GDELT_BACKOFF_SECONDS + 60);
            GwHttp::$lastError = 'HTTP 429 — rate limited, paused for ' . round(GDELT_BACKOFF_SECONDS / 60) . ' min';
            return null;
        }
        if ($status !== 200 || $raw === null) return null;
        $raw = trim($raw);
        if ($raw === '' || $raw === '{}') return [];
        $j = json_decode($raw, true);
        if (!is_array($j)) { GwHttp::$lastError = 'GDELT: ' . mb_substr($raw, 0, 120); return null; } // text error message
        return $j;
    } finally {
        dbUnlock('gdelt');
    }
}

/** Daily article counts for the last 90 days: ['days' => ['Y-m-d' => n]]. One call serves every period. */
function gdeltVolumeFetch(string $keywordsCsv): ?array {
    $q = gdeltQuery($keywordsCsv);
    if ($q === null) return ['days' => []];
    $j = gdeltCall(['query' => $q, 'mode' => 'timelinevolraw',
        'startdatetime' => gmdate('YmdHis', strtotime('-89 days')), 'enddatetime' => gmdate('YmdHis')]);
    if ($j === null) return null;
    $days = [];
    foreach (($j['timeline'][0]['data'] ?? []) as $pt) {
        $d = substr((string)($pt['date'] ?? ''), 0, 8);
        if (strlen($d) !== 8) continue;
        $key = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
        $days[$key] = ($days[$key] ?? 0) + (int)($pt['value'] ?? 0);
    }
    return ['days' => $days];
}

function gdeltArticlesFetch(string $keywordsCsv): ?array {
    $q = gdeltQuery($keywordsCsv);
    if ($q === null) return [];
    $j = gdeltCall(['query' => $q, 'mode' => 'artlist', 'maxrecords' => GDELT_MAX_RECORDS, 'sort' => 'datedesc',
        'startdatetime' => gmdate('YmdHis', strtotime('-30 days')), 'enddatetime' => gmdate('YmdHis')]);
    if ($j === null) return null;
    $out = [];
    foreach (($j['articles'] ?? []) as $a) {
        $out[] = ['title' => $a['title'] ?? null, 'url' => $a['url'] ?? null, 'domain' => $a['domain'] ?? null,
            'language' => $a['language'] ?? null,
            'event_date' => !empty($a['seendate']) ? gmdate('Y-m-d', strtotime($a['seendate'])) : null];
    }
    return $out;
}

function gdeltKeyFor(array $c, string $kind): string {
    return "gdelt:{$kind}:" . (int)$c['id'] . ':' . substr(md5((string)$c['gdelt_keywords']), 0, 8);
}
function gdeltVolume(array $c): array {
    return swr(gdeltKeyFor($c, 'vol'), fn() => gdeltVolumeFetch((string)$c['gdelt_keywords']),
        ['source' => 'gdelt', 'sync' => false, 'group' => 'gdelt', 'priority' => 8]);
}
function gdeltArticles(array $c): array {
    return swr(gdeltKeyFor($c, 'art'), fn() => gdeltArticlesFetch((string)$c['gdelt_keywords']),
        ['source' => 'gdelt', 'sync' => false, 'group' => 'gdelt', 'priority' => 3]);
}
/** Sum of daily counts within the window (GDELT covers at most 90 days). */
function gdeltWindowSum(?array $vol, int $days): ?int {
    if (!$vol || !isset($vol['days'])) return null;
    $since = gmdate('Y-m-d', strtotime('-' . (min($days, 90) - 1) . ' days'));
    $sum = 0;
    foreach ($vol['days'] as $d => $n) if ($d >= $since) $sum += $n;
    return $sum;
}

/* ============================= GDACS ============================= */
/* No key. International-relevance alerts with their own green/orange/red level.
   The SEARCH endpoint is paginated (100 per page): v1.9 only read the first page. */

const GDACS_SUBTYPES = ['EQ' => 'earthquake', 'TC' => 'tropical_cyclone', 'FL' => 'flood',
    'VO' => 'volcano', 'WF' => 'wildfire', 'DR' => 'drought'];

function gdacsFetch(int $days): ?array {
    $effectiveDays = min($days, 730);     // alert system for the recent past, not a deep archive
    $maxPages = $effectiveDays > 90 ? 5 : 3;
    $out = []; $seen = []; $okAny = false;
    for ($page = 1; $page <= $maxPages; $page++) {
        $params = http_build_query([
            'eventlist' => implode(';', array_keys(GDACS_SUBTYPES)),
            'fromdate' => isoDaysAgo($effectiveDays), 'todate' => gmdate('Y-m-d', strtotime('+1 day')),
            'alertlevel' => $effectiveDays > 365 ? 'orange;red' : 'green;orange;red',
            'pagesize' => 100, 'pagenumber' => $page,
        ]);
        $raw = httpGet('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?' . $params, [], 15);
        if ($raw === null) { if ($page === 1) return null; break; }
        $okAny = true;
        $features = jsonArray($raw, 'features');
        $new = 0;
        foreach ($features as $f) {
            $p = $f['properties'] ?? [];
            $id = ($p['eventtype'] ?? '') . ($p['eventid'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true; $new++;
            $coords = $f['geometry']['coordinates'] ?? null;
            while (is_array($coords[0] ?? null)) $coords = $coords[0];
            if (!isset($coords[0], $coords[1])) continue;
            $type = $p['eventtype'] ?? null;
            $title = trim((string)($p['name'] ?? '')) ?: trim((string)($p['eventname'] ?? '')) ?: trim((string)($p['description'] ?? ''));
            $out[] = [
                'id' => 'gdacs-' . $id, 'category' => 'disaster', 'source' => 'gdacs',
                'subtype' => GDACS_SUBTYPES[$type] ?? 'other', 'subtype_raw' => $type,
                'severity_level' => isset($p['alertlevel']) ? strtolower($p['alertlevel']) : null,
                'country' => $p['country'] ?? null,
                'event_date' => !empty($p['fromdate']) ? gmdate('Y-m-d', strtotime($p['fromdate'])) : null,
                'lat' => (float)$coords[1], 'lng' => (float)$coords[0], 'fatalities' => null,
                'title' => $title ?: null,
                'url' => $p['url']['report'] ?? $p['url']['details'] ?? null,
                'extra' => ['ongoing' => filter_var($p['iscurrent'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'severity_text' => $p['severitydata']['severitytext'] ?? null],
            ];
        }
        if (count($features) < 100 || $new === 0) break;   // last page, or pagination params ignored
    }
    return $okAny ? $out : null;
}
function gdacsEvents(int $days): array {
    return swr("gdacs:{$days}", fn() => gdacsFetch($days), ['source' => 'gdacs', 'priority' => 2]);
}

/* ============================= USGS ============================= */
/* No key. Up to 7 days: the real-time GeoJSON summary feeds (regenerated every
   minute and served from a CDN — the freshest and most reliable option).
   Longer periods: the FDSN query service with a magnitude threshold that
   grows with the period (so a wider period never shows fewer quakes). */

function usgsMinMagnitude(int $days): float {
    return $days <= 7 ? 3.0 : ($days <= 90 ? 3.5 : ($days <= 365 ? 4.0 : ($days <= 1095 ? 4.5 : 5.0)));
}
function usgsFetch(int $days, int $limit = 300): ?array {
    $minMag = usgsMinMagnitude($days);
    if ($days <= 7) {
        $feed = $days <= 1 ? '2.5_day' : '2.5_week';
        $raw = httpGet("https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/{$feed}.geojson", [], 15);
        if ($raw === null) return null;
        $since = (time() - $days * 86400) * 1000;
        $features = array_values(array_filter(jsonArray($raw, 'features'),
            fn($f) => (float)($f['properties']['mag'] ?? 0) >= $minMag && (int)($f['properties']['time'] ?? 0) >= $since));
        usort($features, fn($a, $b) => ($b['properties']['mag'] ?? 0) <=> ($a['properties']['mag'] ?? 0));
        $features = array_slice($features, 0, $limit);
    } else {
        $raw = httpGet('https://earthquake.usgs.gov/fdsnws/event/1/query?' . http_build_query([
            'format' => 'geojson', 'starttime' => isoDaysAgo($days), 'endtime' => gmdate('Y-m-d', strtotime('+1 day')),
            'minmagnitude' => $minMag, 'orderby' => 'magnitude', 'limit' => $limit,
        ]), [], 20);
        if ($raw === null) return null;
        $features = jsonArray($raw, 'features');
    }
    $out = [];
    foreach ($features as $f) {
        $p = $f['properties'] ?? [];
        $c = $f['geometry']['coordinates'] ?? null;
        if (!isset($c[0], $c[1])) continue;
        $mag = (float)($p['mag'] ?? 0);
        $pager = $p['alert'] ?? null;   // USGS PAGER impact estimate, when available
        $sev = $pager ? ['green' => 'green', 'yellow' => 'orange', 'orange' => 'red', 'red' => 'red'][$pager] ?? null : null;
        $out[] = [
            'id' => 'usgs-' . ($f['id'] ?? md5(json_encode($c))), 'category' => 'disaster', 'source' => 'usgs',
            'subtype' => 'earthquake', 'subtype_raw' => null,
            'severity_level' => $sev ?? ($mag >= 6 ? 'red' : ($mag >= 4.5 ? 'orange' : 'green')),
            'country' => null,
            'event_date' => isset($p['time']) ? gmdate('Y-m-d', intdiv((int)$p['time'], 1000)) : null,
            'lat' => (float)$c[1], 'lng' => (float)$c[0], 'fatalities' => null,
            'title' => $p['place'] ?? null, 'url' => $p['url'] ?? null,
            'extra' => ['mag' => round($mag, 1), 'depth_km' => isset($c[2]) ? round((float)$c[2]) : null,
                'tsunami' => !empty($p['tsunami']), 'pager' => $pager,
                'time' => isset($p['time']) ? gmdate('c', intdiv((int)$p['time'], 1000)) : null],
        ];
    }
    return $out;
}
function usgsEvents(int $days): array {
    return swr("usgs:{$days}", fn() => usgsFetch($days), ['source' => 'usgs', 'priority' => 1,
        'ttl' => $days <= 7 ? sourceTtl('usgs') : 900]);
}

/* ============================= NASA FIRMS ============================= */
/* Free instant MAP_KEY. The global endpoint returns a CSV with tens of
   thousands of rows per day: streamed to a temp file and read line by line,
   keeping only the most intense hotspots. Limited to 1-3 days by design. */

function firmsFetch(int $dayRange, int $maxPoints = 200): ?array {
    if (!function_exists('curl_init')) return null;
    $url = 'https://firms.modaps.eosdis.nasa.gov/api/area/csv/' . rawurlencode(FIRMS_MAP_KEY) . "/VIIRS_SNPP_NRT/world/{$dayRange}";
    $tmp = fopen('php://temp/maxmemory:2097152', 'w+');
    if (!$tmp) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $tmp, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . gw_user_agent()]]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    if (!$ok || $status !== 200) { GwHttp::$lastError = 'FIRMS HTTP ' . $status; fclose($tmp); return null; }

    rewind($tmp);
    $header = fgetcsv($tmp, 0, ',', '"', '\\');
    if (!$header) { fclose($tmp); return null; }
    $idx = array_flip(array_map('trim', $header));
    if (!isset($idx['latitude'], $idx['longitude'])) {   // e.g. "Invalid MAP_KEY" text response
        GwHttp::$lastError = 'FIRMS: ' . mb_substr(implode(',', $header), 0, 100);
        fclose($tmp); return null;
    }
    $candidates = [];
    $examined = 0;
    while ($examined < 60000 && ($row = fgetcsv($tmp, 0, ',', '"', '\\')) !== false) {
        $examined++;
        if (strtolower($row[$idx['confidence']] ?? '') === 'l') continue;   // low confidence = noise
        $candidates[] = ['lat' => (float)$row[$idx['latitude']], 'lng' => (float)$row[$idx['longitude']],
            'date' => $row[$idx['acq_date']] ?? null, 'time' => $row[$idx['acq_time']] ?? null,
            'frp' => (float)($row[$idx['frp']] ?? 0)];
        if (count($candidates) > $maxPoints * 4) {
            usort($candidates, fn($a, $b) => $b['frp'] <=> $a['frp']);
            $candidates = array_slice($candidates, 0, $maxPoints * 2);
        }
    }
    fclose($tmp);
    usort($candidates, fn($a, $b) => $b['frp'] <=> $a['frp']);
    $out = [];
    foreach (array_slice($candidates, 0, $maxPoints) as $c) {
        $out[] = [
            'id' => 'firms-' . substr(md5($c['lat'] . $c['lng'] . $c['date'] . $c['time']), 0, 16),
            'category' => 'disaster', 'source' => 'firms', 'subtype' => 'fire_hotspot', 'subtype_raw' => null,
            'severity_level' => $c['frp'] >= 50 ? 'red' : ($c['frp'] >= 10 ? 'orange' : 'green'),
            'country' => null, 'event_date' => $c['date'], 'lat' => $c['lat'], 'lng' => $c['lng'],
            'fatalities' => null, 'title' => null, 'url' => null,
            'extra' => ['frp' => round($c['frp']), 'acq_time' => $c['time']],
        ];
    }
    return $out;
}
function firmsEvents(int $days): array {
    $range = max(1, min(3, $days));
    return swr("firms:{$range}", fn() => firmsFetch($range), ['source' => 'firms', 'sync' => false, 'priority' => 6]);
}

/* ============================= NASA EONET ============================= */
/* No key. Curated natural events with open/closed status. Category IDs are
   camelCase: v1.9 sent 'severestorms' / 'seaandlakeice', silently dropping them. */

const EONET_CATEGORIES = ['wildfires' => 'wildfire', 'severeStorms' => 'severe_storm', 'volcanoes' => 'volcano',
    'floods' => 'flood', 'landslides' => 'landslide', 'drought' => 'drought', 'tempExtremes' => 'temperature_extreme'];

function eonetFetch(int $days): ?array {
    $raw = httpGet('https://eonet.gsfc.nasa.gov/api/v3/events?' . http_build_query([
        'status' => 'all', 'days' => min(max($days, 1), 3650), 'limit' => 300,
        'category' => implode(',', array_keys(EONET_CATEGORIES)),
    ]), [], 20);
    if ($raw === null) return null;
    $out = [];
    foreach (jsonArray($raw, 'events') as $e) {
        $catId = $e['categories'][0]['id'] ?? null;
        $geoms = $e['geometry'] ?? [];
        $geom = $geoms ? end($geoms) : null;               // latest known position (storms move)
        $first = $geoms ? reset($geoms) : null;
        $c = $geom['coordinates'] ?? null;
        while (is_array($c[0] ?? null)) $c = $c[0];         // polygons: take a vertex
        if (!isset($c[0], $c[1])) continue;
        $open = empty($e['closed']);
        $mag = isset($geom['magnitudeValue']) ? (float)$geom['magnitudeValue'] : null;
        $sev = $open ? 'orange' : 'green';
        if ($open && $catId === 'severeStorms' && $mag !== null && ($geom['magnitudeUnit'] ?? '') === 'kts' && $mag >= 64) $sev = 'red';
        $out[] = [
            'id' => 'eonet-' . ($e['id'] ?? md5(json_encode($c))), 'category' => 'disaster', 'source' => 'eonet',
            'subtype' => EONET_CATEGORIES[$catId] ?? 'other', 'subtype_raw' => $e['categories'][0]['title'] ?? null,
            'severity_level' => $sev, 'country' => null,
            'event_date' => isset($first['date']) ? gmdate('Y-m-d', strtotime($first['date'])) : null,
            'lat' => (float)$c[1], 'lng' => (float)$c[0], 'fatalities' => null,
            'title' => $e['title'] ?? null, 'url' => $e['sources'][0]['url'] ?? ($e['link'] ?? null),
            'extra' => ['ongoing' => $open, 'magnitude' => $mag, 'magnitude_unit' => $geom['magnitudeUnit'] ?? null,
                'last_update' => isset($geom['date']) ? gmdate('Y-m-d', strtotime($geom['date'])) : null],
        ];
    }
    return $out;
}
function eonetEvents(int $days): array {
    return swr("eonet:{$days}", fn() => eonetFetch($days), ['source' => 'eonet', 'priority' => 3]);
}

/* ============================= ReliefWeb (OCHA) ============================= */
/* Pre-approved appname. Two uses: related reports in a conflict's detail, and
   (new in 1.10) the list of disasters in "alert" / "ongoing" status — which
   includes epidemics and complex emergencies that no other source covers. */

function reliefwebPost(string $endpoint, array $body, int $timeout = 15): ?array {
    [$raw, $status] = httpRequest('https://api.reliefweb.int/v2/' . $endpoint . '?appname=' . rawurlencode(RELIEFWEB_APPNAME),
        ['Content-Type: application/json'], $timeout, json_encode($body));
    if ($status !== 200 || $raw === null) return null;
    return jsonArray($raw, 'data');
}

function reliefwebReportsFetch(string $query, int $limit = 5): ?array {
    $items = reliefwebPost('reports', [
        'query' => ['value' => $query, 'fields' => ['title']], 'sort' => ['date.created:desc'], 'limit' => $limit,
        'fields' => ['include' => ['title', 'date.created', 'url_alias', 'source.name']],
    ], 10);
    if ($items === null) return null;
    return array_map(fn($it) => [
        'title' => $it['fields']['title'] ?? null, 'url' => $it['fields']['url_alias'] ?? null,
        'date' => $it['fields']['date']['created'] ?? null, 'source' => $it['fields']['source'][0]['name'] ?? 'ReliefWeb',
    ], $items);
}
function reliefwebReports(string $query): array {
    return swr('rw:rep:' . md5($query), fn() => reliefwebReportsFetch($query), ['source' => 'reliefweb', 'priority' => 4]);
}

/** ReliefWeb disaster type → [category, subtype code]. */
function reliefwebClassify(?string $type): array {
    $map = [
        'Epidemic' => ['emergency', 'epidemic'], 'Insect Infestation' => ['emergency', 'insect_infestation'],
        'Complex Emergency' => ['emergency', 'complex_emergency'], 'Technological Disaster' => ['emergency', 'technological'],
        'Earthquake' => ['disaster', 'earthquake'], 'Tsunami' => ['disaster', 'tsunami'],
        'Tropical Cyclone' => ['disaster', 'tropical_cyclone'], 'Extratropical Cyclone' => ['disaster', 'severe_storm'],
        'Severe Local Storm' => ['disaster', 'severe_storm'], 'Storm Surge' => ['disaster', 'flood'],
        'Flood' => ['disaster', 'flood'], 'Flash Flood' => ['disaster', 'flood'], 'Land Slide' => ['disaster', 'landslide'],
        'Mud Slide' => ['disaster', 'landslide'], 'Snow Avalanche' => ['disaster', 'landslide'],
        'Volcano' => ['disaster', 'volcano'], 'Wild Fire' => ['disaster', 'wildfire'], 'Fire' => ['disaster', 'wildfire'],
        'Drought' => ['disaster', 'drought'], 'Cold Wave' => ['disaster', 'temperature_extreme'],
        'Heat Wave' => ['disaster', 'temperature_extreme'],
    ];
    return $map[$type ?? ''] ?? ['emergency', 'other'];
}

function reliefwebDisastersFetch(): ?array {
    $items = reliefwebPost('disasters', [
        // valid disaster statuses are alert / current / past (+ archive variants): "ongoing" made the API answer HTTP 400
        'filter' => ['field' => 'status', 'value' => ['alert', 'current'], 'operator' => 'OR'],
        'fields' => ['include' => ['name', 'status', 'glide', 'date.created', 'date.event', 'primary_type.name', 'type.name',
            'primary_country.name', 'primary_country.iso3', 'url_alias', 'url']],   // 'primary_country.location' is not a valid field (HTTP 400)
        'sort' => ['date.created:desc'], 'limit' => 250,
    ], 20);
    if ($items === null) return null;
    $out = [];
    foreach ($items as $it) {
        $f = $it['fields'] ?? [];
        $type = $f['primary_type']['name'] ?? ($f['type'][0]['name'] ?? null);
        [$category, $subtype] = reliefwebClassify($type);
        $pc = $f['primary_country'] ?? [];
        $info = countryInfo($pc['iso3'] ?? null) ?? countryInfo($pc['name'] ?? null);
        $lat = $info['lat'] ?? null; $lng = $info['lng'] ?? null;   // country centroid (ReliefWeb has no event coordinates)
        if ($lat === null || $lng === null) continue;
        [$dLat, $dLng] = gwJitter('rw' . ($it['id'] ?? $f['name'] ?? ''), 0.9);   // country centroid → spread a bit
        $date = $f['date']['event'] ?? $f['date']['created'] ?? null;
        $out[] = [
            'id' => 'rw-' . ($it['id'] ?? md5(json_encode($f))), 'category' => $category, 'source' => 'reliefweb',
            'subtype' => $subtype, 'subtype_raw' => $type,
            'severity_level' => ($f['status'] ?? '') === 'alert' ? 'red' : 'orange',
            'country' => $pc['name'] ?? ($info['name'] ?? null),
            'event_date' => $date ? gmdate('Y-m-d', strtotime($date)) : null,
            'lat' => (float)$lat + $dLat, 'lng' => (float)$lng + $dLng, 'fatalities' => null,
            'title' => $f['name'] ?? null, 'url' => $f['url_alias'] ?? ($f['url'] ?? null),
            'extra' => ['status' => $f['status'] ?? null, 'glide' => $f['glide'] ?? null, 'ongoing' => true, 'approx_location' => true],
        ];
    }
    return $out;
}
function reliefwebDisasters(): array {
    return swr('rw:disasters', fn() => reliefwebDisastersFetch(), ['source' => 'reliefweb_disasters', 'priority' => 4]);
}

/* ============================= HDX HAPI (OCHA) ============================= */
/* Free (app_identifier = base64("app:email"), no approval). ACLED-derived
   MONTHLY counts per country/admin area for three non-exclusive event types:
   political_violence, civilian_targeting, demonstration. It brings back a
   fatality-based conflict intensity and country-level unrest / civilian
   targeting markers without a paid ACLED plan. Updated weekly upstream. */

function hapiIdentifier(): string {
    if (HAPI_APP_IDENTIFIER !== '') return HAPI_APP_IDENTIFIER;
    return HAPI_EMAIL !== '' ? base64_encode(HAPI_APP_NAME . ':' . HAPI_EMAIL) : '';
}
function hapiConfigured(): bool { return hapiIdentifier() !== ''; }

/** ['months' => ['Y-m' => ['political_violence' => [events, fatalities], ...]]] for the last ~13 months. */
function hapiFetchCountry(string $iso3): ?array {
    $backoff = (int)(kvGet('hapi:backoff_until') ?? 0);
    if (time() < $backoff) throw new GwSkip('hapi backoff');
    $start = gmdate('Y-m-01', strtotime('first day of -12 months'));
    $months = [];
    $limit = 10000;
    for ($page = 0; $page < 3; $page++) {
        $url = 'https://hapi.humdata.org/api/v2/coordination-context/conflict-events?' . http_build_query([
            'location_code' => $iso3, 'start_date' => $start, 'output_format' => 'json',
            'limit' => $limit, 'offset' => $page * $limit, 'app_identifier' => hapiIdentifier(),
        ]);
        [$raw, $status] = httpRequest($url, ['Accept: application/json'], 25);
        if ($status === 429) { kvSet('hapi:backoff_until', time() + 900, 960); GwHttp::$lastError = 'HAPI HTTP 429'; return null; }
        if ($status === 404 || $status === 422) return ['months' => [], 'not_covered' => true];
        if ($status !== 200 || $raw === null) return null;
        $rows = jsonArray($raw, 'data');
        foreach ($rows as $r) {
            $m = substr((string)($r['reference_period_start'] ?? ''), 0, 7);
            $t = $r['event_type'] ?? null;
            if (strlen($m) !== 7 || !$t) continue;
            $months[$m][$t][0] = ($months[$m][$t][0] ?? 0) + (int)($r['events'] ?? 0);
            $months[$m][$t][1] = ($months[$m][$t][1] ?? 0) + (int)($r['fatalities'] ?? 0);
        }
        if (count($rows) < $limit) break;
    }
    ksort($months);
    return ['months' => $months];
}
function hapiCountry(string $iso3): array {
    return swr('hapi:ce:' . $iso3, fn() => hapiFetchCountry($iso3),
        ['source' => 'hapi', 'sync' => false, 'group' => 'hapi', 'priority' => 6, 'stale' => 86400 * 14]);
}
/** Sums a HAPI country record over the months touched by the window (min. 1 month: monthly data). */
function hapiWindow(?array $rec, int $days): array {
    $out = ['political_violence' => [0, 0], 'civilian_targeting' => [0, 0], 'demonstration' => [0, 0],
        'months' => [], 'first' => null, 'last' => null];
    if (!$rec || empty($rec['months'])) return $out;
    $sinceMonth = gmdate('Y-m', strtotime('-' . max($days, 30) . ' days'));
    foreach ($rec['months'] as $m => $types) {
        if ($m < $sinceMonth) continue;
        $out['months'][] = $m;
        foreach ($types as $t => [$ev, $fat]) {
            if (!isset($out[$t])) continue;
            $out[$t][0] += $ev; $out[$t][1] += $fat;
        }
    }
    if ($out['months']) { $out['first'] = reset($out['months']); $out['last'] = end($out['months']); }
    return $out;
}

/* ============================= ACLED ============================= */
/* Email+password OAuth. API access now requires a paid plan: disabled by default. */

function acledConfigured(): bool { return ACLED_EMAIL !== '' && ACLED_PASSWORD !== ''; }

function acledGetToken(): ?string {
    if (!isSourceEnabled('acled') || !acledConfigured()) return null;
    $cached = kvGet('acled_token');
    if (!empty($cached['token'])) return $cached['token'];
    [$raw, $status] = httpRequest('https://acleddata.com/oauth/token', ['Content-Type: application/x-www-form-urlencoded'], 12,
        http_build_query(['username' => ACLED_EMAIL, 'password' => ACLED_PASSWORD, 'grant_type' => 'password',
            'client_id' => 'acled', 'scope' => 'authenticated']));
    if ($status !== 200) { gwHealth('acled', false, 'OAuth HTTP ' . $status); return null; }
    $data = jsonObject($raw);
    $token = $data['access_token'] ?? null;
    if ($token) kvSet('acled_token', ['token' => $token], max(60, (int)($data['expires_in'] ?? 3600) - 120));
    return $token;
}

/** Live ACLED rows per country, last N days (max 365). Returns [country => rows] or null. */
function acledFetchCountries(array $countries, int $days): ?array {
    $token = acledGetToken();
    if (!$token) return null;
    $requests = [];
    foreach (array_unique($countries) as $country) {
        $requests[$country] = [
            'url' => 'https://acleddata.com/api/acled/read?' . http_build_query(['_format' => 'json', 'country' => $country,
                'event_date' => isoDaysAgo($days), 'event_date_where' => '>=', 'limit' => 500]),
            'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ];
    }
    $responses = httpGetMulti($requests, 20);
    $out = []; $ok = false;
    foreach ($responses as $country => $raw) {
        if ($raw !== null) $ok = true;
        $out[$country] = jsonArray($raw, 'data');
    }
    return $ok ? $out : null;
}
function acledCountries(array $countries, int $days): array {
    sort($countries);
    return swr('acled:' . $days . ':' . md5(implode('|', $countries)), fn() => acledFetchCountries($countries, min($days, 365)),
        ['source' => 'acled', 'priority' => 5]);
}
function acledCategory(?string $eventType): string {
    if ($eventType === 'Violence against civilians') return 'atrocity';
    if (in_array($eventType, ['Riots', 'Protests'], true)) return 'unrest';
    return 'conflict';
}

/* ============================= UCDP ============================= */
/** Latest calendar year covered by the configured GED release (26.1 → 2025). */
function ucdpYear(): int {
    $major = (int)explode('.', (string)UCDP_VERSION)[0];
    return $major >= 17 ? 1999 + $major : (int)gmdate('Y') - 1;
}
/**
 * Verified fatalities (UCDP "best" estimate) for the countries in the latest GED year.
 * Fixed in 1.10.1: the API filters by Gleditsch–Ward codes (it silently ignored names),
 * GED is a yearly dataset (a "last 30 days" window was always empty) and results are paged.
 */
function ucdpFetch(array $countries, int $year): ?array {
    $codes = array_values(array_unique(array_filter(array_map('ucdpCode', $countries))));
    if (!$codes) return ['fatalities' => null, 'count' => 0, 'year' => $year];
    $fat = 0; $n = 0; $pages = 1; $maxPages = 15;
    for ($page = 1; $page <= min($pages, $maxPages); $page++) {
        $raw = httpGet('https://ucdpapi.pcr.uu.se/api/gedevents/' . rawurlencode(UCDP_VERSION) . '?' . http_build_query([
            'pagesize' => 1000, 'page' => $page, 'Country' => implode(',', $codes),
            'StartDate' => "{$year}-01-01", 'EndDate' => "{$year}-12-31",
        ]), UCDP_TOKEN !== '' ? ['x-ucdp-access-token: ' . UCDP_TOKEN] : [], 25);
        if ($raw === null) { if ($page === 1) return null; break; }
        $j = json_decode($raw, true);
        if (!is_array($j)) { if ($page === 1) { GwHttp::$lastError = 'UCDP: invalid JSON'; return null; } break; }
        $pages = max(1, (int)($j['TotalPages'] ?? 1));
        foreach (($j['Result'] ?? []) as $r) { $fat += (int)($r['best'] ?? 0); $n++; }
    }
    return ['fatalities' => $fat, 'count' => $n, 'year' => $year, 'truncated' => $pages > $maxPages];
}
function ucdpCountries(array $countries, int $days = 0): array {
    sort($countries);
    $year = ucdpYear();
    return swr('ucdp:y' . $year . ':' . md5(implode('|', $countries)), fn() => ucdpFetch($countries, $year),
        ['source' => 'ucdp', 'sync' => false, 'priority' => 7]);
}

/* ============================= Historical archive (ACLED, synced from admin.php) ============================= */

/** Fatalities/events archived for a set of countries in a date range (extends severity beyond 1 year). */
function archivedConflictAggregate(array $countries, string $sinceDate, string $untilDate): array {
    if (!$countries) return ['fatalities' => 0, 'count' => 0];
    try {
        return dbRetry(function (PDO $pdo) use ($countries, $sinceDate, $untilDate) {
            $in = implode(',', array_fill(0, count($countries), '?'));
            $s = $pdo->prepare("SELECT COALESCE(SUM(fatalities),0) AS f, COUNT(*) AS n FROM globewatch_archived_events
                WHERE category = 'conflict' AND country IN ($in) AND event_date BETWEEN ? AND ?");
            $s->execute([...$countries, $sinceDate, $untilDate]);
            $r = $s->fetch();
            return ['fatalities' => (int)($r['f'] ?? 0), 'count' => (int)($r['n'] ?? 0)];
        });
    } catch (GwPublicException $e) { return ['fatalities' => 0, 'count' => 0]; } // archive table not installed
}

/** Archived unrest/atrocity rows as map points (localized title composed by the UI). */
function archivedPointEvents(array $categories, string $sinceDate, string $untilDate, int $limit = 300): array {
    try {
        return dbRetry(function (PDO $pdo) use ($categories, $sinceDate, $untilDate, $limit) {
            $in = implode(',', array_fill(0, count($categories), '?'));
            $s = $pdo->prepare("SELECT * FROM globewatch_archived_events WHERE category IN ($in) AND event_date BETWEEN ? AND ?
                ORDER BY event_date DESC LIMIT " . (int)$limit);
            $s->execute([...$categories, $sinceDate, $untilDate]);
            return array_map(fn($r) => [
                'id' => 'arc-' . $r['id'], 'category' => $r['category'], 'source' => 'acled_archive',
                'subtype' => null, 'subtype_raw' => $r['subtype'], 'severity_level' => null, 'country' => $r['country'],
                'event_date' => $r['event_date'], 'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
                'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
                'fatalities' => $r['fatalities'] !== null ? (int)$r['fatalities'] : null,
                'title' => null, 'url' => null, 'extra' => ['event_type' => $r['event_type']],
            ], $s->fetchAll());
        });
    } catch (GwPublicException $e) { return []; }
}

/** Archived rows for a conflict's detail feed. */
function archivedConflictEvents(int $conflictId, string $sinceDate, string $untilDate, int $limit = 200): array {
    try {
        return dbRetry(function (PDO $pdo) use ($conflictId, $sinceDate, $untilDate, $limit) {
            $s = $pdo->prepare("SELECT * FROM globewatch_archived_events WHERE conflict_id = ? AND event_date BETWEEN ? AND ?
                ORDER BY event_date DESC LIMIT " . (int)$limit);
            $s->execute([$conflictId, $sinceDate, $untilDate]);
            return array_map(fn($r) => ['source' => 'acled_archive', 'event_date' => $r['event_date'], 'title' => null,
                'url' => null, 'fatalities' => $r['fatalities'] !== null ? (int)$r['fatalities'] : null,
                'event_type' => $r['event_type'], 'country' => $r['country']], $s->fetchAll());
        });
    } catch (GwPublicException $e) { return []; }
}

/**
 * One archive sync "step" for a (conflict, country) scope: downloads a 90-day
 * window from ACLED, stores it and moves the cursor back. Called repeatedly
 * from admin.php until the scope is "done".
 */
function syncArchiveStep(string $token, array $scopeRow, int $historyYears): array {
    $pdo = db();
    $country = $scopeRow['country'];
    $targetStart = gmdate('Y-m-d', strtotime("-{$historyYears} years"));
    $windowEnd = $scopeRow['synced_until'] ?? gmdate('Y-m-d', strtotime('-365 days'));

    if ($windowEnd <= $targetStart) {
        $pdo->prepare("UPDATE globewatch_sync_state SET done = 1, last_run_at = NOW() WHERE scope = ?")->execute([$scopeRow['scope']]);
        return ['scope' => $scopeRow['scope'], 'done' => true, 'inserted' => 0];
    }
    $windowStart = max($targetStart, gmdate('Y-m-d', strtotime($windowEnd . ' -90 days')));
    [$raw, $status] = httpRequest('https://acleddata.com/api/acled/read?' . http_build_query([
        '_format' => 'json', 'country' => $country, 'event_date' => $windowStart . '|' . $windowEnd,
        'event_date_where' => 'BETWEEN', 'limit' => 500,
    ]), ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], 25);
    if ($status !== 200) return ['scope' => $scopeRow['scope'], 'error' => 'ACLED HTTP ' . $status];
    $rows = jsonArray($raw, 'data');

    $inserted = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO globewatch_archived_events
          (source, source_event_id, conflict_id, category, event_type, subtype, country, event_date, lat, lng, fatalities)
        VALUES ('acled', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $stmt->execute([
            $r['event_id_cnty'] ?? md5(json_encode($r)), $scopeRow['conflict_id'],
            acledCategory($r['event_type'] ?? null), $r['event_type'] ?? null, $r['sub_event_type'] ?? null,
            $r['country'] ?? $country, $r['event_date'] ?? $windowStart,
            $r['latitude'] ?? null, $r['longitude'] ?? null, isset($r['fatalities']) ? (int)$r['fatalities'] : null,
        ]);
        if ($stmt->rowCount() > 0) $inserted++;
    }
    $newSyncedUntil = gmdate('Y-m-d', strtotime($windowStart . ' -1 day'));
    $done = $windowStart <= $targetStart;
    $pdo->prepare("UPDATE globewatch_sync_state SET synced_until = ?, done = ?, rows_synced = rows_synced + ?, last_run_at = NOW()
        WHERE scope = ?")->execute([$newSyncedUntil, $done ? 1 : 0, $inserted, $scopeRow['scope']]);
    return ['scope' => $scopeRow['scope'], 'done' => $done, 'inserted' => $inserted,
        'window' => [$windowStart, $windowEnd], 'rows_seen' => count($rows)];
}

/* ============================= Source status (UI "Data sources" + admin panel) ============================= */
function sourceConfigured(string $s): bool {
    switch ($s) {
        case 'firms': return FIRMS_MAP_KEY !== '';
        case 'acled': return acledConfigured();
        case 'hapi': return hapiConfigured();
        case 'ucdp': return UCDP_TOKEN !== '';
        case 'reliefweb': case 'reliefweb_disasters': return RELIEFWEB_APPNAME !== '';
        case 'ucdp_candidate': return UCDP_TOKEN !== '';
        case 'acaps': return acapsConfigured();
        case 'inform': case 'hapi_context': return hapiConfigured();
        case 'alerts_ua': return ALERTS_UA_TOKEN !== '';
        case 'cfradar': return CLOUDFLARE_RADAR_TOKEN !== '';
        case 'idmc': return IDMC_CLIENT_ID !== '';
        default: return true;
    }
}
function sourceStatusAll(): array {
    $out = [];
    foreach (GW_SOURCES as $s) {
        $h = [];
        try { $h = kvGet('health:' . $s) ?: []; } catch (Throwable $e) {}
        $backoff = null;
        if (in_array($s, ['gdelt', 'hapi'], true)) {
            try { $b = (int)(kvGet($s . ':backoff_until') ?? 0); $backoff = $b > time() ? $b : null; } catch (Throwable $e) {}
        }
        $enabled = isSourceEnabled($s);
        $configured = sourceConfigured($s);
        $state = !$enabled ? 'disabled' : (!$configured ? 'unconfigured' : ($backoff ? 'backoff'
            : (!empty($h['error']) && ($h['err_at'] ?? 0) > ($h['ok_at'] ?? 0) ? 'error' : (!empty($h['ok_at']) ? 'ok' : 'unknown'))));
        $out[$s] = ['state' => $state, 'enabled' => $enabled, 'configured' => $configured,
            'ok_at' => $h['ok_at'] ?? null, 'err_at' => $h['err_at'] ?? null, 'error' => $h['error'] ?? null, 'backoff_until' => $backoff];
    }
    return $out;
}
