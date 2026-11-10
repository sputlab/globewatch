#!/bin/sh
# Usage: tools/bump-version.sh 2.2.0
# Updates the version in api/version.php, sw.js (cache name) and the README title line check.
set -e
[ -n "$1" ] || { echo "usage: $0 <major.minor.patch>"; exit 1; }
cd "$(dirname "$0")/.."
sed -i.bak "s/define('GW_VERSION', '[^']*')/define('GW_VERSION', '$1')/" api/version.php
sed -i.bak "s/^const VERSION = '[^']*'/const VERSION = '$1'/" sw.js
rm -f api/version.php.bak sw.js.bak
grep -q "v:'$1'" assets/js/i18n.js || echo "reminder: add a {v:'$1', …} entry to CHANGELOG in assets/js/i18n.js and to README.md"
echo "version set to $1"
