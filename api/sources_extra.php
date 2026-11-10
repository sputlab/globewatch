<?php
/*
 * GlobeWatch — additional open-data sources (v1.11.0).
 * All free; only ACAPS needs a (free) account, UCDP Candidate reuses UCDP_TOKEN,
 * INFORM reuses the HDX HAPI identifier. Every fetcher returns point records in
 * the same shape as sources.php (or null on failure) and is cached by swr().
 * They are all refreshed in the background (sync=false): a first visit gets the
 * other sources immediately and these ones on the next poll (~15 s).
 */

/* ============================= helpers ============================= */
function gwPt(array $a): array {
    return $a + ['id' => null, 'category' => 'disaster', 'source' => '', 'subtype' => 'other', 'subtype_raw' => null,
        'severity_level' => null, 'country' => null, 'event_date' => null, 'lat' => null, 'lng' => null,
        'fatalities' => null, 'title' => null, 'url' => null, 'extra' => []];
}
function gwDate($v): ?string {
    if ($v === null || $v === '') return null;
    // RFC 822 dates: drop the weekday, strtotime() would jump to the next matching weekday if it disagrees
    $t = is_numeric($v) ? (int)$v : strtotime(preg_replace('/^[A-Za-z]{3},\s*/', '', trim((string)$v)));
    return $t ? gmdate('Y-m-d', $t) : null;
}
/** Namespace-tolerant XML parsing: "cap:event" becomes <cap_event>, default namespaces are dropped. */
function gwXml(?string $raw): ?SimpleXMLElement {
    if ($raw === null || $raw === '') return null;
    if (!function_exists('simplexml_load_string')) { GwHttp::$lastError = 'PHP SimpleXML extension missing'; return null; }
    $raw = preg_replace('/\sxmlns(:[\w-]+)?\s*=\s*("[^"]*"|\'[^\']*\')/', '', $raw);
    $raw = preg_replace('/<(\/?)([A-Za-z][\w-]*):([A-Za-z])/', '<$1$2_$3', $raw);
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if ($x === false) { GwHttp::$lastError = 'invalid XML'; return null; }
    return $x;
}
function gwCentroidPoint(?array $info, string $seed, float $amp = 0.8): array {
    if (!$info) return [null, null];
    [$dLat, $dLng] = gwJitter($seed, $amp);
    return [$info['lat'] + $dLat, $info['lng'] + $dLng];
}
/** One background source call, uniform options. */
function gwSwrBg(string $key, string $source, callable $producer, int $priority = 6): array {
    return swr($key, $producer, ['source' => $source, 'sync' => false, 'priority' => $priority]);
}

/* ============================= WHO Disease Outbreak News ============================= */
function whoDonFetch(): ?array {
    $raw = httpGet('https://www.who.int/api/news/diseaseoutbreaknews?' . http_build_query([
        'sf_culture' => 'en', '$orderby' => 'PublicationDateAndTime desc', '$top' => 60,
    ]), ['Accept: application/json'], 20);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['value'])) { GwHttp::$lastError = 'WHO: unexpected response'; return null; }
    $out = [];
    foreach ($j['value'] as $it) {
        $title = trim((string)(($it['OverrideTitle'] ?? '') ?: ($it['Title'] ?? '')));
        if ($title === '') continue;
        $info = countryInText($title);
        if (!$info) continue;                                   // multi-country / global notices have no single place
        $date = gwDate($it['PublicationDateAndTime'] ?? $it['PublicationDate'] ?? null);
        $disease = trim(preg_split('/\s+[–—-]\s+/u', $title)[0] ?? $title);
        $high = (bool)preg_match('/ebola|marburg|lassa|nipah|plague|mers|h5n1|avian|smallpox|yellow fever/i', $title);
        [$lat, $lng] = gwCentroidPoint($info, 'who' . ($it['UrlName'] ?? $title), 0.9);
        $slug = (string)($it['UrlName'] ?? '');
        $out[] = gwPt(['id' => 'who-' . ($slug ?: md5($title)), 'category' => 'emergency', 'source' => 'who_don',
            'subtype' => 'epidemic', 'subtype_raw' => $disease, 'severity_level' => $high ? 'red' : 'orange',
            'country' => $info['name'], 'event_date' => $date, 'lat' => $lat, 'lng' => $lng, 'title' => $title,
            'url' => $slug !== '' ? 'https://www.who.int/emergencies/disease-outbreak-news/item/' . rawurlencode($slug) : 'https://www.who.int/emergencies/disease-outbreak-news',
            'extra' => ['disease' => $disease, 'approx_location' => true]]);
    }
    return $out;
}
function whoDonEvents(): array { return gwSwrBg('who:don', 'who_don', fn() => whoDonFetch(), 4); }

/* ============================= IFRC GO (emergencies, DREF & appeals) ============================= */
function ifrcClassify(?string $t): array {
    $t = strtolower((string)$t);
    $map = [
        'earthquake' => ['disaster', 'earthquake'], 'cyclone' => ['disaster', 'tropical_cyclone'], 'storm' => ['disaster', 'severe_storm'],
        'flood' => ['disaster', 'flood'], 'tsunami' => ['disaster', 'tsunami'], 'volcan' => ['disaster', 'volcano'],
        'landslide' => ['disaster', 'landslide'], 'drought' => ['disaster', 'drought'], 'fire' => ['disaster', 'wildfire'],
        'cold wave' => ['disaster', 'temperature_extreme'], 'heat wave' => ['disaster', 'temperature_extreme'],
        'epidemic' => ['emergency', 'epidemic'], 'biological' => ['emergency', 'epidemic'],
        'population movement' => ['emergency', 'displacement'], 'food insecurity' => ['emergency', 'food_insecurity'],
        'complex emergency' => ['emergency', 'complex_emergency'], 'civil unrest' => ['unrest', 'civil_unrest'],
        'chemical' => ['emergency', 'technological'], 'radiological' => ['emergency', 'technological'],
        'transport' => ['emergency', 'technological'], 'explosion' => ['emergency', 'technological'],
    ];
    foreach ($map as $k => $v) if (strpos($t, $k) !== false) return $v;
    return ['emergency', 'other'];
}
function ifrcFetch(int $window): ?array {
    $headers = ['Accept: application/json'];
    if (IFRC_GO_TOKEN !== '') $headers[] = 'Authorization: Token ' . IFRC_GO_TOKEN;
    $url = 'https://goadmin.ifrc.org/api/v2/event/?' . http_build_query([
        'disaster_start_date__gte' => gmdate('Y-m-d\T00:00:00', strtotime("-{$window} days")),
        'ordering' => '-disaster_start_date', 'limit' => 200, 'format' => 'json',
    ]);
    $out = [];
    for ($page = 0; $page < 3 && $url; $page++) {
        $raw = httpGet($url, $headers, 25);
        if ($raw === null) { if ($page === 0) return null; break; }
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['results'])) { if ($page === 0) { GwHttp::$lastError = 'IFRC: unexpected response'; return null; } break; }
        foreach ($j['results'] as $e) {
            $c = $e['countries'][0] ?? [];
            $info = countryInfo($c['iso3'] ?? null) ?? countryInfo($c['name'] ?? null);
            if (!$info) continue;
            $dtype = $e['dtype']['name'] ?? (is_string($e['dtype'] ?? null) ? $e['dtype'] : null);
            [$cat, $sub] = ifrcClassify($dtype);
            $lvl = $e['ifrc_severity_level'] ?? null;
            [$lat, $lng] = gwCentroidPoint($info, 'ifrc' . ($e['id'] ?? ''), 1.0);
            $out[] = gwPt(['id' => 'ifrc-' . ($e['id'] ?? md5(json_encode($e))), 'category' => $cat, 'source' => 'ifrc',
                'subtype' => $sub, 'subtype_raw' => $dtype,
                'severity_level' => [0 => 'green', 1 => 'orange', 2 => 'red'][(int)$lvl] ?? 'orange',
                'country' => $info['name'], 'event_date' => gwDate($e['disaster_start_date'] ?? null), 'lat' => $lat, 'lng' => $lng,
                'title' => $e['name'] ?? null, 'url' => isset($e['id']) ? 'https://go.ifrc.org/emergencies/' . (int)$e['id'] : 'https://go.ifrc.org/',
                'extra' => ['affected' => isset($e['num_affected']) ? (int)$e['num_affected'] : null,
                    'countries' => count($e['countries'] ?? []), 'approx_location' => true]]);
        }
        $url = $j['next'] ?? null;
    }
    return $out;
}
function ifrcEvents(int $days): array {
    $w = $days <= 90 ? 90 : 365;
    return gwSwrBg("ifrc:{$w}", 'ifrc', fn() => ifrcFetch($w), 5);
}

/* ============================= Copernicus EMS Rapid Mapping ============================= */
function cemsFetch(): ?array {
    $url = 'https://rapidmapping.emergency.copernicus.eu/backend/dashboard-api/public-activations-info/?limit=100';
    $out = []; $cut = time() - 400 * 86400;
    for ($page = 0; $page < 4 && $url; $page++) {
        $raw = httpGet($url, ['Accept: application/json'], 25);
        if ($raw === null) { if ($page === 0) return null; break; }
        $j = json_decode($raw, true);
        $rows = is_array($j) ? ($j['results'] ?? (array_is_list($j) ? $j : null)) : null;
        if (!is_array($rows)) { if ($page === 0) { GwHttp::$lastError = 'CEMS: unexpected response'; return null; } break; }
        $older = 0;
        foreach ($rows as $a) {
            $t = strtotime((string)($a['activationTime'] ?? '')) ?: 0;
            if ($t && $t < $cut) { $older++; continue; }
            $country = $a['countries'][0]['short_name'] ?? ($a['countries'][0]['name'] ?? null);
            $info = countryInfo($country);
            $lat = $lng = null;
            if (preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)/i', (string)($a['centroid'] ?? ''), $m)) { $lng = (float)$m[1]; $lat = (float)$m[2]; }
            elseif ($info) { [$lat, $lng] = gwCentroidPoint($info, 'cems' . ($a['code'] ?? '')); }
            if ($lat === null) continue;
            $slug = strtolower((string)($a['category']['slug'] ?? $a['category'] ?? ''));
            $name = (string)($a['name'] ?? '');
            $sub = match (true) {
                str_contains($slug, 'flood') => 'flood', str_contains($slug, 'fire') => 'wildfire',
                str_contains($slug, 'quake') => 'earthquake', str_contains($slug, 'volcan') => 'volcano',
                str_contains($slug, 'land') || str_contains($slug, 'mass') => 'landslide', str_contains($slug, 'drought') => 'drought',
                str_contains($slug, 'storm') => preg_match('/hurricane|typhoon|cyclone|tropical/i', $name) ? 'tropical_cyclone' : 'severe_storm',
                str_contains($slug, 'industr') || str_contains($slug, 'techn') => 'technological',
                str_contains($slug, 'human') => 'complex_emergency', default => 'other',
            };
            $cat = in_array($sub, ['technological', 'complex_emergency'], true) ? 'emergency' : 'disaster';
            $closed = !empty($a['closed']);
            $code = (string)($a['code'] ?? '');
            $out[] = gwPt(['id' => 'cems-' . ($code ?: md5($name)), 'category' => $cat, 'source' => 'cems', 'subtype' => $sub,
                'subtype_raw' => $a['category']['name'] ?? null, 'severity_level' => $closed ? 'green' : 'orange',
                'country' => $info['name'] ?? $country, 'event_date' => gwDate($a['activationTime'] ?? null), 'lat' => $lat, 'lng' => $lng,
                'title' => $name ?: null, 'url' => $code !== '' ? 'https://mapping.emergency.copernicus.eu/activations/' . rawurlencode($code) . '/' : null,
                'extra' => ['code' => $code, 'ongoing' => !$closed, 'products' => $a['n_products'] ?? null,
                    'countries' => count($a['countries'] ?? [])]]);
        }
        if ($rows && $older === count($rows)) break;
        $url = $j['next'] ?? null;
    }
    return $out;
}
function cemsEvents(): array { return gwSwrBg('cems:list', 'cems', fn() => cemsFetch(), 5); }

/* ============================= EMSC (European-Mediterranean Seismological Centre) ============================= */
function emscFetch(int $days): ?array {
    $minMag = $days > 30 ? 5.5 : 4.5;
    $raw = httpGet('https://www.seismicportal.eu/fdsnws/event/1/query?' . http_build_query([
        'format' => 'json', 'minmag' => $minMag, 'starttime' => gmdate('Y-m-d\TH:i:s', time() - $days * 86400),
        'limit' => $days > 30 ? 1000 : 600, 'orderby' => 'time',
    ]), ['Accept: application/json'], 20);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['features'])) { GwHttp::$lastError = 'EMSC: unexpected response'; return null; }
    $out = [];
    foreach ($j['features'] as $f) {
        $p = $f['properties'] ?? [];
        $lat = $p['lat'] ?? ($f['geometry']['coordinates'][1] ?? null); $lng = $p['lon'] ?? ($f['geometry']['coordinates'][0] ?? null);
        if ($lat === null || $lng === null) continue;
        $mag = round((float)($p['mag'] ?? 0), 1);
        $unid = (string)($p['unid'] ?? $f['id'] ?? '');
        $t = strtotime((string)($p['time'] ?? '')) ?: null;
        $out[] = gwPt(['id' => 'emsc-' . ($unid ?: md5(json_encode($p))), 'source' => 'emsc', 'subtype' => 'earthquake',
            'severity_level' => $mag >= 6 ? 'red' : ($mag >= 4.5 ? 'orange' : 'green'), 'event_date' => $t ? gmdate('Y-m-d', $t) : null,
            'lat' => (float)$lat, 'lng' => (float)$lng, 'title' => isset($p['flynn_region']) ? ucwords(strtolower($p['flynn_region'])) : null,
            'url' => $unid !== '' ? 'https://www.seismicportal.eu/eventdetails.html?unid=' . rawurlencode($unid) : 'https://www.emsc-csem.org/',
            'extra' => ['mag' => $mag, 'mag_type' => $p['magtype'] ?? null, 'depth_km' => isset($p['depth']) ? round((float)$p['depth']) : null,
                'time' => $t ? gmdate('c', $t) : null]]);
    }
    return $out;
}
function emscEvents(int $days): array { return gwSwrBg("emsc:{$days}", 'emsc', fn() => emscFetch($days), 2); }
/** Drops EMSC quakes that USGS already reports (same time ±2 min, within ~1°). */
function emscDedupe(array $emsc, array $usgs): array {
    $u = [];
    foreach ($usgs as $e) if (!empty($e['extra']['time'])) $u[] = [strtotime($e['extra']['time']), $e['lat'], $e['lng']];
    if (!$u) return $emsc;
    return array_values(array_filter($emsc, function ($e) use ($u) {
        $t = !empty($e['extra']['time']) ? strtotime($e['extra']['time']) : null;
        if (!$t) return true;
        foreach ($u as [$ut, $la, $lo]) if (abs($ut - $t) <= 120 && abs($la - $e['lat']) <= 1.0 && abs($lo - $e['lng']) <= 1.0) return false;
        return true;
    }));
}

/* ============================= NOAA National Hurricane Center ============================= */
function nhcFetch(): ?array {
    $raw = httpGet('https://www.nhc.noaa.gov/CurrentStorms.json', ['Accept: application/json'], 15);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) { GwHttp::$lastError = 'NHC: unexpected response'; return null; }
    $out = [];
    foreach (($j['activeStorms'] ?? []) as $s) {
        $lat = $s['latitudeNumeric'] ?? null; $lng = $s['longitudeNumeric'] ?? null;
        if ($lat === null || $lng === null) continue;
        $cls = strtoupper((string)($s['classification'] ?? ''));
        $kt = isset($s['intensity']) ? (int)$s['intensity'] : null;
        $out[] = gwPt(['id' => 'nhc-' . ($s['id'] ?? md5(json_encode($s))), 'source' => 'nhc', 'subtype' => 'tropical_cyclone',
            'subtype_raw' => $cls, 'severity_level' => $cls === 'HU' ? 'red' : (in_array($cls, ['TS', 'STS'], true) ? 'orange' : 'green'),
            'event_date' => gwDate($s['lastUpdate'] ?? null) ?? gmdate('Y-m-d'), 'lat' => (float)$lat, 'lng' => (float)$lng,
            'title' => $s['name'] ?? null, 'url' => $s['publicAdvisory']['url'] ?? 'https://www.nhc.noaa.gov/',
            'extra' => ['classification' => $cls, 'wind_kt' => $kt, 'pressure_mb' => isset($s['pressure']) ? (int)$s['pressure'] : null,
                'movement' => isset($s['movementDir'], $s['movementSpeed']) ? ((int)$s['movementDir'] . '° · ' . (int)$s['movementSpeed'] . ' mph') : null, 'ongoing' => true,
                'last_update' => $s['lastUpdate'] ?? null]]);
    }
    return $out;
}
function nhcEvents(): array { return gwSwrBg('nhc:active', 'nhc', fn() => nhcFetch(), 3); }

/* ============================= NOAA Tsunami Warning Centers ============================= */
function tsunamiFetch(): ?array {
    $feeds = ['ntwc' => 'https://www.tsunami.gov/events/xml/PAAQAtom.xml', 'ptwc' => 'https://www.tsunami.gov/events/xml/PHEBAtom.xml'];
    $bodies = httpGetMulti(array_map(fn($u) => ['url' => $u], $feeds), 15);
    if (!array_filter($bodies)) { GwHttp::$lastError = GwHttp::$lastError ?: 'tsunami.gov: no feed reachable'; return null; }
    $out = [];
    foreach ($bodies as $center => $raw) {
        $x = gwXml($raw);
        if (!$x) continue;
        foreach ($x->entry as $e) {
            $title = trim((string)$e->title);
            $text = trim(preg_replace('/\s+/', ' ', strip_tags(($e->summary ? $e->summary->asXML() : '') . ' ' . ($e->content ? $e->content->asXML() : ''))));
            $lat = (string)$e->geo_lat !== '' ? (float)$e->geo_lat : null; $lng = (string)$e->geo_long !== '' ? (float)$e->geo_long : null;
            if (($lat === null || $lng === null) && preg_match('/Lat:\s*(-?[\d.]+)\s*,?\s*Lon:\s*(-?[\d.]+)/i', $text, $m)) { $lat = (float)$m[1]; $lng = (float)$m[2]; }
            if ($lat === null || $lng === null) continue;
            $mag = preg_match('/magnitude\s+(\d+(?:\.\d+)?)/i', $text, $m) ? (float)$m[1] : null;
            $sev = preg_match('/warning/i', $title . ' ' . $text) && !preg_match('/no (tsunami )?warning|not expected/i', $text) ? 'red'
                : (preg_match('/watch|advisory|threat/i', $title) ? 'orange' : 'green');
            $link = '';
            foreach ($e->link as $l) { $link = (string)$l['href']; if ($link) break; }
            $out[] = gwPt(['id' => 'tsu-' . md5((string)$e->id ?: $title . $e->updated), 'source' => 'tsunami', 'subtype' => 'tsunami',
                'subtype_raw' => $title, 'severity_level' => $sev, 'event_date' => gwDate((string)$e->updated),
                'lat' => $lat, 'lng' => $lng, 'title' => $title ?: null, 'url' => $link ?: 'https://www.tsunami.gov/',
                'extra' => ['center' => strtoupper($center), 'mag' => $mag, 'summary' => mb_substr($text, 0, 400)]]);
        }
    }
    return $out;
}
function tsunamiEvents(): array { return gwSwrBg('tsunami:feeds', 'tsunami', fn() => tsunamiFetch(), 2); }

/* ============================= Meteoalarm (Europe, severe weather warnings) ============================= */
const METEOALARM_FEEDS = [
    'AUT' => 'austria', 'BEL' => 'belgium', 'BIH' => 'bosnia-herzegovina', 'BGR' => 'bulgaria', 'HRV' => 'croatia',
    'CYP' => 'cyprus', 'CZE' => 'czechia', 'DNK' => 'denmark', 'EST' => 'estonia', 'FIN' => 'finland', 'FRA' => 'france',
    'DEU' => 'germany', 'GRC' => 'greece', 'HUN' => 'hungary', 'ISL' => 'iceland', 'IRL' => 'ireland', 'ISR' => 'israel',
    'ITA' => 'italy', 'LVA' => 'latvia', 'LTU' => 'lithuania', 'LUX' => 'luxembourg', 'MLT' => 'malta', 'MDA' => 'moldova',
    'MNE' => 'montenegro', 'NLD' => 'netherlands', 'MKD' => 'republic-of-north-macedonia', 'NOR' => 'norway', 'POL' => 'poland',
    'PRT' => 'portugal', 'ROU' => 'romania', 'SRB' => 'serbia', 'SVK' => 'slovakia', 'SVN' => 'slovenia', 'ESP' => 'spain',
    'SWE' => 'sweden', 'CHE' => 'switzerland', 'GBR' => 'united-kingdom', 'UKR' => 'ukraine',
];
function meteoalarmFetch(): ?array {
    $req = [];
    foreach (METEOALARM_FEEDS as $iso => $slug) $req[$iso] = ['url' => 'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-' . $slug];
    $bodies = httpGetMulti($req, 20);
    if (!array_filter($bodies)) { GwHttp::$lastError = GwHttp::$lastError ?: 'Meteoalarm: no feed reachable'; return null; }
    $now = time(); $out = [];
    foreach ($bodies as $iso => $raw) {
        $x = gwXml($raw);
        if (!$x) continue;
        $red = 0; $orange = 0; $events = []; $areas = []; $first = null;
        foreach ($x->entry as $e) {
            $sev = strtolower(trim((string)$e->cap_severity));
            if (!in_array($sev, ['severe', 'extreme'], true)) continue;          // yellow (moderate) warnings are skipped
            $exp = strtotime((string)$e->cap_expires) ?: null;
            if ($exp && $exp < $now) continue;
            $sev === 'extreme' ? $red++ : $orange++;
            $ev = trim((string)$e->cap_event);
            if ($ev !== '') $events[$ev] = ($events[$ev] ?? 0) + 1;
            $area = trim((string)$e->cap_areaDesc);
            if ($area !== '' && count($areas) < 6) $areas[$area] = true;
            $on = strtotime((string)($e->cap_onset ?: $e->cap_effective)) ?: null;
            if ($on) $first = $first === null ? $on : min($first, $on);
        }
        if (!$red && !$orange) continue;
        $info = countryInfo($iso);
        arsort($events);
        $out[] = gwPt(['id' => 'mtl-' . $iso, 'source' => 'meteoalarm', 'subtype' => 'weather_warning',
            'severity_level' => $red ? 'red' : 'orange', 'country' => $info['name'] ?? $iso,
            'event_date' => $first ? gmdate('Y-m-d', max($first, $now - 86400)) : gmdate('Y-m-d'),
            'lat' => $info['lat'] ?? null, 'lng' => $info['lng'] ?? null, 'title' => null,
            'url' => 'https://meteoalarm.org/en/live/region/' . ($info['iso2'] ?? ''),
            'extra' => ['red' => $red, 'orange' => $orange, 'events' => array_slice(array_keys($events), 0, 5),
                'areas' => array_keys($areas), 'ongoing' => true, 'approx_location' => true]]);
    }
    return $out;
}
function meteoalarmEvents(): array { return gwSwrBg('meteoalarm:eu', 'meteoalarm', fn() => meteoalarmFetch(), 5); }

/* ============================= IODA (Internet outages) ============================= */
function iodaFetch(int $window): ?array {
    $now = time();
    $raw = httpGet('https://api.ioda.inetintel.cc.gatech.edu/v2/outages/summary?' . http_build_query([
        'from' => $now - $window * 86400, 'until' => $now, 'entityType' => 'country', 'limit' => 250,
    ]), ['Accept: application/json'], 25);
    if ($raw === null) return null;
    $j = json_decode($raw, true);
    $rows = is_array($j) ? ($j['data'] ?? null) : null;
    if (!is_array($rows)) { GwHttp::$lastError = 'IODA: unexpected response'; return null; }
    $list = [];
    foreach ($rows as $r) {
        $ent = $r['entity'] ?? [];
        $score = (float)($r['scores']['overall'] ?? $r['score'] ?? 0);
        if ($score <= 0) continue;
        $info = countryByIso2($ent['code'] ?? null) ?? countryInfo($ent['name'] ?? null);
        if (!$info) continue;
        $list[] = [$score, $info, (int)($r['event_cnt'] ?? 0), $ent['code'] ?? $info['iso2']];
    }
    usort($list, fn($a, $b) => $b[0] <=> $a[0]);
    $list = array_slice($list, 0, 30);                  // IODA scores are relative: keep the most affected countries
    $n = count($list); $out = [];
    foreach ($list as $i => [$score, $info, $cnt, $code]) {
        $sev = $i < max(1, ceil($n * 0.15)) ? 'red' : ($i < ceil($n * 0.5) ? 'orange' : 'green');
        $out[] = gwPt(['id' => 'ioda-' . $info['iso3'] . '-' . $window, 'category' => 'emergency', 'source' => 'ioda',
            'subtype' => 'internet_outage', 'severity_level' => $sev, 'country' => $info['name'], 'event_date' => gmdate('Y-m-d'),
            'lat' => $info['lat'], 'lng' => $info['lng'], 'title' => null,
            'url' => 'https://ioda.inetintel.cc.gatech.edu/country/' . rawurlencode($code),
            'extra' => ['score' => round($score), 'alerts' => $cnt, 'window_days' => $window, 'rank' => $i + 1,
                'ongoing' => true, 'approx_location' => true]]);
    }
    return $out;
}
function iodaEvents(int $days): array {
    $w = $days <= 1 ? 1 : ($days <= 2 ? 2 : 7);
    return gwSwrBg("ioda:{$w}", 'ioda', fn() => iodaFetch($w), 5);
}

/* ============================= Smithsonian GVP — weekly volcanic activity ============================= */
function gvpFetch(): ?array {
    $x = gwXml(httpGet('https://volcano.si.edu/news/WeeklyVolcanoRSS.xml', [], 20));
    if (!$x) return null;
    $out = [];
    foreach (($x->channel->item ?? []) as $it) {
        $title = trim((string)$it->title);
        $pt = trim((string)$it->georss_point);
        if (!preg_match('/^(-?[\d.]+)[\s,]+(-?[\d.]+)$/', $pt, $m)) continue;
        $name = $title; $country = null;
        if (preg_match('/([^()\-–]+?)\s*\(([^)]+)\)/u', $title, $mm)) { $name = trim($mm[1]); $country = trim($mm[2]); }
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string)$it->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $erupt = (bool)preg_match('/erupt|ash plume|lava|pyroclastic|explosion/i', $desc);
        $out[] = gwPt(['id' => 'gvp-' . md5($title), 'source' => 'gvp', 'subtype' => 'volcano', 'subtype_raw' => $name,
            'severity_level' => $erupt ? 'orange' : 'green', 'country' => countryInfo($country)['name'] ?? $country,
            'event_date' => gwDate((string)$it->pubDate), 'lat' => (float)$m[1], 'lng' => (float)$m[2], 'title' => $name,
            'url' => (string)$it->link ?: 'https://volcano.si.edu/reports_weekly.cfm',
            'extra' => ['summary' => mb_substr($desc, 0, 500)]]);
    }
    return $out;
}
function gvpEvents(): array { return gwSwrBg('gvp:weekly', 'gvp', fn() => gvpFetch(), 6); }

/* ============================= UCDP Candidate Events (monthly, geolocated) ============================= */
/** Finds the latest monthly candidate releases ("26.0.8" = events of August 2026), cached for a day. */
function ucdpCandidateVersions(): array {
    $cached = kvGet('ucdp:cand_versions');
    if (is_array($cached)) return $cached;
    $found = [];
    $y = (int)gmdate('Y'); $m = (int)gmdate('n');
    for ($k = 1; $k <= 9 && count($found) < 3; $k++) {
        $mm = $m - $k; $yy = $y;
        while ($mm <= 0) { $mm += 12; $yy--; }
        $v = sprintf('%d.0.%d', $yy % 100, $mm);
        [$raw, $status] = httpRequest('https://ucdpapi.pcr.uu.se/api/gedevents/' . $v . '?pagesize=1',
            ['x-ucdp-access-token: ' . UCDP_TOKEN], 15);
        if ($status === 200 && is_array(json_decode((string)$raw, true))) $found[] = $v;
        elseif ($status === 401 || $status === 403) { GwHttp::$lastError = 'UCDP: token rejected (HTTP ' . $status . ')'; break; }
    }
    kvSet('ucdp:cand_versions', $found, $found ? 86400 : 6 * 3600);
    return $found;
}
function ucdpCandidateFetch(int $window): ?array {
    $versions = ucdpCandidateVersions();
    if (!$versions) { GwHttp::$lastError = GwHttp::$lastError ?: 'UCDP Candidate: no recent monthly release found'; return null; }
    $since = isoDaysAgo($window);
    $seen = []; $points = []; $agg = []; $okAny = false;
    foreach ($versions as $v) {
        for ($page = 1, $pages = 1; $page <= min($pages, 4); $page++) {
            $raw = httpGet('https://ucdpapi.pcr.uu.se/api/gedevents/' . $v . '?' . http_build_query([
                'pagesize' => 1000, 'page' => $page, 'StartDate' => $since]), ['x-ucdp-access-token: ' . UCDP_TOKEN], 25);
            if ($raw === null) break;
            $j = json_decode($raw, true);
            if (!is_array($j)) break;
            $okAny = true;
            $pages = max(1, (int)($j['TotalPages'] ?? 1));
            foreach (($j['Result'] ?? []) as $r) {
                $id = (string)($r['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                $seen[$id] = true;
                $day = substr((string)($r['date_start'] ?? ''), 0, 10);
                $gw = (int)($r['country_id'] ?? 0); $best = (int)($r['best'] ?? 0);
                if ($gw && $day) { $agg[$gw][$day][0] = ($agg[$gw][$day][0] ?? 0) + 1; $agg[$gw][$day][1] = ($agg[$gw][$day][1] ?? 0) + $best; }
                if (!isset($r['latitude'], $r['longitude'])) continue;
                $tv = (int)($r['type_of_violence'] ?? 1);
                $points[] = gwPt(['id' => 'ucdpc-' . $id, 'category' => $tv === 3 ? 'atrocity' : 'conflict', 'source' => 'ucdp_candidate',
                    'subtype' => [1 => 'state_based', 2 => 'non_state', 3 => 'one_sided'][$tv] ?? 'state_based',
                    'country' => countryInfo(preg_replace('/\s*\(.*\)$/', '', (string)($r['country'] ?? '')))['name'] ?? ($r['country'] ?? null),
                    'event_date' => $day ?: null, 'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'], 'fatalities' => $best,
                    'title' => null, 'url' => 'https://ucdp.uu.se/',
                    'extra' => ['dyad' => $r['dyad_name'] ?? null, 'where' => $r['where_description'] ?? ($r['where_coordinates'] ?? null),
                        'civilians' => isset($r['deaths_civilians']) ? (int)$r['deaths_civilians'] : null,
                        'conflict' => $r['conflict_name'] ?? null, 'release' => $v]]);
            }
        }
    }
    if (!$okAny) return null;
    // keep the payload small: most lethal first, then most recent
    usort($points, fn($a, $b) => ($b['fatalities'] <=> $a['fatalities']) ?: strcmp($b['event_date'] ?? '', $a['event_date'] ?? ''));
    return ['points' => array_slice($points, 0, 1200), 'agg' => $agg, 'releases' => $versions, 'total' => count($seen)];
}
function ucdpCandidate(int $days): array {
    $w = $days <= 30 ? 30 : 90;
    return gwSwrBg("ucdpc:{$w}", 'ucdp_candidate', fn() => ucdpCandidateFetch($w), 7);
}
/** [events, fatalities] for GW codes in the last $days, from candidate aggregates. */
function ucdpCandidateWindow(?array $data, array $gwCodes, int $days): ?array {
    if (!$data || empty($data['agg'])) return null;
    $since = isoDaysAgo(min($days, 90));
    $ev = 0; $fat = 0; $any = false;
    foreach ($gwCodes as $gw) foreach (($data['agg'][$gw] ?? []) as $day => [$n, $f]) {
        if ($day < $since) continue;
        $ev += $n; $fat += $f; $any = true;
    }
    return $any ? [$ev, $fat] : [0, 0];
}

/* ============================= ACAPS — INFORM Severity Index ============================= */
function acapsConfigured(): bool { return ACAPS_TOKEN !== '' || (ACAPS_USERNAME !== '' && ACAPS_PASSWORD !== ''); }
function acapsToken(): ?string {
    if (ACAPS_TOKEN !== '') return ACAPS_TOKEN;
    $t = kvGet('acaps:token');
    if (is_string($t) && $t !== '') return $t;
    [$raw, $status] = httpRequest('https://api.acaps.org/api/v1/token-auth/', ['Content-Type: application/json'], 15,
        json_encode(['username' => ACAPS_USERNAME, 'password' => ACAPS_PASSWORD]));
    $j = json_decode((string)$raw, true);
    if ($status !== 200 || empty($j['token'])) { GwHttp::$lastError = 'ACAPS: login failed (HTTP ' . $status . ')'; return null; }
    kvSet('acaps:token', $j['token'], 20 * 86400);
    return $j['token'];
}
function acapsFetch(): ?array {
    $token = acapsToken();
    if (!$token) return null;
    $h = ['Authorization: Token ' . $token, 'Accept: application/json'];
    for ($k = 0; $k < 3; $k++) {
        $period = gmdate('MY', strtotime("first day of -{$k} months"));      // e.g. Sep2026
        $url = 'https://api.acaps.org/api/v1/inform-severity-index/' . $period . '/';
        $rows = [];
        for ($page = 0; $page < 6 && $url; $page++) {
            [$raw, $status] = httpRequest($url, $h, 25);
            if ($status === 401 || $status === 403) { try { kvSet('acaps:token', '', 60); } catch (Throwable $e) {} return null; }
            if ($status !== 200) break;
            $j = json_decode((string)$raw, true);
            $res = is_array($j) ? ($j['results'] ?? (array_is_list($j) ? $j : [])) : [];
            foreach ($res as $r) $rows[] = $r;
            $url = is_array($j) ? ($j['next'] ?? null) : null;
        }
        if ($rows) return ['period' => $period, 'crises' => array_values(array_filter(array_map('acapsNormalize', $rows)))];
    }
    GwHttp::$lastError = GwHttp::$lastError ?: 'ACAPS: no INFORM Severity data for the last 3 months';
    return null;
}
/** The API field names have changed over time: find them tolerantly. */
function acapsNormalize(array $r): ?array {
    $score = null; $cat = null;
    foreach ($r as $k => $v) {
        $lk = strtolower((string)$k);
        if ($score === null && is_numeric($v) && preg_match('/^(inform[ _]?severity[ _]?index|severity[ _]?index|inform_severity|isi)$/', $lk)) $score = (float)$v;
        if ($cat === null && is_string($v) && preg_match('/severity.*categor|categor.*severity|^category$/', $lk)) $cat = $v;
    }
    $isos = $r['iso3'] ?? ($r['ISO3'] ?? []);
    if (is_string($isos)) $isos = preg_split('/[,;\s]+/', $isos);
    $isos = array_values(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)), (array)$isos)));
    if ($score === null || !$isos) return null;
    return ['id' => (string)($r['crisis_id'] ?? md5(json_encode($r))), 'name' => $r['crisis_name'] ?? ($r['crisis'] ?? null),
        'iso3' => $isos, 'score' => round($score, 1), 'category' => $cat, 'date' => gwDate($r['date'] ?? null)];
}
function acapsData(): array { return gwSwrBg('acaps:isi', 'acaps', fn() => acapsFetch(), 7); }
function acapsLevel(array $c, float $scaleMax): ?string {
    $cat = strtolower((string)$c['category']);
    if ($cat !== '') return str_contains($cat, 'very high') ? 'red' : (str_contains($cat, 'high') ? 'orange' : null);
    $s = $c['score'];
    return $scaleMax > 5.5 ? ($s >= 7 ? 'red' : ($s >= 5.5 ? 'orange' : null)) : ($s >= 4 ? 'red' : ($s >= 3.5 ? 'orange' : null));
}
function acapsPoints(?array $data): array {
    if (!$data || empty($data['crises'])) return [];
    $max = max(array_column($data['crises'], 'score'));
    $out = [];
    foreach ($data['crises'] as $c) {
        $lvl = acapsLevel($c, $max);
        if (!$lvl) continue;
        $info = countryInfo($c['iso3'][0]);
        if (!$info) continue;
        [$lat, $lng] = gwCentroidPoint($info, 'acaps' . $c['id'], 0.7);
        $out[] = gwPt(['id' => 'acaps-' . $c['id'], 'category' => 'emergency', 'source' => 'acaps', 'subtype' => 'humanitarian_crisis',
            'severity_level' => $lvl, 'country' => $info['name'], 'event_date' => $c['date'] ?? gmdate('Y-m-d'),
            'lat' => $lat, 'lng' => $lng, 'title' => $c['name'], 'url' => 'https://www.acaps.org/en/countries',
            'extra' => ['score' => $c['score'], 'scale' => $max > 5.5 ? 10 : 5, 'category_label' => $c['category'],
                'period' => $data['period'] ?? null, 'countries' => count($c['iso3']), 'ongoing' => true, 'approx_location' => true]]);
    }
    return $out;
}
/** Highest ACAPS severity among a set of ISO3 codes (conflict detail). */
function acapsForIsos(?array $data, array $isos): ?array {
    if (!$data || empty($data['crises'])) return null;
    $best = null;
    foreach ($data['crises'] as $c) if (array_intersect($c['iso3'], $isos) && (!$best || $c['score'] > $best['score'])) $best = $c;
    if (!$best) return null;
    $max = max(array_column($data['crises'], 'score'));
    return ['score' => $best['score'], 'scale' => $max > 5.5 ? 10 : 5, 'category' => $best['category'], 'crisis' => $best['name'], 'period' => $data['period'] ?? null];
}

/* ============================= INFORM Risk (via HDX HAPI) ============================= */
function informFetch(string $iso3): ?array {
    [$raw, $status] = httpRequest('https://hapi.humdata.org/api/v2/coordination-context/national-risk?' . http_build_query([
        'location_code' => $iso3, 'output_format' => 'json', 'limit' => 5, 'app_identifier' => hapiIdentifier()]), ['Accept: application/json'], 20);
    if ($status !== 200) return null;
    $rows = jsonArray($raw, 'data');
    if (!$rows) return [];
    usort($rows, fn($a, $b) => strcmp($b['reference_period_start'] ?? '', $a['reference_period_start'] ?? ''));
    $r = $rows[0];
    $num = fn($k) => isset($r[$k]) && is_numeric($r[$k]) ? round((float)$r[$k], 1) : null;
    return ['risk_class' => $r['risk_class'] ?? null, 'overall' => $num('overall_risk'), 'hazard' => $num('hazard_exposure_risk'),
        'vulnerability' => $num('vulnerability_risk'), 'coping' => $num('coping_capacity_risk'),
        'rank' => isset($r['global_rank']) ? (int)$r['global_rank'] : null, 'year' => substr((string)($r['reference_period_start'] ?? ''), 0, 4) ?: null];
}
function informRisk(string $iso3): array { return gwSwrBg('inform:' . $iso3, 'inform', fn() => informFetch($iso3), 8); }
