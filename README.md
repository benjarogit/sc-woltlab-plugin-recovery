# WoltLab Plugin Recovery Tool

> Emergency recovery tool for WoltLab Suite 6.0+ — repairs the ACP, uninstalls broken plugins, resets users, and clears caches when the admin panel is completely inaccessible.

**Language / Sprache:** [English](README.md) | [Deutsch](README.de.md)

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
| **Package List Repair** | Fixes orphaned packages causing null errors in the Package List page |

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
2. Download it and upload it to the same directory
3. Click "Recovery Tool starten" — you are authenticated for 24 hours

### Removal

Use the built-in **"Recovery Tool vollständig entfernen"** button when you are done. Do not leave the file on your server.

## Security

**Delete the tool immediately after use.** The file has full database access — leaving it on a public server is a serious security risk. The authentication mechanism is a first line of defence only; it does not replace proper server security.

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
