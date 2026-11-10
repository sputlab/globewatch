<?php
/* GET api/conflict.php?id=N&days=30 — detail of one conflict: stats, sparkline, monthly series, feed, related reports. */
require_once __DIR__ . '/builders.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) gw_json_error(tr('Missing id', 'id mancante'), 400, 'bad_id');
$detail = build_conflict_detail($id, gw_days());
if ($detail === null) gw_json_error(tr('Conflict not found', 'Conflitto non trovato'), 404, 'not_found');
gw_send_json($detail);
