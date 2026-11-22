#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ $# -gt 1 ]]; then
  echo "Usage: $0 [X.Y.Z] (local build only)" >&2
  exit 2
fi
VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
  VERSION="$(python3 - "$ROOT/recovery-src/version.php" <<'PY_VERSION'
import re, sys
from pathlib import Path
match = re.search(r"define\('RECOVERY_PACKAGE_VERSION',\s*'([^']+)'\)", Path(sys.argv[1]).read_text())
if not match:
    raise SystemExit('Cannot determine existing package version; specify X.Y.Z')
print(match[1])
PY_VERSION
)"
fi
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid version; expected X.Y.Z" >&2
  exit 2
fi
DIST="${RECOVERY_DIST_DIR:-$ROOT/dist}"
python3 - "$ROOT" "$DIST" <<'PY_OUTPUT'
from pathlib import Path
import sys
root, out = (Path(p).resolve() for p in sys.argv[1:])
if root == out or root.is_relative_to(out) or (out.is_relative_to(root) and not out.is_relative_to(root / 'dist')):
    raise SystemExit('Build output must not overwrite source or a source ancestor')
if (out / 'recovery-tool').is_symlink():
    raise SystemExit('Refusing symlinked package staging directory')
for folder in ['stub', 'recovery-src']:
    for item in (root / folder).rglob('*'):
        if item.is_symlink():
            raise SystemExit('Source symlinks are not permitted in a release build')
        if item.name == '.env' or item.name.startswith('.env.') or item.name == 'plugin-recovery-auth.php':
            raise SystemExit('Runtime or secret file in source tree')
PY_OUTPUT
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
# Dateien direkt im Archiv-Root (ohne ./ — PharData kann „.“ nicht entpacken)
python3 - "$PKG" "$DIST/recovery-${VERSION}.tar.gz" <<'PY_ARCHIVE'
import gzip, sys, tarfile
from pathlib import Path
root, output = map(Path, sys.argv[1:])
def normalize(info):
    info.uid = info.gid = 0
    info.uname = info.gname = ''
    info.mtime = 0
    info.mode = 0o755 if info.isdir() else 0o644
    return info
with output.open('wb') as stream:
    with gzip.GzipFile(filename='', mode='wb', fileobj=stream, mtime=0) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as archive:
            for path in sorted(root.rglob('*')):
                archive.add(path, arcname=path.relative_to(root).as_posix(), recursive=False, filter=normalize)
PY_ARCHIVE

# Stub: Shell + Logger + Auth + CSS inline → eine Release-Datei
STUB_OUT="$DIST/plugin-recovery-tool.php"
python3 - "$ROOT" "$VERSION" "$STUB_OUT" <<'PY'
import hashlib
import re
import sys
from pathlib import Path

root, version, out = Path(sys.argv[1]), sys.argv[2], Path(sys.argv[3])
wizard_css = (root / 'stub/recovery-stub-wizard.css').read_text(encoding='utf-8')
acp_layout_css = (root / 'stub/recovery-acp-layout.css').read_text(encoding='utf-8')
shell = (root / 'stub/recovery-stub-shell.php').read_text(encoding='utf-8')
logger = (root / 'stub/recovery-stub-logger.php').read_text(encoding='utf-8')
auth = (root / 'stub/recovery-stub-auth.php').read_text(encoding='utf-8')
main = (root / 'stub/plugin-recovery-tool.php').read_text(encoding='utf-8')

for pattern in (
    r"require __DIR__ \. '/recovery-stub-shell\.php';\n",
    r"require __DIR__ \. '/recovery-stub-logger\.php';\n",
    r"require __DIR__ \. '/recovery-stub-auth\.php';\n",
):
    main = re.sub(pattern, '', main)


def strip_php_header(src: str) -> str:
    src = re.sub(r'<\?php\s*', '', src, count=1)
    src = re.sub(r"declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*", '', src, count=1)
    return src.lstrip('\n')


shell = strip_php_header(shell)
logger = strip_php_header(logger)
auth = strip_php_header(auth)
main = strip_php_header(main)

css_fn = (
    "function recoveryStubWizardCss(): string\n"
    "{\n    return <<<'RECOVERY_STUB_WIZARD_CSS'\n"
    + wizard_css
    + "\nRECOVERY_STUB_WIZARD_CSS;\n}\n"
)
shell = re.sub(
    r"function recoveryStubWizardCss\(\): string\s*\{.*?\n\}",
    css_fn,
    shell,
    count=1,
    flags=re.DOTALL,
)

acp_layout_fn = (
    "function recoveryStubAcpLayoutCss(): string\n"
    "{\n    return <<<'RECOVERY_STUB_ACP_LAYOUT_CSS'\n"
    + acp_layout_css
    + "\nRECOVERY_STUB_ACP_LAYOUT_CSS;\n}\n"
)
shell = re.sub(
    r"function recoveryStubAcpLayoutCss\(\): string\s*\{.*?\n\}",
    acp_layout_fn,
    shell,
    count=1,
    flags=re.DOTALL,
)

merged = "<?php\n\ndeclare(strict_types=1);\n\n" + shell + "\n" + logger + "\n" + auth + "\n" + main
merged = re.sub(
    r"define\('RECOVERY_STUB_VERSION',\s*'[^']*'\);",
    f"define('RECOVERY_STUB_VERSION', '{version}');",
    merged,
    count=1,
)
merged = re.sub(
    r"define\('RECOVERY_PACKAGE_VERSION',\s*'[^']*'\);",
    f"define('RECOVERY_PACKAGE_VERSION', '{version}');",
    merged,
    count=1,
)

placeholder = '0' * 64
if "RECOVERY_STUB_INTEGRITY_HASH" not in merged:
    merged = merged.replace(
        f"define('RECOVERY_PACKAGE_VERSION', '{version}');",
        f"define('RECOVERY_PACKAGE_VERSION', '{version}');\n"
        f"define('RECOVERY_STUB_INTEGRITY_HASH', '{placeholder}');",
        1,
    )
else:
    merged = re.sub(
        r"^define\('RECOVERY_STUB_INTEGRITY_HASH',\s*'[^']*'\);",
        f"define('RECOVERY_STUB_INTEGRITY_HASH', '{placeholder}');",
        merged,
        count=1,
        flags=re.MULTILINE,
    )

integrity_pat = r"^define\('RECOVERY_STUB_INTEGRITY_HASH',\s*'[^']*'\);"
canonical = re.sub(
    integrity_pat,
    f"define('RECOVERY_STUB_INTEGRITY_HASH', '{placeholder}');",
    merged,
    count=1,
    flags=re.MULTILINE,
)
integrity = hashlib.sha256(canonical.encode('utf-8')).hexdigest()
merged = re.sub(
    integrity_pat,
    f"define('RECOVERY_STUB_INTEGRITY_HASH', '{integrity}');",
    merged,
    count=1,
    flags=re.MULTILINE,
)

out.write_text(merged, encoding='utf-8')
print('stub merged', out, 'bytes', out.stat().st_size, 'integrity', integrity[:16] + '…')
PY

python3 - "$ROOT" "$DIST" "$VERSION" <<'PY_MANIFEST'
import hashlib, json, sys
from pathlib import Path
root, dist, version = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
digest = hashlib.sha256()
for folder in ['stub', 'recovery-src']:
    for path in sorted((root / folder).rglob('*')):
        if path.is_file():
            digest.update(path.relative_to(root).as_posix().encode() + b'\0')
            digest.update(path.read_bytes())
artifacts = {}
for name in ['plugin-recovery-tool.php', 'recovery-' + version + '.tar.gz']:
    artifacts[name] = hashlib.sha256((dist / name).read_bytes()).hexdigest()
(dist / 'build-manifest.json').write_text(json.dumps({'schema': 1, 'version': version,
    'source_sha256': digest.hexdigest(), 'builder_sha256': hashlib.sha256((root / 'dev/build-release.sh').read_bytes()).hexdigest(), 'artifacts': artifacts}, indent=2) + '\n')
PY_MANIFEST

echo "==> local build output (no commit, push or release)"
ls -la "$DIST/recovery-${VERSION}.tar.gz" "$STUB_OUT"
echo "OK"
