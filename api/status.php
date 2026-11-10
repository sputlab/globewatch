<?php
/* GET api/status.php — version and per-source health, for the "Data sources" panel. */
require_once __DIR__ . '/builders.php';
gw_send_json(['generated_at' => gmdate('c'), 'server_time' => time(), 'sources' => sourceStatusAll()], 30);
