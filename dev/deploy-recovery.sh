#!/usr/bin/env bash
# Kopiert plugin-recovery-tool.php ins WoltLab-Hauptverzeichnis (nur Entwicklung).
set -euo pipefail

DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$DEV_DIR")"
TOOLS_DIR="$(dirname "$REPO_ROOT")"

TARGET=""

if [ -f "$TOOLS_DIR/.env" ]; then
	# shellcheck disable=SC1091
	source "$TOOLS_DIR/.env"
	TARGET="${WOLTLAB_PUBLIC_DIR:-${WOLTLAB_INSTALL_PATH:-${WOLTLAB_PATH:-}}}"
fi

if [ -n "${1:-}" ]; then
	TARGET="$1"
fi

if [ -z "$TARGET" ]; then
	echo "Verwendung: $0 /pfad/zur/woltlab-installation"
	echo "Oder WOLTLAB_PUBLIC_DIR in tools/.env setzen."
	exit 1
fi

TARGET="${TARGET%/}"

if [ ! -f "$TARGET/global.php" ]; then
	echo "Fehler: global.php nicht gefunden in $TARGET"
	exit 1
fi

if [ -x "$DEV_DIR/validate-php-syntax.sh" ]; then
	"$DEV_DIR/validate-php-syntax.sh"
fi

cp -v "$REPO_ROOT/plugin-recovery-tool.php" "$TARGET/plugin-recovery-tool.php"

echo ""
echo "Recovery Tool (eine Datei) bereitgestellt in: $TARGET"
echo "Aufruf: …/plugin-recovery-tool.php"
echo "Auth-Datei wird vom Tool erzeugt (plugin-recovery-auth.php) — nicht von GitHub."
echo "Nach Gebrauch: Cleanup im Tool."
