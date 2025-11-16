<?php
/**
 * WoltLab Suite Recovery Tool - Universal
 *
 * Vereint 4 Recovery-Modi:
 * 1. ACP Repair - Repariert defekte ACP-Menüeinträge
 * 2. Plugin Uninstall - Deinstalliert Plugins komplett
 * 3. User Management - Admin-Passwort & Berechtigungen
 * 4. Cache Clear - Löscht alle Caches und kompilierte Templates
 *
 * @author Sunny C.
 * @version 1.0.0
 */

// ============================================================================
// KONFIGURATION
// ============================================================================

define('RECOVERY_MODE_SELECTION', 0);
define('RECOVERY_MODE_ACP_REPAIR', 1);
define('RECOVERY_MODE_PLUGIN_UNINSTALL', 2);
define('RECOVERY_MODE_USER_MANAGEMENT', 3);
define('RECOVERY_MODE_CACHE_CLEAR', 4);

$authHash = '';
$authFilename = 'plugin-recovery-auth.php';

// ============================================================================
// AUTHENTIFIZIERUNG (wie WoltLab wsc-recovery.php)
// ============================================================================

// Hash generieren bei erstem Aufruf
if (empty($_REQUEST['t']) || !preg_match('~^[a-f0-9]{40}$~', $_REQUEST['t'])) {
    $authHash = bin2hex(random_bytes(20));
    header("Location: plugin-recovery-tool.php?t={$authHash}");
    exit;
} else {
    $authHash = $_REQUEST['t'];
}

$action = (!empty($_GET['action'])) ? $_GET['action'] : '';

// Auth-Datei zum Download bereitstellen
if ($action === 'download-auth-file') {
    $expiresTimestamp = time() + 86400; // +1 Tag
    $content = "<?php exit; /* --- NICHT BEARBEITEN --- */ ?>\n{$expiresTimestamp}\n{$authHash}";

    header('Content-type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $authFilename . '"');
    header('Content-Length: ' . strlen($content));
    header('Connection: close');

    echo $content;
    exit;
}

// Cleanup-Action: Alle Recovery-Dateien löschen
if ($action === 'cleanup') {
    cleanupRecoveryFiles();

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Cleanup abgeschlossen</title></head><body>';
    echo '<h1 style="color: green;">✓ Recovery Tool erfolgreich entfernt!</h1>';
    echo '<p>Alle Dateien wurden gelöscht:</p>';
    echo '<ul>';
    echo '<li>plugin-recovery-tool.php</li>';
    echo '<li>plugin-recovery-auth.php</li>';
    echo '<li>uploads/</li>';
    echo '</ul>';
    echo '<p><strong>Diese Seite wird nicht mehr funktionieren.</strong></p>';
    echo '</body></html>';
    exit;
}

// Auth-Datei prüfen
$isAuthenticated = false;
if (file_exists(__DIR__ . '/' . $authFilename)) {
    $authFileContent = file_get_contents(__DIR__ . '/' . $authFilename);
    $lines = explode("\n", $authFileContent);

    if (count($lines) >= 3) {
        $expiresTimestamp = (int)$lines[1];
        $storedHash = trim($lines[2]);

        if ($expiresTimestamp > time() && hash_equals($storedHash, $authHash)) {
            $isAuthenticated = true;
        }
    }
}

// ============================================================================
// HELPER FUNKTIONEN
// ============================================================================

/**
 * Extrahiert Package-Identifier aus package.xml
 */
function extractPackageIdentifier($packageXmlPath) {
    if (!file_exists($packageXmlPath)) {
        return null;
    }

    $xml = simplexml_load_file($packageXmlPath);
    if ($xml === false) {
        return null;
    }

    $package = (string)$xml['name'];
    return $package ?: null;
}

/**
 * Entpackt TAR/TAR.GZ Archive
 */
function extractArchive($archivePath, $destination) {
    try {
        $phar = new PharData($archivePath);
        $phar->extractTo($destination, null, true);
        return true;
    } catch (Exception) {
        return false;
    }
}

/**
 * Findet alle Tabellen eines Plugins anhand des Präfixes
 */
function findPackageTables($db, $packageIdentifier) {
    // App-Name aus Package-Identifier extrahieren
    // z.B. de.julian-pfeil.urlshort.featuredLinks -> urlshort
    $parts = explode('.', $packageIdentifier);
    $appName = end($parts);

    // Versuche verschiedene Muster
    $patterns = [
        $appName . '%',
        $appName . '1_%',
        strtolower($appName) . '%',
        strtolower($appName) . '1_%'
    ];

    // Alle Tabellen holen und in PHP filtern (WCF hat kein getHandle())
    $sql = "SHOW TABLES";
    $statement = $db->prepareStatement($sql);
    $statement->execute();

    $tables = [];
    while ($row = $statement->fetchArray()) {
        $tableName = reset($row);

        // Prüfen ob Tabellenname einen der Patterns enthält
        foreach ($patterns as $pattern) {
            $cleanPattern = str_replace('%', '', str_replace('_', '', $pattern));
            if (stripos($tableName, $cleanPattern) !== false) {
                $tables[] = $tableName;
                break;
            }
        }
    }

    return array_unique($tables);
}

/**
 * Löscht alle kompilierten Templates und Caches
 */
function clearCompiledTemplates() {
    $dirs = [
        __DIR__ . '/tmp',
        __DIR__ . '/cache',
        __DIR__ . '/templates/compiled',
        __DIR__ . '/acp/templates/compiled'
    ];

    $deletedFiles = 0;
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
                $deletedFiles++;
            }
        }
    }

    return $deletedFiles;
}

/**
 * Löscht alle Recovery-Dateien
 */
function cleanupRecoveryFiles() {
    $files = [
        __DIR__ . '/plugin-recovery.php',
        __DIR__ . '/universal-recovery.php',
        __DIR__ . '/acp-repair.php',
        __DIR__ . '/wsc-recovery.php',
        __DIR__ . '/recovery-tool.php',
        __DIR__ . '/plugin-recovery-tool.php',
        __DIR__ . '/plugin-recovery-auth.php',
        __DIR__ . '/uploads'  // Upload-Verzeichnis
    ];

    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        } elseif (is_dir($file)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f) : @unlink($f);
            }
            @rmdir($file);
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Plugin Recovery Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #2D2D2D;
            color: #c0c0c0;
            padding: 50px 20px;
            line-height: 1.5;
        }
        .container {
            max-width: 980px;
            margin: 0 auto;
            background: #3D3D3D;
            padding: 40px;
            border-radius: 3px;
        }
        footer {
            max-width: 980px;
            margin: 20px auto 0;
            padding: 10px 0;
            text-align: right;
            color: #9D9D9D;
            font-size: 13px;
        }
        footer a {
            color: inherit;
            text-decoration: none;
        }
        footer a:hover {
            color: #fff;
        }
        h1 {
            color: #fff;
            margin-bottom: 10px;
            font-size: 32px;
            font-weight: 300;
        }
        h2 {
            color: #fff;
            margin: 40px 0 10px 0;
            font-size: 24px;
            font-weight: 300;
        }
        .subtitle {
            color: #9D9D9D;
            margin-bottom: 30px;
            font-size: 14px;
        }
        code {
            color: #fff;
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            word-break: break-word;
        }
        .mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .mode-button {
            display: block;
            padding: 20px;
            background: rgba(0, 0, 0, .125);
            border: 1px solid #444444;
            border-radius: 3px;
            text-decoration: none;
            color: #c0c0c0;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
        }
        .mode-button:hover {
            background: rgba(0, 0, 0, .25);
            border-color: #666;
        }
        .mode-button strong {
            display: block;
            font-size: 18px;
            margin-bottom: 8px;
            color: #fff;
        }
        .mode-button span {
            font-size: 13px;
            color: #9D9D9D;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #fff;
        }
        input[type="text"],
        input[type="password"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #444444;
            border-radius: 3px;
            font-size: 14px;
            background: #2D2D2D;
            color: #c0c0c0;
        }
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px dashed #444444;
            border-radius: 3px;
            background: #2D2D2D;
            color: #c0c0c0;
        }
        button, .button {
            background: #369;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 3px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-block;
            text-decoration: none;
        }
        button:hover, .button:hover {
            background: #258;
        }
        .btn-danger {
            background: #c33;
        }
        .btn-danger:hover {
            background: #a22;
        }
        .btn-success {
            background: #3c3;
        }
        .btn-success:hover {
            background: #2a2;
        }
        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 3px;
            color: #fff;
        }
        .alert-success {
            background: rgba(60, 204, 60, 0.3);
            border: 1px solid #3c3;
        }
        .alert-error {
            background: rgba(204, 51, 51, 0.3);
            border: 1px solid #c33;
        }
        .alert-info {
            background: rgba(51, 102, 153, 0.3);
            border: 1px solid #369;
        }
        .alert-warning {
            background: rgba(204, 153, 51, 0.3);
            border: 1px solid #c93;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #fff;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        pre {
            background: #2D2D2D;
            padding: 15px;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 13px;
            color: #c0c0c0;
            border: 1px solid #444444;
        }
        small {
            color: #9D9D9D;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .table th,
        .table td {
            padding: 10px 20px;
            text-align: left;
            border-bottom: 1px solid #444444;
        }
        .table th {
            border-top: 1px solid #444444;
            border-bottom-width: 2px;
            font-weight: 600;
        }
        .table tbody tr:nth-child(odd) {
            background: rgba(0, 0, 0, .125);
        }
        .table tbody tr:hover {
            background: rgba(0, 0, 0, .25);
        }
    </style>
</head>
<body>
    <div class="container">

<?php

// Auth-Screen anzeigen wenn nicht authentifiziert
if (!$isAuthenticated) {
?>
    <h1>Plugin Recovery Tool</h1>
    <p class="subtitle">Authentifizierung erforderlich</p>

    <div class="alert alert-warning">
        <strong>Schritt 1:</strong> Auth-Datei herunterladen<br>
        <a href="?action=download-auth-file&t=<?= $authHash ?>" class="button" style="display: inline-block; margin-top: 10px;" id="downloadBtn">
            📥 plugin-recovery-auth.php herunterladen
        </a>
    </div>

    <div class="alert alert-info" style="margin-top: 20px;" id="step2" style="display: none;">
        <strong>Schritt 2:</strong> Datei hochladen<br>
        Laden Sie die heruntergeladene Datei <code><?= $authFilename ?></code> in dasselbe Verzeichnis hoch,
        in dem sich diese <code>plugin-recovery-tool.php</code> befindet.
    </div>

    <div class="alert alert-info" style="margin-top: 20px;" id="step3" style="display: none;">
        <strong>Schritt 3:</strong> Recovery starten<br>
        <a href="?t=<?= $authHash ?>" class="button btn-success" style="margin-top: 10px;">
            🚀 Recovery Tool starten
        </a>
    </div>

    <div class="alert alert-error" style="margin-top: 30px;">
        <strong>⚠️ Sicherheitshinweis:</strong><br>
        Löschen Sie beide Dateien (<code>plugin-recovery-tool.php</code> und <code><?= $authFilename ?></code>)
        nach der Verwendung. Diese Dateien können ein Sicherheitsrisiko darstellen!
    </div>

    <footer>
        <a href="https://benjaro.info" target="_blank">Plugin Recovery Tool</a> &copy; 2025 Sunny C. |
        Inspiriert von <a href="https://www.woltlab.com" target="_blank">WoltLab</a>
    </footer>

    <script>
    document.getElementById('downloadBtn').addEventListener('click', function() {
        setTimeout(function() {
            document.getElementById('step2').style.display = 'block';
            document.getElementById('step3').style.display = 'block';
        }, 500);
    });
    </script>

    </div>
</body>
</html>
<?php
    exit;
}

// Ab hier ist der User authentifiziert

// ============================================================================
// WOLTLAB BOOTSTRAP
// ============================================================================

$wcfDir = __DIR__;
if (!file_exists($wcfDir . '/global.php')) {
    die('WoltLab Suite global.php nicht gefunden!');
}

require_once($wcfDir . '/global.php');

use wcf\system\WCF;

// ============================================================================
// MODUS AUSWAHL
// ============================================================================

$mode = isset($_GET['mode']) ? (int)$_GET['mode'] : RECOVERY_MODE_SELECTION;

?>

<?php
// ============================================================================
// MODUS 0: AUSWAHL
// ============================================================================

if ($mode === RECOVERY_MODE_SELECTION) {
?>
    <h1>WoltLab Suite Recovery Tool</h1>
    <p class="subtitle">Wählen Sie den gewünschten Recovery-Modus</p>

    <div class="mode-grid">
        <a href="?mode=<?= RECOVERY_MODE_ACP_REPAIR ?>&t=<?= $authHash ?>" class="mode-button">
            <strong>🔧 ACP Repair</strong>
            <span>Repariert defekte ACP-Menüeinträge eines Plugins</span>
        </a>

        <a href="?mode=<?= RECOVERY_MODE_PLUGIN_UNINSTALL ?>&t=<?= $authHash ?>" class="mode-button">
            <strong>🗑️ Plugin Uninstall</strong>
            <span>Deinstalliert Plugin komplett (DB + Dateien)</span>
        </a>

        <a href="?mode=<?= RECOVERY_MODE_USER_MANAGEMENT ?>&t=<?= $authHash ?>" class="mode-button">
            <strong>👤 User Management</strong>
            <span>Admin-Passwort zurücksetzen & Berechtigungen</span>
        </a>

        <a href="?mode=<?= RECOVERY_MODE_CACHE_CLEAR ?>&t=<?= $authHash ?>" class="mode-button">
            <strong>🧹 Cache Clear</strong>
            <span>Löscht alle Caches & kompilierte Templates</span>
        </a>
    </div>

    <div class="alert alert-info">
        <strong>ℹ️ Hinweis:</strong> Dieses Tool arbeitet direkt auf Datenbank-Ebene und sollte nur im Notfall verwendet werden.
    </div>

    <div class="alert alert-warning" style="margin-top: 30px;">
        <strong>⚠️ Fertig mit Recovery?</strong><br>
        Wenn Sie alle Reparaturen abgeschlossen haben, sollten Sie das Recovery Tool und alle zugehörigen Dateien löschen.<br><br>
        <a href="?action=cleanup&t=<?= $authHash ?>" class="button btn-danger" onclick="return confirm('ACHTUNG: Dies löscht plugin-recovery-tool.php, plugin-recovery-auth.php und alle Upload-Verzeichnisse. Fortfahren?')">
            🗑️ Recovery Tool vollständig entfernen
        </a>
    </div>

<?php
}

// ============================================================================
// MODUS 1: ACP REPAIR
// ============================================================================

elseif ($mode === RECOVERY_MODE_ACP_REPAIR) {
?>
    <a href="?t=<?= $authHash ?>" class="back-link">← Zurück zur Auswahl</a>
    <h1>🔧 ACP Repair</h1>
    <p class="subtitle">Repariert defekte ACP-Menüeinträge eines Plugins</p>

<?php
    $db = WCF::getDB();

    // Schritt 1: Package-Identifier eingeben
    if (!isset($_POST['package_identifier'])) {
?>
    <form method="POST">
        <div class="form-group">
            <label>Package-Identifier des problematischen Plugins:</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.julian-pfeil.urlshort.featuredLinks" required>
            <small style="color: #666; display: block; margin-top: 5px;">
                Der eindeutige Bezeichner des Plugins, dessen ACP-Menüeinträge repariert werden sollen.
            </small>
        </div>
        <button type="submit">Weiter →</button>
    </form>
<?php
    } else {
        $packageIdentifier = trim($_POST['package_identifier']);

        // Package suchen
        $sql = "SELECT packageID, package, packageName
                FROM wcf" . WCF_N . "_package
                WHERE package = ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([$packageIdentifier]);
        $packageData = $statement->fetchArray();

        if (!$packageData && !isset($_POST['force_cleanup'])) {
            // App-Name extrahieren für Pattern-Suche
            $parts = explode('.', $packageIdentifier);
            $appName = end($parts);

            // Prüfen ob überhaupt Menüeinträge mit diesem Pattern existieren
            $sql = "SELECT COUNT(*) as count
                    FROM wcf" . WCF_N . "_acp_menu_item
                    WHERE menuItem LIKE ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$appName . '.acp.menu.%']);
            $menuCount = $statement->fetchArray()['count'];

            echo '<div class="alert alert-warning">';
            echo '<strong>Warnung:</strong> Plugin nicht in Datenbank gefunden.<br>';
            echo 'Dies kann bedeuten, dass die Installation fehlgeschlagen ist.<br><br>';

            if ($menuCount > 0) {
                echo '<strong>Gefundene ACP-Menüeinträge mit Pattern "' . htmlspecialchars($appName) . '.acp.menu.*": ' . $menuCount . '</strong><br><br>';
                echo '<form method="POST">';
                echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                echo '<input type="hidden" name="force_cleanup" value="1">';
                echo '<button type="submit" class="btn-danger">Diese ' . $menuCount . ' Menüeinträge löschen</button>';
                echo '</form>';
            } else {
                echo '<strong>Keine ACP-Menüeinträge mit diesem Pattern gefunden.</strong><br>';
                echo 'Es gibt nichts zu bereinigen.';
            }
            echo '</div>';
        } else {
            $packageID = $packageData ? $packageData['packageID'] : null;

            // ACP-Menüeinträge finden
            if ($packageID) {
                $sql = "SELECT menuItem, menuItemController
                        FROM wcf" . WCF_N . "_acp_menu_item
                        WHERE packageID = ?";
                $statement = $db->prepareStatement($sql);
                $statement->execute([$packageID]);
            } else {
                // Fallback: Nach Pattern suchen
                $parts = explode('.', $packageIdentifier);
                $appName = end($parts);
                $sql = "SELECT menuItem, menuItemController
                        FROM wcf" . WCF_N . "_acp_menu_item
                        WHERE menuItem LIKE ?";
                $statement = $db->prepareStatement($sql);
                $statement->execute([$appName . '.acp.menu.%']);
            }

            $menuItems = [];
            while ($row = $statement->fetchArray()) {
                $menuItems[] = $row;
            }

            if (empty($menuItems)) {
                echo '<div class="alert alert-info">';
                echo '<strong>Keine ACP-Menüeinträge gefunden</strong><br>';
                echo 'Für dieses Plugin existieren keine ACP-Menüeinträge in der Datenbank.';
                echo '</div>';
            } elseif (!isset($_POST['confirm_delete'])) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Gefundene ACP-Menüeinträge (' . count($menuItems) . '):</strong>';
                echo '<table class="table" style="margin-top: 15px;">';
                echo '<thead><tr><th>Menu Item</th><th>Controller</th></tr></thead>';
                echo '<tbody>';
                foreach ($menuItems as $item) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['menuItem']) . '</td>';
                    echo '<td>' . htmlspecialchars($item['menuItemController'] ?: '-') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<form method="POST">';
                echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                if (!$packageID) {
                    echo '<input type="hidden" name="force_cleanup" value="1">';
                }
                echo '<input type="hidden" name="confirm_delete" value="1">';
                echo '<button type="submit" class="btn-danger">Alle löschen</button>';
                echo '</form>';
                echo '</div>';
            } else {
                // Löschen ausführen
                $db->beginTransaction();

                try {
                    if ($packageID) {
                        $sql = "DELETE FROM wcf" . WCF_N . "_acp_menu_item WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                    } else {
                        $parts = explode('.', $packageIdentifier);
                        $appName = end($parts);
                        $sql = "DELETE FROM wcf" . WCF_N . "_acp_menu_item WHERE menuItem LIKE ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$appName . '.acp.menu.%']);
                    }

                    $deletedCount = $statement->getAffectedRows();

                    // Cache löschen
                    clearCompiledTemplates();

                    $db->commitTransaction();

                    echo '<div class="alert alert-success">';
                    echo '<strong>✓ ACP-Repair erfolgreich!</strong><br>';
                    echo 'Gelöschte Menüeinträge: ' . $deletedCount . '<br>';
                    echo 'Cache wurde geleert.<br><br>';
                    echo '<a href="' . WCF::getPath() . 'acp/" class="btn-success">→ Zum ACP</a>';
                    echo '</div>';

                } catch (Exception $e) {
                    $db->rollBackTransaction();
                    echo '<div class="alert alert-error">';
                    echo '<strong>Fehler:</strong> ' . htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            }
        }
    }
}

// ============================================================================
// MODUS 2: PLUGIN UNINSTALL
// ============================================================================

elseif ($mode === RECOVERY_MODE_PLUGIN_UNINSTALL) {
?>
    <a href="?t=<?= $authHash ?>" class="back-link">← Zurück zur Auswahl</a>
    <h1>🗑️ Plugin Uninstall</h1>
    <p class="subtitle">Deinstalliert Plugin komplett aus Datenbank und Dateisystem</p>

<?php
    $db = WCF::getDB();

    // Schritt 1: Package-Identifier eingeben oder hochladen
    if (!isset($_POST['package_identifier']) && !isset($_FILES['package_file'])) {
?>
    <form method="POST">
        <div class="form-group">
            <label>Option 1: Package-Identifier manuell eingeben</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.julian-pfeil.urlshort.featuredLinks">
        </div>
        <button type="submit">Mit Identifier deinstallieren</button>
    </form>

    <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar oder .tar.gz)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz">
        </div>
        <button type="submit">Mit Datei deinstallieren</button>
    </form>
<?php
    } else {
        $packageIdentifier = null;

        // Package-Identifier ermitteln
        if (isset($_POST['package_identifier']) && !empty($_POST['package_identifier'])) {
            $packageIdentifier = trim($_POST['package_identifier']);
        } elseif (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK) {
            // Upload verarbeiten
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $uploadedFile = $uploadDir . '/' . basename($_FILES['package_file']['name']);
            if (move_uploaded_file($_FILES['package_file']['tmp_name'], $uploadedFile)) {
                // Entpacken
                $extractDir = $uploadDir . '/extracted';
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }

                if (extractArchive($uploadedFile, $extractDir)) {
                    $packageXml = $extractDir . '/package.xml';
                    $packageIdentifier = extractPackageIdentifier($packageXml);

                    if (!$packageIdentifier) {
                        echo '<div class="alert alert-error">Fehler: package.xml konnte nicht gelesen werden.</div>';
                    }
                } else {
                    echo '<div class="alert alert-error">Fehler: Archiv konnte nicht entpackt werden.</div>';
                }
            } else {
                echo '<div class="alert alert-error">Fehler: Datei-Upload fehlgeschlagen.</div>';
            }
        }

        if ($packageIdentifier) {
            // Package in DB suchen
            $sql = "SELECT packageID, package, packageName
                    FROM wcf" . WCF_N . "_package
                    WHERE package = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageIdentifier]);
            $packageData = $statement->fetchArray();

            echo '<div class="alert alert-info">';
            echo '<strong>Package:</strong> ' . htmlspecialchars($packageIdentifier) . '<br>';
            if ($packageData) {
                echo '<strong>Status:</strong> In Datenbank gefunden (ID: ' . $packageData['packageID'] . ')<br>';
                echo '<strong>Name:</strong> ' . htmlspecialchars($packageData['packageName']) . '<br>';
            } else {
                echo '<strong>Status:</strong> Nicht in Datenbank (Installation fehlgeschlagen?)<br>';
            }
            echo '</div>';

            // Tabellen finden
            $tables = findPackageTables($db, $packageIdentifier);

            if (!isset($_POST['confirm_uninstall'])) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Zu löschende Daten:</strong><br><br>';

                if ($packageData) {
                    echo '✓ Package-Eintrag in wcf' . WCF_N . '_package<br>';
                    echo '✓ ACP-Menüeinträge<br>';
                    echo '✓ Package-Installationsqueue<br>';
                }

                if (!empty($tables)) {
                    echo '<br><strong>Datenbank-Tabellen (' . count($tables) . '):</strong><br>';
                    foreach ($tables as $table) {
                        // Zähle Zeilen
                        try {
                            $sql = "SELECT COUNT(*) as count FROM `" . $table . "`";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $count = $statement->fetchArray()['count'];
                            echo '- ' . $table . ' (' . $count . ' Einträge)<br>';
                        } catch (Exception $e) {
                            echo '- ' . $table . '<br>';
                        }
                    }
                } else {
                    echo '<br>Keine spezifischen Tabellen gefunden.<br>';
                }

                echo '<br><form method="POST">';
                if (isset($_FILES['package_file'])) {
                    echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                } else {
                    echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                }
                echo '<input type="hidden" name="confirm_uninstall" value="1">';
                echo '<button type="submit" class="btn-danger">JETZT DEINSTALLIEREN</button>';
                echo '</form>';
                echo '</div>';

            } else {
                // Deinstallation durchführen
                $db->beginTransaction();

                try {
                    $log = [];

                    if ($packageData) {
                        $packageID = $packageData['packageID'];

                        // ACP-Menüeinträge löschen
                        $sql = "DELETE FROM wcf" . WCF_N . "_acp_menu_item WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                        $log[] = 'ACP-Menüeinträge gelöscht: ' . $statement->getAffectedRows();

                        // Package-Eintrag löschen
                        $sql = "DELETE FROM wcf" . WCF_N . "_package WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                        $log[] = 'Package-Eintrag gelöscht';

                        // Installationsqueue bereinigen
                        $sql = "DELETE FROM wcf" . WCF_N . "_package_installation_queue WHERE package = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageIdentifier]);
                        $log[] = 'Installationsqueue bereinigt: ' . $statement->getAffectedRows();
                    }

                    // Tabellen löschen
                    foreach ($tables as $table) {
                        $sql = "DROP TABLE IF EXISTS `" . $table . "`";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute();
                        $log[] = 'Tabelle gelöscht: ' . $table;
                    }

                    // Cache löschen
                    $deletedFiles = clearCompiledTemplates();
                    $log[] = 'Cache gelöscht: ' . $deletedFiles . ' Dateien';

                    $db->commitTransaction();

                    echo '<div class="alert alert-success">';
                    echo '<strong>✓ Plugin erfolgreich deinstalliert!</strong><br><br>';
                    echo '<strong>Durchgeführte Aktionen:</strong><br>';
                    foreach ($log as $entry) {
                        echo '• ' . htmlspecialchars($entry) . '<br>';
                    }
                    echo '</div>';

                } catch (Exception $e) {
                    $db->rollBackTransaction();
                    echo '<div class="alert alert-error">';
                    echo '<strong>Fehler bei Deinstallation:</strong><br>' . htmlspecialchars($e->getMessage());
                    echo '</div>';
                }
            }
        }
    }
}

// ============================================================================
// MODUS 3: USER MANAGEMENT
// ============================================================================

elseif ($mode === RECOVERY_MODE_USER_MANAGEMENT) {
?>
    <a href="?t=<?= $authHash ?>" class="back-link">← Zurück zur Auswahl</a>
    <h1>👤 User Management</h1>
    <p class="subtitle">Für User-Management nutzen Sie das offizielle WoltLab Recovery Tool</p>

    <div class="alert alert-info">
        <strong>WoltLab Recovery Tool (wsc-recovery.php)</strong><br><br>
        Für Admin-Passwort-Reset und Benutzer-Management verwenden Sie bitte das offizielle Tool von WoltLab:<br><br>

        <strong>Download & Anleitung:</strong><br>
        <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" style="color: #007bff;">
            → https://manual.woltlab.com/de/recovery-tool/
        </a><br><br>

        <strong>Funktionen des WoltLab Tools:</strong><br>
        • Admin-Passwort zurücksetzen<br>
        • Benutzer zu Administrator-Gruppe hinzufügen<br>
        • E-Mail-Adressen ändern<br>
        • Benutzer aktivieren/deaktivieren<br>
    </div>

<?php
}

// ============================================================================
// MODUS 4: CACHE CLEAR
// ============================================================================

elseif ($mode === RECOVERY_MODE_CACHE_CLEAR) {
?>
    <a href="?t=<?= $authHash ?>" class="back-link">← Zurück zur Auswahl</a>
    <h1>🧹 Cache Clear</h1>
    <p class="subtitle">Löscht alle Caches und kompilierte Templates</p>

<?php
    if (!isset($_POST['confirm_clear'])) {
?>
    <div class="alert alert-warning">
        <strong>Folgende Verzeichnisse werden geleert:</strong><br>
        • tmp/<br>
        • cache/<br>
        • templates/compiled/<br>
        • acp/templates/compiled/<br>
    </div>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <button type="submit" class="btn-danger">Cache jetzt löschen</button>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();

        echo '<div class="alert alert-success">';
        echo '<strong>✓ Cache erfolgreich geleert!</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br><br>';
        echo '<a href="' . WCF::getPath() . '" class="btn-success">→ Zur Hauptseite</a>';
        echo '</div>';
    }
}

?>

    </div>

    <footer>
        <a href="https://benjaro.info" target="_blank">Plugin Recovery Tool</a> &copy; 2025 Sunny C. |
        Inspiriert von <a href="https://www.woltlab.com" target="_blank">WoltLab</a>
    </footer>
</body>
</html>
