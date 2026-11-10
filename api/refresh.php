<?php
/*
 * GET api/refresh.php?key=REFRESH_KEY — optional warm-up for an external pinger.
 * No cron on the hosting? A free service (cron-job.org, UptimeRobot...) calling
 * this URL every 5 minutes keeps every source cache warm, so real visitors
 * never wait for a slow upstream. Disabled while REFRESH_KEY is empty.
 *
 * Since 1.10.2 the answer is sent IMMEDIATELY and the downloads continue on the
 * server afterwards: pinger services close the connection after ~30 s, and a
 * slow GDELT call must not make every run look "failed" (or get cut short).
 * The outcome of the previous run is reported in "last_run".
 */
require_once __DIR__ . '/builders.php';

if (REFRESH_KEY === '' || !hash_equals((string)REFRESH_KEY, (string)($_GET['key'] ?? ''))) {
    gw_json_error('Forbidden', 403, 'forbidden');
}
ignore_user_abort(true);
@set_time_limit(180);
$t0 = microtime(true);

foreach ([2, 7, 30] as $d) build_events('all', $d);   // queues every stale/pending source
build_conflicts(30);
$queued = array_keys(GwJobs::$queue);

$last = null;
try { $last = kvGet('refresh:last'); } catch (Throwable $e) {}
$body = json_encode(['ok' => true, 'version' => GW_VERSION, 'queued' => count($queued),
    'note' => count($queued) ? 'refresh running in background' : 'all caches fresh', 'last_run' => $last],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// send the response now, keep working afterwards (same technique as gw_send_json)
if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
if (function_exists('fastcgi_finish_request')) { echo $body; fastcgi_finish_request(); }
elseif (function_exists('litespeed_finish_request')) { echo $body; litespeed_finish_request(); }
else {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) { header('Connection: close'); header('Content-Length: ' . strlen($body)); }
    echo $body; @flush();
}

if (!$queued) exit;
$done = gw_run_queue(max(60, (float)BACKGROUND_BUDGET_SECONDS * 3));
try {
    kvSet('refresh:last', ['at' => gmdate('c'), 'queued' => count($queued),
        'refreshed' => count(array_filter($done)), 'failed' => array_keys(array_filter($done, fn($v) => !$v)),
        'elapsed_s' => round(microtime(true) - $t0, 1)], 7 * 86400);
} catch (Throwable $e) {}
