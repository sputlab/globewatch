<?php
/*
 * GET api/events.php?category=all|conflict|unrest|atrocity|disaster|emergency&days=30[&refresh=1]
 * Point events: unrest & violence against civilians (ACLED live, or HDX HAPI
 * monthly country aggregates), natural disasters (GDACS, USGS, NASA FIRMS,
 * NASA EONET, ReliefWeb) and humanitarian/health emergencies (ReliefWeb).
 */
require_once __DIR__ . '/builders.php';

$category = $_GET['category'] ?? 'all';
$allowed = ['all', 'conflict', 'unrest', 'atrocity', 'disaster', 'emergency'];
if (!in_array($category, $allowed, true)) {
    gw_json_error(tr('Invalid category, use: ', 'Categoria non valida, usa: ') . implode(', ', $allowed), 400, 'bad_category');
}
gw_send_json(build_events($category, gw_days()));
