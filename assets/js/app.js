/* =====================================================================
   GlobeWatch — front end application
   Depends on: Leaflet 1.9, i18n.js (I18N, SOURCES, CATEGORY_SOURCES, CHANGELOG), window.GW
   ===================================================================== */
'use strict';
(() => {
const CFG = window.GW || {};
const GW_VERSION = CFG.version || '';
const API_BASE = CFG.api || 'api';
const AUTO_REFRESH_MS = 5 * 60 * 1000;    // while the tab is visible
const PENDING_POLL_MS = 15000;            // sources still warming up on the server
const PENDING_POLL_MAX = 4;
const REQUEST_TIMEOUT_MS = 45000;         // first requests can wait for slow upstreams
const LIST_GROUP_LIMIT = 150;             // rows per group before "show more"
const BANNER_EVERY_MS = 5 * 60 * 1000;

const $ = id => document.getElementById(id);
const store = {
  get(k, d = null){ try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch(e){ return d; } },
  set(k, v){ try { localStorage.setItem(k, JSON.stringify(v)); } catch(e){} },
};

/* ============================== i18n ============================== */
function readCookie(name){ const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : null; }
function initialLang(){
  const q = new URLSearchParams(location.search).get('lang');
  let saved = null; try { saved = localStorage.getItem('gw_lang'); } catch(e){}
  const l = (q || saved || readCookie('gw_lang') || document.documentElement.lang || 'en').toLowerCase();
  return I18N[l] ? l : 'en';
}
let LANG = initialLang();
const locale = () => LANG === 'it' ? 'it-IT' : 'en-GB';
const t = (key, ...args) => { const v = I18N[LANG][key] ?? I18N.en[key]; return typeof v === 'function' ? v(...args) : (v ?? key); };
let NF = new Intl.NumberFormat(locale());
const fmtN = n => (n === null || n === undefined || n === '' || Number.isNaN(Number(n))) ? null : NF.format(n);
const big = n => n == null ? null : (n >= 1e6 ? (n / 1e6).toLocaleString(locale(), {maximumFractionDigits:1}) + ' M' : fmtN(n));
function fmtDate(iso){
  if (!iso) return '';
  const d = new Date(iso.length <= 10 ? iso + 'T00:00:00Z' : iso);
  return isNaN(d) ? iso : d.toLocaleDateString(locale(), {day:'numeric', month:'short', year:'numeric', timeZone:'UTC'});
}
function fmtMonth(ym){
  if (!ym) return '';
  const d = new Date(String(ym).slice(0, 7) + '-01T00:00:00Z');
  return isNaN(d) ? ym : d.toLocaleDateString(locale(), {month:'short', year:'numeric', timeZone:'UTC'});
}
function relTime(tsMs){
  const s = Math.max(0, Math.floor((Date.now() - tsMs) / 1000));
  if (s < 5) return t('justNow');
  if (s < 60) return t('secsAgo', s);
  if (s < 3600) return t('minsAgo', Math.floor(s / 60));
  if (s < 86400) return t('hoursAgo', Math.floor(s / 3600));
  return t('daysAgo', Math.floor(s / 86400));
}

/* ============================== safety helpers ============================== */
const ESC = {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'};
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ESC[c]);
function safeUrl(u){
  if (!u) return null;
  try { const x = new URL(u, location.href); return (x.protocol === 'https:' || x.protocol === 'http:') ? x.href : null; } catch(e){ return null; }
}
const icon = (id, cls = 'i') => `<svg class="${cls}" aria-hidden="true"><use href="#i-${id}"/></svg>`;

/* ============================== palette & layers ============================== */
const SEV_COLOR = {high:'#F0524A', medium:'#F2A93B', low:'#4C8DF6'};
const CAT_COLOR = {conflict:'#FF7A45', unrest:'#A78BFA', atrocity:'#F472B6', disaster:'#2DD4BF', emergency:'#A3E635'};
const NEUTRAL = '#8992A0';
const ALERT_TO_SEV = {red:'high', orange:'medium', green:'low'};
const SEV_ORDER = {high:0, medium:1, low:2};
const ZONE_RADIUS = {high:9.5, medium:7.5, low:6};
const PT_RADIUS = {high:7.5, medium:6, low:4.5};
const GROUPS = [
  ['conflict', 'groupConflict', SEV_COLOR.high], ['conflict_ev', 'groupConflictEv', CAT_COLOR.conflict],
  ['unrest', 'groupUnrest', CAT_COLOR.unrest], ['atrocity', 'groupAtrocity', CAT_COLOR.atrocity],
  ['disaster', 'groupDisaster', CAT_COLOR.disaster], ['emergency', 'groupEmergency', CAT_COLOR.emergency],
];
/* A point belongs to the first layer whose test matches. */
const LAYERS = [
  {id:'zones', g:'lgSecurity', color:SEV_COLOR.high},
  {id:'clashes', g:'lgSecurity', color:CAT_COLOR.conflict, t:p => p.source === 'ucdp_candidate' && p.category === 'conflict'},
  {id:'air_alerts', g:'lgSecurity', color:CAT_COLOR.conflict, t:p => p.source === 'alerts_ua'},
  {id:'maritime', g:'lgSecurity', color:CAT_COLOR.conflict, t:p => p.source === 'asam'},
  {id:'unrest', g:'lgSecurity', color:CAT_COLOR.unrest, t:p => p.category === 'unrest'},
  {id:'civilians', g:'lgSecurity', color:CAT_COLOR.atrocity, t:p => p.category === 'atrocity'},
  {id:'quakes', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'earthquake' || p.subtype === 'tsunami'},
  {id:'storms', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'tropical_cyclone' || p.subtype === 'severe_storm'},
  {id:'warnings', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'weather_warning' || p.subtype === 'temperature_extreme'},
  {id:'wildfires', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'wildfire'},
  {id:'fires', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'fire_hotspot', off:true},
  {id:'volcanoes', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'volcano'},
  {id:'floods', g:'lgHazards', color:CAT_COLOR.disaster, t:p => ['flood', 'drought', 'landslide'].includes(p.subtype)},
  {id:'locusts', g:'lgHazards', color:CAT_COLOR.disaster, t:p => p.subtype === 'insect_infestation'},
  {id:'health', g:'lgHuman', color:CAT_COLOR.emergency, t:p => p.subtype === 'epidemic'},
  {id:'displacement', g:'lgHuman', color:CAT_COLOR.emergency, t:p => p.subtype === 'displacement' || p.subtype === 'food_insecurity'},
  {id:'crises', g:'lgHuman', color:CAT_COLOR.emergency, t:p => p.subtype === 'humanitarian_crisis' || p.subtype === 'complex_emergency'},
  {id:'connectivity', g:'lgHuman', color:CAT_COLOR.emergency, t:p => p.subtype === 'internet_outage'},
  {id:'other', g:'lgHuman', color:NEUTRAL, t:() => true},
];
const layerOf = p => (p._layer ??= (LAYERS.find(l => l.t && l.t(p)) || LAYERS[LAYERS.length - 1]).id);
const defaultLayers = () => Object.fromEntries(LAYERS.map(l => [l.id, !l.off]));
let layerOn = {...defaultLayers(), ...(store.get('gw_layers', {}) || {})};

/* ============================== state ============================== */
let CONFLICTS = [], POINTS = [];
let mode = 'connecting';              // live | partial | error | connecting
let category = 'all', activeSev = 'all', searchTerm = '';
let lastLoad = null;                  // {at, sources, pending}
let serverStatus = null;              // api/status.php
let pendingPolls = 0, pendingTimer = null, loadSeq = 0, loading = false, lastBannerAt = 0;
let expandedGroups = {};
let currentDetail = null;             // {kind, id, render(), reload?()}

const periodSel = $('periodSelect');
const days = () => Number(periodSel.value);
const isMobile = () => window.matchMedia('(max-width: 719px)').matches;

/* ============================== severity & texts ============================== */
function pointSev(p){
  if (p._sev) return p._sev;
  if (p.severity_level && ALERT_TO_SEV[p.severity_level]) return (p._sev = ALERT_TO_SEV[p.severity_level]);
  const f = p.fatalities ?? 0;
  return (p._sev = f >= 10 ? 'high' : (f >= 1 ? 'medium' : 'low'));
}
const sevVisible = s => activeSev === 'all' || s === activeSev;
const cName = c => (c.i18n?.[LANG] || c.i18n?.en || {}).name || '';
const cField = (c, f) => (c.i18n?.[LANG] || c.i18n?.en || {})[f] || '';
const subLabel = code => (I18N[LANG].sub[code] || I18N.en.sub[code] || '');
const catLabel = p => t(p.category === 'conflict' ? 'catconflict_ev' : 'cat' + p.category);
const srcName = s => (SOURCES[s] || {}).name || s;

function pointTitle(p){
  const x = p.extra || {};
  switch (p.source){
    case 'usgs': case 'emsc': return `M${x.mag ?? '?'} — ${p.title || subLabel('earthquake')}`;
    case 'firms': return `${subLabel('fire_hotspot')} · ${fmtN(x.frp)} MW`;
    case 'hapi': return `${subLabel(p.subtype)} — ${p.country || ''}`;
    case 'acled_archive': return `${x.event_type || t('cat' + p.category)}${p.country ? ' — ' + p.country : ''}`;
    case 'nhc': return `${(I18N[LANG].nhcCls || {})[x.classification] || subLabel('tropical_cyclone')} ${p.title || ''}`.trim();
    case 'meteoalarm': return `${subLabel('weather_warning')} — ${p.country || ''}`;
    case 'ioda': case 'cfradar': return `${subLabel('internet_outage')} — ${p.country || ''}`;
    case 'ucdp_candidate': return `${subLabel(p.subtype)} — ${x.where || p.country || ''}`;
    case 'gvp': case 'hans': return `${subLabel('volcano')} ${p.title || ''}`.trim();
    case 'nws': case 'cap': return `${subLabel('weather_warning')} — ${p.title || p.country || ''}`;
    case 'asam': return `${subLabel('maritime_attack')}${p.title ? ' — ' + p.title : ''}`;
    case 'alerts_ua': return `${subLabel('air_raid')} — ${p.title || ''}`;
    case 'idmc': return `${subLabel('displacement')} — ${p.title || p.country || ''}`;
    case 'locust': return `${subLabel('insect_infestation')}${p.country ? ' — ' + p.country : ''}`;
  }
  if (p.title) return p.title;
  return [subLabel(p.subtype) || t('cat' + p.category), p.country].filter(Boolean).join(' — ');
}
function pointSubline(p){
  const bits = [];
  if (p.source === 'hapi') bits.push(`${fmtMonth(p.extra?.period_start)} – ${fmtMonth(p.extra?.period_end)}`);
  else if (p.event_date) bits.push(fmtDate(p.event_date));
  if (p.country && p.source !== 'hapi' && !String(pointTitle(p)).includes(p.country)) bits.push(p.country);
  bits.push(srcName(p.source));
  return bits.join(' · ');
}
/* Search index, cached per object and language */
function searchText(o, kind){
  if (o._q && o._ql === LANG) return o._q;
  const s = kind === 'zone'
    ? [cName(o), cField(o, 'region'), cField(o, 'actors'), (o.countries || []).join(' '), o.i18n?.en?.name, o.i18n?.it?.name]
    : [pointTitle(o), o.country, o.subtype_raw, subLabel(o.subtype), o.title, srcName(o.source)];
  o._ql = LANG;
  return (o._q = s.filter(Boolean).join(' ').toLowerCase());
}

/* ============================== map ============================== */
const map = L.map('map', {zoomControl:false, attributionControl:true, worldCopyJump:true, preferCanvas:true,
  zoomSnap:0.5, minZoom:2, maxZoom:12, maxBoundsViscosity:0.8, maxBounds:[[-85, -400], [85, 400]]});
const cartoUrl = style => CFG.cartoKey
  ? `https://basemaps.cartocdn.com/rastertiles/${style}/{z}/{x}/{y}.png?key=${encodeURIComponent(CFG.cartoKey)}`
  : `https://{s}.basemaps.cartocdn.com/${style}/{z}/{x}/{y}{r}.png`;
const cartoAttr = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>';
L.tileLayer(cartoUrl('dark_nolabels'), {attribution:cartoAttr, subdomains:'abcd', maxZoom:19, detectRetina:true}).addTo(map);
L.tileLayer(cartoUrl('dark_only_labels'), {subdomains:'abcd', maxZoom:19, opacity:0.6, detectRetina:true}).addTo(map);
L.control.zoom({position:'bottomright'}).addTo(map);
map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
const svgRenderer = L.svg({padding:0.3});      // conflict zones: few, animated
const pointLayer = L.layerGroup().addTo(map);  // points: many, canvas
const zoneLayer = L.layerGroup().addTo(map);
const selLayer = L.layerGroup().addTo(map);    // selection halo

const sheet = $('sheet');
/** Pixel offset that moves a target into the centre of the part of the map not covered by the panel. */
function panelOffset(){
  const r = sheet.getBoundingClientRect();
  return isMobile() ? L.point(0, r.height / 2) : L.point(-r.right / 2, 0);
}
function flyToVisible(lat, lng, zoom){
  const z = Math.max(map.getZoom(), zoom);
  const target = map.unproject(map.project([lat, lng], z).add(panelOffset()), z);
  map.flyTo(target, z, {duration:0.6, easeLinearity:0.3});
}
function fitWorld(){
  const r = sheet.getBoundingClientRect();
  const opts = isMobile() ? {paddingTopLeft:[0, 60], paddingBottomRight:[0, r.height]} : {paddingTopLeft:[r.right, 0], paddingBottomRight:[0, 0]};
  map.fitBounds([[-45, -150], [65, 160]], {...opts, animate:false});
}

function visibleZones(){
  if (layerOn.zones === false) return [];
  return CONFLICTS.filter(c => sevVisible(c.sev) && (!searchTerm || searchText(c, 'zone').includes(searchTerm)));
}
function visiblePoints(){
  return POINTS.filter(p => p.lat != null && p.lng != null && layerOn[layerOf(p)] !== false && sevVisible(pointSev(p))
    && (!searchTerm || searchText(p, 'point').includes(searchTerm)));
}
const zoomScale = () => { const z = map.getZoom(); return z <= 2 ? 0.75 : z <= 3 ? 0.85 : z <= 4 ? 0.93 : 1; };
let lastScaleKey = null;
map.on('zoomend', () => {
  const k = zoomScale() + (map.getZoom() < 4 ? 'f' : '');
  if (k !== lastScaleKey){ lastScaleKey = k; renderMarkers(visibleZones(), visiblePoints()); }
});
function renderMarkers(zones, points){
  pointLayer.clearLayers(); zoneLayer.clearLayers();
  const k = zoomScale(), far = map.getZoom() < 4;
  // points first so conflict zones stay on top; high severity drawn last (on top)
  [...points].sort((a, b) => SEV_ORDER[pointSev(b)] - SEV_ORDER[pointSev(a)]).forEach(p => {
    const sev = pointSev(p), faint = sev === 'low' && far;
    const m = L.circleMarker([p.lat, p.lng], {radius:PT_RADIUS[sev] * k * (faint ? 0.85 : 1), color:SEV_COLOR[sev],
      weight:faint ? 1 : 1.5, opacity:faint ? 0.45 : 0.85, fillColor:CAT_COLOR[p.category] || NEUTRAL, fillOpacity:faint ? 0.4 : 0.75});
    m.bindTooltip(esc(pointTitle(p)), {direction:'top', offset:[0, -4]});
    m.on('click', () => selectPoint(p.id, false));
    pointLayer.addLayer(m);
  });
  zones.forEach(c => {
    const color = SEV_COLOR[c.sev] || SEV_COLOR.low;
    const m = L.circleMarker([c.lat, c.lng], {renderer:svgRenderer, radius:ZONE_RADIUS[c.sev] * (far ? 0.9 : 1), color, weight:2,
      fillColor:color, fillOpacity:0.8, className:c.sev === 'high' ? 'pulse' : ''});
    m.bindTooltip(`<b>${esc(cName(c))}</b><br><span style="color:#A4ADBA">${esc(t('sevLabel_' + c.sev))}</span>`, {direction:'top', offset:[0, -6]});
    m.on('click', () => selectZone(c.id, false));
    zoneLayer.addLayer(m);
  });
}
function highlight(lat, lng){
  selLayer.clearLayers();
  if (lat == null || lng == null) return;
  L.circleMarker([lat, lng], {radius:15, color:'#ECEFF3', weight:1.5, opacity:0.9, fill:false, interactive:false}).addTo(selLayer);
}

/* ============================== legend, tabs, status ============================== */
function renderLegend(zones, points){
  const cats = new Set(points.map(p => p.category));
  const sw = (c, label, cls = '') => `<span><i class="${cls}" style="--c:${c}"></i>${esc(label)}</span>`;
  let h = '';
  if (zones.length) h += sw(SEV_COLOR.high, t('lgConflict') + ' · ' + t('lgHigh')) + sw(SEV_COLOR.medium, t('lgMedium')) + sw(SEV_COLOR.low, t('lgLow'));
  if (cats.has('conflict')) h += sw(CAT_COLOR.conflict, t('lgConflictEv'));
  ['unrest', 'atrocity', 'disaster', 'emergency'].forEach(c => { if (cats.has(c)) h += sw(CAT_COLOR[c], t('cat' + c)); });
  if (points.length) h += sw('', t('lgRing'), 'ring');
  $('legend').innerHTML = h;
}
function sourceOff(s){ const st = serverStatus?.sources?.[s]?.state; return st === 'disabled' || st === 'unconfigured'; }
function annotateTabs(){
  document.querySelectorAll('#catTabs .tab').forEach(b => {
    const srcs = CATEGORY_SOURCES[b.dataset.cat];
    const off = !!(serverStatus && srcs && srcs.every(sourceOff));
    b.classList.toggle('off', off);
    b.title = off ? t('srcDisabledCat') : '';
    b.setAttribute('aria-selected', b.dataset.cat === category ? 'true' : 'false');
  });
}
function renderStatus(){
  const states = {live:['live', 'stLive'], partial:['partial', 'stPartial'], error:['error', 'stError'], connecting:['', 'stConnecting']};
  const [cls, key] = states[mode] || states.connecting;
  $('statusDot').className = 'status-dot ' + cls;
  $('statusText').textContent = t(key);
  const el = $('lastUpdated');
  if (!lastLoad){ el.textContent = t('notUpdated'); return; }
  const src = Object.values(lastLoad.sources).filter(s => s.state !== 'disabled' && s.state !== 'unconfigured');
  const ok = src.filter(s => s.state === 'ok' || s.state === 'stale').length;
  const pend = lastLoad.pending.length;
  el.innerHTML = t('updated', esc(relTime(lastLoad.at))) + (src.length ? ' · ' + esc(t('srcOk', ok, src.length)) : '')
    + (pend ? ' · ' + esc(t('warming', pend)) : '');
}
let lastClock = '';
function tick(){
  const now = new Date().toISOString().slice(11, 16);
  if (now !== lastClock){ lastClock = now; $('utcClock').textContent = now; }
}
setInterval(tick, 1000);
setInterval(renderStatus, 15000);

/* ============================== loading ============================== */
async function fetchJson(path, params = {}, force = false){
  const qs = new URLSearchParams({...params, lang:LANG});
  if (force) qs.set('refresh', '1');
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT_MS);
  let res;
  try { res = await fetch(`${API_BASE}/${path}?${qs}`, {cache:force ? 'no-store' : 'default', signal:ctrl.signal, headers:{Accept:'application/json'}}); }
  catch(e){ throw new Error(e.name === 'AbortError' ? 'timeout' : t('errOffline')); }
  finally { clearTimeout(timer); }
  let body = null;
  try { body = await res.json(); } catch(e){ throw new Error(`HTTP ${res.status}`); }
  if (!res.ok || body?.ok === false) throw new Error(body?.error || `HTTP ${res.status}`);
  return body;
}
function setLoading(on){
  loading = on;
  $('refreshIcon').classList.toggle('spinning', on);
  $('loadingText').classList.toggle('show', on);
  $('list').setAttribute('aria-busy', on ? 'true' : 'false');
}
async function loadAll({force = false, silent = false} = {}){
  const seq = ++loadSeq;
  if (!silent){ setLoading(true); pendingPolls = 0; }
  clearTimeout(pendingTimer);
  const cat = category, d = days();
  const wantConflicts = cat === 'all' || cat === 'conflict';

  const [cr, er] = await Promise.allSettled([
    wantConflicts ? fetchJson('conflicts.php', {days:d}, force) : Promise.resolve(null),
    fetchJson('events.php', {category:cat, days:d}, force),     // 'conflict' also loads clash / alert points
  ]);
  if (seq !== loadSeq) return;      // superseded by a newer request
  const errors = [];
  const okC = cr.status === 'fulfilled', okE = er.status === 'fulfilled';
  if (!okC) errors.push(cr.reason?.message || 'conflicts');
  if (!okE) errors.push(er.reason?.message || 'events');

  if (!okC && !okE){
    mode = 'error';
    if (!lastLoad){ CONFLICTS = []; POINTS = []; }
    showError([...new Set(errors)].join(' · '));
  } else {
    if (okC) CONFLICTS = cr.value ? (cr.value.conflicts || []) : [];
    if (okE) POINTS = er.value?.events || [];
    const sources = {...(okC && cr.value?.sources || {}), ...(okE && er.value?.sources || {})};
    const pending = [...new Set([...(okC && cr.value?.pending || []), ...(okE && er.value?.pending || [])])];
    lastLoad = {at:Date.now(), sources, pending};
    const bad = Object.values(sources).some(s => s.state === 'error' || s.state === 'partial');
    mode = errors.length || bad ? 'partial' : 'live';
    if (errors.length) showError(t('errPartial') + ' ' + errors.join(' · ')); else hideError();
    if (pending.length && pendingPolls < PENDING_POLL_MAX){
      pendingPolls++;
      pendingTimer = setTimeout(() => loadAll({silent:true}), PENDING_POLL_MS);
    }
  }
  setLoading(false);
  renderAll();
  if (silent && currentDetail?.kind === 'zone') currentDetail.reload?.();
  if (Date.now() - lastBannerAt > BANNER_EVERY_MS) loadBanner();
}
async function loadStatus(){
  try { serverStatus = await fetchJson('status.php'); } catch(e){ serverStatus = null; }
  annotateTabs();
  return serverStatus;
}
function showError(msg){ $('errorDetail').textContent = msg; $('errorBanner').hidden = false; }
function hideError(){ $('errorBanner').hidden = true; }

setInterval(() => { if (document.visibilityState === 'visible' && !loading) loadAll({silent:true}); }, AUTO_REFRESH_MS);
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && lastLoad && Date.now() - lastLoad.at > AUTO_REFRESH_MS) loadAll({silent:true});
});

/* ============================== list ============================== */
const listEl = $('list');
function renderAll(){
  renderLayersBtn();
  const zones = visibleZones(), points = visiblePoints();
  renderMarkers(zones, points);
  renderLegend(zones, points);
  renderList(zones, points);
  renderStatus();
}
function emptyState(){
  if (mode === 'error' && !CONFLICTS.length && !POINTS.length)
    return `<div class="empty-state">${icon('alert')}<b>${esc(t('errTitle'))}</b>${esc(t('errOffline'))}<br><button class="btn" data-retry>${esc(t('retry'))}</button></div>`;
  return `<div class="empty-state">${icon('search')}<b>${esc(t('emptyTitle'))}</b>${esc(searchTerm ? t('emptySearch') : t('emptyList'))}</div>`;
}
function renderList(zones, points){
  const total = zones.length + points.length;
  $('listCount').textContent = loading && !lastLoad ? '' : t('items', total);
  if (!total){ if (!(loading && !lastLoad)) listEl.innerHTML = emptyState(); return; }
  const byCat = {conflict:[...zones].sort((a, b) => SEV_ORDER[a.sev] - SEV_ORDER[b.sev] || cName(a).localeCompare(cName(b)))};
  points.forEach(p => { const k = p.category === 'conflict' ? 'conflict_ev' : p.category; (byCat[k] ||= []).push(p); });
  let html = '';
  for (const [cat, labelKey, color] of GROUPS){
    const rows = byCat[cat];
    if (!rows?.length) continue;
    if (cat !== 'conflict') rows.sort((a, b) => SEV_ORDER[pointSev(a)] - SEV_ORDER[pointSev(b)] || (b.event_date || '').localeCompare(a.event_date || ''));
    html += `<div class="group-head" style="--c:${color}"><i></i>${esc(t(labelKey))}<span class="n">${fmtN(rows.length)}</span></div>`;
    const limit = expandedGroups[cat] ? rows.length : LIST_GROUP_LIMIT;
    for (const o of rows.slice(0, limit)) html += cat === 'conflict' ? zoneRow(o) : pointRow(o);
    if (rows.length > limit) html += `<div class="list-more"><span>${esc(t('showMore', fmtN(rows.length - limit)))}</span><button data-more="${cat}">${esc(t('showMoreBtn'))}</button></div>`;
  }
  listEl.innerHTML = html;
}
function zoneRow(c){
  return `<div class="item" data-kind="zone" data-id="${c.id}" tabindex="0" role="button">
    <span class="ind" style="--c:${SEV_COLOR[c.sev]}"></span>
    <div class="item-body">
      <div class="item-name">${esc(cName(c))}</div>
      <div class="item-meta">${esc(cField(c, 'region'))} · ${esc(t('since'))} ${esc(c.since)}</div>
      <div class="item-desc">${esc(cField(c, 'desc'))}</div>
    </div>${icon('chev-right', 'i chev')}</div>`;
}
function pointRow(p){
  const sev = pointSev(p);
  const tag = catLabel(p) + (p.subtype && p.source !== 'hapi' && subLabel(p.subtype) ? ' · ' + subLabel(p.subtype) : '');
  return `<div class="item" data-kind="point" data-id="${esc(p.id)}" tabindex="0" role="button">
    <span class="ind ring" style="--c:${SEV_COLOR[sev]};--f:${CAT_COLOR[p.category] || NEUTRAL}"></span>
    <div class="item-body">
      <div class="item-name">${esc(pointTitle(p).slice(0, 120))}</div>
      <div class="item-meta">${esc(pointSubline(p))}</div>
      <span class="tag">${esc(tag)}</span>
    </div>${icon('chev-right', 'i chev')}</div>`;
}
listEl.addEventListener('click', e => {
  if (e.target.closest('[data-retry]')){ loadAll({force:true}); return; }
  const more = e.target.closest('[data-more]');
  if (more){ expandedGroups[more.dataset.more] = true; renderAll(); return; }
  const it = e.target.closest('.item');
  if (!it) return;
  if (it.dataset.kind === 'zone') selectZone(Number(it.dataset.id), true); else selectPoint(it.dataset.id, true);
});
listEl.addEventListener('keydown', e => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('item')){ e.preventDefault(); e.target.click(); }
});

/* ============================== detail building blocks ============================== */
const detailBody = $('detailBody');
const stat = (value, label) => value === null || value === undefined || value === '' ? '' : `<div class="stat"><b>${esc(value)}</b><span>${esc(label)}</span></div>`;
const stats = cells => { const h = cells.join(''); return h ? `<div class="stats">${h}</div>` : ''; };
const badge = (color, label, dot = true) => `<span class="badge" style="--c:${color}">${dot ? '<i></i>' : ''}${esc(label)}</span>`;
const section = (title, body, aside = '') => body ? `<div class="section"><div class="section-title">${esc(title)}${aside ? `<span>${esc(aside)}</span>` : ''}</div>${body}</div>` : '';
const note = text => text ? `<div class="note">${icon('info')}<span>${esc(text)}</span></div>` : '';

let chartSeq = 0;
function barChart(values, color, labelLeft, labelRight){
  if (!values.length) return '';
  const id = 'g' + (++chartSeq), max = Math.max(1, ...values), w = 300, h = 64, bw = w / values.length;
  const bars = values.map((v, i) => {
    const bh = v > 0 ? Math.max(2, (v / max) * (h - 4)) : 0;
    return bh ? `<rect x="${(i * bw + bw * 0.14).toFixed(2)}" y="${(h - bh).toFixed(2)}" width="${(bw * 0.72).toFixed(2)}" height="${bh.toFixed(2)}" rx="1.5" fill="url(#${id})"/>` : '';
  }).join('');
  return `<div class="chart"><svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">
      <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${color}"/><stop offset="1" stop-color="${color}" stop-opacity=".35"/></linearGradient></defs>
      <line x1="0" y1="${h - 0.5}" x2="${w}" y2="${h - 0.5}" stroke="rgba(255,255,255,.08)" vector-effect="non-scaling-stroke"/>${bars}</svg>
    <div class="chart-axis"><span>${esc(labelLeft)}</span><span>${esc(labelRight)}</span></div></div>`;
}
function feedHtml(feed, pendingGdelt){
  if (!feed?.length) return `<div class="feed-empty">${esc(pendingGdelt ? t('feedPending') : t('noFeed'))}</div>`;
  return `<div class="feed">` + feed.map(e => {
    const src = e.source === 'acled_archive' ? t('archived') : srcName(e.source).replace(' DOC 2.0', '').replace(' (live)', '');
    const title = e.source === 'acled_archive' && !e.title ? `${e.event_type || ''}${e.country ? ' — ' + e.country : ''}` : (e.title || t('untitled'));
    const meta = [fmtDate(e.event_date), src, e.domain, e.fatalities ? t('reported', fmtN(e.fatalities)) : null].filter(Boolean).map(esc).join(' · ');
    const url = safeUrl(e.url);
    const inner = `<span class="t">${esc(title.slice(0, 200))}</span><div class="feed-meta">${meta}</div>`;
    return url ? `<a class="feed-item" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${inner}</a>` : `<div class="feed-item">${inner}</div>`;
  }).join('') + `</div>`;
}
function reportsHtml(reports){
  if (!reports?.length) return '';
  return section(t('relatedReports'), `<div class="feed">` + reports.map(r => {
    const url = safeUrl(r.url), inner = `<span class="t">${esc((r.title || 'Report').slice(0, 180))}</span><div class="feed-meta">${esc([fmtDate(r.date), r.source || 'ReliefWeb'].filter(Boolean).join(' · '))}</div>`;
    return url ? `<a class="feed-item" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${inner}</a>` : `<div class="feed-item">${inner}</div>`;
  }).join('') + `</div>`);
}

/* ============================== conflict zone detail ============================== */
function renderZone(c, data, state){
  const st = data?.stats || c.stats || {}, ds = data?.stats || {};
  const basis = c.sev_basis || 'none';
  const srcLbl = st.stats_source === 'acled' ? 'ACLED' : (st.stats_source === 'hapi' ? 'HDX HAPI' : '');
  const color = SEV_COLOR[c.sev] || SEV_COLOR.low;
  let h = `<div class="d-eyebrow" style="--c:${color}">${esc(t('catconflict'))} · ${esc(cField(c, 'region'))}</div>
    <h2 class="d-title">${esc(cName(c))}</h2>
    ${cField(c, 'actors') ? `<div class="d-sub">${esc(cField(c, 'actors'))}</div>` : ''}
    <div class="badges">${badge(color, t('sevLabel_' + c.sev))}${badge('#A4ADBA', t('since') + ' ' + c.since, false)}</div>
    <p class="d-desc">${esc(cField(c, 'desc'))}</p>
    ${note(t('basis_' + basis) + (c.stats?.period ? ' ' + t('hapiPeriod', fmtMonth(c.stats.period[0]), fmtMonth(c.stats.period[1])) : ''))}`;
  h += stats([
    stat(fmtN(st.fatalities), t('fatalities') + (srcLbl ? ` · ${srcLbl}` : '')),
    stat(fmtN(st.events), t('events') + (srcLbl ? ` · ${srcLbl}` : '')),
    stat(fmtN(st.media_articles), t('mediaArticles') + ' · GDELT'),
    stat(fmtN(ds.ucdp_fatalities), t('ucdpFatalities', ds.ucdp_year)),
    stat(ds.ucdp_recent ? fmtN(ds.ucdp_recent.fatalities) : null, t('ucdpRecent', ds.ucdp_recent?.days)),
    stat(ds.inform ? `${ds.inform.overall}/10` : null, t('informRisk') + (ds.inform?.risk_class ? ' · ' + ds.inform.risk_class : '') + (ds.inform?.year ? ' ' + ds.inform.year : '')),
    stat(ds.acaps ? `${ds.acaps.score}/${ds.acaps.scale}` : null, t('acapsSev') + (ds.acaps?.category ? ' · ' + ds.acaps.category : '')),
  ]);
  const cx = ds.context;
  if (cx && (cx.ipc3 || cx.idps || cx.refugees || cx.in_need || cx.funding)){
    h += section(t('ctxTitle'), stats([
      stat(big(cx.ipc3?.people), t('ipc3')), stat(big(cx.idps?.people), t('idps')), stat(big(cx.refugees?.people), t('refugeesFrom')),
      stat(big(cx.in_need?.people), t('inNeed')),
      stat(cx.funding?.pct != null ? cx.funding.pct + '%' : null, t('funded') + (cx.funding?.year ? ' ' + cx.funding.year : '')),
    ]));
  }
  const hp = ds.hapi;
  if (hp){
    h += section(t('breakdown'), stats([
      stat(`${fmtN(hp.political_violence[0])} / ${fmtN(hp.political_violence[1])}`, t('pv')),
      stat(`${fmtN(hp.civilian_targeting[0])} / ${fmtN(hp.civilian_targeting[1])}`, t('ct')),
      stat(fmtN(hp.demonstration[0]), t('demo_')),
    ]), `${fmtMonth(hp.period[0])} – ${fmtMonth(hp.period[1])} · ${t('events')} / ${t('fatalities')}`);
  }
  if (state === 'loading') h += `<div class="feed-empty">${esc(t('loadingDetail'))}</div>`;
  if (state === 'error') h += `<div class="feed-empty">${esc(t('errOffline'))}</div>`;
  if (data){
    const sp = data.sparkline;
    if (sp?.source && sp.points?.some(p => p.value > 0)){
      const vals = sp.points.map(p => p.value);
      h += section(t(sp.source === 'acled' ? 'sparkAcled' : 'sparkGdelt'),
        barChart(vals, '#F4B544', fmtDate(sp.points[0].day), fmtDate(sp.points[sp.points.length - 1].day)), t('maxPerDay', fmtN(Math.max(...vals))));
    }
    if (data.monthly?.length){
      const vals = data.monthly.map(m => m.fatalities);
      h += section(t('monthlyTitle'), barChart(vals, SEV_COLOR.high, fmtMonth(data.monthly[0].month), fmtMonth(data.monthly[data.monthly.length - 1].month)), 'max ' + fmtN(Math.max(...vals)));
    }
    h += section(t('latestUpdates'), feedHtml(data.feed, (data.pending || []).includes('gdelt')));
    h += reportsHtml(data.related_reports);
  }
  detailBody.innerHTML = h;
}
function selectZone(id, fly){
  let c = CONFLICTS.find(x => x.id === id);
  if (!c) return;
  let data = null, state = 'loading', polls = 0, timer = null;
  const my = {kind:'zone', id};
  my.render = () => renderZone(c, data, state);
  my.reload = async () => {
    try {
      const d = await fetchJson('conflict.php', {id, days:days()});
      if (currentDetail !== my) return;
      c = CONFLICTS.find(x => x.id === id) || c;
      data = d; state = 'ok'; my.render();
      clearTimeout(timer);
      if (d.pending?.length && polls++ < PENDING_POLL_MAX) timer = setTimeout(my.reload, PENDING_POLL_MS);
    } catch(e){ if (currentDetail === my && !data){ state = 'error'; my.render(); } }
  };
  currentDetail = my;
  my.render();
  openDetail(c.lat, c.lng, fly);
  my.reload();
}

/* ============================== point detail ============================== */
function pointDescription(p){
  const x = p.extra || {}, L_ = I18N[LANG], parts = [];
  switch (p.source){
    case 'gdacs': parts.push(t('d_gdacs', L_.alert[p.severity_level] || p.severity_level || t('na'), x.severity_text || '')); break;
    case 'usgs':
      parts.push(t('d_usgs', x.mag, x.depth_km));
      if (x.pager) parts.push(t('d_pager', L_.alert[x.pager] || x.pager));
      if (x.tsunami) parts.push(t('d_tsunami'));
      break;
    case 'firms': parts.push(t('d_firms', fmtN(x.frp), x.acq_time ? String(x.acq_time).padStart(4, '0').replace(/(\d\d)(\d\d)/, '$1:$2') : '')); break;
    case 'eonet': parts.push(t('d_eonet', x.ongoing)); if (x.last_update) parts.push(t('d_eonetLast', fmtDate(x.last_update))); break;
    case 'reliefweb': parts.push(t('d_rw', L_.rwStatus[x.status] || x.status || t('na'))); break;
    case 'hapi': parts.push(t('d_hapi', fmtN(x.events), fmtN(p.fatalities ?? 0), fmtMonth(x.period_start), fmtMonth(x.period_end))); break;
    case 'acled': parts.push(t('d_acled') + (p.subtype_raw ? ' ' + p.subtype_raw + '.' : '')); break;
    case 'acled_archive': parts.push(t('d_arc') + (p.subtype_raw ? ' ' + p.subtype_raw + '.' : '')); break;
    case 'who_don': parts.push(t('d_who', x.disease)); break;
    case 'ifrc': parts.push(t('d_ifrc', p.subtype_raw, x.affected ? fmtN(x.affected) : null)); break;
    case 'cems': parts.push(t('d_cems', x.code || '', x.ongoing)); break;
    case 'emsc': parts.push(t('d_emsc', x.mag, x.depth_km)); break;
    case 'nhc': parts.push(t('d_nhc', x.wind_kt, x.pressure_mb, x.movement)); break;
    case 'tsunami': parts.push(t('d_tsu', x.center || 'NOAA')); if (x.summary) parts.push(x.summary.slice(0, 300)); break;
    case 'meteoalarm': parts.push(t('d_mtl', x.red || 0, x.orange || 0, (x.events || []).join(', '))); if (x.areas?.length) parts.push(x.areas.join(', ') + '.'); break;
    case 'ioda': parts.push(t('d_ioda', x.rank, x.window_days)); break;
    case 'gvp': parts.push(t('d_gvp')); if (x.summary) parts.push(x.summary.slice(0, 400)); break;
    case 'acaps': parts.push(t('d_acaps', x.score, x.scale, x.category_label)); break;
    case 'ucdp_candidate': parts.push(t('d_ucdpc', x.dyad, x.civilians ? fmtN(x.civilians) : null)); break;
    case 'nws': parts.push(t('d_nws', x.red || 0, x.orange || 0, (x.events || []).join(', '))); if (x.areas?.length) parts.push(x.areas.join('; ') + '.'); break;
    case 'cap': parts.push(t('d_cap', x.red || 0, x.orange || 0, (x.events || []).join(', '), p.country)); if (x.areas?.length) parts.push(x.areas.join('; ') + '.'); break;
    case 'hans': parts.push(t('d_hans', x.alert_level, x.color_code, x.observatory)); break;
    case 'asam': parts.push(t('d_asam', x.victim)); if (x.summary) parts.push(x.summary); break;
    case 'locust': parts.push(t('d_locust', x.locality)); break;
    case 'alerts_ua': parts.push(t('d_ua', (x.types || []).map(k => (L_.uaTypes || {})[k] || k).join(', '), x.alerts || 1, x.whole_oblast)); break;
    case 'cfradar': parts.push(t('d_cfr', (L_.cfCause || {})[x.cause] || x.cause, x.scope, x.ongoing)); break;
    case 'idmc': parts.push(t('d_idmc', fmtN(x.figure), x.cause)); if (x.summary) parts.push(x.summary); break;
  }
  return parts.join(' ');
}
function renderPoint(p){
  const x = p.extra || {}, sev = pointSev(p), color = CAT_COLOR[p.category] || NEUTRAL;
  const longNotes = p.source === 'acled' && p.title;   // ACLED notes are long descriptions
  const title = longNotes ? [p.subtype_raw || t('cat' + p.category), p.country].filter(Boolean).join(' — ') : pointTitle(p);
  const sub = [p.source === 'hapi' ? t('monthlyAgg') : subLabel(p.subtype), p.country].filter(Boolean).join(' · ');
  let h = `<div class="d-eyebrow" style="--c:${color}">${esc(catLabel(p))}</div>
    <h2 class="d-title">${esc(title)}</h2>
    ${sub ? `<div class="d-sub">${esc(sub)}</div>` : ''}
    <div class="badges">${badge(SEV_COLOR[sev], t('sevLabel_' + sev))}<span class="badge ghost">${esc(srcName(p.source))}</span></div>`;
  if (longNotes) h += `<p class="d-desc">${esc(p.title)}</p>`;
  const desc = pointDescription(p);
  if (desc) h += `<p class="d-desc">${esc(desc)}</p>`;
  if (x.approx_location) h += note(t('approx'));
  h += stats([
    p.source === 'hapi' ? stat(`${fmtMonth(x.period_start)} – ${fmtMonth(x.period_end)}`, t('period_')) : stat(fmtDate(p.event_date), t('date')),
    stat(fmtN(p.fatalities), t('fatalities')),
    stat(p.source === 'hapi' ? fmtN(x.events) : null, t('events')),
    stat(x.mag != null ? 'M' + x.mag : null, t('magnitude')),
    stat(x.depth_km != null ? fmtN(x.depth_km) + ' km' : null, t('depth')),
    stat(x.frp != null ? fmtN(x.frp) + ' MW' : null, t('frp')),
    stat(x.wind_kt != null ? fmtN(x.wind_kt) + ' kt' : null, t('maxWind')),
    stat(p.source === 'idmc' ? fmtN(x.figure) : null, t('displaced')),
    stat(p.source === 'alerts_ua' ? fmtN(x.alerts) : null, t('alertsN')),
    stat(x.score != null && p.source === 'acaps' ? `${x.score}/${x.scale}` : null, t('acapsSev')),
    stat(p.country, t('country')),
  ]);
  const url = safeUrl(p.url);
  if (url) h += `<a class="source-link" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(t('openSource').replace(' ↗', ''))}${icon('ext')}</a>`;
  detailBody.innerHTML = h;
}
function selectPoint(id, fly){
  const p = POINTS.find(x => x.id === id);
  if (!p) return;
  currentDetail = {kind:'point', id, render:() => renderPoint(p)};
  currentDetail.render();
  openDetail(p.lat, p.lng, fly);
}

function openDetail(lat, lng, fly){
  $('listView').classList.add('pane-hidden');
  $('filterBar').classList.add('pane-hidden');
  $('detail').classList.add('show');
  $('detail').scrollTop = 0;
  highlight(lat, lng);
  sheetAtLeast(0.6);
  if (lat != null && lng != null){
    if (fly) flyToVisible(lat, lng, 4.5);
    else if (isMobile()) requestAnimationFrame(() => flyToVisible(lat, lng, map.getZoom()));
  }
  $('backBtn').focus({preventScroll:true});
}
function closeDetail(){
  currentDetail = null;
  selLayer.clearLayers();
  $('detail').classList.remove('show');
  $('listView').classList.remove('pane-hidden');
  $('filterBar').classList.remove('pane-hidden');
}
$('backBtn').addEventListener('click', closeDetail);

/* ============================== layers panel ============================== */
function layerCounts(){
  const c = Object.fromEntries(LAYERS.map(l => [l.id, 0]));
  c.zones = CONFLICTS.length;
  POINTS.forEach(p => { c[layerOf(p)]++; });
  return c;
}
function renderLayersBtn(){
  $('layersCount').textContent = `${LAYERS.filter(l => layerOn[l.id] !== false).length}/${LAYERS.length}`;
}
function renderLayersList(){
  const c = layerCounts();
  let h = '';
  for (const g of ['lgSecurity', 'lgHazards', 'lgHuman']){
    h += `<div class="layer-group"><h3>${esc(t(g))}</h3>`;
    LAYERS.filter(l => l.g === g).forEach(l => {
      h += `<label class="layer-row${c[l.id] ? '' : ' empty'}"><input type="checkbox" data-layer="${l.id}"${layerOn[l.id] !== false ? ' checked' : ''}>
        <i class="ld" style="--c:${l.color}"></i><span class="ln">${esc(t('L_' + l.id))}</span><span class="lc">${fmtN(c[l.id])}</span><span class="sw"></span></label>`;
    });
    h += `</div>`;
  }
  $('layersList').innerHTML = h;
}
$('layersBtn').addEventListener('click', e => { renderLayersList(); openModal('layersOverlay', e.currentTarget); });
$('layersList').addEventListener('change', e => {
  const id = e.target.dataset.layer; if (!id) return;
  layerOn[id] = e.target.checked; store.set('gw_layers', layerOn); renderAll();
});
document.querySelectorAll('[data-lp]').forEach(b => b.addEventListener('click', () => {
  const m = b.dataset.lp;
  layerOn = m === 'default' ? defaultLayers() : Object.fromEntries(LAYERS.map(l => [l.id, m === 'all']));
  store.set('gw_layers', layerOn); renderLayersList(); renderAll();
}));

/* ============================== space weather badge ============================== */
let SPACE = null;
async function loadBanner(){
  lastBannerAt = Date.now();
  try { const b = await fetchJson('banner.php'); SPACE = b.space || null; } catch(e){ SPACE = null; }
  renderSpace();
}
function renderSpace(){
  const el = $('spaceChip');
  const top = SPACE ? [['G', SPACE.G], ['S', SPACE.S], ['R', SPACE.R]].sort((a, b) => b[1] - a[1])[0] : null;
  if (!top || top[1] < 1){ el.hidden = true; return; }
  const color = top[1] >= 4 ? SEV_COLOR.high : (top[1] >= 2 ? SEV_COLOR.medium : SEV_COLOR.low);
  el.hidden = false;
  el.innerHTML = `<i style="--c:${color}"></i>${esc(t('spaceChip', top[0], top[1]))}`;
  el.title = t('spaceTitle', SPACE.G, SPACE.S, SPACE.R);
}

/* ============================== sources panel ============================== */
const STATE_COLOR = {ok:'#3FCF8E', stale:'#F2A93B', partial:'#F2A93B', pending:'#F2A93B', backoff:'#F2A93B', error:'#F0524A', disabled:'#6C7584', unconfigured:'#6C7584', unknown:'#6C7584'};
async function openSources(e){
  openModal('sourcesOverlay', e?.currentTarget);
  const listE = $('sourcesList');
  listE.innerHTML = `<li><div class="src-body"><div class="src-desc">${esc(t('loading'))}</div></div></li>`;
  const st = await loadStatus();
  const resp = lastLoad ? lastLoad.sources : {};
  listE.innerHTML = Object.entries(SOURCES).filter(([k]) => k !== 'acled_archive').map(([k, s]) => {
    const srv = st?.sources?.[k] || {}, r = resp[k] || {};
    let state = srv.state || r.state || 'unknown';
    if (r.state === 'pending' && state === 'unknown') state = 'pending';
    const meta = [];
    const okAt = r.fetched_at || srv.ok_at;
    if (state !== 'disabled' && state !== 'unconfigured') meta.push(okAt ? t('srcUpdated', relTime(okAt * 1000)) : t('srcNever'));
    if (srv.backoff_until) meta.push(t('srcBackoff', new Date(srv.backoff_until * 1000).toLocaleTimeString(locale(), {hour:'2-digit', minute:'2-digit'})));
    if (r.count) meta.push(t('srcCount', fmtN(r.count)));
    if (state === 'error' && srv.error) meta.push(t('srcLastErr') + ': ' + String(srv.error).slice(0, 160));
    return `<li><span class="src-state" style="--c:${STATE_COLOR[state] || STATE_COLOR.unknown}">${esc(I18N[LANG].srcStates[state] || state)}</span>
      <div class="src-body"><div class="src-name"><a href="${esc(safeUrl(s.url) || '#')}" target="_blank" rel="noopener noreferrer">${esc(s.name)}</a></div>
      <div class="src-desc">${esc(s[LANG] || s.en)}</div>${meta.length ? `<div class="src-meta">${meta.map(esc).join(' · ')}</div>` : ''}</div></li>`;
  }).join('') + (st ? '' : `<li><div class="src-body"><div class="src-desc">${esc(t('srcStatusFail'))}</div></div></li>`);
}

/* ============================== about ============================== */
function renderAbout(){
  $('aboutVersion').textContent = 'v' + GW_VERSION;
  const li = c => `<li><b>v${esc(c.v)}</b>${esc(c[LANG] || c.en)}</li>`;
  $('changelogList').innerHTML = `<ul class="changelog">${CHANGELOG.slice(0, 3).map(li).join('')}</ul>`
    + (CHANGELOG.length > 3 ? `<details class="older"><summary>${esc(t('olderVersions'))}</summary><ul class="changelog">${CHANGELOG.slice(3).map(li).join('')}</ul></details>` : '');
}

/* ============================== menu & modals ============================== */
const burgerBtn = $('burgerBtn'), burgerMenu = $('burgerMenu');
function openMenu(){
  burgerMenu.classList.add('open');
  burgerBtn.setAttribute('aria-expanded', 'true');
  const r = burgerBtn.getBoundingClientRect(), mw = burgerMenu.offsetWidth, mh = burgerMenu.offsetHeight;
  const left = Math.max(8, Math.min(window.innerWidth - mw - 8, r.right - mw));
  const below = r.bottom + 8 + mh < window.innerHeight;
  burgerMenu.style.left = left + 'px';
  burgerMenu.style.top = (below ? r.bottom + 8 : Math.max(8, r.top - mh - 8)) + 'px';
  burgerMenu.querySelector('[role="menuitem"]')?.focus();
}
function closeMenu(){ if (!burgerMenu.classList.contains('open')) return; burgerMenu.classList.remove('open'); burgerBtn.setAttribute('aria-expanded', 'false'); }
burgerBtn.addEventListener('click', e => { e.stopPropagation(); burgerMenu.classList.contains('open') ? closeMenu() : openMenu(); });
document.addEventListener('click', e => { if (!burgerMenu.contains(e.target) && !burgerBtn.contains(e.target)) closeMenu(); });
burgerMenu.querySelectorAll('[data-lang]').forEach(b => b.addEventListener('click', () => setLang(b.dataset.lang)));

let modalOpener = null;
function openModal(id, opener){
  closeMenu();
  modalOpener = opener || document.activeElement;
  $(id).classList.add('open');
  $(id).querySelector('[data-close]')?.focus();
}
function closeModals(){
  const open = document.querySelectorAll('.modal-overlay.open');
  if (!open.length) return false;
  open.forEach(o => o.classList.remove('open'));
  modalOpener?.focus?.({preventScroll:true});
  return true;
}
document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o || e.target.closest('[data-close]')) closeModals(); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape'){
    if (burgerMenu.classList.contains('open')){ closeMenu(); burgerBtn.focus(); return; }
    if (closeModals()) return;
    if (currentDetail) closeDetail();
    return;
  }
  if (e.key === '/' && !/^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement?.tagName) && !document.querySelector('.modal-overlay.open')){
    e.preventDefault(); if (currentDetail) closeDetail(); $('searchInput').focus();
  }
});
$('aboutBtn').addEventListener('click', e => openModal('aboutOverlay', e.currentTarget));
$('sourcesBtn').addEventListener('click', openSources);
$('lastUpdated').addEventListener('click', openSources);
$('retryBtn').addEventListener('click', () => { hideError(); loadAll({force:true}); });
$('dismissBtn').addEventListener('click', hideError);

/* ============================== filters ============================== */
function saveFilters(){ store.set('gw_filters', {cat:category, days:periodSel.value, sev:activeSev}); }
function setSev(sev){
  activeSev = sev;
  document.querySelectorAll('#sevFilters button').forEach(b => b.setAttribute('aria-pressed', b.dataset.sev === sev ? 'true' : 'false'));
}
$('sevFilters').addEventListener('click', e => {
  const b = e.target.closest('button[data-sev]'); if (!b) return;
  setSev(b.dataset.sev); saveFilters(); renderAll();
});
$('catTabs').addEventListener('click', e => {
  const b = e.target.closest('.tab'); if (!b || b.dataset.cat === category) return;
  category = b.dataset.cat; expandedGroups = {};
  annotateTabs(); saveFilters();
  b.scrollIntoView({behavior:'smooth', block:'nearest', inline:'nearest'});
  loadAll();
});
$('catTabs').addEventListener('keydown', e => {
  if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
  const tabs = [...document.querySelectorAll('#catTabs .tab')], i = tabs.indexOf(document.activeElement);
  if (i < 0) return;
  const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
  n.focus(); n.click(); e.preventDefault();
});
periodSel.addEventListener('change', () => { expandedGroups = {}; saveFilters(); loadAll(); });
let searchDebounce = null;
$('searchInput').addEventListener('input', e => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => { searchTerm = e.target.value.trim().toLowerCase(); renderAll(); }, 140);
});
$('refreshBtn').addEventListener('click', () => loadAll({force:true}));

/* ============================== language ============================== */
function setLang(l){
  if (!I18N[l] || l === LANG) return;
  LANG = l; NF = new Intl.NumberFormat(locale());
  try { localStorage.setItem('gw_lang', l); } catch(e){}
  document.cookie = 'gw_lang=' + l + '; path=/; max-age=31536000; SameSite=Lax';
  applyStaticI18n();
  renderAll();
  renderSpace();
  currentDetail?.render();
}
function applyStaticI18n(){
  document.documentElement.lang = LANG;
  document.querySelectorAll('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
  document.querySelectorAll('[data-i18n-aria]').forEach(el => el.setAttribute('aria-label', t(el.dataset.i18nAria)));
  document.querySelectorAll('[data-i18n-title]').forEach(el => el.setAttribute('title', t(el.dataset.i18nTitle)));
  document.querySelectorAll('[data-i18n-ph]').forEach(el => el.setAttribute('placeholder', t(el.dataset.i18nPh)));
  document.querySelectorAll('.menu [data-lang]').forEach(b => b.setAttribute('aria-pressed', b.dataset.lang === LANG ? 'true' : 'false'));
  document.title = 'GlobeWatch — ' + t('appTitle');
  renderAbout();
  annotateTabs();
  renderStatus();
}

/* ============================== bottom sheet (mobile) ============================== */
const handleZone = $('handleZone');
const rootStyle = document.documentElement.style;
const vh = () => window.innerHeight;
let lastPeek = 150;   // handle + header + search row; measured while the filter bar is visible
const peekH = () => { const fb = $('filterBar'); if (fb.offsetParent) lastPeek = fb.offsetTop + 56; return lastPeek; };
let startY = 0, startH = 0, dragging = false, moved = false;
function setSheetH(px){ sheet.style.height = Math.round(px) + 'px'; }
function sheetAtLeast(frac){ if (isMobile() && sheet.getBoundingClientRect().height < vh() * frac) setSheetH(vh() * frac); }
function onStart(y){ if (!isMobile()) return; dragging = true; moved = false; sheet.classList.add('dragging'); startY = y; startH = sheet.getBoundingClientRect().height; }
function onMove(y){
  if (!dragging) return;
  if (Math.abs(y - startY) > 3) moved = true;
  setSheetH(Math.max(peekH() - 30, Math.min(vh() * 0.9, startH + startY - y)));
}
function onEnd(){
  if (!dragging) return;
  dragging = false; sheet.classList.remove('dragging');
  const h = sheet.getBoundingClientRect().height, snaps = [peekH(), vh() * 0.46, vh() * 0.86];
  if (!moved){ setSheetH(h < vh() * 0.4 ? snaps[1] : (h < vh() * 0.7 ? snaps[2] : snaps[0])); return; }  // tap cycles
  setSheetH(snaps.reduce((a, b) => Math.abs(b - h) < Math.abs(a - h) ? b : a));
}
handleZone.addEventListener('touchstart', e => onStart(e.touches[0].clientY), {passive:true});
handleZone.addEventListener('touchmove', e => onMove(e.touches[0].clientY), {passive:true});
handleZone.addEventListener('touchend', onEnd);
handleZone.addEventListener('mousedown', e => { onStart(e.clientY); e.preventDefault(); });
window.addEventListener('mousemove', e => onMove(e.clientY));
window.addEventListener('mouseup', onEnd);
window.addEventListener('resize', () => { if (!isMobile()) sheet.style.height = ''; });
$('searchInput').addEventListener('focus', () => sheetAtLeast(0.46));
if ('ResizeObserver' in window){
  new ResizeObserver(() => rootStyle.setProperty('--sheet-h', isMobile() ? sheet.getBoundingClientRect().height + 'px' : '0px')).observe(sheet);
}

/* ============================== boot ============================== */
/* ============================== PWA: service worker, install, offline ============================== */
let deferredInstall = null;
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
function updateInstallItem(){ $('installBtn').hidden = isStandalone() || !(deferredInstall || isIos()); }
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferredInstall = e; updateInstallItem(); });
window.addEventListener('appinstalled', () => { deferredInstall = null; updateInstallItem(); });
$('installBtn').addEventListener('click', async () => {
  closeMenu();
  if (deferredInstall){ deferredInstall.prompt(); await deferredInstall.userChoice.catch(() => {}); deferredInstall = null; updateInstallItem(); }
  else if (isIos()) showError(t('installIos'));
});
updateInstallItem();
if ('serviceWorker' in navigator && location.protocol !== 'file:'){
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
}
window.addEventListener('offline', () => { mode = 'error'; renderStatus(); });
window.addEventListener('online', () => { loadAll({silent:true}); });

const f = store.get('gw_filters', null);
if (f){
  if (CATEGORY_SOURCES[f.cat] || f.cat === 'all') category = f.cat;
  if ([...periodSel.options].some(o => o.value === f.days)) periodSel.value = f.days;
  if (['all', 'high', 'medium', 'low'].includes(f.sev)) setSev(f.sev);
}
const tabParam = new URLSearchParams(location.search).get('tab');   // PWA shortcuts
if (tabParam && CATEGORY_SOURCES[tabParam]) category = tabParam;
applyStaticI18n();
tick();
fitWorld();
requestAnimationFrame(() => document.querySelector(`#catTabs .tab[data-cat="${category}"]`)?.scrollIntoView({block:'nearest', inline:'nearest'}));
loadStatus();
loadAll();
})();
