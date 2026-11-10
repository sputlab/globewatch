<?php
/*
 * GET api/conflicts.php?days=30[&refresh=1]
 * Curated conflict zones with an intensity computed from live data:
 *   1) ACLED live (if a paid plan is active)  → fatalities/events
 *   2) HDX HAPI monthly ACLED aggregates (free) → fatalities/events
 *   3) otherwise GDELT media coverage, ranked relative to the other conflicts
 * `sev_basis` tells the UI which of these was used. Every source is cached
 * independently (stale-while-revalidate): the response is immediate and stale
 * sources are refreshed after it has been sent. `pending` lists sources still
 * warming up: the UI polls again shortly.
 */
require_once __DIR__ . '/builders.php';
gw_send_json(build_conflicts(gw_days()));
