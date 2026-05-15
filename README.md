# WoltLab Plugin Recovery Tool

> Notfall-Wiederherstellungstool für WoltLab Suite 6.0+ — repariert das ACP, deinstalliert defekte Plugins, setzt Benutzer zurück und löscht Caches, auch wenn das Admin-Panel vollständig unzugänglich ist.

**Sprache / Language:** [Deutsch](README.md) | [English](README.en.md)

![WoltLab Suite](https://img.shields.io/badge/WoltLab%20Suite-6.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Version](https://img.shields.io/github/v/release/benjarogit/sc-woltlab-plugin-recovery)

![Authentifizierungs-Bildschirm](https://github.com/user-attachments/assets/d18e0871-2ab2-4f47-9bd7-8820671bffa1)

## Wann wird dieses Tool benötigt?

- Das ACP zeigt „Call to a member function toHtml() on null"
- Eine Plugin-Installation ist fehlgeschlagen und hat das Admin-Panel beschädigt
- Ein Plugin muss deinstalliert werden, aber das ACP ist nicht erreichbar
- Ein defektes Plugin hat ungültige ACP-Menüeinträge hinterlassen
- Alle Caches müssen gelöscht werden und nichts anderes funktioniert
- Ein Admin-Passwort muss ohne ACP-Zugang zurückgesetzt werden

## Modi

| Modus | Funktion |
|---|---|
| **ACP Repair** | Entfernt defekte ACP-Menüeinträge anhand des Package-Identifiers oder eines hochgeladenen Archivs |
| **Plugin Uninstall** | Entfernt ein Plugin vollständig aus Datenbank und Dateisystem, inklusive SQL-Vorschau |
| **User Management** | Benutzer suchen, Passwort zurücksetzen, Gruppen verwalten, E-Mail ändern, Konto aktivieren |
| **Cache Clear** | Löscht kompilierte Templates und alle Cache-Verzeichnisse |
| **Package List Repair** | Behebt verwaiste Pakete, die auf der Paketlisten-Seite zu Null-Fehlern führen |

![Modus-Auswahl](https://github.com/user-attachments/assets/c108ffbb-4db9-448c-853c-0b0a9bffc5c4)

## Installation

1. **Herunterladen** von `plugin-recovery-tool.php` unter [Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)
2. **Hochladen** ins WoltLab-Hauptverzeichnis (denselben Ordner wie `global.php`)
3. **Aufrufen** im Browser: `https://ihre-domain.de/plugin-recovery-tool.php`

```
ihr-woltlab-root/
├── global.php
├── lib/
├── acp/
└── plugin-recovery-tool.php   ← nur diese Datei wird benötigt
```

Das Tool erkennt die WoltLab-Installation automatisch. Es lädt `global.php` **nicht** — das ist beabsichtigt, damit es auch dann funktioniert, wenn defekte Plugins den Bootstrap-Vorgang unterbrechen.

### Authentifizierung

Das Tool verwendet ein dateibasiertes Verfahren, das von WoltLabs eigenem `wsc-recovery.php` inspiriert ist:

1. Tool-URL aufrufen — eine `plugin-recovery-auth.php`-Datei wird automatisch erzeugt
2. Diese Datei über FTP/SFTP herunterladen und in dasselbe Verzeichnis hochladen
3. „Recovery Tool starten" klicken — die Authentifizierung gilt für **24 Stunden**

Der Token wird beim Upload verifiziert und läuft automatisch ab. Ein erneutes Hochladen ist danach erforderlich.

### Entfernen

Nach Abschluss der Arbeiten den integrierten Button **„Recovery Tool vollständig entfernen"** verwenden. Das Tool löscht dabei sich selbst sowie die Auth-Datei und leitet zurück ins ACP. Die Datei darf nicht dauerhaft auf dem Server verbleiben.

---

## Funktionen im Detail

### ACP Repair

Entfernt defekte ACP-Menüeinträge, die das Admin-Panel abstürzen lassen:

- **Eingabe per Identifier:** Package-Identifier manuell eingeben (z. B. `de.example.my-plugin`)
- **Eingabe per Archiv:** `.tar`, `.tar.gz` oder `.tgz`-Datei hochladen — `package.xml` wird automatisch ausgelesen
- **Vorschau vor dem Löschen:** Gefundene Menüeinträge werden tabellarisch angezeigt (Menu Item + Controller), erst nach Bestätigung gelöscht
- Nach dem Löschen wird der Cache automatisch geleert

### Plugin Uninstall

Vollständige Plugin-Deinstallation in einem **3-Schritt-Assistenten**:

**Schritt 1 — Analyse & Auswahl**
- Package-Identifier eingeben oder Archiv hochladen
- Das Tool erkennt den Status in der Datenbank (packageID, Name, WCF_N)
- **PIP-Ressourcen-Matrix:** Zeigt alle erkannten Ressourcen mit Datenbankzählung an:
  - ACP-Menüeinträge, Event-Listener, Template-Listener, Optionen, Benutzergruppen-Optionen
  - Cronjobs, Objekttypen, Sprachvariablen, Templates, ACP-Templates, Seiten, Boxen
  - Benachrichtigungs-Events, BBCodes, Smileys, ACL-Optionen, Menüeinträge und weitere
  - Dateisystem: eigene Plugin-Tabellen (DROP TABLE), Dateien auf Disk
- **Checkbox-Auswahl pro Ressource:** Einzelne Ressourcentypen können abgewählt werden
- **Dry-Run-Modus:** Simuliert die Deinstallation ohne Datenbankänderungen

**Schritt 2 — Backup**
- Generiert ein `.sql`-Backup aller ausgewählten Datenbankzeilen (pure PHP, kein `mysqldump` erforderlich)
- Backup kann direkt im Browser heruntergeladen werden
- Weitermachen ohne Backup ist möglich, aber nicht empfohlen

**Schritt 3 — Ausführen**
- Löscht alle ausgewählten Ressourcen aus der Datenbank
- Optionales Löschen von Plugin-Dateien auf dem Dateisystem mit Sicherheitsprüfungen:
  - Blockliste für geschützte Verzeichnisse (z. B. `lib/`, `acp/`, `templates/`)
  - Realpath-Prüfung verhindert Path-Traversal-Angriffe
- Repariert verwaiste `wcf_package`-Einträge nach dem Löschen

### User Management

Benutzerverwaltung ohne ACP-Zugang:

- **Benutzersuche:** Suche nach Name oder E-Mail-Adresse
- **Passwort zurücksetzen:** Neues Passwort setzen (kompatibel mit WoltLab-Passwort-Hashing)
- **Gruppen verwalten:** Benutzergruppen anzeigen und einzeln zuweisen/entziehen
- **E-Mail ändern:** Neue E-Mail-Adresse direkt in der Datenbank setzen
- **Konto aktivieren:** Gesperrte oder inaktive Konten entsperren
- **2FA deaktivieren:** Zwei-Faktor-Authentifizierung zurücksetzen, wenn der Zugangscode nicht mehr verfügbar ist

### Cache Clear

Löscht alle Cache- und Kompilat-Verzeichnisse direkt über das Dateisystem (ohne WCF/CacheHandler):

| Verzeichnis | Inhalt |
|---|---|
| `tmp/` | Temporäre Dateien |
| `cache/` | Datei-Cache |
| `templates/compiled/` | Kompilierte Frontend-Templates |
| `acp/templates/compiled/` | Kompilierte ACP-Templates |

### Package List Repair

Behebt verwaiste Datenbankeinträge, die die ACP-Paketliste oder den Deinstallations-Prozess blockieren:

- Entfernt verwaiste `wcf_package_installation_queue`-Einträge
- Entfernt verwaiste `wcf_application`-Einträge ohne zugehöriges Paket
- Nützlich wenn die Paketliste im ACP einen Null-Fehler wirft oder ein Paket sich nicht deinstallieren lässt

---

## Sicherheit

**Das Tool nach der Verwendung sofort löschen.** Die Datei hat vollen Datenbankzugriff — sie auf einem öffentlichen Server zu belassen, stellt ein erhebliches Sicherheitsrisiko dar. Der Authentifizierungsmechanismus ist nur eine erste Absicherung und ersetzt keine ordentliche Serversicherheit.

- **Blockliste:** Kritische Systemverzeichnisse und -dateien sind gegen Löschung geschützt
- **Realpath-Prüfung:** Alle Dateipfade werden gegen Path-Traversal-Angriffe geprüft
- **Prepared Statements:** Alle Datenbankabfragen verwenden vorbereitete Anweisungen
- **Auth-Ablauf:** Die Authentifizierung läuft nach **24 Stunden** automatisch ab
- Plugin Uninstall ist **unwiderruflich** — vorher immer ein Datenbank-Backup erstellen
- Das Tool erkennt **keine** Basis-Plugin-Abhängigkeiten; für normale Deinstallationen das WoltLab ACP verwenden

## Voraussetzungen

- WoltLab Suite 6.0+
- PHP 8.0+
- Schreibrechte im Installationsverzeichnis

## Changelog

Release Notes zu jeder Version sind auf der [Releases-Seite](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases) zu finden.

## Lizenz

MIT License — Copyright (c) 2025 Sunny C.

---

*Inspiriert von [WoltLab's wsc-recovery.php](https://manual.woltlab.com/de/recovery-tool/)*
