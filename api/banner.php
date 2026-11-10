<?php
/* GET api/banner.php — global conditions without a place on the map (v1.12: NOAA space weather). */
require_once __DIR__ . '/builders.php';
$meta = [];
$space = null;
if (!gwMetaOff($meta, 'swpc')) {
    $r = swr('swpc:scales', fn() => swpcFetch(), ['source' => 'swpc', 'priority' => 2]);
    $space = $r['data']; gwMetaAdd($meta, 'swpc', $r);
}
gw_send_json(['space' => $space, 'sources' => gwMetaFinalize($meta)], 60);
