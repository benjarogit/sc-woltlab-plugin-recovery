# WoltLab Plugin Recovery Tool

> Notfall-Wiederherstellungstool für WoltLab Suite 6.0+, wenn Plugins Ihre Installation beschädigen. Repariert das ACP, deinstalliert defekte Plugins und löscht Caches - auch wenn das Admin-Panel vollständig unzugänglich ist.

**Sprache / Language:** [🇩🇪 Deutsch](README.de.md) | [🇬🇧 English](README.md)

![WoltLab Suite](https://img.shields.io/badge/WoltLab%20Suite-6.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

<!-- SCREENSHOT: screenshots/auth-screen.png -->
<!-- Zeige den Authentifizierungs-Bildschirm mit den 3 Schritten und dem Download-Button -->
![Authentifizierungs-Bildschirm](screenshots/auth-screen.png)

## 🆘 Wann benötigen Sie dieses Tool?

- ✅ ACP zeigt "Call to a member function toHtml() on null"
- ✅ Plugin-Installation fehlgeschlagen und Admin-Panel beschädigt
- ✅ Plugin muss deinstalliert werden, aber ACP ist unzugänglich
- ✅ Defektes Plugin hat ungültige ACP-Menüeinträge erstellt
- ✅ Alle Caches müssen gelöscht werden, wenn nichts anderes funktioniert

## ⚡ Funktionen

<!-- SCREENSHOT: screenshots/mode-selection.png -->
<!-- Zeige die 4 Modi-Kacheln: ACP Repair, Plugin Uninstall, User Management, Cache Clear -->
![Modus-Auswahl](screenshots/mode-selection.png)

### 🔧 ACP Repair
- Entfernt defekte ACP-Menüeinträge anhand des Plugin-Identifiers
- **NEU:** Package-Datei-Upload (.tar/.tar.gz) mit automatischer Ressourcen-Erkennung
- Musterbasierte Bereinigung, wenn Plugin nicht in Datenbank vorhanden
- Zeigt Vorschau vor dem Löschen
- Identifiziert automatisch alle ACP-Menüeinträge aus package.xml

<!-- SCREENSHOT: screenshots/acp-repair.png -->
<!-- Zeige den ACP Repair Modus mit Package-Identifier Eingabefeld und Ergebnis-Tabelle -->
![ACP Repair Modus](screenshots/acp-repair.png)

### 🗑️ Plugin Uninstall
- Vollständige Plugin-Entfernung aus Datenbank und Dateisystem
- Zwei Methoden:
  - Manuelle Package-Identifier-Eingabe
  - **Package-Upload (.tar/.tar.gz) mit automatischer Ressourcen-Erkennung**
- **NEU:** Identifiziert automatisch ALLE Plugin-Ressourcen:
  - Datenbank-Tabellen (aus Install-Dateien)
  - Optionen (aus option.xml)
  - Berechtigungen/User Group Options (aus userGroupOption.xml)
  - Cronjobs (aus package.xml und Cronjob-Dateien)
  - ACP-Menüeinträge (aus acpMenu.xml)
  - Sprachvariablen (aus language/*.xml)
  - Objekttypen (aus objectType.xml)
  - Page Locations (aus pageLocation.xml)
  - URL-Regeln (aus urlRule.xml)
- **NEU:** SQL-Vorschau und Export für manuelle Bereinigung
- Sicher: Zeigt detaillierte Vorschau vor der Ausführung
- Filtert automatisch Basis-Plugin-Tabellen aus

<!-- SCREENSHOT: screenshots/plugin-uninstall.png -->
<!-- Zeige den Plugin Uninstall Modus mit den zwei Optionen und der Bestätigungs-Seite -->
![Plugin Uninstall Modus](screenshots/plugin-uninstall.png)

### 🧹 Cache Clear
- Löscht alle kompilierten Templates
- Leert Cache-Verzeichnisse:
  - `tmp/`
  - `cache/`
  - `templates/compiled/`
  - `acp/templates/compiled/`

<!-- SCREENSHOT: screenshots/cache-clear.png -->
<!-- Zeige den Cache Clear Modus mit Erfolgs-Meldung und Anzahl gelöschter Dateien -->
![Cache Clear Modus](screenshots/cache-clear.png)

### 👤 User Management
- Verlinkung zum offiziellen WoltLab Recovery Tool
- Für Admin-Passwort-Reset und Benutzerverwaltung

## 📦 Installation

**Einzeldatei (v1.2.0):** Nur `plugin-recovery-tool.php` ins WoltLab-Hauptverzeichnis legen. Keine `recovery-bootstrap.php`, `recovery-cleanup.php` oder andere Hilfsdateien. Die Auth-Datei (`plugin-recovery-auth.php`) wird beim Aufruf erzeugt und im Tool heruntergeladen.

1. **Download** `plugin-recovery-tool.php` von [Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)
2. **Upload** ins WoltLab-Root (gleiche Ebene wie `global.php` — nur zur Erkennung des Installationspfads)
3. **Aufruf** über Browser: `https://ihre-domain.de/plugin-recovery-tool.php`

Optional: `deploy-recovery.sh` kopiert nur `plugin-recovery-tool.php` in einen konfigurierten WoltLab-Pfad.

```
ihr-woltlab-root/
├── global.php
├── lib/
├── acp/
└── plugin-recovery-tool.php  ← nur diese Datei aus dem Repo
```

## 🔐 Authentifizierung

Aus Sicherheitsgründen verwendet das Tool ein dateibasiertes Authentifizierungssystem (inspiriert von WoltLab's wsc-recovery.php):

1. **Besuchen** Sie die Tool-URL
2. **Download** der `plugin-recovery-auth.php` Datei (automatisch generiert)
3. **Upload** in dasselbe Verzeichnis
4. **Klicken** Sie auf "🚀 Recovery Tool starten"

Die Auth-Datei ist 24 Stunden gültig.

<!-- SCREENSHOT: screenshots/auth-flow.gif -->
<!-- OPTIONAL: Animiertes GIF das den kompletten Auth-Flow zeigt -->
<!-- Falls kein GIF: Drei Screenshots auth-step1.png, auth-step2.png, auth-step3.png -->

## 📖 Verwendungsbeispiele

### Beispiel 1: Defektes ACP reparieren

**Szenario:** ACP zeigt "Call to a member function toHtml() on null"

```
1. Aufruf: https://ihre-domain.de/plugin-recovery-tool.php
2. Authentifizierung (Auth-Datei downloaden + hochladen)
3. Auswahl: "🔧 ACP Repair"
4. Package-Identifier eingeben: de.vendor.plugin.name
   ODER Package-Datei hochladen (.tar/.tar.gz)
5. Gefundene Menüeinträge prüfen
6. Auf "Alle löschen" klicken
7. Cache wird automatisch geleert
8. ✅ ACP sollte wieder funktionieren!
```

### Beispiel 2: Defektes Plugin deinstallieren

**Szenario:** Plugin-Installation fehlgeschlagen, alle Spuren müssen entfernt werden

```
1. Recovery Tool aufrufen
2. Auswahl: "🗑️ Plugin Uninstall"
3. Methode wählen:
   Option A: "de.vendor.plugin.name" eingeben
   Option B: Plugin .tar-Datei hochladen (EMPFOHLEN)
4. Wenn hochgeladen, analysiert das Tool automatisch package.xml und findet:
   - Datenbank-Tabellen (aus install_info.php)
   - Optionen (aus option.xml)
   - Berechtigungen (aus userGroupOption.xml)
   - Cronjobs (aus package.xml)
   - ACP-Menüeinträge (aus acpMenu.xml)
   - Sprachvariablen (aus language/*.xml)
   - Objekttypen, Page Locations, URL-Regeln
5. Detaillierte Vorschau mit allen gefundenen Ressourcen prüfen
6. Optional: Auf "SQL anzeigen" klicken, um generiertes Cleanup-SQL zu sehen
7. Auf "JETZT DEINSTALLIEREN" klicken
8. ✅ Plugin vollständig mit allen Ressourcen entfernt!
```

### Beispiel 3: Alle Caches löschen (Schnell-Fix)

**Szenario:** Etwas funktioniert nicht, zuerst Caches löschen versuchen

```
1. Recovery Tool aufrufen
2. Auswahl: "🧹 Cache Clear"
3. Auf "Cache jetzt löschen" klicken
4. ✅ Fertig! 98 Dateien gelöscht
```

## 🔍 Funktionsweise

### ACP Repair Modus

**Mit Package in Datenbank:**
```sql
-- PackageID finden
SELECT packageID FROM wcf1_package WHERE package = 'de.vendor.plugin.name'

-- Alle ACP-Menüeinträge für dieses Package löschen
DELETE FROM wcf1_acp_menu_item WHERE packageID = 123
```

**Ohne Package (Fehlgeschlagene Installation):**
```sql
-- Musterbasierte Suche mit App-Name aus Identifier
-- Beispiel: de.vendor.app.extension → sucht nach "app.acp.menu.%"
SELECT COUNT(*) FROM wcf1_acp_menu_item WHERE menuItem LIKE 'app.acp.menu.%'
```

**Mit Package-Upload (Automatische Erkennung):**
```php
// 1. Package-Datei entpacken
extractArchive($uploadedFile, $extractDir)

// 2. package.xml parsen
$packageData = parsePackageXml($extractDir . '/package.xml')

// 3. ACP-Menüeinträge aus acpMenu.xml identifizieren
$acpMenu = findAcpMenuItems($extractDir, $application)
// Extrahiert Präfix z.B. "plugin.acp.menu."

// 4. Alle Einträge mit diesem Präfix löschen
DELETE FROM wcf1_acp_menu_item WHERE menuItem LIKE 'plugin.acp.menu.%'
```

### Plugin Uninstall Modus

**Mit Package-Upload (Automatische Erkennung):**
```php
// 1. Package-Archiv entpacken
extractArchive($uploadedFile, $extractDir)

// 2. package.xml parsen
$packageData = parsePackageXml($extractDir . '/package.xml')
// Extrahiert: Application-Name, Instruction-Typen

// 3. Alle Ressourcen analysieren:
- findDatabaseTables() // Parst install_info.php nach DatabaseTable::create()
- findOptions() // Parst option.xml, extrahiert Präfix
- findUserGroupOptions() // Parst userGroupOption.xml
- findCronjobs() // Findet Cronjobs in package.xml
- findAcpMenuItems() // Parst acpMenu.xml
- findLanguageItems() // Scannt language/*.xml
- findObjectTypes() // Parst objectType.xml
- findPageLocations() // Parst pageLocation.xml
- findUrlRules() // Parst urlRule.xml

// 4. WCF_N erkennen (aus Datenbank oder Tabellennamen)
$wcfN = detectWcfN($db, $packageIdentifier, $extractDir)

// 5. Alle identifizierten Ressourcen in Transaktion löschen
```

**Manueller Package-Identifier (Fallback):**
```php
// 1. Package finden
$packageData = SELECT * FROM wcf1_package WHERE package = ?

// 2. Alle Tabellen finden (Muster-Matching auf App-Name)
$tables = SHOW TABLES
// Filter: "appname1_*", "appname_*"

// 3. In Transaktion löschen:
DELETE FROM wcf1_acp_menu_item WHERE packageID = ?
DELETE FROM wcf1_package WHERE packageID = ?
DROP TABLE appname1_table1, appname1_table2, ...
```

### Cache Clear Modus

```php
// Löscht rekursiv alle Dateien in:
- tmp/*
- cache/*
- templates/compiled/*
- acp/templates/compiled/*
```

## ⚠️ Wichtige Warnungen

### 🚨 Sicherheit

- **LÖSCHEN SIE DAS TOOL IMMER NACH DER VERWENDUNG!**
- Das Recovery Tool hat vollen Datenbankzugriff
- Es auf dem Server zu belassen ist ein **ernstes Sicherheitsrisiko**
- Verwenden Sie den **"🗑️ Recovery Tool vollständig entfernen"** Button, wenn fertig

### 🚨 Datenverlust

- **Plugin Uninstall ist UNWIDERRUFLICH**
- **Es wird automatisch kein Backup erstellt**
- **Erstellen Sie IMMER ein Backup Ihrer Datenbank vor der Verwendung**
- Prüfen Sie, was gelöscht wird, bevor Sie bestätigen

### 🚨 Basis-Plugin-Warnung

⚠️ Das Tool erkennt **KEINE** Basis-Plugin-Abhängigkeiten!

**Beispiel:**
```
Basis-Plugin:  de.vendor.urlshort
Erweiterung:    de.vendor.urlshort.featuredLinks

Problem: Wenn die Erweiterung Dateien fälschlicherweise im
         Basis-Plugin-Verzeichnis platziert hat, könnte die
         Deinstallation Dateien löschen, die das Basis-Plugin benötigt!
```

**Lösung:**
- Verwenden Sie dieses Tool nur für Notfall-Bereinigung
- Für normale Deinstallation verwenden Sie das WoltLab ACP
- Prüfen Sie Dateipfade vor der Installation von Erweiterungen

## 🛠️ Technische Details

**Anforderungen:**
- PHP 8.0+
- WoltLab Suite 6.0+
- Schreibrechte im Installationsverzeichnis

**Bootstrap & Datenbank:**
- **Lädt kein `global.php`** — minimaler Bootstrap, damit das Tool läuft, wenn kaputte Plugins das ACP zerstören
- Lädt `config.inc.php` und nur benötigte WoltLab-Klassen
- Datenbankzugriff über WoltLabs DB-Schicht nach minimalem Bootstrap (kein vollständiger Frontend-Bootstrap)
- Prepared Statements; Transaktionen für sichere Rollbacks
- **Generische Deinstallation** per `packageID` für jedes installierte Plugin
- Baut `options.inc.php` nach Optionen-Bereinigung per `OptionEditor::rebuild()` neu

**Unterstützte Archive:**
- `.tar`
- `.tar.gz`
- `.tgz`

**Automatische Ressourcen-Erkennung:**
- Parst `package.xml` für alle Instruction-Typen
- Findet Dateien in verschiedenen Verzeichnissen (Root, `files_{application}/acp/`, etc.)
- Extrahiert Präfixe für LIKE-Queries
- Erkennt WCF_N automatisch (Datenbank primär, Tabellennamen als Fallback)
- Filtert Basis-Plugin-Tabellen automatisch aus

## 🐛 Fehlerbehebung

### "WoltLab nicht gefunden..."

**Ursache:** `plugin-recovery-tool.php` liegt nicht im WoltLab-Root (Verzeichnis muss `global.php` und `config.inc.php` enthalten — nur zur Erkennung; `global.php` wird nicht geladen)

**Lösung:** Nur `plugin-recovery-tool.php` ins Root neben `global.php` legen

### "Plugin nicht in Datenbank gefunden"

**Ursache:** Installation fehlgeschlagen, bevor Plugin registriert wurde

**Lösung:**
1. "ACP Repair" Modus verwenden
2. Tool zeigt musterbasierte Treffer: `app.acp.menu.%`
3. Anzahl prüfen und Löschung bestätigen
4. **ODER:** Package-Datei hochladen für automatische Erkennung

### "Call to undefined method getHandle()"

**Ursache:** Veraltete Version

**Lösung:** Neueste Version von [GitHub Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases) herunterladen

### ACP funktioniert nach Cache Clear immer noch nicht

**Mögliche Ursachen:**
1. Fehlende PHP-Klassendateien
2. Datenbankkorruption
3. Basis-Plugin-Dateien gelöscht

**Lösung:**
1. Zuerst ACP Repair Modus versuchen
2. Wenn das fehlschlägt, Basis-Plugin über FTP + Datenbank neu installieren
3. WoltLab Fehlerprotokolle prüfen: `log/YYYY-MM-DD.txt`

### "Keine Ressourcen in Package-Datei gefunden"

**Ursache:** Package-Datei ist möglicherweise beschädigt oder unvollständig

**Lösung:**
1. Prüfen Sie, ob `package.xml` im Root des Archivs vorhanden ist
2. Versuchen Sie eine andere Package-Datei
3. Verwenden Sie manuellen Package-Identifier als Fallback

## 📝 Changelog

### v1.2.0 (2026-05-15)
- 📦 **Eine Datei für Nutzer:** nur `plugin-recovery-tool.php`; Auth-Datei wird im Tool heruntergeladen
- 🚫 **Kein `global.php`-Bootstrap** — funktioniert, wenn das ACP durch fehlerhafte Plugins kaputt ist
- 🗑️ **Generische Deinstallation** per `packageID` für alle Plugins
- ⚙️ **`options.inc.php` wird neu aufgebaut** mit `OptionEditor::rebuild()`
- 🧹 Separate `recovery-bootstrap.php` / `recovery-cleanup.php` aus dem Repository entfernt

### v1.1.0 (2025-01-XX)
- 🆕 **Automatische Ressourcen-Erkennung aus Package-Dateien**
- 🆕 Package-Upload-Unterstützung für ACP Repair Modus
- 🆕 Umfassende Ressourcen-Analyse:
  - Datenbank-Tabellen (aus Install-Dateien)
  - Optionen, Berechtigungen, Cronjobs
  - ACP-Menüeinträge, Sprachvariablen
  - Objekttypen, Page Locations, URL-Regeln
- 🆕 SQL-Vorschau und Export-Funktionalität
- 🆕 Automatische WCF_N-Erkennung (Datenbank + Fallback)
- 🆕 Basis-Plugin-Tabellen-Filterung
- 🆕 Verbesserte Vorschau mit detaillierter Ressourcen-Aufschlüsselung

### v1.0.0 (2025-01-16)
- ✨ Erste Veröffentlichung
- 🔧 ACP Repair Modus (musterbasiert + packageID)
- 🗑️ Plugin Uninstall Modus (manuell + Upload)
- 🧹 Cache Clear Modus
- 🔐 Dateibasierte Authentifizierung (24h Gültigkeit)
- 🎨 WoltLab-inspiriertes Dark Theme
- 🗑️ Selbstzerstörungs-Funktion
- 📊 Vorschau vor dem Löschen

## 📄 Lizenz

MIT License - Copyright (c) 2025 Sunny C.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

**Inspiriert von [WoltLab's wsc-recovery.php](https://manual.woltlab.com/de/recovery-tool/)**

## 🤝 Beitragen

Issues und Pull Requests sind willkommen!

1. Repository forken
2. Feature-Branch erstellen: `git checkout -b feature/amazing-feature`
3. Änderungen committen: `git commit -m 'Add amazing feature'`
4. Auf Branch pushen: `git push origin feature/amazing-feature`
5. Pull Request öffnen

## ⚡ Schnelllinks

- [WoltLab Suite Dokumentation](https://docs.woltlab.com/6.0/)
- [Offizielles WoltLab Recovery Tool](https://manual.woltlab.com/de/recovery-tool/)
- [Probleme melden](https://github.com/benjarogit/sc-woltlab-plugin-recovery/issues)
- [Neueste Version](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)

## 🔗 Verwandte Projekte

- [Simple WoltLab Plugin Manager](https://github.com/benjarogit/simple-woltlab-plugin-manager) - Plugin-Verwaltungstool
- [Photoshop CC Linux](https://github.com/benjarogit/photoshopCClinux) - Photoshop CC für Linux
- [WoltLab Profil](https://www.woltlab.com/user/1350052-sunny-c/) - Mein WoltLab Community Profil

---

<p align="center">
  <strong>Made with ❤️ by <a href="https://github.com/benjarogit">Sunny C.</a></strong>
</p>

<p align="center">
  <strong>⚠️ Verwendung auf eigene Gefahr. Erstellen Sie IMMER ein Backup Ihrer Datenbank vor der Verwendung von Recovery-Tools!</strong>
</p>

