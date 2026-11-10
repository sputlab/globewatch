<?php
/*
 * Country lookup: ISO3 code + approximate centroid.
 * Needed because HDX HAPI works with ISO3 codes, while the conflicts table
 * (and ACLED/UCDP) use country names; the centroid places country-level
 * aggregates (HAPI, ReliefWeb) on the map when the source has no coordinates.
 * Add a line here if you add a conflict in a country that is missing.
 */
const GW_COUNTRIES = [
    // ISO3 => [name, lat, lng, ISO2, Gleditsch-Ward code (UCDP; Palestine → Israel 666)]
    'AFG' => ['Afghanistan', 33.9, 67.7, 'AF', 700], 'ALB' => ['Albania', 41.2, 20.2, 'AL', 339], 'DZA' => ['Algeria', 28.0, 1.7, 'DZ', 615],
    'AGO' => ['Angola', -11.2, 17.9, 'AO', 540], 'ARG' => ['Argentina', -38.4, -63.6, 'AR', 160], 'ARM' => ['Armenia', 40.1, 45.0, 'AM', 371],
    'AUS' => ['Australia', -25.3, 133.8, 'AU', 900], 'AUT' => ['Austria', 47.5, 14.6, 'AT', 305], 'AZE' => ['Azerbaijan', 40.1, 47.6, 'AZ', 373],
    'BHS' => ['Bahamas', 25.0, -77.4, 'BS', 31], 'BHR' => ['Bahrain', 26.0, 50.6, 'BH', 692], 'BGD' => ['Bangladesh', 23.7, 90.4, 'BD', 771],
    'BRB' => ['Barbados', 13.2, -59.5, 'BB', 53], 'BLR' => ['Belarus', 53.7, 27.9, 'BY', 370], 'BEL' => ['Belgium', 50.5, 4.5, 'BE', 211],
    'BLZ' => ['Belize', 17.2, -88.5, 'BZ', 80], 'BEN' => ['Benin', 9.3, 2.3, 'BJ', 434], 'BTN' => ['Bhutan', 27.5, 90.4, 'BT', 760],
    'BOL' => ['Bolivia', -16.3, -63.6, 'BO', 145], 'BIH' => ['Bosnia and Herzegovina', 43.9, 17.7, 'BA', 346], 'BWA' => ['Botswana', -22.3, 24.7, 'BW', 571],
    'BRA' => ['Brazil', -14.2, -51.9, 'BR', 140], 'BRN' => ['Brunei', 4.5, 114.7, 'BN', 835], 'BGR' => ['Bulgaria', 42.7, 25.5, 'BG', 355],
    'BFA' => ['Burkina Faso', 12.2, -1.6, 'BF', 439], 'BDI' => ['Burundi', -3.4, 29.9, 'BI', 516], 'KHM' => ['Cambodia', 12.6, 105.0, 'KH', 811],
    'CMR' => ['Cameroon', 7.4, 12.4, 'CM', 471], 'CAN' => ['Canada', 56.1, -106.3, 'CA', 20], 'CPV' => ['Cape Verde', 16.0, -24.0, 'CV', 402],
    'CAF' => ['Central African Republic', 6.6, 20.9, 'CF', 482], 'TCD' => ['Chad', 15.5, 18.7, 'TD', 483], 'CHL' => ['Chile', -35.7, -71.5, 'CL', 155],
    'CHN' => ['China', 35.9, 104.2, 'CN', 710], 'COL' => ['Colombia', 4.6, -74.3, 'CO', 100], 'COM' => ['Comoros', -11.9, 43.9, 'KM', 581],
    'COD' => ['Democratic Republic of Congo', -4.0, 21.8, 'CD', 490], 'COG' => ['Republic of Congo', -0.2, 15.8, 'CG', 484], 'CRI' => ['Costa Rica', 9.7, -83.8, 'CR', 94],
    'HRV' => ['Croatia', 45.1, 15.2, 'HR', 344], 'CUB' => ['Cuba', 21.5, -77.8, 'CU', 40], 'CYP' => ['Cyprus', 35.1, 33.4, 'CY', 352],
    'CZE' => ['Czechia', 49.8, 15.5, 'CZ', 316], 'DNK' => ['Denmark', 56.3, 9.5, 'DK', 390], 'DJI' => ['Djibouti', 11.8, 42.6, 'DJ', 522],
    'DOM' => ['Dominican Republic', 18.7, -70.2, 'DO', 42], 'ECU' => ['Ecuador', -1.8, -78.2, 'EC', 130], 'EGY' => ['Egypt', 26.8, 30.8, 'EG', 651],
    'SLV' => ['El Salvador', 13.8, -88.9, 'SV', 92], 'GNQ' => ['Equatorial Guinea', 1.6, 10.3, 'GQ', 411], 'ERI' => ['Eritrea', 15.2, 39.8, 'ER', 531],
    'EST' => ['Estonia', 58.6, 25.0, 'EE', 366], 'SWZ' => ['Eswatini', -26.5, 31.5, 'SZ', 572], 'ETH' => ['Ethiopia', 9.1, 40.5, 'ET', 530],
    'FJI' => ['Fiji', -17.7, 178.1, 'FJ', 950], 'FIN' => ['Finland', 61.9, 25.7, 'FI', 375], 'FRA' => ['France', 46.2, 2.2, 'FR', 220],
    'GAB' => ['Gabon', -0.8, 11.6, 'GA', 481], 'GMB' => ['Gambia', 13.4, -15.3, 'GM', 420], 'GEO' => ['Georgia', 42.3, 43.4, 'GE', 372],
    'DEU' => ['Germany', 51.2, 10.5, 'DE', 260], 'GHA' => ['Ghana', 7.9, -1.0, 'GH', 452], 'GRC' => ['Greece', 39.1, 21.8, 'GR', 350],
    'GTM' => ['Guatemala', 15.8, -90.2, 'GT', 90], 'GIN' => ['Guinea', 9.9, -9.7, 'GN', 438], 'GNB' => ['Guinea-Bissau', 11.8, -15.2, 'GW', 404],
    'GUY' => ['Guyana', 4.9, -58.9, 'GY', 110], 'HTI' => ['Haiti', 19.0, -72.3, 'HT', 41], 'HND' => ['Honduras', 15.2, -86.2, 'HN', 91],
    'HUN' => ['Hungary', 47.2, 19.5, 'HU', 310], 'ISL' => ['Iceland', 64.96, -19.0, 'IS', 395], 'IND' => ['India', 20.6, 79.0, 'IN', 750],
    'IDN' => ['Indonesia', -0.8, 113.9, 'ID', 850], 'IRN' => ['Iran', 32.4, 53.7, 'IR', 630], 'IRQ' => ['Iraq', 33.2, 43.7, 'IQ', 645],
    'IRL' => ['Ireland', 53.4, -8.2, 'IE', 205], 'ISR' => ['Israel', 31.0, 34.9, 'IL', 666], 'ITA' => ['Italy', 41.9, 12.6, 'IT', 325],
    'CIV' => ['Ivory Coast', 7.5, -5.5, 'CI', 437], 'JAM' => ['Jamaica', 18.1, -77.3, 'JM', 51], 'JPN' => ['Japan', 36.2, 138.3, 'JP', 740],
    'JOR' => ['Jordan', 30.6, 36.2, 'JO', 663], 'KAZ' => ['Kazakhstan', 48.0, 66.9, 'KZ', 705], 'KEN' => ['Kenya', 0.0, 37.9, 'KE', 501],
    'XKX' => ['Kosovo', 42.6, 20.9, 'XK', 347], 'KWT' => ['Kuwait', 29.3, 47.5, 'KW', 690], 'KGZ' => ['Kyrgyzstan', 41.2, 74.8, 'KG', 703],
    'LAO' => ['Laos', 19.9, 102.5, 'LA', 812], 'LVA' => ['Latvia', 56.9, 24.6, 'LV', 367], 'LBN' => ['Lebanon', 33.9, 35.9, 'LB', 660],
    'LSO' => ['Lesotho', -29.6, 28.2, 'LS', 570], 'LBR' => ['Liberia', 6.4, -9.4, 'LR', 450], 'LBY' => ['Libya', 26.3, 17.2, 'LY', 620],
    'LTU' => ['Lithuania', 55.2, 23.9, 'LT', 368], 'LUX' => ['Luxembourg', 49.8, 6.1, 'LU', 212], 'MDG' => ['Madagascar', -18.8, 46.9, 'MG', 580],
    'MWI' => ['Malawi', -13.3, 34.3, 'MW', 553], 'MYS' => ['Malaysia', 4.2, 102.0, 'MY', 820], 'MDV' => ['Maldives', 3.2, 73.2, 'MV', 781],
    'MLI' => ['Mali', 17.6, -4.0, 'ML', 432], 'MLT' => ['Malta', 35.9, 14.4, 'MT', 338], 'MRT' => ['Mauritania', 21.0, -10.9, 'MR', 435],
    'MUS' => ['Mauritius', -20.3, 57.6, 'MU', 590], 'MEX' => ['Mexico', 23.6, -102.5, 'MX', 70], 'MDA' => ['Moldova', 47.4, 28.4, 'MD', 359],
    'MNG' => ['Mongolia', 46.9, 103.8, 'MN', 712], 'MNE' => ['Montenegro', 42.7, 19.4, 'ME', 341], 'MAR' => ['Morocco', 31.8, -7.1, 'MA', 600],
    'MOZ' => ['Mozambique', -18.7, 35.5, 'MZ', 541], 'MMR' => ['Myanmar', 21.9, 96.0, 'MM', 775], 'NAM' => ['Namibia', -22.9, 18.5, 'NA', 565],
    'NPL' => ['Nepal', 28.4, 84.1, 'NP', 790], 'NLD' => ['Netherlands', 52.1, 5.3, 'NL', 210], 'NZL' => ['New Zealand', -40.9, 174.9, 'NZ', 920],
    'NIC' => ['Nicaragua', 12.9, -85.2, 'NI', 93], 'NER' => ['Niger', 17.6, 8.1, 'NE', 436], 'NGA' => ['Nigeria', 9.1, 8.7, 'NG', 475],
    'PRK' => ['North Korea', 40.3, 127.5, 'KP', 731], 'MKD' => ['North Macedonia', 41.6, 21.7, 'MK', 343], 'NOR' => ['Norway', 60.5, 8.5, 'NO', 385],
    'OMN' => ['Oman', 21.5, 55.9, 'OM', 698], 'PAK' => ['Pakistan', 30.4, 69.3, 'PK', 770], 'PSE' => ['Palestine', 31.9, 35.2, 'PS', 666],
    'PAN' => ['Panama', 8.5, -80.8, 'PA', 95], 'PNG' => ['Papua New Guinea', -6.3, 143.9, 'PG', 910], 'PRY' => ['Paraguay', -23.4, -58.4, 'PY', 150],
    'PER' => ['Peru', -9.2, -75.0, 'PE', 135], 'PHL' => ['Philippines', 12.9, 121.8, 'PH', 840], 'POL' => ['Poland', 51.9, 19.1, 'PL', 290],
    'PRT' => ['Portugal', 39.4, -8.2, 'PT', 235], 'QAT' => ['Qatar', 25.4, 51.2, 'QA', 694], 'ROU' => ['Romania', 45.9, 25.0, 'RO', 360],
    'RUS' => ['Russia', 55.8, 45.0, 'RU', 365], 'RWA' => ['Rwanda', -1.9, 29.9, 'RW', 517], 'SAU' => ['Saudi Arabia', 23.9, 45.1, 'SA', 670],
    'SEN' => ['Senegal', 14.5, -14.5, 'SN', 433], 'SRB' => ['Serbia', 44.0, 21.0, 'RS', 345], 'SLE' => ['Sierra Leone', 8.5, -11.8, 'SL', 451],
    'SGP' => ['Singapore', 1.35, 103.8, 'SG', 830], 'SVK' => ['Slovakia', 48.7, 19.7, 'SK', 317], 'SVN' => ['Slovenia', 46.2, 15.0, 'SI', 349],
    'SLB' => ['Solomon Islands', -9.6, 160.2, 'SB', 940], 'SOM' => ['Somalia', 5.2, 46.2, 'SO', 520], 'ZAF' => ['South Africa', -30.6, 22.9, 'ZA', 560],
    'KOR' => ['South Korea', 35.9, 127.8, 'KR', 732], 'SSD' => ['South Sudan', 6.9, 31.3, 'SS', 626], 'ESP' => ['Spain', 40.5, -3.7, 'ES', 230],
    'LKA' => ['Sri Lanka', 7.9, 80.8, 'LK', 780], 'SDN' => ['Sudan', 12.9, 30.2, 'SD', 625], 'SUR' => ['Suriname', 3.9, -56.0, 'SR', 115],
    'SWE' => ['Sweden', 60.1, 18.6, 'SE', 380], 'CHE' => ['Switzerland', 46.8, 8.2, 'CH', 225], 'SYR' => ['Syria', 34.8, 39.0, 'SY', 652],
    'TWN' => ['Taiwan', 23.7, 121.0, 'TW', 713], 'TJK' => ['Tajikistan', 38.9, 71.3, 'TJ', 702], 'TZA' => ['Tanzania', -6.4, 34.9, 'TZ', 510],
    'THA' => ['Thailand', 15.9, 101.0, 'TH', 800], 'TLS' => ['Timor-Leste', -8.9, 125.7, 'TL', 860], 'TGO' => ['Togo', 8.6, 0.8, 'TG', 461],
    'TTO' => ['Trinidad and Tobago', 10.7, -61.2, 'TT', 52], 'TUN' => ['Tunisia', 33.9, 9.5, 'TN', 616], 'TUR' => ['Turkey', 39.0, 35.2, 'TR', 640],
    'TKM' => ['Turkmenistan', 38.97, 59.6, 'TM', 701], 'UGA' => ['Uganda', 1.4, 32.3, 'UG', 500], 'UKR' => ['Ukraine', 48.4, 31.2, 'UA', 369],
    'ARE' => ['United Arab Emirates', 23.4, 53.8, 'AE', 696], 'GBR' => ['United Kingdom', 55.4, -3.4, 'GB', 200], 'USA' => ['United States', 37.1, -95.7, 'US', 2],
    'URY' => ['Uruguay', -32.5, -55.8, 'UY', 165], 'UZB' => ['Uzbekistan', 41.4, 64.6, 'UZ', 704], 'VUT' => ['Vanuatu', -15.4, 166.9, 'VU', 935],
    'VEN' => ['Venezuela', 6.4, -66.6, 'VE', 101], 'VNM' => ['Vietnam', 14.1, 108.3, 'VN', 816], 'YEM' => ['Yemen', 15.6, 48.5, 'YE', 678],
    'ZMB' => ['Zambia', -13.1, 27.8, 'ZM', 551], 'ZWE' => ['Zimbabwe', -19.0, 29.2, 'ZW', 552],
];

function ucdpCode(?string $nameOrIso): ?int { $i = countryInfo($nameOrIso); return $i ? $i['gw'] : null; }
/** ISO 3166 alpha-2 → info (IODA, Meteoalarm). */
function countryByIso2(?string $iso2): ?array {
    static $map = null;
    if ($map === null) { $map = []; foreach (GW_COUNTRIES as $iso => $c) $map[$c[3]] = $iso; }
    $iso = $map[strtoupper((string)$iso2)] ?? null;
    return $iso ? countryInfo($iso) : null;
}
/** Finds the first known country mentioned in a free text (e.g. WHO titles "Cholera – Sudan"). */
function countryInText(string $text): ?array {
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (GW_COUNTRIES as $iso => $c) $names[mb_strtolower($c[0])] = $iso;
        foreach (GW_COUNTRY_ALIASES as $alias => $iso) $names[$alias] = $iso;
        uksort($names, fn($a, $b) => strlen($b) <=> strlen($a));   // longest first: "south sudan" before "sudan"
    }
    $t = mb_strtolower($text);
    foreach ($names as $n => $iso) if (strlen($n) > 3 && preg_match('/\b' . preg_quote($n, '/') . '\b/u', $t)) return countryInfo($iso);
    return null;
}

/** Alternative spellings used by ACLED, UN M49, ReliefWeb... (lowercase => ISO3). */
const GW_COUNTRY_ALIASES = [
    'democratic republic of the congo' => 'COD', 'dr congo' => 'COD', 'drc' => 'COD', 'congo, dem. rep.' => 'COD',
    'democratic republic of congo' => 'COD', 'congo' => 'COG', 'republic of the congo' => 'COG',
    "côte d'ivoire" => 'CIV', "cote d'ivoire" => 'CIV', 'state of palestine' => 'PSE',
    'occupied palestinian territory' => 'PSE', 'palestinian territories' => 'PSE', 'türkiye' => 'TUR', 'turkiye' => 'TUR',
    'syrian arab republic' => 'SYR', 'iran (islamic republic of)' => 'IRN', 'russian federation' => 'RUS',
    'venezuela (bolivarian republic of)' => 'VEN', 'united republic of tanzania' => 'TZA', 'republic of korea' => 'KOR',
    "democratic people's republic of korea" => 'PRK', 'viet nam' => 'VNM', 'bolivia (plurinational state of)' => 'BOL',
    'republic of moldova' => 'MDA', 'united states of america' => 'USA', 'united kingdom of great britain and northern ireland' => 'GBR',
    'czech republic' => 'CZE', 'bosnia-herzegovina' => 'BIH', 'republic of north macedonia' => 'MKD', 'the gambia' => 'GMB',
    "lao people's democratic republic" => 'LAO', 'burma' => 'MMR', 'myanmar (burma)' => 'MMR', 'united states' => 'USA',
    'usa' => 'USA', 'uk' => 'GBR', 'swaziland' => 'SWZ', 'cabo verde' => 'CPV', 'timor leste' => 'TLS', 'east timor' => 'TLS',
    'dr congo (zaire)' => 'COD', 'russia (soviet union)' => 'RUS', 'yemen (north yemen)' => 'YEM', 'serbia (yugoslavia)' => 'SRB',
    'cambodia (kampuchea)' => 'KHM', 'zimbabwe (rhodesia)' => 'ZWE', 'madagascar (malagasy)' => 'MDG',
    'iran, islamic republic of' => 'IRN', 'korea, republic of' => 'KOR', 'moldova, republic of' => 'MDA',
    'tanzania, united republic of' => 'TZA', 'gaza' => 'PSE', 'west bank' => 'PSE', 'korea, north' => 'PRK', 'korea, south' => 'KOR',
];

/** Name or ISO3 → ['iso3','name','lat','lng'] or null. */
function countryInfo(?string $nameOrIso): ?array {
    static $byName = null;
    if ($nameOrIso === null || $nameOrIso === '') return null;
    if ($byName === null) {
        $byName = GW_COUNTRY_ALIASES;
        foreach (GW_COUNTRIES as $iso => $c) $byName[mb_strtolower($c[0])] = $iso;
    }
    $key = trim($nameOrIso);
    $iso = strtoupper($key);
    if (!isset(GW_COUNTRIES[$iso])) $iso = $byName[mb_strtolower($key)] ?? null;
    if ($iso === null || !isset(GW_COUNTRIES[$iso])) return null;
    [$name, $lat, $lng, $iso2, $gw] = GW_COUNTRIES[$iso];
    return ['iso3' => $iso, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'iso2' => $iso2, 'gw' => $gw];
}

/** Deterministic small offset (degrees) so that several markers in the same country don't overlap exactly. */
function gwJitter(string $seed, float $amp = 0.8): array {
    $h = crc32($seed);
    return [(($h & 0xFFFF) / 0xFFFF - 0.5) * 2 * $amp, ((($h >> 16) & 0xFFFF) / 0xFFFF - 0.5) * 2 * $amp];
}
