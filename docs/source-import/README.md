# Herkunft und Erhalt der Quellen

Am 22. November 2026 wurden die vollständigen Recovery-Quellen aus dem sauberen, bereits versionierten SWPM-Unterbaum `tools/woltlab-plugin-recovery/` übernommen. Es wurde kein fremdes Werkzeug als Ersatz eingebunden.

- Bestehender öffentlicher Recovery-`master`: `1e44c26d3657b7c781c3d8430b054a1ff95cb91d` mit zwei READMEs und veröffentlichtem Root-Stub.
- Quellstand SWPM: `d04403c769d020f0ae5062455dc3fc3e4359ccfd`.
- Aus einer separaten, wegwerfbaren lokalen SWPM-Kopie mit `git subtree split --prefix=tools/woltlab-plugin-recovery` erzeugter Quellzweig: `5e1260c4666724222bfb0732ed43887b9295374f`.
- Zusammenführung beider Geschichten ausschließlich auf `codex/source-extraction-20261122`: `600e91f104150859a0ad34140735ff43ffdc5ef6`.

`manifest.json` enthält die Zuordnung aller drei betroffenen SWPM-Commits zu den extrahierten Recovery-Commits sowie relative Pfade und SHA-256-Werte aller 53 ursprünglich getrackten Quelldateien. Der Split erhält Autor-/Datumsangaben der betroffenen Geschichte. Die ursprünglichen SWPM-Commits bleiben zusätzlich in dessen Repository erhalten; es gab keinen Rewrite oder Force-Push am Ursprungsrepository.

Zwei gleichnamige README-Dateien führten beim Verbinden der unabhängigen Geschichten zu Add/Add-Konflikten. Die vorhandenen öffentlichen Root-READMEs wurden erhalten; die bytegleichen alten SWPM-READMEs liegen hier als `swpm-README.md` und `swpm-README.en.md`. Alle anderen importierten Quelldateien waren beim Import am zugeordneten Zielpfad bytegleich. Der bestehende öffentliche Root-Stub blieb unverändert.

Die Regression `SourceHistoryTests` prüft beide Vorfahren und jeden gespeicherten Hash sowohl gegen den Quell-Split als auch gegen den tatsächlichen Merge-Snapshot. Spätere gezielte Anpassungen an Build-/Publisher-/Deploy-Werkzeugen, Regeln und Dokumentation sind davon getrennte Arbeitsänderungen. Die Hashliste behauptet nicht, dass diese bewusst angepassten Dateien für immer unverändert bleiben müssen.

Es wurde weder nach `master` gemergt noch ein Release veröffentlicht. Die alte SWPM-Kopie darf erst nach erfolgreichem Vergleich, Standalone-Build, Tests und übergreifender Integration entfernt werden. Keine Doppelquelle dauerhaft weiterpflegen. Private Konfiguration, Auth-Dateien, Datenbanken oder Sitzungen gehören nicht zu diesem Import.

Die bisher veröffentlichten Recovery-READMEs nennen MIT und Copyright 2025 Sunny C.; das Ursprungsrepository SWPM enthält den MIT-Hinweis Copyright 2026 benjarogit. `LICENSE` erhält den vollständigen bestehenden MIT-Text mit beiden vorhandenen Copyright-Hinweisen. Bestehende Autorenhinweise in den importierten Quellen wurden nicht entfernt.
