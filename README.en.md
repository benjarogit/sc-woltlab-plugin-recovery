# WoltLab Plugin Recovery Tool

> Emergency recovery tool for WoltLab Suite 6.0+ — repairs the ACP, uninstalls broken plugins, resets users, and clears caches when the admin panel is completely inaccessible.

**Language / Sprache:** [English](README.en.md) | [Deutsch](README.md)

![WoltLab Suite](https://img.shields.io/badge/WoltLab%20Suite-6.0+-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![Version](https://img.shields.io/github/v/release/benjarogit/sc-woltlab-plugin-recovery)

<p align="center">
  <img src="https://github.com/user-attachments/assets/387d02cb-4d84-47e9-8d2b-aac2cebccf8a" width="100%">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/b05703cc-8461-408f-9280-2b405d8be538" width="15%">

  <img src="https://github.com/user-attachments/assets/9b2b31e5-63a4-449d-85b1-ad6ce56dc1e4" width="15%">
</p>

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

<p align="center">
  <img src="https://github.com/user-attachments/assets/0278a36b-419b-45be-8b60-971c1ff1d3d0" width="100%">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/8d482e46-2729-492a-b244-4df70849374e" width="15%">

  <img src="https://github.com/user-attachments/assets/7aaf67d0-89b5-4c6d-8c26-1c4677fa5950" width="15%">

  <img src="https://github.com/user-attachments/assets/f8a01a05-1a63-4805-bba2-8d5af716ebb1" width="15%">

  <img src="https://github.com/user-attachments/assets/a0c86fbd-811f-460f-be98-8d117ef56af8" width="15%">

  <img src="https://github.com/user-attachments/assets/1002c23d-52f8-4f17-821f-9efe8e277251" width="15%">
</p>

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

![ACP Repair Mode](https://github.com/user-attachments/assets/50602546-147a-41a6-a7bd-c1866c96493e)

Removes broken ACP menu entries that crash the admin panel:

- **Input by identifier:** Enter the package identifier manually (e.g. `de.example.my-plugin`)
- **Input by archive:** Upload a `.tar`, `.tar.gz`, or `.tgz` file — `package.xml` is parsed automatically
- **Preview before deletion:** Found menu entries are displayed in a table (Menu Item + Controller); deletion requires explicit confirmation
- The cache is cleared automatically after deletion

### Plugin Uninstall

![Plugin Uninstall](https://github.com/user-attachments/assets/5a7f13c6-2988-4e11-9071-67980e7515fc)

![Uninstall wizard](https://github.com/user-attachments/assets/19e278bc-31ec-4496-97d9-fa71d9ec2dec)

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

![User Management](https://github.com/user-attachments/assets/f1555a4f-4b10-478a-a7ef-c953d37b922e)

Full user administration without ACP access:

- **User search:** Search by username or email address
- **Password reset:** Set a new password (compatible with WoltLab's password hashing)
- **Group management:** View and assign or remove individual user groups
- **Email change:** Update the email address directly in the database
- **Account activation:** Unlock banned or inactive accounts
- **2FA disable:** Reset two-factor authentication when the user no longer has access to their authenticator

### Cache Clear

![Cache Clear Mode](https://github.com/user-attachments/assets/1fc1f79e-fdc2-4b76-98ec-2719b8706bd7)

Deletes all cache and compiled-template directories directly via the filesystem (bypasses WCF/CacheHandler):

| Directory | Contents |
|---|---|
| `tmp/` | Temporary files |
| `cache/` | File cache |
| `templates/compiled/` | Compiled frontend templates |
| `acp/templates/compiled/` | Compiled ACP templates |

### Package List Repair

![Package List Repair](https://github.com/user-attachments/assets/1002c23d-52f8-4f17-821f-9efe8e277251)

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
