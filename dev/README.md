# Entwickler-Hilfen (nicht für Endnutzer)

**Endnutzer** laden nur **`plugin-recovery-tool.php`** aus den [GitHub Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases) herunter — keine weiteren Dateien aus diesem Ordner.

| Datei | Zweck |
|--------|--------|
| `validate-php-syntax.sh` | `php -l` vor Commit/Release |
| `deploy-recovery.sh` | Kopiert die eine PHP-Datei in eine lokale WoltLab-Installation |
| `debug_invoke_recovery.php` | CLI-Testlauf mit Debug-Logs |

Beim ersten Aufruf des Tools im Browser erzeugt das Tool selbst **`plugin-recovery-auth.php`** auf dem Server (WoltLab-Recovery-Verfahren). Diese Datei kommt **nicht** aus dem Repository.
