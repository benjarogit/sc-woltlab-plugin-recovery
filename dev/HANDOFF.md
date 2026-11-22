# Übergabe: Plugin Recovery

Stand: 22. November 2026. Quellen ausgelagert und eigenständigen Build/Offline-Regressionen geprüft. Keine Live-Reparatur und kein Release dieser Runde.

## Quellenauslagerung – 22. November 2026

**Neue Zuständigkeit:** vollständige Quellen im eigenständigen Repository `sc-woltlab-plugin-recovery`, Entwicklungsbranch `codex/source-extraction-20261122`. Root-AGENTS, Entwickler-README und `docs/source-import/` sind die aktuellen Einstiege. Der folgende Bericht vom 13. November bleibt historisch erhalten; seine Aussage „Quellen im Manager, öffentliches Repo nur drei Dateien“ ist nach diesem Import nicht mehr aktuell.

- Öffentliche bisherige Geschichte mit `master` `1e44c26d3657b7c781c3d8430b054a1ff95cb91d` unverändert erhalten. SWPM-Unterbaum aus `d04403c769d020f0ae5062455dc3fc3e4359ccfd` in einer wegwerfbaren lokalen Kopie per `git subtree split` extrahiert. Split-Head `5e1260c4666724222bfb0732ed43887b9295374f`, Import-Merge `600e91f104150859a0ad34140735ff43ffdc5ef6`; beide Geschichten sind Vorfahren des Entwicklungsstands.
- Alle 53 ursprünglich getrackten Quelldateien vor Anpassungen bytegleich verglichen. Drei Commit-Zuordnungen und alle Datei-SHA-256 in `docs/source-import/manifest.json`; alte README-Dateien dort archiviert, öffentliche READMEs erhalten und um Entwicklerverweis ergänzt. Historisch veröffentlichte Root-PHP-Datei bleibt erhalten und unverändert; `stub/` ist die Entwicklungsquelle.
- Lokaler Build ist vollständig eigenständig und verwendet standardmäßig die vorhandene Paketversion 2.7.48. Keine Quellversionsnummer geändert. Alte unterschiedliche Stub-/Manifestangaben bleiben als bekannte historische Inkonsistenz erhalten; nur erzeugte Metadaten werden wie zuvor auf die explizit gewählte Buildversion normalisiert. Gleiche Quellen/Version erzeugen bytegleiche normalisierte Archive und Stub; neues Buildmanifest enthält Artefakt-Hashes, Quell- und Builderfingerprint.
- Publisher führt im Standardpfad ausschließlich lokale Artefakt-/Quellprüfung und Plan aus. Nur ausdrückliches `--publish VERSION` nach eigener Freigabe darf GitHub ansprechen. Kein automatischer Build, Commit, Tag, Push oder `--clobber`; sauberes Repo und zuvor separat vorbereiteter exakter Tag erforderlich. Keine Veröffentlichung oder Anmeldung für diese Runde.
- Deploymenthelfer hat keine Kopplung an SWPM-`.env`, keinen impliziten Target oder Build und unterdrückt keine Syntaxfehler. Nur `--apply /absolutes/ziel VERSION`, vorhandene geprüfte Artefakte und zuvor abgeschlossener/autorisiert zugeordneter WSC-Lokal-Snapshot. Er überschreibt keine vorhandene Installation; Paket landet im eigenen Unterverzeichnis statt an der Suitewurzel.
- Fehlendes PHP ist jetzt BLOCKED mit Fehlerstatus, kein übersprungener Syntax-PASS. `tests/test_standalone.py` enthält Offline-Regressionen für Quellen-/Historienerhalt, isolierten Build ohne SWPM, unveränderte Quellversionen, reproduzierbare Artefakte/Stub-Integrität, Publisher ohne Git/GitHub-Aufruf im Standardpfad, Ablehnung geänderter Quellen/Artefakte, unzulässige Versionen/Secret-Dateien/Ausgabepfade, fehlendes PHP und synthetischen Deploymentzielschutz.
- Abschließender eigener Lauf: **13 Offline-Regressionen PASS**, Bash-Syntax/Shellcheck der vier angepassten Shell-Helfer PASS, eigenständiger Build unverändert 2.7.48 PASS, Quell-/erzeugter Stub-PHP-Syntaxgate PASS auf PHP 8.5.13, Publisher-Standardpfad nur Plan PASS. Dauerhafter Nachweis `~/.local/share/woltlab-dev/test-results/recovery-extraction-20261122T063528Z/summary.json` mit sechs einzelnen Prozess-Exitcodes, Loghashes und Artefakthashes. Orchestrator hat zusätzlich die 13 Tests und beide Geschichten/53 Quellblobs unabhängig geprüft. Ein lokaler Prüfbuild 2.7.48 ist kein neues Release. Keine WoltLab-Instanz geladen, repariert oder absichtlich beschädigt; Offline-Deploymenttest nutzt nur einen temporären synthetischen `global.php`-Marker.
- Alte SWPM-Kopie unverändert erhalten; letzte Wiederholungsprüfung bestätigt alle 53 ursprünglichen Hashes. Orchestrator übernimmt Prüfung/selektiven Commit der aktuellen Anpassungen und normalen Push des Recovery-Entwicklungsbranches. Erst nach gesicherter Recovery-Referenz erfolgt die alte SWPM-Entfernung als getrennt geprüfter Commit. Kein Staging oder Commit im schmutzigen SWPM-Repository durch den Auslagerungsagenten. Kein Master-Merge, Tag, Force-Push oder Release.

## Historischer Stand vom 13. November 2026

### Zuständigkeit und Quellen

- Bearbeitbare Quellen: `stub/` und `recovery-src/`; Build-/Entwicklungshilfen: `dev/`; Ausgaben: `dist/`.
- Versionsverwaltung der Quellen erfolgt im übergeordneten Plugin-Manager-Repository. Dort sind bereits Quellen auf `origin/main` enthalten.
- [Eigenes öffentliches Repository](https://github.com/benjarogit/sc-woltlab-plugin-recovery), Branch `master`: zwei READMEs und auslieferbarer PHP-Stub. Release `v2.7.48` enthält Stub und Paketarchiv. Nicht als vollständigen Quellcheckout behandeln.
- WSC Lokals integriertes Recovery ist das offizielle WoltLab-Tool und ein anderes Produkt. Eine Kopplung des eigenen Tools ist noch nicht implementiert.

## Harness

`bash dev/validate-php-syntax.sh` besteht für Quellstub, Paketquellen und dist-Stub. Nur Syntax geprüft. Das Skript meldet bei fehlendem PHP derzeit Warnung und Exit 0; als verbindliches Gate künftig fehlende Prüfung deutlich behandeln.

Nach PHP-Änderungen lokale Regel `.cursor/rules/php-recovery-tool.mdc` und Syntaxgate beachten. Vor produktiver Reparatur erst Diagnose/Vorschau und Daten-/Dateisicherung. Reparatur und Deinstallation nur auf einem bewusst gewählten Ziel.

## Offene Punkte

- Noch keine Wiederherstellungsabnahme mit WoltLab 6.3/PHP 8.5. Gegen einen protokollierten Snapshot einer wegwerfbaren Testinstallation testen.
- Versionsquellen sind uneinheitlich: Quellmanifest 2.7.12; Quell-`version.php` Paket 2.7.48/Stub 2.7.15; Quellstub Paket 2.7.48/Stub 2.7.42. Der Build überschreibt Ausgabemetadaten. Das erklärt mögliche Unterschiede, ersetzt aber keinen Artefaktcheck.
- Regressionstests fehlen als klarer gemeinsamer Einstieg für Auth, Diagnose, Vorschau, Paketabhängigkeiten, Dateireparatur, Cache/ACP-Reparatur und vollständige Tool-Entfernung. Keine absichtlichen Beschädigungen an der normalen Entwicklerinstanz.
- `dev/publish-github-release.sh` baut, committet und pusht selbst und kann Release-Assets mit `--clobber` ersetzen. Nicht als Prüfskript ausführen; Veröffentlichung separat vorbereiten und prüfen.

Nächster Schritt: Versionsvertrag und reproduzierbare Build-Prüfung definieren, anschließend isolierte 6.3-Recovery-Szenarien. Die bloße Angabe „6.0+“ in der README belegt keine geprüfte 6.3-Wiederherstellung.
