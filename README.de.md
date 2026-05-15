# WoltLab Plugin Recovery Tool

> Notfall-Wiederherstellungstool für WoltLab Suite 6.0+ — repariert das ACP, deinstalliert defekte Plugins, setzt Benutzer zurück und löscht Caches, auch wenn das Admin-Panel vollständig unzugänglich ist.

**Sprache / Language:** [Deutsch](README.de.md) | [English](README.md)

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
2. Diese Datei herunterladen und in dasselbe Verzeichnis hochladen
3. „Recovery Tool starten" klicken — die Authentifizierung gilt für 24 Stunden

### Entfernen

Nach Abschluss der Arbeiten den integrierten Button **„Recovery Tool vollständig entfernen"** verwenden. Die Datei darf nicht dauerhaft auf dem Server verbleiben.

## Sicherheitshinweis

**Das Tool nach der Verwendung sofort löschen.** Die Datei hat vollen Datenbankzugriff — sie auf einem öffentlichen Server zu belassen, stellt ein erhebliches Sicherheitsrisiko dar. Der Authentifizierungsmechanismus ist nur eine erste Absicherung und ersetzt keine ordentliche Serversicherheit.

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
