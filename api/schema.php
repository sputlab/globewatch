<?php
/*
 * GlobeWatch — database schema check and one-click installation (admin.php, diagnostics).
 * The schema itself lives in sql/schema.sql: this file only checks that the tables
 * exist and, on request, executes that file statement by statement.
 */
require_once __DIR__ . '/lib.php';

const GW_TABLES = ['globewatch_conflicts', 'globewatch_api_cache', 'globewatch_archived_events',
    'globewatch_sync_state', 'globewatch_source_toggles'];

function gwTableExists(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?");
    $s->execute([$t]);
    return (int)$s->fetchColumn() > 0;
}

/** ['ok' => bool, 'missing' => [table, …]] */
function gw_schema_status(): array {
    $pdo = db();
    $missing = array_values(array_filter(GW_TABLES, fn($t) => !gwTableExists($pdo, $t)));
    return ['ok' => !$missing, 'missing' => $missing];
}

/** Runs sql/schema.sql. Idempotent (CREATE TABLE IF NOT EXISTS / INSERT IGNORE). */
function gw_install_schema(): array {
    $file = dirname(__DIR__) . '/sql/schema.sql';
    if (!is_readable($file)) return ['ok' => false, 'log' => ['sql/schema.sql not found on the server: upload the sql/ folder too.']];
    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);                     // full-line comments only
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql)));
    $pdo = db();
    $log = [];
    foreach ($statements as $st) {
        $pdo->exec($st);
        if (preg_match('/^(CREATE TABLE IF NOT EXISTS|INSERT IGNORE INTO)\s+(\w+)/i', $st, $m)) {
            $log[] = (stripos($m[1], 'CREATE') === 0 ? 'table ' : 'seed ') . $m[2] . ' ✓';
        }
    }
    return ['ok' => gw_schema_status()['ok'], 'log' => $log];
}
