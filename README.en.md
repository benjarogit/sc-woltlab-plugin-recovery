# WoltLab Plugin Recovery Tool

> Emergency recovery tool for WoltLab Suite 6.0+ — repairs the ACP, uninstalls broken plugins, resets users, and clears caches when the admin panel is completely inaccessible.

**Language / Sprache:** [English](README.en.md) | [Deutsch](README.md)

![WoltLab Suite](https://img.shields.io/badge/WoltLab%20Suite-6.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Version](https://img.shields.io/github/v/release/benjarogit/sc-woltlab-plugin-recovery)

![Authentication Screen](https://github.com/user-attachments/assets/d18e0871-2ab2-4f47-9bd7-8820671bffa1)

## When do you need this?

- ACP shows "Call to a member function toHtml() on null"
- A plugin installation failed and broke your admin panel
- You need to uninstall a plugin but the ACP is inaccessible
- A broken plugin created invalid ACP menu entries
- You need to clear all caches and nothing else works
- You need to reset an admin password without ACP access

## Modes

| Mode | What it does |
|---|---|
| **ACP Repair** | Removes broken ACP menu entries by package identifier or uploaded archive |
| **Plugin Uninstall** | Fully removes a plugin from the database and filesystem, with SQL preview |
| **User Management** | Search users, reset passwords, manage groups, change email, activate accounts |
| **Cache Clear** | Deletes compiled templates and all cache directories |
| **Package List Repair** | Fixes orphaned packages causing null errors on the Package List page |

![Mode Selection](https://github.com/user-attachments/assets/c108ffbb-4db9-448c-853c-0b0a9bffc5c4)

## Installation

1. **Download** `plugin-recovery-tool.php` from [Releases](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases)
2. **Upload** to your WoltLab Suite root directory (same folder as `global.php`)
3. **Open** in your browser: `https://your-domain.com/plugin-recovery-tool.php`

```
your-woltlab-root/
├── global.php
├── lib/
├── acp/
└── plugin-recovery-tool.php   ← only this file needed
```

The tool detects your WoltLab installation automatically. It does **not** load `global.php` — this is intentional so it still works when broken plugins crash the bootstrap.

### Authentication

The tool uses a file-based challenge inspired by WoltLab's own `wsc-recovery.php`:

1. Visit the tool URL — a `plugin-recovery-auth.php` file is generated automatically
2. Download it via FTP/SFTP and upload it back to the same directory
3. Click "Start Recovery Tool" — you are authenticated for **24 hours**

The token is verified on upload and expires automatically. Re-uploading the auth file is required after expiry.

### Removal

Use the built-in **"Remove Recovery Tool completely"** button when you are done. The tool deletes itself along with the auth file and redirects you back to the ACP. Do not leave the file on your server.

---

## Features in Detail

### ACP Repair

Removes broken ACP menu entries that crash the admin panel:

- **Input by identifier:** Enter the package identifier manually (e.g. `de.example.my-plugin`)
- **Input by archive:** Upload a `.tar`, `.tar.gz`, or `.tgz` file — `package.xml` is parsed automatically
- **Preview before deletion:** Found menu entries are displayed in a table (Menu Item + Controller); deletion requires explicit confirmation
- The cache is cleared automatically after deletion

### Plugin Uninstall

Complete plugin removal via a **3-step wizard**:

**Step 1 — Analysis & Selection**
- Enter a package identifier or upload an archive
- The tool checks the database for a matching package (packageID, name, WCF_N)
- **PIP resource matrix:** Displays all detected resource types with live database row counts:
  - ACP menu items, event listeners, template listeners, options, user group options
  - Cronjobs, object types, language variables, templates, ACP templates, pages, boxes
  - Notification events, BBCodes, smileys, ACL options, menu items, and more
  - Filesystem: plugin-specific database tables (DROP TABLE), files on disk
- **Per-resource checkboxes:** Individual resource types can be deselected
- **Dry-run mode:** Simulates the uninstall without making any database changes

**Step 2 — Backup**
- Generates a `.sql` backup of all selected database rows (pure PHP, no `mysqldump` required)
- The backup file can be downloaded directly in the browser
- Proceeding without a backup is possible, but not recommended

**Step 3 — Execute**
- Deletes all selected resources from the database
- Optionally deletes plugin files from the filesystem with safety checks:
  - Blocklist protects critical system directories (e.g. `lib/`, `acp/`, `templates/`)
  - Realpath validation prevents path-traversal attacks
- Repairs orphaned `wcf_package` entries after deletion

### User Management

Full user administration without ACP access:

- **User search:** Search by username or email address
- **Password reset:** Set a new password (compatible with WoltLab's password hashing)
- **Group management:** View and assign or remove individual user groups
- **Email change:** Update the email address directly in the database
- **Account activation:** Unlock banned or inactive accounts
- **2FA disable:** Reset two-factor authentication when the user no longer has access to their authenticator

### Cache Clear

Deletes all cache and compiled-template directories directly via the filesystem (bypasses WCF/CacheHandler):

| Directory | Contents |
|---|---|
| `tmp/` | Temporary files |
| `cache/` | File cache |
| `templates/compiled/` | Compiled frontend templates |
| `acp/templates/compiled/` | Compiled ACP templates |

### Package List Repair

Fixes orphaned database entries that block the ACP package list or prevent package uninstallation:

- Removes orphaned `wcf_package_installation_queue` entries
- Removes orphaned `wcf_application` entries without a matching package
- Useful when the ACP package list throws a null error or a package refuses to uninstall

---

## Security

**Delete the tool immediately after use.** The file has full database access — leaving it on a public server is a serious security risk. The authentication mechanism is a first line of defence only; it does not replace proper server security.

- **Blocklist:** Critical system directories and files are protected against deletion
- **Realpath validation:** All file paths are checked against path-traversal attacks
- **Prepared statements:** All database queries use prepared statements
- **Auth expiry:** Authentication expires automatically after **24 hours**
- Plugin Uninstall is **irreversible** — always back up your database beforehand
- The tool does **not** detect base-plugin dependencies; use the WoltLab ACP for normal uninstalls

## Requirements

- WoltLab Suite 6.0+
- PHP 8.0+
- Write permissions in the installation directory

## Changelog

Release notes for every version are on the [Releases page](https://github.com/benjarogit/sc-woltlab-plugin-recovery/releases).

## License

MIT License — Copyright (c) 2025 Sunny C.

---

*Inspired by [WoltLab's wsc-recovery.php](https://manual.woltlab.com/de/recovery-tool/)*
