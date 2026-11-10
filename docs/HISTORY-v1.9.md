# GlobeWatch

Webapp mobile-first per la visualizzazione su mappa mondiale di conflitti armati,
disordini civili, violenza contro i civili e disastri naturali — filtrabile per
tipo di evento e periodo, con dati interrogati **live al momento della visita**.

## Perché "live" e non un database di eventi

Il piano iniziale prevedeva un database che accumula eventi via cron/webcron.
Su un hosting condiviso senza accesso al cron, e visto che l'obiettivo era
comunque mostrare la situazione "adesso" e non costruire un archivio storico,
si è scelta un'architettura più semplice: **ogni richiesta all'API interroga
le fonti esterne in quel momento** e restituisce la risposta fresca. Niente
cron, niente tabelle di eventi da tenere sincronizzate, niente webcron.

Il database resta, ma con un ruolo minimo:
- `conflicts` — la lista curata a mano delle "zone" di conflitto (editoriale).
- `api_cache` — una cache generica con scadenza (default 10 minuti, configurabile,
  disattivabile) che evita di martellare le API esterne se arrivano più
  visitatori ravvicinati, e tiene i tempi di risposta ragionevoli. Non è "la
  fonte dei dati": è solo un buffer temporaneo.

## ⚠️ Struttura delle cartelle — leggi prima di caricare

```
globewatch/
├── webroot/              ← carica TUTTO il contenuto di questa cartella
│   ├── index.php         come radice del sito (document root / public_html)
│   └── api/
│       ├── lib.php
│       ├── conflicts.php
│       ├── conflict.php
│       ├── events.php
│       ├── reliefweb.php
│       ├── diagnostics.php
│       ├── db.php
│       └── config.php.example
├── sql/                  ← solo per l'installazione (phpMyAdmin), non va sul server
└── README.md
```

Il frontend chiama le API con un percorso relativo (`api/conflicts.php`): perché
funzioni, la cartella `api/` deve stare **allo stesso livello** di `index.php`,
dentro la cartella che il tuo hosting serve come sito. Se il pannello di
controllo del tuo hosting ti fa scegliere una document root (es. `public_html/`
o `htdocs/`), il contenuto di `webroot/` (non la cartella `webroot/` stessa)
va caricato lì dentro. Un errore comune — quello che probabilmente hai
avuto tu — è caricare `index.php` nella document root ma lasciare `api/`
un livello più su o più giù: il browser chiede `.../api/conflicts.php`,
non lo trova, e l'app passa in modalità demo con un 404.

## Architettura

```
webroot/index.php    → frontend (Leaflet, vanilla JS, nessuna build necessaria)
webroot/api/lib.php          → cache + funzioni di query live per tutte le fonti
webroot/api/conflicts.php    → GET zone di conflitto curate, severità calcolata live (param: days)
webroot/api/conflict.php     → GET dettaglio conflitto: feed + sparkline + report correlati, live (params: id, days)
webroot/api/events.php       → GET eventi puntuali: disordini / atrocità / disastri, live (params: category, days)
webroot/api/reliefweb.php    → GET report ReliefWeb correlati, on-demand (param: q) — chiamato solo aprendo un dettaglio
webroot/api/diagnostics.php  → GET pagina di diagnostica per ogni fonte + requisiti PHP
webroot/api/db.php           → connessione PDO
webroot/api/config.php.example → copiare in config.php (stesso posto) e compilare
sql/schema.sql        → tabella conflicts (curata) + api_cache — solo per l'installazione
sql/004_migrate_to_live.sql → SOLO se avevi già una versione con cron/eventi: ripulisce le vecchie tabelle
```

## Le sette fonti

- **GDELT DOC 2.0 API** — nessuna registrazione. Volume di copertura mediatica
  recente per ogni conflitto (segnale di intensità, non conteggio vittime).
- **ACLED** — registrazione gratuita, OAuth con email+password (dal 2025 non
  esiste più una "access key" statica — vedi sotto). Dà vittime/coordinate/tipo
  per singolo evento, ed è la fonte sia dei conflitti "pesanti" sia dei
  disordini/atrocità (riclassificati dal loro `event_type` nativo: Riots/
  Protests → disordini, Violence against civilians → atrocità, il resto →
  conflitto).
- **GDACS** — nessuna registrazione. Terremoti, cicloni, alluvioni, vulcani,
  incendi boschivi, siccità **di rilievo internazionale**, con livello di
  allerta verde/arancione/rosso proprio. Non copre eventi locali di scala
  minore — per quelli servono USGS e FIRMS qui sotto.
- **USGS** — nessuna registrazione, sempre attivo. Terremoti di **qualsiasi**
  magnitudo (soglia di default 3.0, configurabile nel codice), a differenza
  di GDACS che riporta solo i sismi di rilievo internazionale.
- **NASA FIRMS** — chiave gratuita rilasciata **istantaneamente** (nessuna
  approvazione, a differenza di ACLED/UCDP/ReliefWeb):
  https://firms.modaps.eosdis.gov/api/map_key/. Rilevamento satellitare dei
  focolai attivi (VIIRS): copre anche incendi locali di scala ridotta che
  GDACS non riporta — è la fonte pensata apposta per casi come un incendio
  in Friuli che non fa notizia internazionale ma è comunque un evento reale
  sul territorio. Interroga l'endpoint globale, quindi limitata a 1-3 giorni
  e filtrata per confidenza/intensità per restare dentro tempi di risposta
  ragionevoli su hosting condiviso.
- **UCDP** (Uppsala Conflict Data Program) — dal 2025 richiede un token
  gratuito su registrazione (https://ucdp.uu.se/apidocs/). I dati si
  aggiornano a cicli periodici, non in tempo reale: qui è usato come
  **riscontro accademico sulle vittime**, con cache più lunga (almeno 6 ore)
  perché interrogarlo ad ogni visita non avrebbe senso.
- **ReliefWeb (OCHA)** — dal 1° novembre 2025 richiede un `appname`
  **pre-approvato**: si richiede con un modulo da https://reliefweb.int/help/api
  e OCHA lo revisiona via email, non è immediato. Usata per mostrare report/
  situazioni umanitarie correlate nel dettaglio di un conflitto — chiamata
  solo quando l'utente apre quel dettaglio, non nella lista principale.

Sono opzionali ACLED, UCDP, ReliefWeb e FIRMS: se non le configuri, l'app
funziona comunque solo con GDELT, GDACS e USGS (sempre attive, nessuna
chiave), con meno dettaglio (niente disordini/atrocità senza ACLED, niente
riscontro vittime accademico senza UCDP, niente report correlati senza
ReliefWeb, niente incendi locali a bassa intensità senza FIRMS — restano
comunque quelli di rilievo internazionale via GDACS).

## Setup

1. Crea il database ed esegui `sql/schema.sql` (dalla cartella `sql/` sul tuo
   computer, non serve caricarla sul server — usa phpMyAdmin o simili). Se
   stai facendo l'upgrade da una versione precedente con cron/eventi, esegui
   prima `sql/004_migrate_to_live.sql`.
2. Copia `webroot/api/config.php.example` in `webroot/api/config.php` (stessa
   cartella) e compila:
   - **DB**: host/nome/utente/password.
   - **ACLED**: `ACLED_EMAIL` / `ACLED_PASSWORD` (non una "key" — vedi sotto).
   - **UCDP**: `UCDP_TOKEN`, richiesto via https://ucdp.uu.se/apidocs/.
   - **ReliefWeb**: `RELIEFWEB_APPNAME`, richiesto via https://reliefweb.int/help/api.
   - **CACHE_TTL_SECONDS**: quanto tenere in cache le risposte (default 600 = 10 min).
3. Carica **il contenuto** di `webroot/` (non la cartella `webroot/` stessa)
   nella document root del tuo hosting — vedi l'avviso sulla struttura delle
   cartelle più sopra. Nessun cron da configurare: apri il sito e i dati
   arrivano live ad ogni richiesta.
4. Se il backend non risponde (es. apri `index.php` come file locale sul tuo
   computer, senza un vero server PHP dietro), il frontend passa da solo in
   "modalità demo" con un dataset statico di soli conflitti, e te lo dice
   chiaramente con un banner invece di fingere che vada tutto bene.

### Verificare le credenziali ACLED

Dal 2025 ACLED non rilascia più una "access key" da un pannello: ci si
autentica con email + password del proprio account (OAuth). Verifica che
funzionino con:
```
curl -X POST "https://acleddata.com/oauth/token" \
  -d "username=TUA_EMAIL" -d "password=TUA_PASSWORD" \
  -d "grant_type=password" -d "client_id=acled" -d "scope=authenticated"
```
Se la risposta contiene un `access_token`, sei a posto.

## Diagnosticare problemi ("mi mostra pochi eventi demo")

Se l'app mostra sempre lo stesso, piccolo dataset (6 conflitti) invece dei 18
reali, il backend sta fallendo e il frontend sta usando il fallback demo — lo
capisci subito perché ora appare un banner d'errore in alto invece che un
fallback silenzioso. Apri **`/api/diagnostics.php`** nel browser: controlla,
uno per uno, PHP/estensioni, database, e la raggiungibilità di ognuna delle
cinque fonti — per ogni fonte mostra anche l'**errore curl reale** (timeout,
connessione rifiutata, errore SSL, HTTP diverso da 200...), non solo un
generico "non raggiungibile", così puoi capire davvero cosa succede.

Cause riscontrate finora, già corrette in questa versione:
- **JSON rotto da warning PHP**: `api/lib.php` ora disattiva la stampa diretta
  degli errori PHP e decodifica il JSON delle fonti esterne in modo sicuro,
  restituendo sempre una risposta JSON valida (gli errori reali finiscono nei
  log del server via `error_log`, non nella risposta HTTP).
- **UCDP interrogato in sequenza per ogni conflitto nella lista principale**:
  con 18 conflitti, una fonte lenta poteva far scadere il tempo massimo di
  esecuzione PHP. UCDP ora si interroga solo nel dettaglio del singolo
  conflitto, mai nella lista.
- **Il file d'ingresso si chiamava `index.html`**: molti hosting condivisi
  cercano `index.php` come documento predefinito e restituiscono 404 se non
  lo trovano. Il file ora si chiama `index.php` (contenuto identico, solo
  l'estensione cambia: funziona comunque perché non c'è codice PHP da
  eseguire, è HTML puro servito dall'interprete PHP).
- **`api/` e `index.php` erano cartelle separate**: se carichi `index.php`
  nella document root ma `api/` finisce un livello più su o più giù, il
  percorso relativo `api/conflicts.php` usato dal frontend restituisce 404 e
  l'app passa in modalità demo. Ora `index.php` e `api/` stanno insieme dentro
  `webroot/`, pensata apposta per essere caricata così com'è come contenuto
  della document root — vedi l'avviso in cima a questo file.
- **Un bug di layout CSS**: `#listView` e `#detail` non avevano un'altezza
  vincolata dentro il pannello flessibile, quindi con molti elementi il
  contenuto usciva fuori dalla finestra invece di scorrere internamente —
  particolarmente visibile nel pannello laterale da desktop. Corretto dando
  loro `flex:1 1 auto; min-height:0`, il pattern standard per far scorrere
  correttamente un figlio dentro un contenitore flex.

Se **GDELT specificamente** continua a fallire mentre le altre fonti
funzionano: `diagnostics.php` ora te lo dice con l'errore curl esatto. Se è
un timeout o una connessione rifiutata (non un errore SSL o un HTTP diverso
da 200), è probabile che il tuo hosting blocchi le connessioni in uscita
verso `api.gdeltproject.org`, o che GDELT stia limitando l'intervallo di IP
del tuo hosting condiviso (capita, alcuni provider bloccano range noti per
abusi passati). Vale la pena contattare il supporto del tuo hosting per
chiedere conferma. Nel frattempo l'app funziona comunque: GDELT contribuisce
solo al segnale di "volume di copertura mediatica", non è indispensabile —
i conflitti restano visibili con severità basata su ACLED.

**Verifica rapida per un 404 sull'API**: apri direttamente nel browser
`https://tuosito.it/api/conflicts.php?days=30` (adatta il dominio). Se vedi
JSON, il percorso è corretto e il problema è altrove; se vedi il 404 della
tua pagina di errore standard, `api/` non è dove il frontend se lo aspetta —
rivedi dove hai caricato le cartelle rispetto all'avviso in cima a questo file.

## Nota sulle prestazioni

Ogni caricamento della lista conflitti fa, in parallelo, una chiamata GDELT
per ciascun conflitto attivo più una chiamata ACLED per ciascun paese
coinvolto — tipicamente qualche secondo la prima volta, quasi istantaneo
per i visitatori successivi finché la cache è valida. Se senti che è troppo
lento, alza `CACHE_TTL_SECONDS`; se vuoi dati più freschi a costo di
qualche secondo di attesa in più, abbassalo (o mettilo a 0 per disattivarla).

## Toggle che non si vedeva subito, GDELT v1/v2, nuova fonte (v1.9.0)

**Disattivare una fonte dal pannello non aveva effetto immediato**: le
risposte di `conflicts.php`/`events.php` restano in cache fino a
`CACHE_TTL_SECONDS` (default 10 minuti) per non martellare le fonti esterne
ad ogni visita — ma il toggle non svuotava quella cache, quindi la vecchia
risposta (con la fonte ancora presente) continuava a essere servita finché
non scadeva da sola. Corretto: ora attivare/disattivare una fonte svuota
subito le risposte in cache, ed è stato aggiunto anche un pulsante "Svuota
cache" nel pannello come valvola di sicurezza generale.

**GDELT: uso la v1 o la v2?** La v2. Quello che consultavi tu (la pagina
"Data" del sito GDELT) si riferisce all'**Event Database grezzo** (i file
CSV/BigQuery con gli eventi codificati CAMEO, scaricabili in blocco): quello
sì è diviso in due — v1 per il 1979-feb.2015, v2 per feb.2015-oggi, perché
la v1 storica non è mai stata riconvertita in v2. Ma **questo progetto non
usa quell'Event Database**: usa la **DOC 2.0 API** (ricerca full-text sugli
articoli, endpoint `/api/v2/doc/doc`), che è un prodotto GDELT separato,
esiste solo in versione 2.0, ed è quella attualmente mantenuta e aggiornata
ogni 15 minuti. Nessuna ambiguità quindi: la fonte che uso è quella giusta e
corrente — resta solo il problema, già noto, che il tuo hosting sembra
bloccare le connessioni verso `api.gdeltproject.org` a livello di rete.

**Nuova fonte: NASA EONET** — gratuita, nessuna chiave, aggiunge incendi,
tempeste severe, eruzioni vulcaniche, alluvioni, frane e siccità come
**eventi curati con data di inizio/fine** (a differenza di FIRMS, che dà
pixel satellitari grezzi). Basso volume (poche centinaia di eventi aperti
nel mondo), quindi leggera — buon complemento a GDACS/USGS/FIRMS con una
metodologia diversa, non li sostituisce. Esegui `sql/008_add_eonet_toggle.sql`
se avevi già eseguito la migrazione 007 prima che fosse aggiunta.

**Altre fonti valutate ma non aggiunte, per trasparenza**: EM-DAT e il
Global Terrorism Database sono dataset di ricerca da scaricare in blocco,
non pensati per interrogazioni live per-visita; EMSC (terremoti euro-
mediterranei) è in buona parte ridondante con USGS; non esiste, che io
sappia, un vero equivalente gratuito di ACLED per disordini/violenza
contro i civili — l'unica strada percorribile sarebbe minare l'Event
Database grezzo di GDELT (i codici CAMEO includono categorie come
"protesta"), ma richiede scaricare ed elaborare file CSV grezzi (via
BigQuery o export orari), un lavoro via via più corposo di quanto valga
la pena per ora. Se vuoi che lo affronti comunque, fammelo sapere.

## Coerenza dei periodi e stabilità DB (v1.8.0)

**Il numero di eventi non cresceva con il periodo selezionato** (a volte
calava addirittura): la causa era in USGS. La query terremoti usava una
soglia di magnitudo fissa (3.0) con un tetto di risultati fisso (300)
ordinati per data più recente — dato che i terremoti M3+ sono frequentissimi
nel mondo (centinaia al giorno), quel tetto veniva raggiunto già entro
pochi giorni: allargare il periodo da 90 giorni a 1, 3 o 10 anni restituiva
sempre e solo "gli ultimi 300 terremoti", identici indipendentemente dal
periodo scelto. Corretto: la soglia di magnitudo ora sale con l'ampiezza
del periodo (3.0 fino a 7gg, 3.5 fino a 90gg, 4.0 fino a 1 anno, 4.5 fino a
3 anni, 5.0 oltre), e l'ordinamento privilegia i terremoti più significativi
invece dei più recenti quando il tetto di risultati viene comunque
raggiunto. Così un periodo più ampio mostra sempre eventi ≥ di uno più
stretto, mai meno — stessa logica di ACLED/GDACS/FIRMS, dove la data di un
evento è sempre "quando è avvenuto/iniziato".

GDACS è stato inoltre limitato a un massimo di 2 anni indietro: è un
sistema di allerta pensato per il recente/attuale, non un archivio storico
profondo — interrogarlo su 10 anni non aggiungeva copertura reale, solo
tempo di risposta.

**"MySQL server has gone away"** con periodi ampi + tutte le categorie:
sintomo di una richiesta che passa troppo tempo su chiamate esterne (GDACS
su una finestra enorme, FIRMS, USGS) prima di toccare di nuovo il database,
oltre il `wait_timeout` configurato sull'hosting. Le correzioni sopra
riducono di per sé il tempo totale della richiesta; in aggiunta, le funzioni
di cache e lettura archivio ora si riconnettono automaticamente e ritentano
una volta se la connessione risulta caduta, invece di far fallire l'intera
richiesta.

**Su "continuo a non rilevare disordini o violenza contro i civili"**: qui
non c'è (più) un bug da correggere — è la conseguenza diretta di quanto
ACLED ti ha comunicato. Con l'account che hai, le chiamate a `/acled/read`
non restituiscono dati (il piano gratuito non lo permette più), quindi
disordini/atrocità e il contributo di ACLED alla severità dei conflitti
restano vuoti per costruzione, non per un errore nel codice. L'interruttore
ACLED nel pannello admin è già spento di default per questo motivo — se in
futuro sottoscrivi un piano, riattivalo da lì e tornerà a funzionare senza
altre modifiche.

## Interruttori per fonte (v1.7.0) — ACLED è passata a un piano a pagamento

ACLED ha comunicato che l'accesso via API richiede ora un piano a
pagamento — non più gratuito come quando questo progetto è partito. Invece
di rimuovere il codice ACLED (che resta intatto e pronto, nel caso in
futuro tu decida di sottoscrivere un piano, o compaia un livello gratuito),
ho aggiunto un sistema di interruttori: ogni fonte (GDELT, ACLED, GDACS,
USGS, FIRMS, UCDP, ReliefWeb) può essere accesa o spenta dal pannello di
amministrazione, in una card dedicata "Fonti dati".

- Esegui `sql/007_source_toggles.sql`.
- **ACLED parte spenta di default** in questa migrazione — riattivala solo
  se sottoscrivi un piano a pagamento.
- Disattivare una fonte la rende semplicemente inerte (le funzioni
  ritornano vuoto prima di fare qualunque chiamata esterna): niente
  chiamate di rete, niente costi, niente errori mostrati come se fosse un
  guasto — il codice resta comunque a posto, pronto a essere riattivato con
  un click il giorno in cui serve.
- **L'archivio storico già sincronizzato resta utilizzabile anche con ACLED
  spenta**: la lettura da `archived_events` è dati propri del database, non
  fa chiamate all'API live. Solo *aggiungere* nuovo storico richiede ACLED
  attiva (e quindi, ora, un piano a pagamento).
- `api/diagnostics.php` distingue chiaramente una fonte "disattivata dal
  pannello admin" da un vero errore di connessione/autenticazione.

## Archivio storico (v1.6.0) — eventi oltre 1 anno senza cron

Interrogare ACLED dal vivo su una finestra di anni sarebbe lento e comunque
limitato ai primi 500 risultati per paese (non un quadro rappresentativo).
Per il periodo "10 anni" del selettore, quindi, **solo l'ultimo anno resta
sempre live**; la parte più vecchia viene servita dal database, popolato a
mano — visto che l'hosting condiviso non permette un cron — tramite un
pannello dedicato.

**`dbpanel.php`** (nella stessa cartella di `index.php`, quindi
`https://tuosito.it/dbpanel.php`):
1. Esegui `sql/006_archived_events.sql` (dopo le migrazioni precedenti se
   provieni da una versione più vecchia).
2. In `config.php`, imposta `ADMIN_PANEL_PASSWORD` (cambia il valore di
   esempio) ed eventualmente `HISTORY_YEARS_BACK` (default 5 anni).
3. Apri `dbpanel.php`, accedi con la password, premi **"1. Inizializza
   elenco paesi"** (crea una riga di avanzamento per ogni combinazione
   conflitto/paese), poi **"2. Sincronizza tutto"**.
4. La sincronizzazione procede a finestre di 90 giorni per volta, con una
   pausa di 400ms tra una chiamata e l'altra (per non martellare ACLED), ed
   è **completamente ripetibile**: puoi premere "Ferma" in qualsiasi
   momento e riprendere più tardi da dove avevi lasciato — il progresso
   (`synced_until` per ogni paese) resta salvato nel database.
5. La dimensione reale del database e il conteggio degli eventi archiviati
   sono sempre visibili in cima al pannello, così puoi decidere tu quando
   fermarti rispetto allo spazio che hai a disposizione.

**Scelta di design per lo spazio**: le righe archiviate NON includono il
testo lungo delle note ACLED — solo campi strutturati (tipo evento, paese,
data, coordinate, vittime). Con 18 conflitti/paesi, anche in scenari
piuttosto intensi questo resta tipicamente entro 20-30MB per 5 anni di
storico, ben dentro gli 80MB disponibili. Il titolo mostrato in app per un
evento storico viene ricostruito al volo da questi campi (es. "Violenza
contro i civili — Sudan, 12/03/2023 (4 vittime)") invece di riportare la
prosa originale ACLED.

Solo ACLED viene archiviato (conflitti/disordini/atrocità): GDACS, USGS e
FIRMS restano fonti sempre live, dato che il loro valore è soprattutto
nell'attualità recente, non nello storico pluriennale.

## Novità e correzioni (v1.5.0)

**Bug critici corretti:**
- **"Out of memory" con "Tutti i tipi" selezionato**: la query FIRMS globale
  ("world") può restituire 30.000-100.000+ righe per un solo giorno (lo dice
  la stessa documentazione NASA) — caricare tutto il CSV in una stringa PHP
  esauriva il limite di memoria dell'hosting. Riscritto per leggere la
  risposta in streaming (scrittura diretta su file temporaneo, lettura riga
  per riga con `fgetcsv`), tenendo in memoria solo un pool limitato dei
  candidati più intensi, con un tetto di sicurezza di 40.000 righe esaminate
  indipendentemente dalla dimensione reale del dataset.
- **Timeout di connessione troppo aggressivo**: nella scorsa versione l'avevo
  abbassato a 5s per far fallire GDELT più in fretta (visto che il blocco è
  di rete, non di lentezza), ma per errore l'avevo applicato a **tutte** le
  fonti, non solo GDELT — rischiando di interrompere prematuramente anche
  query legittime ma un po' più lente (come i dati ACLED). Riportato a 8s
  per tutte le fonti tranne il caso GDELT specifico, dove comunque non fa
  differenza (il blocco è indipendente dal timeout).

**Diagnostica migliorata:**
- Il test ACLED in `diagnostics.php` prima usava una funzione che, in caso
  di fallimento della richiesta dati, restituiva semplicemente "nessuna
  risposta" senza dettagli — utile a poco. Ora cattura l'errore curl e lo
  status HTTP reali della query dati (non solo dell'autenticazione), per
  distinguere un vero fallimento di rete da uno zero-risultati legittimo.
- `events.php` ora include `counts_by_source` (quanti eventi arrivano da
  GDACS, USGS, FIRMS separatamente), visibile anche nell'interfaccia sotto
  "Aggiornato". Utile perché GDACS e FIRMS sono feed in tempo reale — un
  conteggio totale che varia anche sensibilmente tra due richieste fatte in
  momenti diversi (es. con periodo diverso) non è necessariamente un bug,
  ma ora è verificabile invece di essere un numero opaco.

## Correzioni UI e nuove fonti (v1.4.0)

**Bug corretti:**
- **Colori dei marker troppo simili**: palette rivista con tonalità nettamente
  distinte (blu/ambra/rosso per l'intensità dei conflitti, viola/magenta/
  turchese per disordini/atrocità/disastri), più contrasto e opacità di
  riempimento più alta.
- **La lista non era più scrollabile dopo aver chiuso un dettaglio** (serviva
  ricaricare la pagina): il colpevole era `document.getElementById('listView')
  .style.display = 'block'` — uno stile inline che sovrascrive silenziosamente
  la regola CSS `display:flex` da cui dipende lo scroll del figlio. Sostituito
  con classi CSS (`.pane-hidden`), che non entrano in conflitto.
- **Il pannello laterale desktop restava piccolo dopo un dettaglio**: stesso
  identico bug (stile inline vs CSS), risolto allo stesso modo.
- **NASA FIRMS restituiva sempre 0 risultati**: dominio scritto male nel
  codice (`firms.modaps.eosdis.gov` invece di `firms.modaps.eosdis.nasa.gov`
  — mancava ".nasa."). Corretto.
- **Nome paese sbagliato per il conflitto del Kivu**: ACLED elenca il paese
  come "Democratic Republic of Congo" (senza "the"), un mismatch che fa
  tornare silenziosamente zero risultati per quel conflitto. Se il tuo
  database è già popolato, esegui `sql/005_fix_drc_country_name.sql`.
- **Intensità sempre bassa / nessun disordine o atrocità rilevato**: non ho
  potuto isolare una causa certa senza vedere una risposta reale del tuo
  account ACLED, quindi ho esteso `api/diagnostics.php` con un test dal vivo
  su un paese sicuramente attivo (Sudan, ultimo anno): mostra il conteggio
  reale delle righe e la distribuzione per `event_type`. Rilanciala e guarda
  il campo `acled.test_event_type_breakdown` — se è vuoto o mancano proprio
  Riots/Protests/Violence against civilians, quel campo dice esattamente
  dove si ferma.

**Novità:**
- **Zoom automatico**: tornando alla lista, la mappa torna alla vista
  mondiale invece di restare sull'ultimo evento aperto.
- **Periodo esteso fino a 10 anni** (prima il tetto era 365 giorni).
- **Filtro "Solo eventi in corso (ultime 48h)"**: un interruttore che, se
  attivo, ignora il periodo selezionato e mostra solo l'attualità più
  stretta — utile per distinguere "cosa sta succedendo ora" da "cosa è
  successo nell'intervallo scelto".
- **Due nuove fonti per eventi locali/a basso impatto** che GDACS da solo
  non copre (vedi sezione fonti più sopra): **USGS** (terremoti di qualsiasi
  magnitudo) e **NASA FIRMS** (incendi rilevati via satellite, incluso il
  caso "incendio locale che non fa notizia internazionale").
- **Menu ☰ in alto a sinistra** con due voci: **About** (apre una scheda con
  versione, descrizione, changelog e contatti — `sputnik@sputlab.com` per
  domande generali, `bug@sputlab.com` per segnalazioni) e **Support** (link
  a https://www.sputlab.com/donate).

## Correzioni UI recenti (v1.3.0)

- **Il pannello laterale desktop restava piccolo dopo aver chiuso un
  dettaglio**: lo script impostava l'altezza del pannello via stile inline
  (per il comportamento "a tendina" su mobile), che però vince sempre sulla
  regola CSS pensata per tenerlo a piena altezza da desktop. Ora lo stile
  inline si tocca solo sotto i 720px di larghezza — da desktop il pannello
  resta sempre gestito dal CSS.
- **Tornando alla lista, la mappa ora torna alla vista mondiale** (zoom 2,
  centrata) invece di restare sullo zoom del singolo evento aperto in
  precedenza.

## Prossimi affinamenti possibili

- Pannello admin minimale per modificare `conflicts` (oggi solo via SQL diretto).
- Selettore di periodo "personalizzato" con due date invece dei soli preset.
- Un piccolo indicatore "aggiornato X secondi fa" basato sul flag `cached`
  che ogni endpoint già restituisce.
