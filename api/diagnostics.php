<?php
/*
 * api/diagnostics.php — checks PHP requirements, database schema and the
 * reachability of every source, showing the REAL curl/HTTP error.
 * Admin only (log in to admin.php first): it reveals server details that
 * shouldn't be public. Language: ?lang=it|en (default: the panel's language).
 */
require_once __DIR__ . '/schema.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!gw_is_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => tr('Log in to admin.php first, then reload this page.',
        'Accedi prima ad admin.php, poi ricarica questa pagina.')]);
    exit;
}
session_write_close();
@set_time_limit(300);   // 20+ sources are tested one after the other

/** Like httpRequest() but reports timing/status/error details. */
function debugGet(string $url, array $headers = [], int $timeout = 15, ?string $post = null): array {
    $t0 = microtime(true);
    if (!function_exists('curl_init')) return ['ok' => false, 'detail' => 'curl extension missing'];
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . gw_user_agent()], $headers)];
    if (HTTP_FORCE_IPV4 && defined('CURL_IPRESOLVE_V4')) $o[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $post; }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch); $errno = curl_errno($ch);
    $elapsed = round(microtime(true) - $t0, 2);
    if ($raw === false) return ['ok' => false, 'elapsed_s' => $elapsed, 'detail' => "curl errno {$errno}: {$err}"];
    if ($status !== 200) return ['ok' => false, 'elapsed_s' => $elapsed, 'http_status' => $status, 'body_snippet' => mb_substr($raw, 0, 200)];
    return ['ok' => true, 'elapsed_s' => $elapsed, 'http_status' => $status, 'bytes' => strlen($raw), 'body' => $raw];
}
function strip(array $r): array { unset($r['body']); return $r; }
function off(string $s): ?array {
    if (!isSourceEnabled($s)) return ['ok' => null, 'detail' => tr('disabled from the admin panel', 'disattivata dal pannello admin')];
    if (!sourceConfigured($s)) return ['ok' => null, 'detail' => tr('not configured in config.php', 'non configurata in config.php')];
    return null;
}

$r = ['version' => GW_VERSION, 'checked_at' => gmdate('c')];
$r['php_version'] = PHP_VERSION;
$r['curl_extension'] = extension_loaded('curl') ? 'ok' : tr('MISSING — no external source will work', 'MANCANTE — nessuna fonte esterna funzionerà');
$r['pdo_mysql_extension'] = extension_loaded('pdo_mysql') ? 'ok' : 'MISSING';
$r['openssl_extension'] = extension_loaded('openssl') ? 'ok' : 'MISSING';
$r['background_refresh'] = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request (PHP-FPM) — ok'
    : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request — ok'
    : tr('mod_php fallback (Connection: close). If pages feel slow, configure api/refresh.php with an external pinger.',
         'fallback mod_php (Connection: close). Se le pagine risultano lente, configura api/refresh.php con un pinger esterno.'));

try {
    $schema = gw_schema_status();
    $r['database'] = $schema['ok'] ? 'ok' : $schema;
    if ($schema['ok']) {
        $n = (int)db()->query("SELECT COUNT(*) FROM globewatch_conflicts WHERE active = 1")->fetchColumn();
        $r['conflicts_active'] = $n;
        $r['cache_rows'] = (int)db()->query("SELECT COUNT(*) FROM globewatch_api_cache")->fetchColumn();
    }
} catch (Throwable $e) {
    $r['database'] = 'ERROR: ' . $e->getMessage();
}
$r['source_status'] = sourceStatusAll();

// GDELT — single paced call (respects the shared rate-limit clock)
if (!($r['gdelt'] = off('gdelt'))) {
    try {
        $t0 = microtime(true);
        $vol = gdeltVolumeFetch('Ukraine war');
        $r['gdelt'] = $vol === null
            ? ['ok' => false, 'detail' => GwHttp::$lastError, 'hint' => tr(
                'HTTP 429 = rate limited (GlobeWatch pauses GDELT automatically). A timeout/connect error usually means the hosting blocks outgoing connections to api.gdeltproject.org.',
                'HTTP 429 = limite di frequenza (GlobeWatch mette in pausa GDELT da solo). Un timeout/errore di connessione di solito indica che l\'hosting blocca le connessioni in uscita verso api.gdeltproject.org.')]
            : ['ok' => true, 'elapsed_s' => round(microtime(true) - $t0, 2), 'days_returned' => count($vol['days'])];
    } catch (GwSkip $e) {
        $r['gdelt'] = ['ok' => null, 'detail' => tr('skipped: GDELT is in rate-limit backoff or busy', 'saltata: GDELT è in pausa per limite di frequenza o occupato')];
    }
}

if (!($r['gdacs'] = off('gdacs'))) {
    $r['gdacs'] = strip(debugGet('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?' . http_build_query([
        'eventlist' => 'EQ;TC;FL;VO;WF;DR', 'fromdate' => isoDaysAgo(7), 'todate' => gmdate('Y-m-d', strtotime('+1 day')),
        'alertlevel' => 'green;orange;red', 'pagesize' => 100, 'pagenumber' => 1])));
}
if (!($r['usgs'] = off('usgs'))) {
    $r['usgs'] = strip(debugGet('https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/2.5_day.geojson'));
}
if (!($r['eonet'] = off('eonet'))) {
    $r['eonet'] = strip(debugGet('https://eonet.gsfc.nasa.gov/api/v3/events?' . http_build_query(['status' => 'open', 'limit' => 5, 'days' => 7])));
}
if (!($r['firms'] = off('firms'))) {
    $t0 = microtime(true);
    $fires = firmsFetch(1, 20);
    $r['firms'] = $fires === null ? ['ok' => false, 'detail' => GwHttp::$lastError]
        : ['ok' => true, 'elapsed_s' => round(microtime(true) - $t0, 2), 'detail' => count($fires) . ' hotspots (last day, top 20)'];
}
if (!($r['hapi'] = off('hapi'))) {
    $t0 = microtime(true);
    try {
        $h = hapiFetchCountry('SDN');
        $r['hapi'] = $h === null ? ['ok' => false, 'detail' => GwHttp::$lastError]
            : ['ok' => true, 'elapsed_s' => round(microtime(true) - $t0, 2), 'test' => 'Sudan', 'months_returned' => count($h['months'])];
    } catch (GwSkip $e) { $r['hapi'] = ['ok' => null, 'detail' => 'backoff (HTTP 429 earlier)']; }
}
if (!($r['reliefweb'] = off('reliefweb'))) {
    $rep = reliefwebReportsFetch('Sudan', 1);
    $r['reliefweb'] = $rep === null ? ['ok' => false, 'detail' => GwHttp::$lastError] : ['ok' => true, 'reports' => count($rep)];
}
if (!($r['reliefweb_disasters'] = off('reliefweb_disasters'))) {
    $d = reliefwebDisastersFetch();
    $r['reliefweb_disasters'] = $d === null ? ['ok' => false, 'detail' => GwHttp::$lastError] : ['ok' => true, 'ongoing_or_alert' => count($d)];
}
if (!($r['ucdp'] = off('ucdp'))) {
    $u = debugGet('https://ucdpapi.pcr.uu.se/api/gedevents/' . rawurlencode(UCDP_VERSION) . '?' . http_build_query(['pagesize' => 1, 'Country' => 625, 'StartDate' => ucdpYear() . '-01-01', 'EndDate' => ucdpYear() . '-12-31']),
        ['x-ucdp-access-token: ' . UCDP_TOKEN]);
    $r['ucdp'] = strip($u);
    if (($u['http_status'] ?? 0) === 404) $r['ucdp']['hint'] = tr('UCDP_VERSION probably outdated: check https://ucdp.uu.se/downloads/', 'UCDP_VERSION probabilmente superata: controlla https://ucdp.uu.se/downloads/');
}
if (!($r['acled'] = off('acled'))) {
    $token = acledGetToken();
    if (!$token) $r['acled'] = ['ok' => false, 'detail' => tr('OAuth authentication failed (email/password?)', 'autenticazione OAuth fallita (email/password?)')];
    else {
        $t = debugGet('https://acleddata.com/api/acled/read?' . http_build_query(['_format' => 'json', 'country' => 'Sudan',
            'event_date' => isoDaysAgo(365), 'event_date_where' => '>=', 'limit' => 500]), ['Authorization: Bearer ' . $token], 25);
        $byType = [];
        foreach (jsonArray($t['body'] ?? null, 'data') as $row) { $k = $row['event_type'] ?? '?'; $byType[$k] = ($byType[$k] ?? 0) + 1; }
        $r['acled'] = strip($t) + ['test' => 'Sudan, 365 days', 'event_type_breakdown' => $byType];
        if ($t['ok'] && !$byType) $r['acled']['hint'] = tr('HTTP 200 but no rows: most likely your plan does not include API data access.',
            'HTTP 200 ma nessuna riga: molto probabilmente il tuo piano non include l\'accesso ai dati via API.');
    }
}

/* ---- v1.11 sources: call the real fetchers, report item counts or the upstream error ---- */
function gwDiag(string $src, callable $fn, callable $count): array {
    if ($o = off($src)) return $o;
    $t0 = microtime(true); GwHttp::$lastError = '';
    try { $d = $fn(); } catch (Throwable $e) { return ['ok' => false, 'detail' => get_class($e) . ': ' . $e->getMessage()]; }
    $el = round(microtime(true) - $t0, 2);
    return $d === null ? ['ok' => false, 'elapsed_s' => $el, 'detail' => GwHttp::$lastError ?: 'no data']
        : ['ok' => true, 'elapsed_s' => $el, 'items' => $count($d)];
}
$cnt = fn($d) => is_array($d) ? count($d) : 0;
$r['who_don'] = gwDiag('who_don', 'whoDonFetch', $cnt);
$r['ifrc'] = gwDiag('ifrc', fn() => ifrcFetch(90), $cnt);
$r['cems'] = gwDiag('cems', 'cemsFetch', $cnt);
$r['emsc'] = gwDiag('emsc', fn() => emscFetch(2), $cnt);
$r['nhc'] = gwDiag('nhc', 'nhcFetch', $cnt);
$r['nhc']['note'] = tr('0 items is normal outside the Atlantic/Pacific hurricane season', '0 elementi è normale fuori dalla stagione degli uragani');
$r['tsunami'] = gwDiag('tsunami', 'tsunamiFetch', $cnt);
$r['meteoalarm'] = gwDiag('meteoalarm', 'meteoalarmFetch', $cnt);
$r['meteoalarm']['note'] = tr('items = countries with orange/red warnings right now', 'elementi = paesi con allerte arancioni/rosse in questo momento');
$r['ioda'] = gwDiag('ioda', fn() => iodaFetch(2), $cnt);
$r['gvp'] = gwDiag('gvp', 'gvpFetch', $cnt);
$r['ucdp_candidate'] = gwDiag('ucdp_candidate', fn() => ucdpCandidateFetch(30), fn($d) => ($d['total'] ?? 0) . ' events, releases ' . implode(', ', $d['releases'] ?? []));
$r['acaps'] = gwDiag('acaps', 'acapsFetch', fn($d) => count($d['crises'] ?? []) . ' crises (' . ($d['period'] ?? '?') . ')');
$r['inform'] = gwDiag('inform', fn() => informFetch('SDN'), fn($d) => $d ? 'Sudan: ' . ($d['overall'] ?? '?') . ' (' . ($d['risk_class'] ?? '?') . ')' : 'no rows');

// v1.12
$r['nws'] = gwDiag('nws', 'nwsFetch', fn($d) => count($d) . ' US states with severe/extreme alerts');
$r['cap'] = gwDiag('cap', 'capFetch', fn($d) => count($d) . ' countries with severe/extreme alerts (feeds: ' . implode(', ', array_keys(capFeeds())) . ')');
$r['hans'] = gwDiag('hans', 'hansFetch', fn($d) => count($d) . ' elevated US volcanoes');
$r['asam'] = gwDiag('asam', 'asamFetch', fn($d) => count($d) . ' maritime incidents (400 days)');
$r['locust'] = gwDiag('locust', 'locustFetch', fn($d) => count($d) . ' locust reports (120 days), layer ' . (kvGet('locust:layer') ?: FAO_LOCUST_LAYER_URL ?: '?'));
$r['swpc'] = gwDiag('swpc', 'swpcFetch', fn($d) => sprintf('G%d S%d R%d', $d['G'], $d['S'], $d['R']));
$r['hapi_context'] = gwDiag('hapi_context', fn() => hapiContextFetch('SDN'), fn($d) => 'Sudan: ' . implode(', ', array_keys($d)));
$r['alerts_ua'] = gwDiag('alerts_ua', 'alertsUaFetch', fn($d) => count($d) . ' oblasts with active alerts');
$r['cfradar'] = gwDiag('cfradar', fn() => cfRadarFetch(7), fn($d) => count($d) . ' outages (7 days)');
$r['idmc'] = gwDiag('idmc', 'idmcFetch', fn($d) => count($d) . ' displacement updates (180 days)');

echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
