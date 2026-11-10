<?php
/*
 * GlobeWatch — admin panel: database installation, source toggles & health,
 * cache, diagnostics, manual ACLED archive sync (no cron needed on shared hosting).
 */
require_once __DIR__ . '/api/schema.php';
gw_admin_session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$LANG = gw_lang();
$STR = [
  'en' => [
    'title' => 'Admin', 'restricted' => 'Restricted area. Sign in with the admin password set in api/config.php.', 'password' => 'Password', 'enter' => 'Sign in',
    'wrong' => 'Wrong password.', 'locked' => 'Too many attempts. Try again in %d minutes.', 'nopw' => 'ADMIN_PANEL_PASSWORD is not set in api/config.php.', 'logout' => 'Sign out',
    'sub' => 'Database, data sources, cache and ACLED archive', 'open_app' => 'Open the map',
    'inst_title' => 'Database not installed', 'inst_text' => 'Some GlobeWatch tables are missing. The installer runs sql/schema.sql: it only creates what is missing and never overwrites existing data.',
    'inst_btn' => 'Install database', 'inst_done' => 'Database installed.', 'missing' => 'Missing tables',
    'sources' => 'Data sources', 'sources_sub' => 'Switch a source off without touching the code. ACLED starts off: its API requires a paid plan.',
    'th_source' => 'Source', 'th_use' => 'Used for', 'th_health' => 'Last result', 'th_state' => 'Enabled',
    'clear' => 'Clear cached data', 'diag' => 'Run diagnostics', 'maintenance' => 'Maintenance',
    'db_mb' => 'MB used by the database', 'arc_rows' => 'archived events', 'years' => 'years of history configured',
    'seed' => '1 · Build the country list', 'sync' => '2 · Sync everything', 'stop' => 'Stop',
    'th_conflict' => 'Conflict', 'th_country' => 'Country', 'th_until' => 'Synced back to', 'th_events' => 'Events', 'th_status' => 'Status',
    'loading' => 'Loading…', 'no_scopes' => 'No scopes yet — press "Build the country list".', 'not_started' => 'not started',
    'done' => 'completed', 'in_progress' => 'in progress', 'reset' => 'Reset', 'on' => 'on', 'off' => 'off',
    'ok_ago' => 'OK', 'err' => 'error', 'never' => 'no calls yet', 'on_demand' => 'on demand — queried when a conflict is opened', 'unconfigured' => 'not configured', 'backoff' => 'paused (rate limit)',
    'confirm_reset' => 'Delete the archive for %s and start over?', 'cache_cleared' => 'Cache cleared: %d rows removed.',
    'seeded' => '%d new scopes created.', 'sync_start' => 'Sync started…', 'sync_none' => 'No scopes: build the country list first.',
    'sync_done' => 'Everything synced ✓', 'sync_stopped' => 'Stopped — progress is saved, resume whenever you want.',
    'sync_abort' => 'Too many consecutive errors: sync stopped. Check ACLED in the diagnostics.', 'toggled_on' => '%s enabled', 'toggled_off' => '%s disabled',
    'archive' => 'Historical archive', 'archive_sub' => 'ACLED events older than one year, downloaded in 90-day windows (needs an ACLED plan with API access).',
    'activity' => 'Activity', 'req_failed' => 'Request failed',
  ],
  'it' => [
    'title' => 'Amministrazione', 'restricted' => 'Area riservata. Accedi con la password admin impostata in api/config.php.', 'password' => 'Password', 'enter' => 'Entra',
    'wrong' => 'Password errata.', 'locked' => 'Troppi tentativi. Riprova tra %d minuti.', 'nopw' => 'ADMIN_PANEL_PASSWORD non è impostata in api/config.php.', 'logout' => 'Esci',
    'sub' => 'Database, fonti dati, cache e archivio ACLED', 'open_app' => 'Apri la mappa',
    'inst_title' => 'Database non installato', 'inst_text' => 'Mancano alcune tabelle di GlobeWatch. L\'installazione esegue sql/schema.sql: crea solo ciò che manca e non sovrascrive mai i dati esistenti.',
    'inst_btn' => 'Installa database', 'inst_done' => 'Database installato.', 'missing' => 'Tabelle mancanti',
    'sources' => 'Fonti dati', 'sources_sub' => 'Disattiva una fonte senza toccare il codice. ACLED parte spenta: la sua API richiede un piano a pagamento.',
    'th_source' => 'Fonte', 'th_use' => 'Usata per', 'th_health' => 'Ultimo esito', 'th_state' => 'Attiva',
    'clear' => 'Svuota la cache', 'diag' => 'Esegui diagnostica', 'maintenance' => 'Manutenzione',
    'db_mb' => 'MB usati dal database', 'arc_rows' => 'eventi archiviati', 'years' => 'anni di storico configurati',
    'seed' => '1 · Crea l\'elenco dei paesi', 'sync' => '2 · Sincronizza tutto', 'stop' => 'Ferma',
    'th_conflict' => 'Conflitto', 'th_country' => 'Paese', 'th_until' => 'Sincronizzato fino a', 'th_events' => 'Eventi', 'th_status' => 'Stato',
    'loading' => 'Caricamento…', 'no_scopes' => 'Nessuno scope — premi "Crea l\'elenco dei paesi".', 'not_started' => 'non iniziato',
    'done' => 'completato', 'in_progress' => 'in corso', 'reset' => 'Reset', 'on' => 'attiva', 'off' => 'spenta',
    'ok_ago' => 'OK', 'err' => 'errore', 'never' => 'nessuna chiamata', 'on_demand' => 'su richiesta — interrogata quando si apre un conflitto', 'unconfigured' => 'non configurata', 'backoff' => 'in pausa (limite)',
    'confirm_reset' => 'Cancellare l\'archivio per %s e ricominciare?', 'cache_cleared' => 'Cache svuotata: %d righe rimosse.',
    'seeded' => 'Creati %d nuovi scope.', 'sync_start' => 'Avvio sincronizzazione…', 'sync_none' => 'Nessuno scope: crea prima l\'elenco dei paesi.',
    'sync_done' => 'Tutto sincronizzato ✓', 'sync_stopped' => 'Fermato — il progresso è salvato, riprendi quando vuoi.',
    'sync_abort' => 'Troppi errori consecutivi: sincronizzazione fermata. Controlla ACLED nella diagnostica.', 'toggled_on' => '%s attivata', 'toggled_off' => '%s disattivata',
    'archive' => 'Archivio storico', 'archive_sub' => 'Eventi ACLED più vecchi di un anno, scaricati a finestre di 90 giorni (richiede un piano ACLED con accesso API).',
    'activity' => 'Attività', 'req_failed' => 'Richiesta fallita',
  ],
];
$t = fn(string $k) => $STR[$LANG][$k] ?? $k;
$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/* ---------------- Login (rate-limited per IP) / logout (POST + CSRF) ---------------- */
const GW_LOGIN_MAX_FAILS = 5;
const GW_LOGIN_LOCK_SECONDS = 900;
$ipKey = 'login_fail:' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$loginState = function () use ($ipKey): array { try { return kvGet($ipKey) ?: ['n' => 0, 'until' => 0]; } catch (Throwable $e) { return ['n' => 0, 'until' => 0]; } };

if (isset($_POST['password'])) {
    $ls = $loginState();
    if (ADMIN_PANEL_PASSWORD === '') {
        $loginError = $t('nopw');
    } elseif (($ls['until'] ?? 0) > time()) {
        $loginError = sprintf($t('locked'), (int)ceil(($ls['until'] - time()) / 60));
    } elseif (hash_equals((string)ADMIN_PANEL_PASSWORD, (string)$_POST['password'])) {
        try { kvSet($ipKey, ['n' => 0, 'until' => 0], 60); } catch (Throwable $e) {}
        session_regenerate_id(true);
        $_SESSION['gw_admin'] = true;
        header('Location: admin.php'); exit;
    } else {
        sleep(1);
        $n = (int)($ls['n'] ?? 0) + 1;
        try { kvSet($ipKey, ['n' => $n, 'until' => $n >= GW_LOGIN_MAX_FAILS ? time() + GW_LOGIN_LOCK_SECONDS : 0], GW_LOGIN_LOCK_SECONDS); } catch (Throwable $e) {}
        $loginError = $n >= GW_LOGIN_MAX_FAILS ? sprintf($t('locked'), (int)(GW_LOGIN_LOCK_SECONDS / 60)) : $t('wrong');
    }
}
if (isset($_POST['logout']) && !empty($_SESSION['gw_admin']) && hash_equals(gw_csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    $_SESSION = []; session_destroy(); header('Location: admin.php'); exit;
}
$authed = !empty($_SESSION['gw_admin']);

/* ---------------- JSON actions (authenticated; state-changing ones need POST + CSRF) ---------------- */
if ($authed && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $action = (string)$_GET['action'];
    if ($action !== 'status' && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(gw_csrf_token(), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')))) {
        http_response_code(403); echo json_encode(['error' => 'CSRF check failed']); exit;
    }
    session_write_close();   // long sync steps must not block other admin requests
    $pdo = db();
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

    if ($action === 'install') { echo json_encode(gw_install_schema()); exit; }

    if ($action === 'status') {
        $schema = gw_schema_status();
        $resp = ['schema' => $schema, 'history_years' => HISTORY_YEARS_BACK, 'now' => time()];
        try {
            $resp['db_size_mb'] = (float)$pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
        } catch (Throwable $e) { $resp['db_size_mb'] = null; }
        if ($schema['ok']) {
            $resp['scopes'] = $pdo->query("SELECT s.*, COALESCE(NULLIF(c.name_en, ''), c.name) AS conflict_name FROM globewatch_sync_state s
                LEFT JOIN globewatch_conflicts c ON c.id = s.conflict_id ORDER BY conflict_name, s.country")->fetchAll();
            $resp['archived_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM globewatch_archived_events")->fetchColumn();
            $resp['sources'] = sourceStatusAll();
        }
        echo json_encode($resp); exit;
    }

    if ($action === 'seed') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO globewatch_sync_state (scope, conflict_id, country) VALUES (?, ?, ?)");
        $created = 0;
        foreach ($pdo->query("SELECT id, countries FROM globewatch_conflicts WHERE active = 1")->fetchAll() as $c) {
            foreach (array_filter(array_map('trim', explode(',', $c['countries'] ?? ''))) as $country) {
                $stmt->execute(['acled:' . $c['id'] . ':' . $country, $c['id'], $country]);
                $created += $stmt->rowCount();
            }
        }
        echo json_encode(['created' => $created]); exit;
    }

    if ($action === 'step') {
        $stmt = $pdo->prepare("SELECT * FROM globewatch_sync_state WHERE scope = ?");
        $stmt->execute([(string)($in['scope'] ?? '')]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['error' => 'unknown scope']); exit; }
        if ($row['done']) { echo json_encode(['scope' => $row['scope'], 'done' => true, 'inserted' => 0]); exit; }
        $token = acledGetToken();
        if (!$token) { echo json_encode(['error' => 'ACLED authentication failed / ACLED disabled']); exit; }
        echo json_encode(syncArchiveStep($token, $row, (int)HISTORY_YEARS_BACK)); exit;
    }

    if ($action === 'reset') {
        $scope = (string)($in['scope'] ?? '');
        $stmt = $pdo->prepare("SELECT conflict_id, country FROM globewatch_sync_state WHERE scope = ?");
        $stmt->execute([$scope]);
        if ($row = $stmt->fetch()) {
            $pdo->prepare("DELETE FROM globewatch_archived_events WHERE conflict_id <=> ? AND country = ?")->execute([$row['conflict_id'], $row['country']]);
            $pdo->prepare("UPDATE globewatch_sync_state SET synced_until = NULL, done = 0, rows_synced = 0 WHERE scope = ?")->execute([$scope]);
        }
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'clear_cache') {
        // keeps kv: rows (rate-limit clocks, tokens, health): wiping them would make GDELT hit its limit again
        $n = $pdo->exec("DELETE FROM globewatch_api_cache WHERE cache_key NOT LIKE 'kv:%'");
        echo json_encode(['ok' => true, 'cleared' => (int)$n]); exit;
    }

    if ($action === 'toggle_source') {
        $source = (string)($in['source'] ?? '');
        if (!in_array($source, GW_SOURCES, true)) { http_response_code(400); echo json_encode(['error' => 'unknown source']); exit; }
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $pdo->prepare("INSERT INTO globewatch_source_toggles (source, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)")
            ->execute([$source, $enabled]);
        echo json_encode(['ok' => true, 'source' => $source, 'enabled' => (bool)$enabled]); exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']); exit;
}
$csrf = $authed ? gw_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="<?= $LANG ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#07090D">
<title>GlobeWatch · <?= $h($t('title')) ?></title>
<link rel="icon" href="assets/icons/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin.css?v=<?= GW_VERSION ?>">
</head>
<body>
<div class="wrap">
  <header class="top">
    <a class="brand" href="./">
      <span class="brand-mark" aria-hidden="true"><img src="assets/icons/icon-192.png" alt="" width="34" height="34"></span>
      <span><b>GlobeWatch</b><small><?= $h($t('title')) ?> · v<?= GW_VERSION ?></small></span>
    </a>
    <div class="top-actions">
      <nav class="lang" aria-label="Language">
        <a href="?lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>" data-lang="en">EN</a><a href="?lang=it" class="<?= $LANG === 'it' ? 'on' : '' ?>" data-lang="it">IT</a>
      </nav>
      <?php if ($authed): ?>
      <form method="post" action="admin.php" class="inline"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button class="btn ghost sm" name="logout" value="1"><?= $h($t('logout')) ?></button></form>
      <?php endif; ?>
    </div>
  </header>

<?php if (!$authed): ?>
  <main class="login">
    <div class="card">
      <h1><?= $h($t('title')) ?></h1>
      <p class="muted"><?= $h($t('restricted')) ?></p>
      <form method="post" action="admin.php" class="login-form">
        <input type="password" name="password" placeholder="<?= $h($t('password')) ?>" autofocus autocomplete="current-password" required>
        <button type="submit" class="btn"><?= $h($t('enter')) ?></button>
      </form>
      <?php if (!empty($loginError)): ?><div class="alert"><?= $h($loginError) ?></div><?php endif; ?>
    </div>
  </main>
<?php else: ?>
  <main>
    <div class="page-head">
      <div><h1><?= $h($t('title')) ?></h1><p class="muted"><?= $h($t('sub')) ?></p></div>
      <a class="btn ghost" href="./"><?= $h($t('open_app')) ?> →</a>
    </div>

    <section class="card warn" id="instCard" hidden>
      <h2><?= $h($t('inst_title')) ?></h2>
      <p class="muted"><?= $h($t('inst_text')) ?></p>
      <p class="mono small" id="instMissing"></p>
      <button class="btn" id="instBtn"><?= $h($t('inst_btn')) ?></button>
    </section>

    <div class="stats" id="statsRow">
      <div class="stat"><b id="statDbSize">—</b><span><?= $h($t('db_mb')) ?></span></div>
      <div class="stat"><b id="statRows">—</b><span><?= $h($t('arc_rows')) ?></span></div>
      <div class="stat"><b id="statYears">—</b><span><?= $h($t('years')) ?></span></div>
    </div>

    <section class="card" id="sourcesCard" hidden>
      <div class="card-head">
        <div><h2><?= $h($t('sources')) ?></h2><p class="muted"><?= $h($t('sources_sub')) ?></p></div>
        <div class="row">
          <button id="clearCacheBtn" class="btn ghost sm"><?= $h($t('clear')) ?></button>
          <a class="btn ghost sm" href="api/diagnostics.php?lang=<?= $LANG ?>" target="_blank" rel="noopener"><?= $h($t('diag')) ?> ↗</a>
        </div>
      </div>
      <div class="tablewrap"><table>
        <thead><tr><th><?= $h($t('th_source')) ?></th><th><?= $h($t('th_use')) ?></th><th><?= $h($t('th_health')) ?></th><th class="right"><?= $h($t('th_state')) ?></th></tr></thead>
        <tbody id="sourceBody"><tr><td colspan="4" class="muted"><?= $h($t('loading')) ?></td></tr></tbody>
      </table></div>
    </section>

    <section class="card" id="archiveCard" hidden>
      <div class="card-head">
        <div><h2><?= $h($t('archive')) ?></h2><p class="muted"><?= $h($t('archive_sub')) ?></p></div>
        <div class="row">
          <button id="seedBtn" class="btn ghost sm"><?= $h($t('seed')) ?></button>
          <button id="syncAllBtn" class="btn sm"><?= $h($t('sync')) ?></button>
          <button id="stopBtn" class="btn ghost sm" disabled><?= $h($t('stop')) ?></button>
        </div>
      </div>
      <div class="tablewrap"><table>
        <thead><tr><th><?= $h($t('th_conflict')) ?></th><th><?= $h($t('th_country')) ?></th><th><?= $h($t('th_until')) ?></th><th><?= $h($t('th_events')) ?></th><th><?= $h($t('th_status')) ?></th><th></th></tr></thead>
        <tbody id="scopeBody"><tr><td colspan="6" class="muted"><?= $h($t('loading')) ?></td></tr></tbody>
      </table></div>
    </section>

    <section class="card">
      <h2><?= $h($t('activity')) ?></h2>
      <div id="log" class="log" aria-live="polite"></div>
    </section>
  </main>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const S = <?= json_encode($STR[$LANG], JSON_UNESCAPED_UNICODE) ?>;
const LANG = <?= json_encode($LANG) ?>;
const SOURCES = {
  gdelt: ['GDELT', 'media coverage volume per conflict', 'volume di copertura mediatica per conflitto'],
  hapi: ['HDX HAPI (OCHA)', 'monthly ACLED aggregates: conflict intensity, unrest, violence against civilians', 'aggregati mensili ACLED: intensità conflitti, disordini, violenza sui civili'],
  acled: ['ACLED', 'live event-level data (paid plan)', 'dati per singolo evento live (piano a pagamento)'],
  gdacs: ['GDACS', 'natural disasters of international relevance', 'disastri naturali di rilievo internazionale'],
  usgs: ['USGS', 'earthquakes (real-time feeds)', 'terremoti (feed in tempo reale)'],
  emsc: ['EMSC', 'earthquakes, deduplicated with USGS', 'terremoti, senza doppioni USGS'],
  firms: ['NASA FIRMS', 'satellite fire hotspots', 'focolai rilevati via satellite'],
  eonet: ['NASA EONET', 'curated natural events', 'eventi naturali curati'],
  nhc: ['NOAA NHC', 'active hurricanes and tropical storms', 'uragani e tempeste tropicali attivi'],
  tsunami: ['NOAA Tsunami', 'tsunami warnings and statements', 'allerte e comunicati tsunami'],
  meteoalarm: ['Meteoalarm', 'orange/red weather warnings in Europe', 'allerte meteo arancioni/rosse in Europa'],
  nws: ['NOAA NWS', 'US severe/extreme weather alerts', 'allerte meteo gravi/estreme USA'],
  cap: ['CAP alerts', 'official national CAP feeds', 'feed CAP nazionali ufficiali'],
  gvp: ['Smithsonian GVP', 'weekly volcanic activity', 'attività vulcanica settimanale'],
  hans: ['USGS HANS', 'US volcanoes above normal level', 'vulcani USA sopra il livello normale'],
  locust: ['FAO Desert Locust', 'locust swarms and bands', 'sciami e bande di locuste'],
  cems: ['Copernicus EMS', 'EU satellite rapid-mapping activations', 'attivazioni di mappatura rapida UE'],
  reliefweb_disasters: ['ReliefWeb disasters', 'ongoing disasters and emergencies', 'disastri ed emergenze in corso'],
  reliefweb: ['ReliefWeb reports', 'related reports (conflict detail)', 'report correlati (dettaglio conflitto)'],
  who_don: ['WHO DON', 'official outbreak notices', 'notizie ufficiali di focolai'],
  ifrc: ['IFRC GO', 'Red Cross/Red Crescent operations', 'operazioni Croce Rossa/Mezzaluna Rossa'],
  acaps: ['ACAPS', 'INFORM Severity of crises (free account)', 'INFORM Severity delle crisi (account gratuito)'],
  inform: ['INFORM Risk', 'national risk index (conflict detail)', 'indice di rischio nazionale (dettaglio conflitto)'],
  hapi_context: ['HAPI context', 'hunger, displacement, funding (conflict detail)', 'fame, sfollati, fondi (dettaglio conflitto)'],
  ioda: ['IODA', 'internet outages by country', 'interruzioni di internet per paese'],
  cfradar: ['Cloudflare Radar', 'internet outages with cause (token)', 'interruzioni internet con causa (token)'],
  idmc: ['IDMC', 'new internal displacements (client id)', 'nuovi sfollamenti interni (client id)'],
  ucdp: ['UCDP', 'yearly verified fatalities (conflict detail)', 'vittime annuali verificate (dettaglio conflitto)'],
  ucdp_candidate: ['UCDP Candidate', 'monthly geolocated clashes (UCDP_TOKEN)', 'scontri mensili geolocalizzati (UCDP_TOKEN)'],
  alerts_ua: ['alerts.in.ua', 'Ukraine air-raid alerts (token)', 'allerte aeree Ucraina (token)'],
  asam: ['NGA ASAM', 'attacks on ships, piracy', 'attacchi alle navi, pirateria'],
  swpc: ['NOAA SWPC', 'space weather badge', 'badge meteo spaziale'],
};
const ON_DEMAND = ['ucdp', 'reliefweb', 'inform', 'hapi_context'];
let stopRequested = false;
const $ = id => document.getElementById(id);
document.querySelectorAll('.lang a').forEach(a => a.addEventListener('click', () => {
  document.cookie = 'gw_lang=' + a.dataset.lang + ';path=/;max-age=31536000;samesite=lax';
}));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = (k, v) => (S[k] || k).replace(/%[sd]/, v);
const nf = new Intl.NumberFormat(LANG === 'it' ? 'it-IT' : 'en-GB');
function ago(ts, now){ if (!ts) return ''; const s = Math.max(0, now - ts); return s < 90 ? s + 's' : s < 5400 ? Math.round(s/60) + 'm' : s < 172800 ? Math.round(s/3600) + 'h' : Math.round(s/86400) + 'd'; }
function log(msg, kind){ const el = $('log'), d = document.createElement('div');
  d.className = kind || ''; d.textContent = `${new Date().toLocaleTimeString(LANG === 'it' ? 'it-IT' : 'en-GB')}  ${msg}`;
  el.prepend(d); }
async function api(action, body){
  const res = await fetch('admin.php?action=' + action, body === undefined ? {} : {
    method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF}, body: JSON.stringify(body)});
  if (res.status === 403 || res.redirected) { location.reload(); throw new Error('session expired'); }
  try { return await res.json(); } catch(e) { throw new Error(S.req_failed + ' (HTTP ' + res.status + ')'); }
}

async function refreshStatus(){
  let data;
  try { data = await api('status'); } catch(e) { log(e.message, 'err'); return []; }
  const sc = data.schema || {};
  $('instCard').hidden = !!sc.ok;
  $('instMissing').textContent = sc.ok ? '' : S.missing + ': ' + (sc.missing || []).join(', ');
  $('sourcesCard').hidden = $('archiveCard').hidden = !sc.ok;
  $('statDbSize').textContent = data.db_size_mb ?? '—';
  $('statYears').textContent = data.history_years;
  if (!sc.ok) return [];
  $('statRows').textContent = nf.format(data.archived_rows || 0);
  renderSources(data.sources || {}, data.now);
  const scopes = data.scopes || [];
  $('scopeBody').innerHTML = scopes.length ? scopes.map(s => `<tr>
      <td>${esc(s.conflict_name || '—')}</td><td>${esc(s.country)}</td><td class="mono">${esc(s.synced_until || S.not_started)}</td>
      <td class="mono">${esc(nf.format(s.rows_synced))}</td>
      <td><span class="badge ${s.done == 1 ? 'b-ok' : 'b-warn'}">${esc(s.done == 1 ? S.done : S.in_progress)}</span></td>
      <td class="right"><button class="btn danger xs" data-reset="${esc(s.scope)}">${esc(S.reset)}</button></td></tr>`).join('')
    : `<tr><td colspan="6" class="muted">${esc(S.no_scopes)}</td></tr>`;
  return scopes;
}

function renderSources(st, now){
  $('sourceBody').innerHTML = Object.keys(SOURCES).map(src => {
    const s = st[src] || {state: 'unknown', enabled: true}, [name, en, it] = SOURCES[src];
    const badge = {ok: ['b-ok', S.ok_ago + ' · ' + ago(s.ok_at, now)], error: ['b-err', S.err + ' · ' + ago(s.err_at, now)],
      unconfigured: ['b-off', S.unconfigured], disabled: ['b-off', S.off], backoff: ['b-warn', S.backoff],
      unknown: ['b-off', ON_DEMAND.includes(src) ? S.on_demand : S.never]}[s.state] || ['b-off', s.state];
    const errLine = s.error && s.state !== 'ok' ? `<div class="errtxt">${esc(s.error)}</div>` : '';
    return `<tr><td class="strong">${esc(name)}</td><td class="muted">${esc(LANG === 'it' ? it : en)}</td>
      <td><span class="badge ${badge[0]}">${esc(badge[1])}</span>${errLine}</td>
      <td class="right"><label class="switch"><input type="checkbox" data-source="${src}" ${s.enabled ? 'checked' : ''}><span></span></label></td></tr>`;
  }).join('');
}

$('sourceBody').addEventListener('change', async e => {
  const cb = e.target.closest('input[data-source]'); if (!cb) return;
  try { await api('toggle_source', {source: cb.dataset.source, enabled: cb.checked});
    log(fmt(cb.checked ? 'toggled_on' : 'toggled_off', SOURCES[cb.dataset.source][0])); }
  catch(err) { log(err.message, 'err'); }
  refreshStatus();
});
$('scopeBody').addEventListener('click', async e => {
  const b = e.target.closest('button[data-reset]'); if (!b) return;
  if (!confirm(fmt('confirm_reset', b.dataset.reset))) return;
  try { await api('reset', {scope: b.dataset.reset}); log('Reset: ' + b.dataset.reset); } catch(err) { log(err.message, 'err'); }
  refreshStatus();
});
$('instBtn').addEventListener('click', async () => {
  const btn = $('instBtn'); btn.disabled = true;
  try { const r = await api('install', {}); (r.log || []).forEach(l => log(l)); if (r.ok) log(S.inst_done, 'ok'); }
  catch(err) { log(err.message, 'err'); }
  btn.disabled = false; refreshStatus();
});
$('clearCacheBtn').addEventListener('click', async () => {
  try { const d = await api('clear_cache', {}); log(fmt('cache_cleared', d.cleared), 'ok'); } catch(err) { log(err.message, 'err'); }
});
$('seedBtn').addEventListener('click', async () => {
  try { const d = await api('seed', {}); log(fmt('seeded', d.created)); } catch(err) { log(err.message, 'err'); }
  refreshStatus();
});
$('stopBtn').addEventListener('click', () => { stopRequested = true; });
$('syncAllBtn').addEventListener('click', async () => {
  stopRequested = false;
  const syncBtn = $('syncAllBtn'), stopBtn = $('stopBtn');
  syncBtn.disabled = true; stopBtn.disabled = false;
  log(S.sync_start);
  let scopes = await refreshStatus(), consecutiveErrors = 0;
  if (!scopes.length) log(S.sync_none);
  while (scopes.length && !stopRequested) {
    const pending = scopes.filter(s => s.done != 1);
    if (!pending.length) { log(S.sync_done, 'ok'); break; }
    let progressed = false;
    for (const s of pending) {
      if (stopRequested) break;
      let data;
      try { data = await api('step', {scope: s.scope}); } catch (e) { data = {error: e.message}; }
      if (data.error) {
        log(`${s.scope}: ${data.error}`, 'err');
        if (++consecutiveErrors >= 3) { log(S.sync_abort, 'err'); stopRequested = true; break; }
        continue;
      }
      consecutiveErrors = 0; progressed = true;
      log(`${s.scope}: +${data.inserted}${data.window ? ' (' + data.window[0] + ' → ' + data.window[1] + ')' : ''}${data.done ? ' ✓' : ''}`);
      await new Promise(r => setTimeout(r, 400));   // polite pause towards ACLED
    }
    if (!progressed) break;
    scopes = await refreshStatus();
  }
  if (stopRequested) log(S.sync_stopped);
  syncBtn.disabled = false; stopBtn.disabled = true;
});
refreshStatus();
</script>
<?php endif; ?>
</div>
</body>
</html>
