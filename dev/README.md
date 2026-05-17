# Entwickler-Hilfen (nicht für Endnutzer)

**Endnutzer** laden aus [GitHub Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases):

- `plugin-recovery-tool.php` (Stub)
- `recovery-X.Y.Z.tar.gz` (wird nach Auth automatisch installiert)

## Struktur (v2.0)

| Pfad | Inhalt |
|------|--------|
| `stub/` | Release-Stub |
| `recovery-src/` | Paket-Quellen → `dist/recovery-tool/` |
| `dist/` | Build-Artefakte (gitignored) |
| `dev/legacy-monolith.php` | Referenz 1.x (nicht releasen) |

## Skripte

| Datei | Zweck |
|--------|--------|
| `build-release.sh [VERSION]` | `dist/recovery-{VERSION}.tar.gz` + Stub |
| `validate-php-syntax.sh` | `php -l` für `stub/` und `recovery-src/` |
| `extract-package-app.sh` | `app.php` aus Monolith regenerieren |
| `split-modes.sh` | Modi in `lib/Recovery/Modes/` aufteilen |
| `deploy-recovery.sh` | Stub + Paket in lokale WoltLab-Installation |

```bash
./dev/build-release.sh 2.0.0
./dev/validate-php-syntax.sh
./dev/deploy-recovery.sh /pfad/zum/wcf-root
```

Auth-Datei `plugin-recovery-auth.php` wird **vom Stub auf dem Server** erzeugt — nicht aus dem Repository.
