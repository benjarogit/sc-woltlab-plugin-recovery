# WoltLab Plugin Recovery Tool

> Emergency recovery tool for WoltLab Suite 6.0+ when plugins break your installation. Repairs ACP, uninstalls broken plugins, and clears caches - even when the admin panel is completely inaccessible.

**Language / Sprache:** [🇬🇧 English](README.md) | [🇩🇪 Deutsch](README.de.md)

![WoltLab Suite](https://img.shields.io/badge/WoltLab%20Suite-6.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

<!-- SCREENSHOT: screenshots/auth-screen.png -->
<!-- Zeige den Authentifizierungs-Bildschirm mit den 3 Schritten und dem Download-Button -->
![Authentication Screen](https://github.com/user-attachments/assets/d18e0871-2ab2-4f47-9bd7-8820671bffa1)

## 🆘 When Do You Need This?

- ✅ ACP shows "Call to a member function toHtml() on null"
- ✅ Plugin installation failed and broke your admin panel
- ✅ Need to uninstall a plugin but ACP is inaccessible
- ✅ Broken plugin created invalid ACP menu entries
- ✅ Need to clear all caches when nothing else works

## ⚡ Features

<!-- SCREENSHOT: screenshots/mode-selection.png -->
<!-- Zeige die 4 Modi-Kacheln: ACP Repair, Plugin Uninstall, User Management, Cache Clear -->
![Mode Selection](https://github.com/user-attachments/assets/c108ffbb-4db9-448c-853c-0b0a9bffc5c4)

### 🔧 ACP Repair
- Removes broken ACP menu entries by plugin identifier
- **NEW:** Package file upload (.tar/.tar.gz) with automatic resource detection
- Pattern-based cleanup when plugin is not in database
- Shows preview before deletion
- Automatically identifies all ACP menu items from package.xml

<!-- SCREENSHOT: screenshots/acp-repair.png -->
<!-- Zeige den ACP Repair Modus mit Package-Identifier Eingabefeld und Ergebnis-Tabelle -->
![ACP Repair Mode](https://github.com/user-attachments/assets/50602546-147a-41a6-a7bd-c1866c96493e)

### 🗑️ Plugin Uninstall
- Complete plugin removal from database and filesystem
- Two methods:
  - Manual package identifier input
  - **Package upload (.tar/.tar.gz) with automatic resource detection**
- **NEW:** Automatically identifies ALL plugin resources:
  - Database tables (from install files)
  - Options (from option.xml)
  - Permissions/User Group Options (from userGroupOption.xml)
  - Cronjobs (from package.xml and cronjob files)
  - ACP menu items (from acpMenu.xml)
  - Language items (from language/*.xml)
  - Object types (from objectType.xml)
  - Page locations (from pageLocation.xml)
  - URL rules (from urlRule.xml)
- **NEW:** SQL preview and export for manual cleanup
- Safe: Shows detailed preview before executing
- Automatically filters out base plugin tables

<!-- SCREENSHOT: screenshots/plugin-uninstall.png -->
![Plugin Uninstall](https://github.com/user-attachments/assets/5a7f13c6-2988-4e11-9071-67980e7515fc)

### 🧹 Cache Clear
- Deletes all compiled templates
- Clears cache directories:
  - `tmp/`
  - `cache/`
  - `templates/compiled/`
  - `acp/templates/compiled/`

<!-- SCREENSHOT: screenshots/cache-clear.png -->
<!-- Zeige den Cache Clear Modus mit Erfolgs-Meldung und Anzahl gelöschter Dateien -->
![Cache Clear Mode](https://github.com/user-attachments/assets/1fc1f79e-fdc2-4b76-98ec-2719b8706bd7)

### 👤 User Management
- Links to official WoltLab recovery tool
- For admin password reset and user management

![User Management](https://github.com/user-attachments/assets/f1555a4f-4b10-478a-a7ef-c953d37b922e)

## 📦 Installation

**Single-file deployment (v1.2.0):** upload only `plugin-recovery-tool.php` to your WoltLab root. No `recovery-bootstrap.php`, `recovery-cleanup.php`, or other helper files. The auth file (`plugin-recovery-auth.php`) is generated when you open the tool and must be downloaded from the tool UI.

1. **Download** `plugin-recovery-tool.php` from [Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)
2. **Upload** to your WoltLab Suite root directory (same level as `global.php` — used only to detect the installation path)
3. **Access** via browser: `https://your-domain.com/plugin-recovery-tool.php`

Optional: `deploy-recovery.sh` copies only `plugin-recovery-tool.php` to a configured WoltLab path.

```
your-woltlab-root/
├── global.php
├── lib/
├── acp/
└── plugin-recovery-tool.php  ← only this file from the repo
```
## 🗑️ Deinstallation
![Deinstallation](https://github.com/user-attachments/assets/19e278bc-31ec-4496-97d9-fa71d9ec2dec)

## 🔐 Authentication

For security, the tool uses a file-based authentication system (inspired by WoltLab's wsc-recovery.php):

1. **Visit** the tool URL
2. **Download** the `plugin-recovery-auth.php` file (automatically generated)
3. **Upload** it to the same directory
4. **Click** "🚀 Recovery Tool starten"

The auth file is valid for 24 hours.

<!-- SCREENSHOT: screenshots/auth-flow.gif -->
<!-- OPTIONAL: Animiertes GIF das den kompletten Auth-Flow zeigt -->
<!-- Falls kein GIF: Drei Screenshots auth-step1.png, auth-step2.png, auth-step3.png -->

## 📖 Usage Examples

### Example 1: Repair Broken ACP

**Scenario:** ACP shows "Call to a member function toHtml() on null"

```
1. Access: https://your-domain.com/plugin-recovery-tool.php
2. Authenticate (download + upload auth file)
3. Select: "🔧 ACP Repair"
4. Enter package identifier: de.vendor.plugin.name
5. Review found menu items
6. Click "Alle löschen"
7. Cache gets cleared automatically
8. ✅ ACP should work again!
```

### Example 2: Uninstall Broken Plugin

**Scenario:** Plugin installation failed, need to remove all traces

```
1. Access recovery tool
2. Select: "🗑️ Plugin Uninstall"
3. Choose method:
   Option A: Enter "de.vendor.plugin.name"
   Option B: Upload the plugin .tar file (RECOMMENDED)
4. If uploaded, tool automatically analyzes package.xml and finds:
   - Database tables (from install_info.php)
   - Options (from option.xml)
   - Permissions (from userGroupOption.xml)
   - Cronjobs (from package.xml)
   - ACP menu items (from acpMenu.xml)
   - Language items (from language/*.xml)
   - Object types, page locations, URL rules
5. Review detailed preview with all found resources
6. Optional: Click "SQL anzeigen" to see generated cleanup SQL
7. Click "JETZT DEINSTALLIEREN"
8. ✅ Plugin completely removed with all resources!
```

### Example 3: Clear All Caches (Quick Fix)

**Scenario:** Something is broken, try clearing caches first

```
1. Access recovery tool
2. Select: "🧹 Cache Clear"
3. Click "Cache jetzt löschen"
4. ✅ Done! 98 files deleted
```

## 🔍 How It Works

### ACP Repair Mode

**With Package in Database:**
```sql
-- Find packageID
SELECT packageID FROM wcf1_package WHERE package = 'de.vendor.plugin.name'

-- Delete all ACP menu items for this package
DELETE FROM wcf1_acp_menu_item WHERE packageID = 123
```

**Without Package (Failed Installation):**
```sql
-- Pattern-based search using app name from identifier
-- Example: de.vendor.app.extension → searches for "app.acp.menu.%"
SELECT COUNT(*) FROM wcf1_acp_menu_item WHERE menuItem LIKE 'app.acp.menu.%'
```

### Plugin Uninstall Mode

**With Package Upload (Automatic Detection):**
```php
// 1. Extract package archive
extractArchive($uploadedFile, $extractDir)

// 2. Parse package.xml
$packageData = parsePackageXml($extractDir . '/package.xml')
// Extracts: application name, instruction types

// 3. Analyze all resources:
- findDatabaseTables() // Parses install_info.php for DatabaseTable::create()
- findOptions() // Parses option.xml, extracts prefix
- findUserGroupOptions() // Parses userGroupOption.xml
- findCronjobs() // Finds cronjobs in package.xml
- findAcpMenuItems() // Parses acpMenu.xml
- findLanguageItems() // Scans language/*.xml
- findObjectTypes() // Parses objectType.xml
- findPageLocations() // Parses pageLocation.xml
- findUrlRules() // Parses urlRule.xml

// 4. Detect WCF_N (from database or table names)
$wcfN = detectWcfN($db, $packageIdentifier, $extractDir)

// 5. Delete all identified resources in transaction
```

**Manual Package Identifier (Fallback):**
```php
// 1. Find package
$packageData = SELECT * FROM wcf1_package WHERE package = ?

// 2. Find all tables (pattern matching on app name)
$tables = SHOW TABLES
// Filters: "appname1_*", "appname_*"

// 3. Delete in transaction:
DELETE FROM wcf1_acp_menu_item WHERE packageID = ?
DELETE FROM wcf1_package WHERE packageID = ?
DROP TABLE appname1_table1, appname1_table2, ...
```

### Cache Clear Mode

```php
// Recursively deletes all files in:
- tmp/*
- cache/*
- templates/compiled/*
- acp/templates/compiled/*
```

## ⚠️ Important Warnings

### 🚨 Security

- **ALWAYS delete the tool after use!**
- The recovery tool has full database access
- Keeping it on your server is a **serious security risk**
- Use the **"🗑️ Recovery Tool vollständig entfernen"** button when done

### 🚨 Data Loss

- **Plugin Uninstall is IRREVERSIBLE**
- **No backup is created automatically**
- **Always backup your database before using this tool**
- Review what will be deleted before confirming

### 🚨 Base Plugin Warning

⚠️ The tool does **NOT** detect base plugin dependencies!

**Example:**
```
Base Plugin:  de.vendor.urlshort
Extension:    de.vendor.urlshort.featuredLinks

Problem: If the extension incorrectly placed files in 
         the base plugin's directory, uninstalling might
         delete files the base plugin needs!
```

**Solution:**
- Only use this tool for emergency cleanup
- For normal uninstallation, use the WoltLab ACP
- Check file locations before installing extensions

## 🛠️ Technical Details

**Requirements:**
- PHP 8.0+
- WoltLab Suite 6.0+
- Write permissions in installation directory

**Bootstrap & database:**
- **Does not load `global.php`** — minimal bootstrap so the tool still runs when broken plugins crash the ACP
- Loads `config.inc.php` and required WoltLab classes only as needed
- Database access via WoltLab's DB layer after minimal bootstrap (not a full front-end/bootstrap)
- All queries use prepared statements; transactions for safe rollback on errors
- **Generic uninstall** by `packageID` for any installed plugin
- Rebuilds `options.inc.php` via `OptionEditor::rebuild()` after option cleanup

**Supported Archives:**
- `.tar`
- `.tar.gz`
- `.tgz`

## 🐛 Troubleshooting

### "WoltLab nicht gefunden..."

**Cause:** `plugin-recovery-tool.php` is not in the WoltLab root (directory must contain `global.php` and `config.inc.php` for detection only — the tool does not load `global.php`)

**Solution:** Upload only `plugin-recovery-tool.php` to the root next to `global.php`

### "Plugin nicht in Datenbank gefunden"

**Cause:** Installation failed before plugin was registered

**Solution:**
1. Use "ACP Repair" mode
2. Tool shows pattern-based matches: `app.acp.menu.%`
3. Review count and confirm deletion

### "Call to undefined method getHandle()"

**Cause:** Outdated version

**Solution:** Download latest from [GitHub Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)

### ACP still broken after Cache Clear

**Possible causes:**
1. Missing PHP class files
2. Database corruption
3. Base plugin files deleted

**Solution:**
1. Try ACP Repair mode first
2. If that fails, reinstall the base plugin via FTP + database
3. Check WoltLab error logs: `log/YYYY-MM-DD.txt`

## 📝 Changelog

### v1.2.2 (2026-05-15)
- 🎨 **UI:** Official WoltLab layout (`WCFSetup.css` + suite logo from your installation, like Rescue Mode)
- 🗑️ Removed custom dark theme – consistent look with suite setup/recovery
- 📐 Forms and notices follow WoltLab conventions (`section`, `formSubmit`, `p.info` / `p.success` / `p.error`)

### v1.2.1 (2026-05-15)
- 🐛 Bootstrap fix: `class wcf\system\WCF not found` with minimal autoload

### v1.2.0 (2026-05-15)
- 📦 **Single file for users:** `plugin-recovery-tool.php` only; auth file downloaded from the tool
- 🚫 **No `global.php` bootstrap** — works when the ACP is broken by faulty plugins
- 🗑️ **Generic uninstall** via `packageID` for all plugins
- ⚙️ **`options.inc.php` rebuilt** with `OptionEditor::rebuild()`
- 🧹 Removed separate `recovery-bootstrap.php` / `recovery-cleanup.php` from the repository

### v1.1.0 (2025-01-XX)
- 🆕 **Automatic resource detection from package files**
- 🆕 Package upload support for ACP Repair mode
- 🆕 Comprehensive resource analysis:
  - Database tables (from install files)
  - Options, Permissions, Cronjobs
  - ACP menu items, Language items
  - Object types, Page locations, URL rules
- 🆕 SQL preview and export functionality
- 🆕 Automatic WCF_N detection (database + fallback)
- 🆕 Base plugin table filtering
- 🆕 Improved preview with detailed resource breakdown

### v1.0.0 (2025-01-16)
- ✨ Initial release
- 🔧 ACP Repair mode (pattern-based + packageID)
- 🗑️ Plugin Uninstall mode (manual + upload)
- 🧹 Cache Clear mode
- 🔐 File-based authentication (24h validity)
- 🎨 WoltLab-inspired dark theme
- 🗑️ Self-destruct function
- 📊 Preview before deletion

## 📄 License

MIT License - Copyright (c) 2025 Sunny C.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

**Inspired by [WoltLab's wsc-recovery.php](https://manual.woltlab.com/de/recovery-tool/)**

## 🤝 Contributing

Issues and pull requests are welcome!

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes: `git commit -m 'Add amazing feature'`
4. Push to the branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

## ⚡ Quick Links

- [WoltLab Suite Documentation](https://docs.woltlab.com/6.0/)
- [Official WoltLab Recovery Tool](https://manual.woltlab.com/de/recovery-tool/)
- [Report Issues](https://github.com/benjarogit/sc-woltlab-plugin-recovery/issues)
- [Latest Release](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)

## 🔗 Related Projects

- [Simple WoltLab Plugin Manager](https://github.com/benjarogit/simple-woltlab-plugin-manager) - Plugin management tool
- [Photoshop CC Linux](https://github.com/benjarogit/photoshopCClinux) - Photoshop CC for Linux
- [WoltLab Profile](https://www.woltlab.com/user/1350052-sunny-c/) - My WoltLab Community profile

---

<p align="center">
  <strong>Made with ❤️ by <a href="https://github.com/benjarogit">Sunny C.</a></strong>
</p>

<p align="center">
  <strong>⚠️ Use at your own risk. Always backup your database before using recovery tools!</strong>
</p>
