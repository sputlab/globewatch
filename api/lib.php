<?php
/*
 * GlobeWatch — core library (infrastructure only).
 * Source fetchers live in sources.php, response builders in builders.php.
 *
 * ============================= ERROR HANDLING =============================
 * On shared hosting display_errors is often on: a single PHP notice printed
 * before the JSON corrupts the response. Errors are never printed; they are
 * logged server-side and converted to a valid JSON error. Internal details
 * (SQL messages, hostnames, file paths) are NOT sent to the public: only
 * GwPublicException messages are, everything else is a generic message.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Minimal fallbacks for hosts without the mbstring extension (byte-based, good enough for logs/limits). */
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen((string)$s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null) { return (string)substr((string)$s, $start, $len); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string)$s); } }

require_once __DIR__ . '/version.php';
/** Periods offered by the UI. Any ?days= value is snapped UP to one of these
 *  so that cache keys stay bounded and never explode with arbitrary values. */
const GW_PERIODS = [1, 2, 7, 30, 90, 365, 1095, 3650];

/** Every data source known to the app (toggle key => group). */
const GW_SOURCES = ['gdelt', 'hapi', 'acled', 'gdacs', 'usgs', 'firms', 'eonet', 'ucdp', 'reliefweb', 'reliefweb_disasters',
    'who_don', 'ifrc', 'cems', 'ucdp_candidate', 'acaps', 'inform', 'emsc', 'nhc', 'tsunami', 'meteoalarm', 'ioda', 'gvp',
    'nws', 'cap', 'hans', 'asam', 'locust', 'swpc', 'hapi_context', 'alerts_ua', 'cfradar', 'idmc'];

class GwPublicException extends RuntimeException {}
/** Thrown by a producer that cannot run right now (rate-limit backoff, lock busy): not a failure. */
class GwSkip extends RuntimeException {}

function gw_json_error(string $message, int $httpCode = 500, string $code = 'internal'): void {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'error' => $message, 'error_code' => $code]);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('GlobeWatch exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof GwPublicException) gw_json_error($e->getMessage(), 500, 'public');
    gw_json_error(tr('Internal server error (details in the server log).', 'Errore interno del server (dettagli nel log del server).'));
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('GlobeWatch fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) gw_json_error('Fatal server error (details in the server log).');
    }
});

if (!is_file(__DIR__ . '/config.php')) {
    gw_json_error('config.php missing: copy api/config.php.example to api/config.php and fill it in.', 500, 'no_config');
}
require_once __DIR__ . '/db.php';

/* ---------- defaults for constants introduced after the user's config.php ---------- */
function gw_def(string $name, $value): void { if (!defined($name)) define($name, $value); }
gw_def('CACHE_TTL_SECONDS', 600);
gw_def('SOURCE_TTL_OVERRIDES', []);
gw_def('BACKGROUND_BUDGET_SECONDS', 25);
gw_def('REFRESH_KEY', '');
gw_def('GDELT_DOC_ENDPOINT', 'https://api.gdeltproject.org/api/v2/doc/doc');
gw_def('GDELT_MAX_RECORDS', 60);
gw_def('GDELT_MIN_INTERVAL', 6);
gw_def('GDELT_REFRESH_PER_RUN', 2);
gw_def('GDELT_BACKOFF_SECONDS', 1800);
gw_def('GDELT_CONNECT_TIMEOUT', 20);   // GDELT's TLS handshake can be slow from some hosts
gw_def('GDELT_HTTP_FALLBACK', true);   // retry over plain http:// when the https connection/TLS handshake times out
gw_def('HTTP_FORCE_IPV4', true);       // many shared hosts have broken IPv6 routes → connect timeouts
gw_def('CARTO_API_KEY', '');
gw_def('IFRC_GO_TOKEN', '');          // optional: public GO events work without it
gw_def('ACAPS_TOKEN', '');
gw_def('ACAPS_USERNAME', '');
gw_def('ACAPS_PASSWORD', '');
gw_def('CAP_EXTRA_FEEDS', []);        // ['ISO3' => 'https://…cap feed…'] extra national CAP feeds (WMO register)
gw_def('CAP_MAX_DOCS_PER_RUN', 40);   // new linked CAP documents downloaded per refresh
gw_def('FAO_LOCUST_LAYER_URL', '');   // optional override of the auto-discovered FAO swarms layer
gw_def('ALERTS_UA_TOKEN', '');
gw_def('CLOUDFLARE_RADAR_TOKEN', '');
gw_def('IDMC_CLIENT_ID', '');
gw_def('FIRMS_MAP_KEY', '');
gw_def('ACLED_EMAIL', '');
gw_def('ACLED_PASSWORD', '');
gw_def('HAPI_APP_NAME', 'GlobeWatch');
gw_def('HAPI_EMAIL', '');
gw_def('HAPI_APP_IDENTIFIER', '');
gw_def('HAPI_REFRESH_PER_RUN', 6);
gw_def('UCDP_TOKEN', '');
gw_def('UCDP_VERSION', '26.1');
gw_def('RELIEFWEB_APPNAME', '');
gw_def('ADMIN_PANEL_PASSWORD', '');
gw_def('HISTORY_YEARS_BACK', 5);

/* ============================= I18N (server side) ============================= */
/* The UI translates almost everything itself from codes; the server only needs
   this for the admin panel and human-readable diagnostics/error messages. */
function gw_lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $l = strtolower((string)($_GET['lang'] ?? $_COOKIE['gw_lang'] ?? 'en'));
    return $lang = in_array($l, ['en', 'it'], true) ? $l : 'en';
}
function tr(string $en, string $it): string { return gw_lang() === 'it' ? $it : $en; }

/* ============================= REQUEST HELPERS ============================= */
function gw_days(): int {
    $d = max(1, (int)($_GET['days'] ?? 30));
    foreach (GW_PERIODS as $p) if ($d <= $p) return $p;
    return end(GW_PERIODS);
}
function gw_force(): bool { return ($_GET['refresh'] ?? '') === '1'; }

/** Safe JSON decode helpers: never emit a warning on a null offset. */
function jsonArray(?string $raw, ?string $key = null): array {
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    if ($key === null) return $decoded;
    return is_array($decoded[$key] ?? null) ? $decoded[$key] : [];
}
function jsonObject(?string $raw): array {
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
function isoDaysAgo(int $days): string { return gmdate('Y-m-d', strtotime("-{$days} days")); }

/* ============================= DB HELPERS ============================= */

/** Runs a PDO callback, retrying once on a fresh connection after "MySQL server has gone away". */
function dbRetry(callable $fn) {
    try {
        return $fn(db());
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '2006') !== false || stripos($e->getMessage(), 'gone away') !== false) {
            return $fn(forceReconnectDb());
        }
        if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
            error_log('GlobeWatch schema error: ' . $e->getMessage());
            throw new GwPublicException(tr(
                'Database not installed: open admin.php and press "Install database" (or import sql/schema.sql).',
                'Database non installato: apri admin.php e premi "Installa database" (oppure importa sql/schema.sql).'
            ));
        }
        throw $e;
    }
}

/** Named MySQL advisory lock (non-blocking by default). Names are hashed to fit the 64-char limit. */
function dbLock(string $name, int $timeout = 0): bool {
    try {
        $s = db()->prepare('SELECT GET_LOCK(?, ?)');
        $s->execute(['gw_' . md5($name), $timeout]);
        return (int)$s->fetchColumn() === 1;
    } catch (Throwable $e) { return true; } // locks unsupported: degrade gracefully
}
function dbUnlock(string $name): void {
    try { db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['gw_' . md5($name)]); } catch (Throwable $e) {}
}

/**
 * Per-source toggle, managed from the admin panel (globewatch_source_toggles).
 * If the table does not exist yet everything stays enabled.
 */
function isSourceEnabled(string $source): bool {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT source, enabled FROM globewatch_source_toggles")->fetchAll() as $r) {
                $cache[$r['source']] = (bool)$r['enabled'];
            }
        } catch (Throwable $e) { /* table missing: defaults below */ }
    }
    return $cache[$source] ?? true;
}

/* ============================= CACHE (stale-while-revalidate) ============================= */
/*
 * Table globewatch_api_cache: cache_key, payload, fetched_at, expires_at, stale_until (unix seconds).
 *   now < expires_at               → fresh, served as is
 *   expires_at <= now < stale_until → stale: served INSTANTLY + refreshed after the response
 *   now >= stale_until / no row     → fetched synchronously (fast sources) or queued (slow ones)
 * A failed refresh never overwrites good data: the old payload is kept and retried later.
 */

final class GwCache { public static array $memo = []; }

/** Freshness window per source, aligned to how often each upstream really updates. */
function sourceTtl(string $source): int {
    $defaults = [
        'usgs' => 120,          // real-time feeds regenerated every minute
        'gdacs' => 300,         // alerts republished every few minutes
        'eonet' => 600,
        'reliefweb_disasters' => 1800,
        'reliefweb' => 1800,
        'firms' => 1800,        // NRT satellite passes, heavy global query
        'gdelt' => 1800,        // updates every 15 min, but strictly rate-limited
        'hapi' => 21600,        // ACLED aggregates on HDX are updated weekly
        'acled' => 21600,
        'ucdp' => 43200,
        'emsc' => 120, 'tsunami' => 300, 'nhc' => 900, 'meteoalarm' => 900, 'ioda' => 900,
        'who_don' => 3600, 'ifrc' => 3600, 'cems' => 3600, 'gvp' => 21600,
        'ucdp_candidate' => 21600, 'acaps' => 43200, 'inform' => 604800,
        'alerts_ua' => 60, 'nws' => 300, 'cap' => 600, 'swpc' => 900, 'hans' => 1800, 'cfradar' => 1800,
        'idmc' => 21600, 'asam' => 43200, 'locust' => 43200, 'hapi_context' => 86400,
    ];
    $o = is_array(SOURCE_TTL_OVERRIDES) ? SOURCE_TTL_OVERRIDES : [];
    return max(30, (int)($o[$source] ?? $defaults[$source] ?? 600));
}

/** Loads several cache rows in one query (e.g. all GDELT volumes) into the memo. */
function cachePrefetch(array $keys): void {
    $keys = array_values(array_diff(array_unique($keys), array_keys(GwCache::$memo)));
    if (!$keys || CACHE_TTL_SECONDS <= 0) return;
    foreach (array_chunk($keys, 200) as $chunk) {
        $rows = dbRetry(function (PDO $pdo) use ($chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $pdo->prepare("SELECT cache_key, payload, fetched_at, expires_at, stale_until FROM globewatch_api_cache WHERE cache_key IN ($in)");
            $s->execute($chunk);
            return $s->fetchAll();
        });
        foreach ($chunk as $k) GwCache::$memo[$k] = null;
        foreach ($rows as $r) GwCache::$memo[$r['cache_key']] = cacheDecodeRow($r);
    }
}
function cacheDecodeRow(array $r): array {
    return ['data' => json_decode($r['payload'], true), 'fetched_at' => (int)$r['fetched_at'],
        'expires_at' => (int)$r['expires_at'], 'stale_until' => (int)$r['stale_until']];
}
function cacheRow(string $key, bool $bypassMemo = false): ?array {
    if (CACHE_TTL_SECONDS <= 0) return null;
    if (!$bypassMemo && array_key_exists($key, GwCache::$memo)) return GwCache::$memo[$key];
    $row = dbRetry(function (PDO $pdo) use ($key) {
        $s = $pdo->prepare("SELECT cache_key, payload, fetched_at, expires_at, stale_until FROM globewatch_api_cache WHERE cache_key = ?");
        $s->execute([$key]);
        return $s->fetch() ?: null;
    });
    return GwCache::$memo[$key] = $row ? cacheDecodeRow($row) : null;
}
function cacheSet(string $key, $data, int $ttl, int $stale): void {
    if (CACHE_TTL_SECONDS <= 0) return;
    $now = time();
    dbRetry(function (PDO $pdo) use ($key, $data, $ttl, $stale, $now) {
        $s = $pdo->prepare("
            INSERT INTO globewatch_api_cache (cache_key, payload, fetched_at, expires_at, stale_until)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at),
              expires_at = VALUES(expires_at), stale_until = VALUES(stale_until)");
        $s->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $now, $now + $ttl, $now + $ttl + $stale]);
        return null;
    });
    GwCache::$memo[$key] = ['data' => $data, 'fetched_at' => $now, 'expires_at' => $now + $ttl, 'stale_until' => $now + $ttl + $stale];
}
/** After a failed refresh: keep the payload, just postpone the next attempt. */
function cachePostpone(string $key, int $seconds): void {
    if (CACHE_TTL_SECONDS <= 0) return;
    dbRetry(function (PDO $pdo) use ($key, $seconds) {
        $pdo->prepare("UPDATE globewatch_api_cache SET expires_at = ? WHERE cache_key = ?")->execute([time() + $seconds, $key]);
        return null;
    });
    unset(GwCache::$memo[$key]);
}

/** Tiny key/value store on the same table (rate-limit clocks, tokens, source health).
 *  Always backed by the DB, even when CACHE_TTL_SECONDS = 0, because rate-limit
 *  clocks must be shared between concurrent PHP processes. */
function kvGet(string $key) {
    $row = dbRetry(function (PDO $pdo) use ($key) {
        $s = $pdo->prepare("SELECT payload, stale_until FROM globewatch_api_cache WHERE cache_key = ?");
        $s->execute(['kv:' . $key]);
        return $s->fetch() ?: null;
    });
    if (!$row || time() >= (int)$row['stale_until']) return null;
    return json_decode($row['payload'], true);
}
function kvSet(string $key, $value, int $ttl = 86400 * 30): void {
    $now = time();
    dbRetry(function (PDO $pdo) use ($key, $value, $ttl, $now) {
        $s = $pdo->prepare("
            INSERT INTO globewatch_api_cache (cache_key, payload, fetched_at, expires_at, stale_until) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at),
              expires_at = VALUES(expires_at), stale_until = VALUES(stale_until)");
        $s->execute(['kv:' . $key, json_encode($value), $now, $now + $ttl, $now + $ttl]);
        return null;
    });
}

/** Records the outcome of the last call to a source, shown in the "Data sources" panel. */
function gwHealth(string $source, bool $ok, string $error = ''): void {
    try {
        $h = kvGet('health:' . $source) ?: [];
        if ($ok) { $h['ok_at'] = time(); $h['error'] = null; }
        else { $h['err_at'] = time(); $h['error'] = mb_substr($error, 0, 200); }
        kvSet('health:' . $source, $h);
    } catch (Throwable $e) { /* health is best-effort */ }
}

/* ---------------- background jobs ---------------- */
final class GwJobs {
    public static array $queue = [];
    public static array $pending = [];
}

/**
 * Stale-while-revalidate read.
 * $o: source (string, required), ttl (int), stale (int), sync (bool, default true),
 *     group (string|null — per-run limit, e.g. 'gdelt'), priority (int, lower first).
 * Returns ['data' => mixed|null, 'fetched_at' => int|null, 'state' => fresh|stale|live|pending|error].
 */
function swr(string $key, callable $producer, array $o): array {
    $source = $o['source'];
    $ttl = $o['ttl'] ?? sourceTtl($source);
    $o += ['stale' => max($ttl * 12, 6 * 3600), 'sync' => true, 'group' => null, 'priority' => 5, 'ttl' => $ttl];
    $now = time();

    if (CACHE_TTL_SECONDS <= 0) {           // cache disabled: always live, always synchronous
        try { $d = $producer(); } catch (GwSkip $e) { return ['data' => null, 'fetched_at' => null, 'state' => 'pending']; }
        gwHealth($source, $d !== null);
        return ['data' => $d, 'fetched_at' => $now, 'state' => $d === null ? 'error' : 'live'];
    }

    $row = cacheRow($key);
    $forceStale = gw_force() && $row && ($now - $row['fetched_at']) > 60;

    if ($row && $now < $row['expires_at'] && !$forceStale) {
        return ['data' => $row['data'], 'fetched_at' => $row['fetched_at'], 'state' => 'fresh'];
    }
    if ($row && $now < $row['stale_until']) {
        GwJobs::$queue[$key] = [$producer, $o];
        return ['data' => $row['data'], 'fetched_at' => $row['fetched_at'], 'state' => 'stale'];
    }
    if (!$o['sync']) {
        GwJobs::$queue[$key] = [$producer, $o];
        GwJobs::$pending[$source] = true;
        return ['data' => $row['data'] ?? null, 'fetched_at' => $row['fetched_at'] ?? null, 'state' => 'pending'];
    }
    $data = gwRefresh($key, $producer, $o, false);
    if ($data === null) {
        return ['data' => $row['data'] ?? null, 'fetched_at' => $row['fetched_at'] ?? null,
            'state' => ($row ? 'stale' : (isset(GwJobs::$pending[$source]) ? 'pending' : 'error'))];
    }
    return ['data' => $data, 'fetched_at' => time(), 'state' => 'live'];
}

/** Runs one producer under a lock and stores the result. Returns the data or null. */
function gwRefresh(string $key, callable $producer, array $o, bool $background) {
    $source = $o['source'];
    if (!dbLock('refresh:' . $key, $background ? 0 : 8)) return null; // someone else is refreshing it
    try {
        if ($background) {
            $row = cacheRow($key, true);                 // re-check: another worker may have done it
            if ($row && time() < $row['expires_at'] && !gw_force()) return $row['data'];
        }
        GwHttp::$lastError = '';
        try {
            $data = $producer();
        } catch (GwSkip $e) {
            GwJobs::$pending[$source] = true;
            return null;
        }
        if ($data === null) {
            gwHealth($source, false, GwHttp::$lastError ?: 'no valid response');
            if (cacheRow($key, true)) cachePostpone($key, min($o['ttl'], 300));
            else cacheSet($key, [], 90, 0);          // negative cache: don't hammer a dead source
            return null;
        }
        cacheSet($key, $data, $o['ttl'], $o['stale']);
        gwHealth($source, true);
        return $data;
    } finally {
        dbUnlock('refresh:' . $key);
    }
}

/**
 * Sends the JSON response, detaches from the client when possible, then runs the
 * queued refreshes. This is what keeps data fresh without a cron job: visitors
 * always get an immediate answer and "pay" for the next visitor's fresh data
 * after their own connection has already been closed.
 */
function gw_send_json(array $payload, int $maxAge = 30): void {
    $payload['pending'] = array_values(array_keys(GwJobs::$pending));
    $payload['version'] = GW_VERSION;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: ' . ($payload['pending'] ? 'no-store' : 'public, max-age=' . $maxAge));
    }
    if (!GwJobs::$queue) { echo $body; exit; }

    ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit(BACKGROUND_BUDGET_SECONDS + 90);   // a slow GDELT call (TLS timeout + http retry) can run past the budget
    if (function_exists('fastcgi_finish_request')) {          // PHP-FPM (most shared hosting)
        echo $body; fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {  // LiteSpeed
        echo $body; litespeed_finish_request();
    } else {                                                  // mod_php: close via Content-Length
        while (ob_get_level() > 0) @ob_end_clean();
        if (!headers_sent()) { header('Connection: close'); header('Content-Length: ' . strlen($body)); }
        echo $body; @flush();
    }
    gw_run_queue();
    exit;
}

/** Executes queued refreshes within the time budget and per-group limits. */
function gw_run_queue(?float $budget = null): array {
    $deadline = microtime(true) + ($budget ?? BACKGROUND_BUDGET_SECONDS);
    $limits = ['gdelt' => max(0, (int)GDELT_REFRESH_PER_RUN), 'hapi' => max(0, (int)HAPI_REFRESH_PER_RUN)];
    $used = [];
    $jobs = GwJobs::$queue;
    GwJobs::$queue = [];
    uasort($jobs, fn($a, $b) => ($a[1]['priority'] ?? 5) <=> ($b[1]['priority'] ?? 5));
    $done = [];
    foreach ($jobs as $key => [$producer, $o]) {
        if (microtime(true) > $deadline) break;
        $g = $o['group'] ?? null;
        if ($g !== null && isset($limits[$g])) {
            if (($used[$g] ?? 0) >= $limits[$g]) continue;
            $used[$g] = ($used[$g] ?? 0) + 1;
        }
        try { $done[$key] = gwRefresh($key, $producer, $o, true) !== null; }
        catch (Throwable $e) { error_log('GlobeWatch background refresh ' . $key . ': ' . $e->getMessage()); $done[$key] = false; }
    }
    if (mt_rand(1, 50) === 1) {   // occasional housekeeping: long-dead cache rows and expired kv entries (CAP docs, tokens…)
        try {
            db()->prepare("DELETE FROM globewatch_api_cache WHERE (cache_key NOT LIKE 'kv:%' AND stale_until < ?)
                OR (cache_key LIKE 'kv:%' AND stale_until < ?)")->execute([time() - 86400 * 7, time()]);
        } catch (Throwable $e) {}
    }
    return $done;
}

/* ============================= HTTP ============================= */
final class GwHttp { public static string $lastError = ''; public static int $lastErrno = 0; }

function gw_user_agent(): string { return 'GlobeWatch/' . GW_VERSION . ' (+https://www.sputlab.com)'; }

/** Hides credentials in URLs before they reach the server log (query params and FIRMS-style path keys). */
function gw_redact_url(string $url): string {
    $url = preg_replace('/(key|token|app_identifier|appname|client_id|password)=[^&]+/i', '$1=***', $url);
    return FIRMS_MAP_KEY !== '' ? str_replace(rawurlencode(FIRMS_MAP_KEY), '***', $url) : $url;
}

/** GET returning [body|null, httpStatus, curlError]. */
function httpRequest(string $url, array $headers = [], int $timeout = 12, ?string $postBody = null, array $curlOpts = []): array {
    if (!function_exists('curl_init')) { GwHttp::$lastError = 'curl extension missing'; return [null, 0, 'curl extension missing']; }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . gw_user_agent()], $headers),
    ];
    if (HTTP_FORCE_IPV4 && defined('CURL_IPRESOLVE_V4')) $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    foreach ($curlOpts as $k => $v) $opts[$k] = $v;
    if ($postBody !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $postBody; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    GwHttp::$lastErrno = curl_errno($ch);
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    if ($raw === false || $status !== 200) {
        // keep the upstream's own explanation (e.g. ReliefWeb/UCDP JSON error message) — it's what makes a 400 debuggable
        $detail = '';
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            $msg = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? $j['error'] ?? null) : null;
            $detail = is_string($msg) ? $msg : mb_substr(trim(strip_tags($raw)), 0, 140);
        }
        GwHttp::$lastError = ($err ?: ('HTTP ' . $status)) . ($detail !== '' ? ' — ' . $detail : '');
        error_log('GlobeWatch HTTP ' . $status . ' ' . $err . ' — ' . gw_redact_url($url));
        return [$raw === false ? null : $raw, $status, $err];
    }
    return [$raw, $status, ''];
}
function httpGet(string $url, array $headers = [], int $timeout = 12): ?string {
    [$raw, $status] = httpRequest($url, $headers, $timeout);
    return $status === 200 ? $raw : null;
}

/** Parallel GETs. $requests = ['key' => ['url' => .., 'headers' => [..]]]. Returns ['key' => body|null]. */
function httpGetMulti(array $requests, int $timeout = 12): array {
    if (!$requests) return [];
    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($requests as $k => $r) $out[$k] = httpGet($r['url'], $r['headers'] ?? [], $timeout);
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => $req) {
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . gw_user_agent()], $req['headers'] ?? []),
        ]);
        if (HTTP_FORCE_IPV4 && defined('CURL_IPRESOLVE_V4')) curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running && curl_multi_select($mh, 1.0) === -1) usleep(100000); // avoid a busy loop
    } while ($running > 0 && $st === CURLM_OK);
    $results = [];
    foreach ($handles as $key => $ch) {
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$key] = $status === 200 ? curl_multi_getcontent($ch) : null;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $results;
}

/* ============================= ADMIN SESSION ============================= */
function gw_admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('GWADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
function gw_is_admin(): bool { gw_admin_session_start(); return !empty($_SESSION['gw_admin']); }
function gw_csrf_token(): string {
    gw_admin_session_start();
    if (empty($_SESSION['gw_csrf'])) $_SESSION['gw_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['gw_csrf'];
}

require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/sources_extra.php';
require_once __DIR__ . '/sources_v112.php';
