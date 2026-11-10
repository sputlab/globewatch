<?php
/* GlobeWatch — front end shell.
   Server side only: the initial <html lang> (from ?lang / gw_lang cookie, so the first paint is
   already in the right language), the version for cache busting and the public CARTO key. */
require_once __DIR__ . '/api/version.php';
$gwLang = strtolower((string)($_GET['lang'] ?? $_COOKIE['gw_lang'] ?? 'en'));
if (!in_array($gwLang, ['en', 'it'], true)) $gwLang = 'en';

/* CARTO_API_KEY ends up in the tile URLs, so it is public by nature: restrict it by domain in the CARTO dashboard. */
$gwCartoKey = '';
if (is_file(__DIR__ . '/api/config.php')) {
    ob_start();
    try { require_once __DIR__ . '/api/config.php'; } catch (Throwable $e) {}
    ob_end_clean();
}
if (defined('CARTO_API_KEY')) $gwCartoKey = (string)CARTO_API_KEY;

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$v = rawurlencode(GW_VERSION);
?><!DOCTYPE html>
<html lang="<?= $gwLang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="description" content="GlobeWatch — live world map of armed conflicts, unrest, disasters and humanitarian emergencies from open data.">
<meta name="theme-color" content="#07090D">
<meta name="color-scheme" content="dark">
<meta property="og:title" content="GlobeWatch">
<meta property="og:description" content="Live world map of armed conflicts, unrest, disasters and humanitarian emergencies from open data.">
<title>GlobeWatch — Global events map</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/icons/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="GlobeWatch">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta property="og:image" content="assets/icons/icon-512.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://basemaps.cartocdn.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></symbol>
  <symbol id="i-layers" viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.34-5.66L20 8.5"/><path d="M20 3.5v5h-5"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4.5M12 8h.01"/></symbol>
  <symbol id="i-signal" viewBox="0 0 24 24"><path d="M4 20v-3M9 20v-7M14 20V9M19 20V4"/></symbol>
  <symbol id="i-heart" viewBox="0 0 24 24"><path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7 7-7z"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
  <symbol id="i-chev-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
  <symbol id="i-chev-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
  <symbol id="i-chev-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
  <symbol id="i-ext" viewBox="0 0 24 24"><path d="M14 4h6v6M10 14 20 4M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></symbol>
  <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.8 3.9 5.8 3.9 9s-1.3 6.2-3.9 9c-2.6-2.8-3.9-5.8-3.9-9S9.4 5.8 12 3z"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
  <symbol id="i-lang" viewBox="0 0 24 24"><path d="M4 5h8M8 3v2M10 5c-.7 4-3 7-6 9M6 9c1 2 3 4 6 5M13 21l4-9 4 9M14.5 18h5"/></symbol>
</svg>

<div id="map" role="application" aria-label="Map"></div>
<div class="map-vignette" aria-hidden="true"></div>

<!-- status (top right) -->
<div class="hud">
  <a id="spaceChip" class="space-chip glass" href="https://www.swpc.noaa.gov/" target="_blank" rel="noopener" hidden></a>
  <div class="hud-pill glass" aria-live="polite">
    <span class="status-dot" id="statusDot"></span><b id="statusText">…</b>
    <span class="hud-sep"></span><span><span id="utcClock">--:--</span> UTC</span>
  </div>
</div>

<!-- side panel (desktop) / bottom sheet (mobile) -->
<aside id="sheet" class="glass" aria-label="Events">
  <div class="handle-zone" id="handleZone"><span class="handle"></span></div>

  <header class="panel-head">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true"><svg class="i"><use href="#i-globe"/></svg></span>
      <div class="brand-text">
        <div class="brand-name">GlobeWatch</div>
        <div class="brand-sub" data-i18n="appTitle">Global events map</div>
      </div>
    </div>
    <div class="actions">
      <button class="icon-btn" id="refreshBtn" data-i18n-title="refreshNow" data-i18n-aria="refreshNow" title="Refresh now" aria-label="Refresh now"><svg class="i" id="refreshIcon"><use href="#i-refresh"/></svg></button>
      <button class="icon-btn" id="burgerBtn" data-i18n-aria="menu" aria-label="Menu" aria-haspopup="menu" aria-expanded="false" aria-controls="burgerMenu"><svg class="i"><use href="#i-menu"/></svg></button>
    </div>
  </header>

  <div id="filterBar">
    <div class="search-row">
      <label class="search">
        <svg class="i"><use href="#i-search"/></svg>
        <span class="sr-only" data-i18n="searchPh">Search</span>
        <input type="search" id="searchInput" data-i18n-ph="searchPh" placeholder="Search…" autocomplete="off" spellcheck="false" enterkeyhint="search">
      </label>
      <button type="button" class="layers-btn" id="layersBtn" aria-haspopup="dialog"><svg class="i"><use href="#i-layers"/></svg><span class="lbl" data-i18n="layersShort">Layers</span><span class="count" id="layersCount"></span></button>
    </div>

    <div class="tabs" id="catTabs" role="tablist" data-i18n-aria="eventType" aria-label="Event type">
      <button class="tab" role="tab" data-cat="all" aria-selected="true"><span data-i18n="tab_all">All</span></button>
      <button class="tab" role="tab" data-cat="conflict" aria-selected="false" style="--c:var(--high)"><i></i><span data-i18n="tab_conflict">Conflicts</span></button>
      <button class="tab" role="tab" data-cat="unrest" aria-selected="false" style="--c:var(--c-unrest)"><i></i><span data-i18n="tab_unrest">Unrest</span></button>
      <button class="tab" role="tab" data-cat="atrocity" aria-selected="false" style="--c:var(--c-atrocity)"><i></i><span data-i18n="tab_atrocity">Civilians</span></button>
      <button class="tab" role="tab" data-cat="disaster" aria-selected="false" style="--c:var(--c-disaster)"><i></i><span data-i18n="tab_disaster">Disasters</span></button>
      <button class="tab" role="tab" data-cat="emergency" aria-selected="false" style="--c:var(--c-emergency)"><i></i><span data-i18n="tab_emergency">Emergencies</span></button>
    </div>

    <div class="controls-row">
      <label class="select">
        <span class="sr-only" data-i18n="period">Period</span>
        <select id="periodSelect">
          <option value="1" data-i18n="p1">Last 24 hours</option>
          <option value="2" data-i18n="p2">Last 48 hours</option>
          <option value="7" data-i18n="p7">Last 7 days</option>
          <option value="30" data-i18n="p30" selected>Last 30 days</option>
          <option value="90" data-i18n="p90">Last 90 days</option>
          <option value="365" data-i18n="p365">Last year</option>
          <option value="1095" data-i18n="p1095">Last 3 years</option>
          <option value="3650" data-i18n="p3650">Last 10 years</option>
        </select>
        <svg class="i"><use href="#i-chev-down"/></svg>
      </label>
      <div class="seg" id="sevFilters" role="group" data-i18n-aria="intensity" aria-label="Intensity">
        <button type="button" class="all" data-sev="all" aria-pressed="true"><span data-i18n="sevAllShort">All</span></button>
        <button type="button" data-sev="high" aria-pressed="false" data-i18n-title="sevHigh" style="--c:var(--high)"><i></i><span data-i18n="sevHigh">High</span></button>
        <button type="button" data-sev="medium" aria-pressed="false" data-i18n-title="sevMedium" style="--c:var(--medium)"><i></i><span data-i18n="sevMedium">Medium</span></button>
        <button type="button" data-sev="low" aria-pressed="false" data-i18n-title="sevLow" style="--c:var(--low)"><i></i><span data-i18n="sevLow">Low</span></button>
      </div>
    </div>

    <div class="legend" id="legend"></div>
  </div>

  <section id="listView">
    <div class="list-head">
      <h2 data-i18n="listTitle">Active situations</h2>
      <span id="listCount"></span>
    </div>
    <div id="list" aria-live="polite" aria-busy="true">
      <div class="skeleton" aria-hidden="true">
        <div class="skel"><span class="d"></span><div class="l"><div style="width:72%"></div><div style="width:44%"></div></div></div>
        <div class="skel"><span class="d"></span><div class="l"><div style="width:64%"></div><div style="width:38%"></div></div></div>
        <div class="skel"><span class="d"></span><div class="l"><div style="width:78%"></div><div style="width:50%"></div></div></div>
        <div class="skel"><span class="d"></span><div class="l"><div style="width:58%"></div><div style="width:34%"></div></div></div>
      </div>
    </div>
  </section>

  <section id="detail" aria-live="polite">
    <button class="back" id="backBtn"><svg class="i"><use href="#i-chev-left"/></svg><span data-i18n="back">Back to list</span></button>
    <div id="detailBody"></div>
  </section>

  <footer class="panel-foot">
    <button class="update-text" id="lastUpdated">—</button>
    <span class="loading-text" id="loadingText"><i></i><span data-i18n="loadingShort">Updating…</span></span>
  </footer>
</aside>

<!-- menu -->
<nav id="burgerMenu" class="menu glass" role="menu" data-i18n-aria="menu" aria-label="Menu">
  <button role="menuitem" id="aboutBtn"><svg class="i"><use href="#i-info"/></svg><span data-i18n="menuAbout">About</span></button>
  <button role="menuitem" id="sourcesBtn"><svg class="i"><use href="#i-signal"/></svg><span data-i18n="menuSources">Data sources</span></button>
  <button role="menuitem" id="installBtn" hidden><svg class="i"><use href="#i-download"/></svg><span data-i18n="menuInstall">Install app</span></button>
  <a role="menuitem" href="https://www.sputlab.com/donate" target="_blank" rel="noopener"><svg class="i"><use href="#i-heart"/></svg><span data-i18n="menuSupport">Support the project</span></a>
  <div class="menu-sep"></div>
  <div class="menu-label" data-i18n="menuLanguage">Language</div>
  <div class="seg" role="group" data-i18n-aria="menuLanguage" aria-label="Language">
    <button type="button" data-lang="en" aria-pressed="true">English</button>
    <button type="button" data-lang="it" aria-pressed="false">Italiano</button>
  </div>
</nav>

<!-- error toast -->
<div id="errorBanner" class="toast glass" role="alert" hidden>
  <svg class="i"><use href="#i-alert"/></svg>
  <div class="toast-body">
    <b data-i18n="errTitle">Live data unavailable</b>
    <span id="errorDetail"></span>
    <div class="toast-actions">
      <button class="btn" id="retryBtn" data-i18n="retry">Retry</button>
      <button class="btn ghost" id="dismissBtn" data-i18n="dismiss">Dismiss</button>
    </div>
  </div>
</div>

<!-- modals -->
<div id="aboutOverlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="aboutTitle">
  <div class="modal-card glass">
    <div class="modal-head">
      <h2 id="aboutTitle">GlobeWatch<span class="ver" id="aboutVersion"></span></h2>
      <button class="icon-btn" data-close data-i18n-aria="close" aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button>
    </div>
    <div class="modal-body">
      <p class="modal-lead" data-i18n="aboutLead"></p>
      <h3 data-i18n="contacts">Contacts</h3>
      <div class="contacts">
        <a href="mailto:sputnik@sputlab.com"><span data-i18n="contactGeneral">General questions</span>sputnik@sputlab.com</a>
        <a href="mailto:bug@sputlab.com"><span data-i18n="contactBugs">Bug reports</span>bug@sputlab.com</a>
      </div>
      <h3 data-i18n="changelogTitle">What's new</h3>
      <div id="changelogList"></div>
    </div>
  </div>
</div>

<div id="layersOverlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="layersTitle">
  <div class="modal-card glass">
    <div class="modal-head">
      <h2 id="layersTitle" data-i18n="layersTitle">Map layers</h2>
      <button class="icon-btn" data-close data-i18n-aria="close" aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button>
    </div>
    <div class="modal-body">
      <p class="modal-lead" data-i18n="layersLead"></p>
      <div class="layer-actions">
        <button type="button" class="btn ghost" data-lp="all" data-i18n="lpAll">All</button>
        <button type="button" class="btn ghost" data-lp="none" data-i18n="lpNone">None</button>
        <button type="button" class="btn ghost" data-lp="default" data-i18n="lpDefault">Default</button>
      </div>
      <div id="layersList"></div>
    </div>
  </div>
</div>

<div id="sourcesOverlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sourcesTitle">
  <div class="modal-card wide glass">
    <div class="modal-head">
      <h2 id="sourcesTitle" data-i18n="menuSources">Data sources</h2>
      <button class="icon-btn" data-close data-i18n-aria="close" aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button>
    </div>
    <div class="modal-body">
      <p class="modal-lead" data-i18n="sourcesLead"></p>
      <ul class="src-list" id="sourcesList"></ul>
    </div>
  </div>
</div>

<script>window.GW = <?= json_encode(['version' => GW_VERSION, 'cartoKey' => $gwCartoKey, 'api' => 'api'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="assets/js/i18n.js?v=<?= $v ?>"></script>
<script src="assets/js/app.js?v=<?= $v ?>"></script>
</body>
</html>
