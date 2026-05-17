#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-2.0.0}"
DIST="$ROOT/dist"
PKG="$DIST/recovery-tool"

echo "==> Recovery Release v${VERSION}"

rm -rf "$PKG"
mkdir -p "$PKG" "$DIST"

rsync -a --exclude='.git' --exclude='.cache' \
  "$ROOT/recovery-src/" "$PKG/"

# manifest.json Version setzen
python3 - "$PKG/manifest.json" "$VERSION" <<'PY'
import json, sys
path, ver = sys.argv[1], sys.argv[2]
data = json.load(open(path, encoding='utf-8'))
data['version'] = ver
data['minStubVersion'] = ver
json.dump(data, open(path, 'w', encoding='utf-8'), indent=4)
print('manifest', ver)
PY

# version.php im Paket
cat > "$PKG/version.php" <<PHP
<?php

declare(strict_types=1);

define('RECOVERY_STUB_VERSION', '${VERSION}');
define('RECOVERY_PACKAGE_VERSION', '${VERSION}');
define('RECOVERY_VERSION', RECOVERY_PACKAGE_VERSION);
define('RECOVERY_GITHUB_REPO', 'benjarogit/sc-woltlab-plugin-recovery');
PHP

# tar.gz
rm -f "$DIST/recovery-${VERSION}.tar.gz"
tar -czf "$DIST/recovery-${VERSION}.tar.gz" -C "$DIST" recovery-tool

# Stub mit Version
STUB_OUT="$DIST/plugin-recovery-tool.php"
sed -e "s/define('RECOVERY_STUB_VERSION', '[^']*');/define('RECOVERY_STUB_VERSION', '${VERSION}');/" \
    -e "s/define('RECOVERY_PACKAGE_VERSION', '[^']*');/define('RECOVERY_PACKAGE_VERSION', '${VERSION}');/" \
    "$ROOT/stub/plugin-recovery-tool.php" > "$STUB_OUT"

echo "==> dist/"
ls -la "$DIST/recovery-${VERSION}.tar.gz" "$STUB_OUT"
echo "OK"
