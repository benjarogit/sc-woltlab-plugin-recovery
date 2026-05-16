#!/usr/bin/env bash
# Kopiert das Plugin Recovery Tool (eine Datei) ins WoltLab-Hauptverzeichnis.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS_DIR="$(dirname "$SCRIPT_DIR")"

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

if [ -x "$SCRIPT_DIR/validate-php-syntax.sh" ]; then
	"$SCRIPT_DIR/validate-php-syntax.sh"
fi

cp -v "$SCRIPT_DIR/plugin-recovery-tool.php" "$TARGET/plugin-recovery-tool.php"

echo ""
echo "Recovery Tool (eine Datei) bereitgestellt in: $TARGET"
echo "Aufruf: …/plugin-recovery-tool.php"
echo "Auth-Datei wird im Tool heruntergeladen (plugin-recovery-auth.php)."
echo "Nach Gebrauch: Cleanup im Tool."
