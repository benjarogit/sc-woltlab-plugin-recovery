# WoltLab Plugin Recovery Tool

> Emergency recovery tool for WoltLab Suite 6.0+ when plugins break your installation. Repairs ACP, uninstalls broken plugins, and clears caches - even when the admin panel is completely inaccessible.

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
![Mode Selection](https://github.com/user-attachments/assets/0cdafa3d-4a65-49d5-a5a7-8194a468a518)

### 🔧 ACP Repair
- Removes broken ACP menu entries by plugin identifier
- Pattern-based cleanup when plugin is not in database
- Shows preview before deletion

<!-- SCREENSHOT: screenshots/acp-repair.png -->
<!-- Zeige den ACP Repair Modus mit Package-Identifier Eingabefeld und Ergebnis-Tabelle -->
![ACP Repair Mode](https://github.com/user-attachments/assets/abab0e73-cc67-4601-bde5-8d60c3865e89)

### 🗑️ Plugin Uninstall
- Complete plugin removal from database and filesystem
- Two methods:
  - Manual package identifier input
  - Package upload (.tar/.tar.gz) with auto-detection
- Finds and removes all plugin tables automatically
- Safe: Shows what will be deleted before executing

<!-- SCREENSHOT: screenshots/plugin-uninstall.png -->
![Plugin Uninstall](https://github.com/user-attachments/assets/bfa8dbba-a2a8-450a-88c3-f7c4cfc61847)

### 🧹 Cache Clear
- Deletes all compiled templates
- Clears cache directories:
  - `tmp/`
  - `cache/`
  - `templates/compiled/`
  - `acp/templates/compiled/`

<!-- SCREENSHOT: screenshots/cache-clear.png -->
<!-- Zeige den Cache Clear Modus mit Erfolgs-Meldung und Anzahl gelöschter Dateien -->
![Cache Clear Mode](https://github.com/user-attachments/assets/07f04bc6-e157-45a8-891d-3abe946d02c0)

### 👤 User Management
- Links to official WoltLab recovery tool
- For admin password reset and user management

![User Management](https://github.com/user-attachments/assets/63d9832c-0857-4c9a-ad22-fbd1566c50b1)

## 📦 Installation

1. **Download** `plugin-recovery-tool.php` from [Releases](https://github.com/SunnyCueq/woltlab-plugin-recovery/releases)
2. **Upload** to your WoltLab Suite root directory (same level as `global.php`)
3. **Access** via browser: `https://your-domain.com/plugin-recovery-tool.php`

```
your-woltlab-root/
├── global.php
├── lib/
├── acp/
└── plugin-recovery-tool.php  ← Upload here
```
## 🗑️ Deinstallation
![Deinstallation](https://github.com/user-attachments/assets/1d36e80c-07ce-49e2-918d-72ccef211e68)

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
   Option B: Upload the plugin .tar file
4. Review what will be deleted:
   - Package entry from wcf1_package
   - 3 database tables (plugin1_table1, plugin1_table2, ...)
   - ACP menu items
5. Click "JETZT DEINSTALLIEREN"
6. ✅ Plugin completely removed!
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

**Database Access:**
- Uses WoltLab's `WCF::getDB()`
- All queries use prepared statements
- Transactions for safe rollback on errors

**Supported Archives:**
- `.tar`
- `.tar.gz`
- `.tgz`

## 🐛 Troubleshooting

### "WoltLab Suite global.php nicht gefunden!"

**Cause:** Tool not in WoltLab root directory

**Solution:** Upload to root (where `global.php` is located)

### "Plugin nicht in Datenbank gefunden"

**Cause:** Installation failed before plugin was registered

**Solution:**
1. Use "ACP Repair" mode
2. Tool shows pattern-based matches: `app.acp.menu.%`
3. Review count and confirm deletion

### "Call to undefined method getHandle()"

**Cause:** Outdated version

**Solution:** Download latest from [GitHub Releases](https://github.com/SunnyCueq/woltlab-plugin-recovery/releases)

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
- [Report Issues](https://github.com/SunnyCueq/woltlab-plugin-recovery/issues)
- [Latest Release](https://github.com/SunnyCueq/woltlab-plugin-recovery/releases)

---

<p align="center">
  <strong>Made with ❤️ by <a href="https://benjaro.info">Sunny C.</a></strong>
</p>

<p align="center">
  <strong>⚠️ Use at your own risk. Always backup your database before using recovery tools!</strong>
</p>
