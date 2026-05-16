#!/usr/bin/env bash
# Syntax-Check für plugin-recovery-tool.php (nur Entwicklung / vor Release).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${REPO_ROOT}/plugin-recovery-tool.php"

PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
	for candidate in php8.4 php84 php8.3 php83 php8.2 php82 php8.1 php81 php; do
		if command -v "$candidate" >/dev/null 2>&1; then
			PHP_BIN="$candidate"
			break
		fi
	done
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
	echo "Fehler: PHP nicht gefunden. Bitte PHP installieren oder PHP_BIN setzen." >&2
	exit 1
fi

echo "Prüfe: $TARGET ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
"$PHP_BIN" -l "$TARGET"
