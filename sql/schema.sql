-- =====================================================================
-- GlobeWatch 2.0 — definitive database schema (MySQL 5.7+ / MariaDB 10.3+)
--
-- Import once into an empty database (phpMyAdmin > Import), or open
-- admin.php and press "Install database": it runs this same file.
-- Re-running it is harmless: tables are created only if missing and
-- seed rows never overwrite existing data.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Curated conflict zones shown on the map
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS globewatch_conflicts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug            VARCHAR(80)  NOT NULL,
  name            VARCHAR(160) NOT NULL COMMENT 'Italian name',
  name_en         VARCHAR(160) DEFAULT NULL COMMENT 'English name (falls back to name)',
  region          VARCHAR(80)  NOT NULL,
  region_en       VARCHAR(80)  DEFAULT NULL,
  lat             DECIMAL(8,5) NOT NULL,
  lng             DECIMAL(8,5) NOT NULL,
  since_year      SMALLINT UNSIGNED NOT NULL,
  actors          VARCHAR(255) DEFAULT NULL,
  actors_en       VARCHAR(255) DEFAULT NULL,
  description     TEXT,
  description_en  TEXT,
  gdelt_keywords  VARCHAR(400) DEFAULT NULL COMMENT 'GDELT exact phrases, comma separated, each at least 5 characters',
  countries       VARCHAR(200) DEFAULT NULL COMMENT 'Country names used for ACLED, UCDP, HDX HAPI and ReliefWeb (see api/countries.php)',
  manual_severity ENUM('auto','low','medium','high') NOT NULL DEFAULT 'auto' COMMENT 'auto = computed from live data',
  active          TINYINT(1) NOT NULL DEFAULT 1,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_active_name (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Per-source stale-while-revalidate cache + small key/value store
-- (rate-limit clocks, tokens, source health: keys prefixed "kv:").
-- Times are unix seconds. Disposable: emptying it only costs a few slower requests.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS globewatch_api_cache (
  cache_key   VARCHAR(190) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  fetched_at  INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  INT UNSIGNED NOT NULL DEFAULT 0,
  stale_until INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (cache_key),
  KEY idx_stale (stale_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Historical ACLED archive (older than one year), synced from admin.php
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS globewatch_archived_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source          VARCHAR(10)  NOT NULL DEFAULT 'acled',
  source_event_id VARCHAR(150) NOT NULL,
  conflict_id     INT UNSIGNED DEFAULT NULL,
  category        ENUM('conflict','unrest','atrocity') NOT NULL,
  event_type      VARCHAR(100) DEFAULT NULL,
  subtype         VARCHAR(100) DEFAULT NULL,
  country         VARCHAR(100) DEFAULT NULL,
  event_date      DATE NOT NULL,
  lat             DECIMAL(8,5) DEFAULT NULL,
  lng             DECIMAL(8,5) DEFAULT NULL,
  fatalities      SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_evt (source, source_event_id),
  KEY idx_conflict_date (conflict_id, event_date),
  KEY idx_category_date (category, event_date),
  KEY idx_category_country_date (category, country, event_date),
  CONSTRAINT fk_gw_arc_conflict FOREIGN KEY (conflict_id) REFERENCES globewatch_conflicts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Progress of the archive sync, one row per (conflict, country) scope
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS globewatch_sync_state (
  scope        VARCHAR(160) NOT NULL,
  conflict_id  INT UNSIGNED DEFAULT NULL,
  country      VARCHAR(100) NOT NULL,
  synced_until DATE DEFAULT NULL,
  done         TINYINT(1) NOT NULL DEFAULT 0,
  rows_synced  INT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (scope),
  KEY idx_conflict (conflict_id),
  CONSTRAINT fk_gw_sync_conflict FOREIGN KEY (conflict_id) REFERENCES globewatch_conflicts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- On/off switch for every data source (managed from admin.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS globewatch_source_toggles (
  source  VARCHAR(30) NOT NULL,
  enabled TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Seed data
-- =====================================================================

-- ACLED starts disabled: its API requires a paid plan.
INSERT IGNORE INTO globewatch_source_toggles (source, enabled) VALUES
  ('gdelt',1),('hapi',1),('acled',0),('gdacs',1),('usgs',1),('firms',1),('eonet',1),('ucdp',1),
  ('reliefweb',1),('reliefweb_disasters',1),('who_don',1),('ifrc',1),('cems',1),('ucdp_candidate',1),
  ('acaps',1),('inform',1),('emsc',1),('nhc',1),('tsunami',1),('meteoalarm',1),('ioda',1),('gvp',1),
  ('nws',1),('cap',1),('hans',1),('asam',1),('locust',1),('swpc',1),('hapi_context',1),
  ('alerts_ua',1),('cfradar',1),('idmc',1);

INSERT IGNORE INTO globewatch_conflicts
  (slug, name, name_en, region, region_en, lat, lng, since_year, actors, actors_en, description, description_en, gdelt_keywords, countries)
VALUES
('medio-oriente', 'Crisi mediorientale (Israele–Iran–Libano–Gaza)', 'Middle East crisis (Israel–Iran–Lebanon–Gaza)', 'Medio Oriente', 'Middle East', 32.00000, 35.50000, 1948, 'Israele, Iran, Hezbollah, fazioni palestinesi', 'Israel, Iran, Hezbollah, Palestinian factions', 'Rete di conflitti interconnessi che coinvolge Israele, i territori palestinesi, il Libano e l\'Iran.', 'Network of interconnected conflicts involving Israel, the Palestinian territories, Lebanon and Iran.', 'Gaza Strip,Hezbollah,West Bank,Israeli strike,Israel Iran', 'Israel,Palestine,Lebanon,Iran'),
('ucraina', 'Guerra russo-ucraina', 'Russia–Ukraine war', 'Europa orientale', 'Eastern Europe', 49.00000, 32.00000, 2022, 'Russia, Ucraina', 'Russia, Ukraine', 'Conflitto su larga scala avviato con l\'invasione russa del febbraio 2022.', 'Full-scale war launched by the Russian invasion of February 2022.', 'Ukraine war,Ukrainian forces,Russian forces,Donbas', 'Ukraine,Russia'),
('sudan', 'Guerra civile in Sudan', 'Sudanese civil war', 'Africa', 'Africa', 15.50000, 32.50000, 2023, 'SAF, RSF', 'SAF, RSF', 'Scontro tra l\'esercito regolare e i paramilitari RSF.', 'Fighting between the regular army and the RSF paramilitaries.', 'Rapid Support Forces,Sudan war,Darfur,El Fasher', 'Sudan'),
('kivu', 'Conflitto nel Kivu (RD Congo)', 'Kivu conflict (DR Congo)', 'Africa', 'Africa', -1.70000, 29.20000, 1996, 'Governo RDC, M23', 'DRC government, M23', 'Insurrezione nell\'est della RD Congo guidata dal gruppo M23.', 'Insurgency in eastern DR Congo led by the M23 group.', 'North Kivu,South Kivu,M23 rebels', 'Democratic Republic of Congo'),
('myanmar', 'Guerra civile in Myanmar', 'Myanmar civil war', 'Asia sud-orientale', 'Southeast Asia', 21.90000, 96.00000, 1948, 'Giunta militare, forze di resistenza', 'Military junta, resistance forces', 'Conflitto pluridecennale riacceso dal colpo di stato del 2021.', 'Decades-long conflict reignited by the 2021 coup.', 'Myanmar junta,Myanmar military,Myanmar resistance', 'Myanmar'),
('sahel', 'Insurrezione jihadista nel Sahel', 'Jihadist insurgency in the Sahel', 'Africa', 'Africa', 14.50000, -2.00000, 2012, 'Mali, Burkina Faso, Niger, JNIM, ISGS', 'Mali, Burkina Faso, Niger, JNIM, ISGS', 'Espansione jihadista nel Sahel centrale.', 'Jihadist expansion across the central Sahel.', 'Burkina Faso attack,Mali jihadists,Niger militants,Sahel jihadist', 'Mali,Burkina Faso,Niger'),
('pakistan-afghanistan', 'Conflitto Pakistan–Afghanistan', 'Pakistan–Afghanistan conflict', 'Asia meridionale', 'South Asia', 33.90000, 69.50000, 2025, 'Pakistan, Talebani afghani', 'Pakistan, Afghan Taliban', 'Scontri transfrontalieri intensificatisi nel 2025-2026.', 'Cross-border clashes that intensified in 2025-2026.', 'Afghan Taliban,Durand Line,Pakistan Afghanistan', 'Pakistan,Afghanistan'),
('yemen', 'Guerra civile in Yemen', 'Yemeni civil war', 'Medio Oriente', 'Middle East', 15.50000, 44.20000, 2014, 'Governo riconosciuto, Houthi', 'Recognized government, Houthis', 'Conflitto interno con coinvolgimento regionale.', 'Internal conflict with regional involvement.', 'Houthis,Houthi rebels,Yemen war', 'Yemen'),
('somalia', 'Insurrezione di al-Shabaab', 'Al-Shabaab insurgency', 'Africa', 'Africa', 2.00000, 45.30000, 1991, 'Governo somalo, al-Shabaab', 'Somali government, al-Shabaab', 'Insurrezione islamista di lunga durata.', 'Long-running Islamist insurgency.', 'al-Shabaab,Al Shabaab,Mogadishu', 'Somalia'),
('messico', 'Violenza dei cartelli in Messico', 'Cartel violence in Mexico', 'Nord America', 'North America', 23.60000, -102.50000, 2006, 'Governo, cartelli della droga', 'Government, drug cartels', 'Violenza armata diffusa legata al narcotraffico.', 'Widespread armed violence linked to drug trafficking.', 'cartel violence,Sinaloa cartel,Mexican cartel', 'Mexico'),
('venezuela', 'Instabilità interna in Venezuela', 'Internal instability in Venezuela', 'Sud America', 'South America', 8.00000, -66.00000, 2026, 'Governo, gruppi armati, attori esterni', 'Government, armed groups, external actors', 'Crisi politico-militare interna aggravata nel 2026.', 'Political and military crisis that worsened in 2026.', 'Venezuela crisis,Venezuelan military,Maduro', 'Venezuela'),
('nigeria', 'Insurrezione di Boko Haram / ISWAP', 'Boko Haram / ISWAP insurgency', 'Africa', 'Africa', 11.80000, 13.20000, 2009, 'Governo nigeriano, Boko Haram, ISWAP', 'Nigerian government, Boko Haram, ISWAP', 'Insurrezione jihadista nel nord-est della Nigeria.', 'Jihadist insurgency in north-eastern Nigeria.', 'Boko Haram,ISWAP,Borno attack', 'Nigeria'),
('haiti', 'Crisi delle bande armate in Haiti', 'Armed gang crisis in Haiti', 'Caraibi', 'Caribbean', 18.90000, -72.30000, 2021, 'Governo di transizione, coalizioni di bande', 'Transitional government, gang coalitions', 'Collasso della sicurezza pubblica nella capitale.', 'Collapse of public security in the capital.', 'Haiti gang,Port-au-Prince gang,Viv Ansanm', 'Haiti'),
('etiopia', 'Tensioni post-belliche in Etiopia', 'Post-war tensions in Ethiopia', 'Africa', 'Africa', 9.10000, 38.70000, 2020, 'Governo federale, milizie regionali', 'Federal government, regional militias', 'Fragile stabilizzazione dopo il conflitto del Tigray.', 'Fragile stabilization after the Tigray war.', 'Amhara Fano,Tigray,Ethiopia conflict', 'Ethiopia'),
('libia', 'Frammentazione in Libia', 'Fragmentation in Libya', 'Africa', 'Africa', 26.30000, 17.20000, 2011, 'Governi rivali, milizie locali', 'Rival governments, local militias', 'Divisione politico-militare tra amministrazioni concorrenti.', 'Political and military division between competing administrations.', 'Libya clashes,Tripoli militia,Haftar', 'Libya'),
('siria', 'Instabilità post-transizione in Siria', 'Post-transition instability in Syria', 'Medio Oriente', 'Middle East', 35.00000, 38.50000, 2024, 'Nuova amministrazione, milizie residue', 'New administration, residual militias', 'Transizione dopo la caduta del governo Assad.', 'Transition after the fall of the Assad government.', 'Syria violence,Syrian government forces,Alawite,Sweida', 'Syria'),
('nagorno-karabakh', 'Tensione Armenia–Azerbaigian', 'Armenia–Azerbaijan tension', 'Caucaso', 'Caucasus', 39.80000, 46.70000, 1988, 'Armenia, Azerbaigian', 'Armenia, Azerbaijan', 'Contenzioso di lunga data sul Nagorno-Karabakh.', 'Long-standing dispute over Nagorno-Karabakh.', 'Armenia Azerbaijan,Nagorno-Karabakh', 'Armenia,Azerbaijan'),
('colombia-eln', 'Insurrezione dell\'ELN in Colombia', 'ELN insurgency in Colombia', 'Sud America', 'South America', 6.20000, -73.10000, 1964, 'Governo colombiano, ELN', 'Colombian government, ELN', 'Guerriglia storica ancora attiva in diverse regioni rurali.', 'Historic guerrilla still active in several rural regions.', 'ELN guerrilla,Colombia guerrilla,Catatumbo', 'Colombia');
