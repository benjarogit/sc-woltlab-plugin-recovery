#!/usr/bin/env bash
# Explicit development copy only. Take and verify a WSC Lokal snapshot first.
# No parent .env, implicit target, automatic build or replacement of existing tools.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ $# -ne 3 || "${1:-}" != "--apply" || "$2" != /* || ! "$3" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 --apply /absolute/woltlab-root X.Y.Z" >&2
  echo "Requires explicit target authorization and a completed file/database snapshot." >&2
  exit 2
fi
TARGET="${2%/}"
VERSION="$3"
DIST="${RECOVERY_DIST_DIR:-$ROOT/dist}"
if [[ ! -f "$TARGET/global.php" || -L "$TARGET" || -e "$TARGET/plugin-recovery-tool.php" || -e "$TARGET/recovery-tool" ]]; then
  echo "BLOCKED: invalid target or existing Recovery installation; no automatic overwrite." >&2
  exit 2
fi
"$ROOT/dev/validate-php-syntax.sh"
"$ROOT/dev/publish-github-release.sh" "$VERSION" >/dev/null
if [[ ! -f "$DIST/plugin-recovery-tool.php" || ! -f "$DIST/recovery-${VERSION}.tar.gz" ]]; then
  echo "BLOCKED: build and review artifacts before deploying." >&2
  exit 2
fi
mkdir "$TARGET/recovery-tool"
tar -xzf "$DIST/recovery-${VERSION}.tar.gz" -C "$TARGET/recovery-tool"
cp "$DIST/plugin-recovery-tool.php" "$TARGET/plugin-recovery-tool.php"
echo "Copied explicitly selected prebuilt Recovery artifacts. No repair was executed."
