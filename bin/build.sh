#!/usr/bin/env bash
# Build dist/lingolane-<version>.zip from lingolane/ (excludes tests and dev files).
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 '^ \* Version:' lingolane/lingolane.php | sed 's/.*Version:[[:space:]]*//')
const=$(grep -m1 "define( 'LINGOLANE_VERSION'" lingolane/lingolane.php | sed "s/.*, '\(.*\)' );/\1/")
stable=$(grep -m1 '^Stable tag:' lingolane/readme.txt | sed 's/Stable tag:[[:space:]]*//')
if [ "$version" != "$const" ] || { [ "$stable" != "$version" ] && [ "$stable" != "trunk" ]; }; then
  echo "Version mismatch: header=$version const=$const readme=$stable" >&2
  exit 1
fi
python3 bin/make-mo.py >/dev/null
find lingolane -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
stage=$(mktemp -d)
rsync -a --exclude 'tests' --exclude '.DS_Store' lingolane "$stage/"
if [ -f LICENSE ]; then
  cp LICENSE "$stage/lingolane/LICENSE.txt"
fi
mkdir -p dist
out="$PWD/dist/lingolane-$version.zip"
(cd "$stage" && zip -qr "$out" lingolane)
echo "$out"
