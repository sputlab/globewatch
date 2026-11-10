#!/bin/sh
# Regenerates every PWA icon from docs/branding/globewatch-icon.jpg (macOS: uses sips).
# The source has rounded corners baked in, so the outer 7% is cropped to get a full-bleed square;
# the maskable variants reuse the full-bleed image.
set -e
cd "$(dirname "$0")/.."
SRC=docs/branding/globewatch-icon.jpg
OUT=assets/icons
TMP=$(mktemp -d)
sips -s format png -c 1760 1760 --cropOffset 144 144 "$SRC" --out "$TMP/full.png" >/dev/null
for s in 512 192 180 32; do
  sips -s format png -z $s $s "$TMP/full.png" --out "$OUT/icon-$s.png" >/dev/null
done
mv "$OUT/icon-180.png" "$OUT/apple-touch-icon.png"
mv "$OUT/icon-32.png" "$OUT/favicon-32.png"
# maskable: the artwork sits within ~42% of the centre (safe zone 40%, OS mask cuts at 50%), so the full-bleed image is used as is
cp "$OUT/icon-512.png" "$OUT/icon-maskable-512.png"
cp "$OUT/icon-192.png" "$OUT/icon-maskable-192.png"
rm -rf "$TMP"
ls -l "$OUT"
