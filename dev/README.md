# Entwicklung im eigenständigen Recovery-Repository

Die vollständigen Quellen werden im Repository `sc-woltlab-plugin-recovery` gepflegt. Der bisherige öffentliche `master` mit zwei READMEs und Root-Stub bleibt in der Geschichte erhalten; die importierte SWPM-Quellhistorie ist auf dem Entwicklungsbranch verbunden. Herkunft, Commit-Zuordnung und Vergleich aller 53 importierten Quelldateien stehen in `docs/source-import/manifest.json`.

Endnutzer verwenden weiterhin die freigegebenen Dateien aus GitHub Releases. Ein vorhandener Release-Tag oder der historische Root-Stub beweist nicht die Funktionsprüfung des neuen Quellstands.

## Quellen und erzeugte Dateien

- `stub/`: bearbeitbarer, aufgeteilter Stub samt Auth-/Logger-/Shellquellen und CSS.
- `recovery-src/`: bearbeitbare Paketquellen und vorhandene Versionsangaben.
- `dev/`: lokale Build-, Syntax-, Vorschau-, Deployment- und Entwicklungshilfen.
- `tests/`: ausschließlich synthetische Offline-Regressionen; keine reale Suite wird geladen oder repariert.
- `dist/`: ignorierte lokale Buildausgabe mit Paketarchiv, zusammengefügtem Stub und `build-manifest.json`.
- Root-`plugin-recovery-tool.php`: historisch veröffentlichter Kompatibilitätsstand; erhalten, keine zweite Entwicklungsquelle. Ein Prüfbuild aktualisiert diese Datei nicht.

Es gibt keine Abhängigkeit von einem benachbarten Plugin Manager oder dessen `.env`. PHP >= 8.1 ist die historische Laufzeituntergrenze des Recovery-Werkzeugs; die Offline-Prüfung hier auf PHP 8.5 ist keine vollständige Suite-Kompatibilitätsabnahme.

## Lokaler Build und Prüfungen

```bash
bash dev/validate-php-syntax.sh
python3 -m unittest discover -s tests -p 'test_*.py'
bash dev/build-release.sh
bash dev/publish-github-release.sh 2.7.48
```

Der letzte Befehl prüft ausschließlich vorhandene Artefakte und zeigt den Veröffentlichungsplan. Er startet keine GitHub-Anmeldung und führt keinen Commit, Push, Tag oder Release aus. Ein vorhandener Bericht ersetzt nicht den Blick auf aktuelle Testergebnisse.

Ohne Argument leitet der Build die bereits vorhandene Paketversion aus `recovery-src/version.php` ab, derzeit 2.7.48. Ein optionales explizites `X.Y.Z` ist zulässig. Die Quellenauslagerung erhöht keine Versionsnummer: alte Stub-/Manifestangaben bleiben unverändert, während der Build nur die erzeugten Metadaten auf die gewählte Paketversion normalisiert. Ein beliebiger weiterer Text ist kein zulässiger Versionswert.

`RECOVERY_DIST_DIR` kann eine ausdrücklich gewählte unabhängige Ausgabe angeben, etwa ein temporäres Testverzeichnis. Ein Quellverzeichnis, ein Quellvorfahr oder ein Symlink im Quellbaum wird nicht als Build-Staging akzeptiert. Das Paketarchiv hat direkt `app.php`, `bootstrap.php`, `lib/` usw. an der Archivwurzel; normalisierte Archiveinträge und ein konstanter gzip-Zeitstempel machen gleiche Quellen und Version bytegleich reproduzierbar.

Der Build verändert keine Quellen, erzeugt keine Git-Commits und benötigt keine SWPM-Installation. PHP-Syntaxgate und Regressionen sind getrennte Prüfungen. Fehlendes PHP führt zum Fehlerstatus BLOCKED, nicht zu einem übersprungenen PASS. `tests/test_standalone.py` baut in einer isolierten Quellkopie ohne Nachbarprojekte und prüft unter anderem Versionserhalt, Archiv-/Stub-Reproduzierbarkeit und Integrität, keine Git-/GitHub-Aufrufe im Publisher-Standardpfad, veränderte Artefakte/Quellen, ungültige Versionen, Secret-/Ausgabeschutz, fehlendes PHP und synthetisches Deployment.

## Veröffentlichung bleibt ein eigener beauftragter Schritt

Nur nach separater Veröffentlichungsfreigabe:

```bash
bash dev/publish-github-release.sh --publish X.Y.Z
```

Der Publisher baut, committet, taggt und pusht weiterhin nichts selbst. Er verlangt bereits geprüfte Artefakte mit passenden Hashes/Quell- und Builderfingerprint, ein sauberes eigenständiges Repository und einen zuvor separat vorbereiteten Tag für genau den geprüften Commit. Die Veröffentlichungs-API verlangt zusätzlich den existierenden Remote-Tag. Vorhandene Releases werden nicht überschrieben; es gibt kein `--clobber`.

Die Arbeit auf `codex/source-extraction-20261122` ist kein Auftrag für einen Merge nach `master`, Tag, Push oder Release.

## Deployment und Recovery-Abnahme

Der Deploymenthelfer verlangt `--apply`, einen expliziten absoluten Zielpfad und die vorhandene Buildversion. Er bezieht keinen Zielpfad aus einer übergeordneten `.env`, baut nicht automatisch, ignoriert keine Syntaxfehler und überschreibt keine bestehende Recovery-Installation. Voraussetzung sind die gesonderte Zielautorisierung und eine abgeschlossene Datei-/DB-Sicherung über WSC Lokal. Der Helfer selbst ersetzt diese organisatorische Freigabe oder Sicherung nicht.

Keine Live-Reparatur oder absichtliche Beschädigung von Benjaro im Rahmen der Auslagerung. Die Deployment-Regression verwendet ein temporäres Verzeichnis mit einem synthetischen `global.php`-Marker und führt dessen PHP nicht aus. Dadurch werden weder Authentifizierung noch Diagnose-/Reparatur-/Deinstallationsfunktionen einer realen WoltLab-Suite abgenommen.

Auth-Datei `plugin-recovery-auth.php`, lokale Logs, Zertifikate, Dumps und Sitzungen dürfen nie aus einer Installation in Quellen, Paketarchive oder Commits übernommen werden.
