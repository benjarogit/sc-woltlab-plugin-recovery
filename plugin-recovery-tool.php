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
    if (!file_exists($packageXmlPath) || !is_file($packageXmlPath)) {
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
function findPackageTables($db, $packageIdentifier, $wcfN = null) {
    // App-Name aus Package-Identifier extrahieren
    // z.B. de.julian-pfeil.urlshort.featuredLinks -> urlshort
    // z.B. info.benjaro.urlshort.affiliate -> urlshort (Basis-Plugin)
    $parts = explode('.', $packageIdentifier);
    
    // Versuche verschiedene App-Namen:
    // 1. Letzter Teil (für direkte Plugins)
    // 2. Vorletzter Teil (für Erweiterungen, z.B. urlshort.affiliate -> urlshort)
    $appNames = [];
    if (count($parts) >= 2) {
        $appNames[] = $parts[count($parts) - 2]; // Vorletzter Teil (Basis-Plugin)
    }
    $appNames[] = end($parts); // Letzter Teil (Erweiterung oder direktes Plugin)
    
    // Entferne Duplikate und leere Werte
    $appNames = array_unique(array_filter($appNames));

    // Alle Tabellen holen und in PHP filtern (WCF hat kein getHandle())
    $sql = "SHOW TABLES";
    $statement = $db->prepareStatement($sql);
    $statement->execute();

    $tables = [];
    
    // Basis-Tabellen für alle möglichen WCF_N Werte sammeln
    $allBaseTables = [];
    for ($n = 1; $n <= 10; $n++) {
        $allBaseTables = array_merge($allBaseTables, getBasePluginTables($n));
    }
    $allBaseTables = array_unique($allBaseTables);
    
    while ($row = $statement->fetchArray()) {
        $tableName = reset($row);
        
        // Überspringe Basis-Tabellen
        if (in_array($tableName, $allBaseTables)) {
            continue;
        }

        // Prüfe ob Tabellenname mit einem der App-Namen beginnt
        // Unterstützt verschiedene Formate:
        // - urlshort1_table (mit WCF_N)
        // - urlshort_table (ohne WCF_N)
        // - urlshort1table (ohne Unterstrich)
        foreach ($appNames as $appName) {
            $appNameLower = strtolower($appName);
            
            // Pattern 1: appname{N}_table (z.B. urlshort1_url)
            if (preg_match('/^' . preg_quote($appName, '/') . '\d+_/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            
            // Pattern 2: appname_table (ohne Nummer)
            if (preg_match('/^' . preg_quote($appName, '/') . '_/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            
            // Pattern 3: appname{N}table (ohne Unterstrich)
            if (preg_match('/^' . preg_quote($appName, '/') . '\d+/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            
            // Pattern 4: appname (direkt, ohne alles)
            if (stripos($tableName, $appName) === 0) {
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

// ============================================================================
// PACKAGE-RESSOURCEN ANALYSE FUNKTIONEN
// ============================================================================

/**
 * Findet längstes gemeinsames Präfix aus einem Array von Strings
 */
function extractCommonPrefix($items, $separator = '.') {
    if (empty($items)) {
        return '';
    }

    $prefix = $items[0];
    foreach ($items as $item) {
        while (substr($item, 0, strlen($prefix)) !== $prefix) {
            $prefix = substr($prefix, 0, -1);
            if (empty($prefix)) {
                return '';
            }
        }
    }

    // Finde letztes Trennzeichen
    $lastSep = max(strrpos($prefix, '.'), strrpos($prefix, '_'));
    if ($lastSep !== false) {
        $prefix = substr($prefix, 0, $lastSep + 1);
    }

    return $prefix;
}

/**
 * Extrahiert Namespace aus PHP-Klassenname
 */
function extractNamespace($phpClass) {
    $parts = explode('\\', $phpClass);
    if (count($parts) > 1) {
        return $parts[0] . '\\';
    }
    return '';
}

/**
 * Gibt Liste bekannter WoltLab Basis-Tabellen zurück
 */
function getBasePluginTables($wcfN) {
    return [
        "wcf{$wcfN}_package",
        "wcf{$wcfN}_user",
        "wcf{$wcfN}_user_group",
        "wcf{$wcfN}_user_group_option",
        "wcf{$wcfN}_option",
        "wcf{$wcfN}_option_category",
        "wcf{$wcfN}_language",
        "wcf{$wcfN}_language_item",
        "wcf{$wcfN}_acp_menu_item",
        "wcf{$wcfN}_cronjob",
        "wcf{$wcfN}_object_type",
        "wcf{$wcfN}_page_location",
        "wcf{$wcfN}_url_rule",
        "wcf{$wcfN}_package_installation_queue",
        "wcf{$wcfN}_package_installation_file_log",
        "wcf{$wcfN}_package_installation_plugin",
    ];
}

/**
 * Findet Datei in verschiedenen möglichen Verzeichnissen
 */
function findFileInExtractDir($extractDir, $application, $filename, $possiblePaths = []) {
    // Standard-Pfade wenn keine angegeben
    if (empty($possiblePaths)) {
        $possiblePaths = [
            $filename, // Root direkt
            '', // Root (leer bedeutet filename direkt)
            "files_{$application}/acp/{$filename}",
            "files_{$application}/{$filename}",
        ];
    }

    foreach ($possiblePaths as $path) {
        if (empty($path)) {
            $fullPath = $extractDir . '/' . $filename;
        } else {
            $fullPath = $extractDir . '/' . ltrim($path, '/');
        }
        
        // Prüfe ob es eine Datei ist (nicht ein Verzeichnis)
        if (file_exists($fullPath) && is_file($fullPath)) {
            return $fullPath;
        }
    }

    return null;
}

/**
 * Ermittelt WCF_N Nummer
 */
function detectWcfN($db, $packageIdentifier, $extractDir = null) {
    // Primär: Aus Datenbank
    for ($n = 1; $n <= 10; $n++) {
        try {
            $sql = "SELECT packageID FROM wcf{$n}_package WHERE package = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageIdentifier]);
            if ($statement->fetchArray()) {
                return $n;
            }
        } catch (Exception $e) {
            // Tabelle existiert nicht, weiter mit nächstem N
        }
    }

    // Fallback: Aus Tabellennamen in Install-Dateien
    if ($extractDir) {
        $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
        if ($packageXml) {
            $xml = simplexml_load_file($packageXml);
            if ($xml) {
                $instructions = $xml->xpath('//instructions[@type="install"]/instruction[@type="database"]');
                if (!empty($instructions)) {
                    $dbPath = (string)$instructions[0]['path'];
                    $dbFile = findFileInExtractDir($extractDir, '', $dbPath);
                    if ($dbFile && file_exists($dbFile)) {
                        $content = file_get_contents($dbFile);
                        // Suche nach Pattern: prefix{N}_tablename
                        if (preg_match('/[a-z]+(\d+)_/i', $content, $matches)) {
                            return (int)$matches[1];
                        }
                    }
                }
            }
        }
    }

    // Default: 1
    return 1;
}

/**
 * Parst package.xml und extrahiert Metadaten
 */
function parsePackageXml($packageXmlPath) {
    if (!file_exists($packageXmlPath) || !is_file($packageXmlPath)) {
        return null;
    }

    $xml = simplexml_load_file($packageXmlPath);
    if ($xml === false) {
        return null;
    }

    $result = [
        'package' => (string)$xml['name'],
        'application' => (string)$xml['application'] ?: '',
        'instructions' => []
    ];

    // Finde alle Instructions
    $instructions = $xml->xpath('//instructions[@type="install"]/instruction');
    foreach ($instructions as $instruction) {
        $result['instructions'][] = [
            'type' => (string)$instruction['type'],
            'application' => (string)$instruction['application'] ?: '',
            'path' => (string)$instruction['path'] ?: ''
        ];
    }

    return $result;
}

/**
 * Findet Datenbank-Tabellen aus Install-Datei
 */
function findDatabaseTables($extractDir, $packageIdentifier, $wcfN) {
    $tables = [];
    $baseTables = getBasePluginTables($wcfN);

    $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
    if (!$packageXml) {
        return $tables;
    }

    $xml = simplexml_load_file($packageXml);
    if (!$xml) {
        return $tables;
    }

    // Finde database instruction
    $instructions = $xml->xpath('//instructions[@type="install"]/instruction[@type="database"]');
    if (empty($instructions)) {
        return $tables;
    }

    $dbPath = (string)$instructions[0]['path'];
    $dbFile = findFileInExtractDir($extractDir, '', $dbPath);
    if (!$dbFile || !file_exists($dbFile)) {
        return $tables;
    }

    $content = file_get_contents($dbFile);
    // Suche nach DatabaseTable::create('tabellenname')
    if (preg_match_all("/DatabaseTable::create\(['\"]([^'\"]+)['\"]\)/", $content, $matches)) {
        foreach ($matches[1] as $tableName) {
            // Filtere Basis-Tabellen aus
            if (!in_array($tableName, $baseTables)) {
                $tables[] = $tableName;
            }
        }
    }

    return array_unique($tables);
}

/**
 * Findet Optionen aus option.xml
 */
function findOptions($extractDir, $application) {
    $options = [];
    $possiblePaths = [
        'option.xml',
        "files_{$application}/acp/option/option.xml",
        "files_{$application}/option.xml",
    ];

    $optionFile = findFileInExtractDir($extractDir, $application, 'option.xml', $possiblePaths);
    if (!$optionFile) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($optionFile);
    if (!$xml) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $optionNames = [];
    foreach ($xml->xpath('//option[@name]') as $option) {
        $name = (string)$option['name'];
        $optionNames[] = $name;
        $options[] = $name;
    }

    $prefix = extractCommonPrefix($optionNames, '_');
    return [
        'prefix' => $prefix,
        'count' => count($options),
        'items' => $options
    ];
}

/**
 * Findet User Group Options (Permissions) aus userGroupOption.xml
 */
function findUserGroupOptions($extractDir, $application) {
    $options = [];
    $possiblePaths = [
        'userGroupOption.xml',
        "files_{$application}/acp/userGroupOption/userGroupOption.xml",
        "files_{$application}/userGroupOption.xml",
    ];

    $optionFile = findFileInExtractDir($extractDir, $application, 'userGroupOption.xml', $possiblePaths);
    if (!$optionFile) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($optionFile);
    if (!$xml) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $optionNames = [];
    foreach ($xml->xpath('//option[@name]') as $option) {
        $name = (string)$option['name'];
        $optionNames[] = $name;
        $options[] = $name;
    }

    $prefix = extractCommonPrefix($optionNames, '.');
    return [
        'prefix' => $prefix,
        'count' => count($options),
        'items' => $options
    ];
}

/**
 * Findet Cronjobs aus package.xml oder separaten XML-Dateien
 */
function findCronjobs($extractDir, $packageXmlPath) {
    $cronjobs = [];
    $classes = [];

    // Suche in package.xml
    if (file_exists($packageXmlPath) && is_file($packageXmlPath)) {
        $xml = simplexml_load_file($packageXmlPath);
        if ($xml) {
            foreach ($xml->xpath('//cronjob[@className]') as $cronjob) {
                $className = (string)$cronjob['className'];
                $classes[] = $className;
            }
        }
    }

    // Suche in separaten XML-Dateien
    $cronjobDir = $extractDir . '/acp/cronjob';
    if (is_dir($cronjobDir)) {
        $files = glob($cronjobDir . '/*.xml');
        foreach ($files as $file) {
            if (is_file($file)) {
                $xml = simplexml_load_file($file);
                if ($xml) {
                    foreach ($xml->xpath('//cronjob[@className]') as $cronjob) {
                        $className = (string)$cronjob['className'];
                        $classes[] = $className;
                    }
                }
            }
        }
    }

    $namespace = '';
    if (!empty($classes)) {
        $namespace = extractNamespace($classes[0]);
    }

    return [
        'namespace' => $namespace,
        'count' => count($classes),
        'classes' => $classes
    ];
}

/**
 * Findet ACP-Menü-Einträge aus acpMenu.xml
 */
function findAcpMenuItems($extractDir, $application) {
    $items = [];
    $possiblePaths = [
        'acpMenu.xml',
        "files_{$application}/acp/menu/acpMenu.xml",
        "files_{$application}/acpMenu.xml",
    ];

    $menuFile = findFileInExtractDir($extractDir, $application, 'acpMenu.xml', $possiblePaths);
    if (!$menuFile) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($menuFile);
    if (!$xml) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $menuNames = [];
    foreach ($xml->xpath('//acpmenuitem[@name]') as $menuItem) {
        $name = (string)$menuItem['name'];
        $menuNames[] = $name;
        $items[] = $name;
    }

    $prefix = extractCommonPrefix($menuNames, '.');
    return [
        'prefix' => $prefix,
        'count' => count($items),
        'items' => $items
    ];
}

/**
 * Findet Sprachvariablen aus language/*.xml
 */
function findLanguageItems($extractDir, $application) {
    $items = [];
    $possiblePaths = [
        'language',
        "files_{$application}/language",
    ];

    $languageDir = null;
    foreach ($possiblePaths as $path) {
        $fullPath = $extractDir . '/' . ltrim($path, '/');
        if (is_dir($fullPath)) {
            $languageDir = $fullPath;
            break;
        }
    }

    if (!$languageDir) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $xmlFiles = glob($languageDir . '/*.xml');
    $itemNames = [];
    foreach ($xmlFiles as $xmlFile) {
        if (is_file($xmlFile)) {
            $xml = simplexml_load_file($xmlFile);
            if ($xml) {
                foreach ($xml->xpath('//item[@name]') as $item) {
                    $name = (string)$item['name'];
                    if (!in_array($name, $itemNames)) {
                        $itemNames[] = $name;
                        $items[] = $name;
                    }
                }
            }
        }
    }

    $prefix = extractCommonPrefix($itemNames, '.');
    return [
        'prefix' => $prefix,
        'count' => count($items),
        'items' => $items
    ];
}

/**
 * Findet Objekttypen aus objectType.xml
 */
function findObjectTypes($extractDir, $application) {
    $types = [];
    $possiblePaths = [
        'objectType.xml',
        "files_{$application}/acp/objectType/objectType.xml",
        "files_{$application}/objectType.xml",
    ];

    $typeFile = findFileInExtractDir($extractDir, $application, 'objectType.xml', $possiblePaths);
    if (!$typeFile) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($typeFile);
    if (!$xml) {
        return ['prefix' => '', 'count' => 0, 'items' => []];
    }

    $typeNames = [];
    foreach ($xml->xpath('//type[@name]') as $type) {
        $name = (string)$type['name'];
        $typeNames[] = $name;
        $types[] = $name;
    }

    $prefix = extractCommonPrefix($typeNames, '.');
    return [
        'prefix' => $prefix,
        'count' => count($types),
        'items' => $types
    ];
}

/**
 * Findet Page Locations aus pageLocation.xml
 */
function findPageLocations($extractDir, $application) {
    $locations = [];
    $possiblePaths = [
        'pageLocation.xml',
        "files_{$application}/acp/page/pageLocation.xml",
        "files_{$application}/pageLocation.xml",
    ];

    $locationFile = findFileInExtractDir($extractDir, $application, 'pageLocation.xml', $possiblePaths);
    if (!$locationFile) {
        return ['count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($locationFile);
    if (!$xml) {
        return ['count' => 0, 'items' => []];
    }

    foreach ($xml->xpath('//pagelocation[@identifier]') as $location) {
        $identifier = (string)$location['identifier'];
        $locations[] = $identifier;
    }

    return [
        'count' => count($locations),
        'items' => $locations
    ];
}

/**
 * Findet URL Rules aus urlRule.xml
 */
function findUrlRules($extractDir, $application) {
    $rules = [];
    $possiblePaths = [
        'urlRule.xml',
        "files_{$application}/acp/page/urlRule.xml",
        "files_{$application}/urlRule.xml",
    ];

    $ruleFile = findFileInExtractDir($extractDir, $application, 'urlRule.xml', $possiblePaths);
    if (!$ruleFile) {
        return ['count' => 0, 'items' => []];
    }

    $xml = simplexml_load_file($ruleFile);
    if (!$xml) {
        return ['count' => 0, 'items' => []];
    }

    foreach ($xml->xpath('//pattern') as $pattern) {
        $patternText = trim((string)$pattern);
        if (!empty($patternText)) {
            $rules[] = $patternText;
        }
    }

    return [
        'count' => count($rules),
        'items' => $rules
    ];
}

/**
 * Hauptfunktion: Analysiert Package und identifiziert alle Ressourcen
 */
function analyzePackageResources($extractDir, $packageIdentifier, $db) {
    $resources = [
        'tables' => [],
        'options' => ['prefix' => '', 'count' => 0, 'items' => []],
        'permissions' => ['prefix' => '', 'count' => 0, 'items' => []],
        'cronjobs' => ['namespace' => '', 'count' => 0, 'classes' => []],
        'acpMenu' => ['prefix' => '', 'count' => 0, 'items' => []],
        'language' => ['prefix' => '', 'count' => 0, 'items' => []],
        'objectTypes' => ['prefix' => '', 'count' => 0, 'items' => []],
        'pageLocations' => ['count' => 0, 'items' => []],
        'urlRules' => ['count' => 0, 'items' => []]
    ];

    if (!is_dir($extractDir)) {
        return $resources;
    }

    // Parse package.xml
    $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
    if (!$packageXml) {
        return $resources;
    }

    $packageData = parsePackageXml($packageXml);
    if (!$packageData) {
        return $resources;
    }

    $application = $packageData['application'] ?: '';
    $wcfN = detectWcfN($db, $packageIdentifier, $extractDir);

    // Finde alle Ressourcen
    $resources['tables'] = findDatabaseTables($extractDir, $packageIdentifier, $wcfN);
    $resources['options'] = findOptions($extractDir, $application);
    $resources['permissions'] = findUserGroupOptions($extractDir, $application);
    $resources['cronjobs'] = findCronjobs($extractDir, $packageXml);
    $resources['acpMenu'] = findAcpMenuItems($extractDir, $application);
    $resources['language'] = findLanguageItems($extractDir, $application);
    $resources['objectTypes'] = findObjectTypes($extractDir, $application);
    $resources['pageLocations'] = findPageLocations($extractDir, $application);
    $resources['urlRules'] = findUrlRules($extractDir, $application);
    $resources['wcfN'] = $wcfN;

    return $resources;
}

/**
 * Generiert SQL-Statements für Cleanup
 */
function generateCleanupSql($resources, $wcfN) {
    $sql = "-- WoltLab Plugin Cleanup SQL\n";
    $sql .= "-- Generated automatically from package analysis\n";
    $sql .= "-- WCF_N: {$wcfN}\n\n";

    // Tabellen
    if (!empty($resources['tables'])) {
        $sql .= "-- Tabellen\n";
        foreach ($resources['tables'] as $table) {
            $sql .= "DROP TABLE IF EXISTS `" . addslashes($table) . "`;\n";
        }
        $sql .= "\n";
    }

    // Optionen
    if (!empty($resources['options']['prefix'])) {
        $sql .= "-- Optionen\n";
        $prefix = addslashes($resources['options']['prefix']);
        $sql .= "DELETE FROM wcf{$wcfN}_option WHERE optionName LIKE '{$prefix}%';\n\n";
    }

    // Permissions
    if (!empty($resources['permissions']['prefix'])) {
        $sql .= "-- Permissions (User Group Options)\n";
        $prefix = addslashes($resources['permissions']['prefix']);
        $sql .= "DELETE FROM wcf{$wcfN}_user_group_option WHERE optionName LIKE '{$prefix}%';\n\n";
    }

    // Cronjobs
    if (!empty($resources['cronjobs']['namespace'])) {
        $sql .= "-- Cronjobs\n";
        $namespace = addslashes($resources['cronjobs']['namespace']);
        $sql .= "DELETE FROM wcf{$wcfN}_cronjob WHERE className LIKE '{$namespace}%';\n\n";
    }

    // ACP-Menü
    if (!empty($resources['acpMenu']['prefix'])) {
        $sql .= "-- ACP-Menü-Einträge\n";
        $prefix = addslashes($resources['acpMenu']['prefix']);
        $sql .= "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE '{$prefix}%';\n\n";
    }

    // Sprachvariablen
    if (!empty($resources['language']['prefix'])) {
        $sql .= "-- Sprachvariablen\n";
        $prefix = addslashes($resources['language']['prefix']);
        $sql .= "DELETE FROM wcf{$wcfN}_language_item WHERE languageItem LIKE '{$prefix}%';\n\n";
    }

    // Objekttypen
    if (!empty($resources['objectTypes']['prefix'])) {
        $sql .= "-- Objekttypen\n";
        $prefix = addslashes($resources['objectTypes']['prefix']);
        $sql .= "DELETE FROM wcf{$wcfN}_object_type WHERE objectType LIKE '{$prefix}%';\n\n";
    }

    // Page Locations
    if (!empty($resources['pageLocations']['items'])) {
        $sql .= "-- Page Locations\n";
        foreach ($resources['pageLocations']['items'] as $identifier) {
            $id = addslashes($identifier);
            $sql .= "DELETE FROM wcf{$wcfN}_page_location WHERE identifier = '{$id}';\n";
        }
        $sql .= "\n";
    }

    // URL Rules
    if (!empty($resources['urlRules']['items'])) {
        $sql .= "-- URL Rules\n";
        foreach ($resources['urlRules']['items'] as $pattern) {
            $pat = addslashes($pattern);
            $sql .= "DELETE FROM wcf{$wcfN}_url_rule WHERE pattern = '{$pat}';\n";
        }
        $sql .= "\n";
    }

    return $sql;
}

/**
 * Zeigt Vorschau der gefundenen Ressourcen
 */
function displayResourcePreview($resources, $wcfN, $packageIdentifier) {
    echo '<div class="alert alert-info" style="margin-top: 20px;">';
    echo '<strong>📦 Gefundene Ressourcen aus Package-Datei:</strong><br>';
    echo '<small>WCF_N: ' . htmlspecialchars($wcfN) . '</small><br><br>';

    $hasResources = false;

    // Tabellen
    if (!empty($resources['tables'])) {
        $hasResources = true;
        echo '<strong>Datenbank-Tabellen (' . count($resources['tables']) . '):</strong><br>';
        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
        foreach ($resources['tables'] as $table) {
            echo '<li><code>' . htmlspecialchars($table) . '</code></li>';
        }
        echo '</ul><br>';
    }

    // Optionen
    if (!empty($resources['options']['prefix'])) {
        $hasResources = true;
        echo '<strong>Optionen (' . $resources['options']['count'] . '):</strong> ';
        echo 'Präfix: <code>' . htmlspecialchars($resources['options']['prefix']) . '%</code><br><br>';
    }

    // Permissions
    if (!empty($resources['permissions']['prefix'])) {
        $hasResources = true;
        echo '<strong>Permissions (' . $resources['permissions']['count'] . '):</strong> ';
        echo 'Präfix: <code>' . htmlspecialchars($resources['permissions']['prefix']) . '%</code><br><br>';
    }

    // Cronjobs
    if (!empty($resources['cronjobs']['namespace'])) {
        $hasResources = true;
        echo '<strong>Cronjobs (' . $resources['cronjobs']['count'] . '):</strong> ';
        echo 'Namespace: <code>' . htmlspecialchars($resources['cronjobs']['namespace']) . '%</code><br><br>';
    }

    // ACP-Menü
    if (!empty($resources['acpMenu']['prefix'])) {
        $hasResources = true;
        echo '<strong>ACP-Menü-Einträge (' . $resources['acpMenu']['count'] . '):</strong> ';
        echo 'Präfix: <code>' . htmlspecialchars($resources['acpMenu']['prefix']) . '%</code><br><br>';
    }

    // Sprachvariablen
    if (!empty($resources['language']['prefix'])) {
        $hasResources = true;
        echo '<strong>Sprachvariablen (' . $resources['language']['count'] . '):</strong> ';
        echo 'Präfix: <code>' . htmlspecialchars($resources['language']['prefix']) . '%</code><br><br>';
    }

    // Objekttypen
    if (!empty($resources['objectTypes']['prefix'])) {
        $hasResources = true;
        echo '<strong>Objekttypen (' . $resources['objectTypes']['count'] . '):</strong> ';
        echo 'Präfix: <code>' . htmlspecialchars($resources['objectTypes']['prefix']) . '%</code><br><br>';
    }

    // Page Locations
    if (!empty($resources['pageLocations']['items'])) {
        $hasResources = true;
        echo '<strong>Page Locations (' . $resources['pageLocations']['count'] . '):</strong><br>';
        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
        foreach ($resources['pageLocations']['items'] as $identifier) {
            echo '<li><code>' . htmlspecialchars($identifier) . '</code></li>';
        }
        echo '</ul><br>';
    }

    // URL Rules
    if (!empty($resources['urlRules']['items'])) {
        $hasResources = true;
        echo '<strong>URL Rules (' . $resources['urlRules']['count'] . '):</strong><br>';
        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
        foreach ($resources['urlRules']['items'] as $pattern) {
            echo '<li><code>' . htmlspecialchars($pattern) . '</code></li>';
        }
        echo '</ul><br>';
    }

    if (!$hasResources) {
        echo '<em>Keine zusätzlichen Ressourcen in Package-Datei gefunden.</em><br>';
    }

    echo '</div>';
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
        <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank">Plugin Recovery Tool</a> &copy; 2025 Sunny C. |
        Inspiriert von <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank">WoltLab</a> |
        <a href="https://github.com/benjarogit/simple-woltlab-plugin-manager" target="_blank">Simple Plugin Manager</a> |
        <a href="https://www.woltlab.com/user/1350052-sunny-c/" target="_blank">WoltLab Profil</a> |
        <a href="https://github.com/benjarogit/photoshopCClinux" target="_blank">Photoshop CC Linux</a>
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

    // Schritt 1: Package-Identifier eingeben oder hochladen
    if (!isset($_POST['package_identifier']) && !isset($_FILES['package_file'])) {
?>
    <form method="POST">
        <div class="form-group">
            <label>Option 1: Package-Identifier manuell eingeben</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.julian-pfeil.urlshort.featuredLinks">
            <small style="color: #666; display: block; margin-top: 5px;">
                Der eindeutige Bezeichner des Plugins, dessen ACP-Menüeinträge repariert werden sollen.
            </small>
        </div>
        <button type="submit">Mit Identifier reparieren</button>
    </form>

    <hr style="margin: 30px 0; border: none; border-top: 1px solid #444444;">

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar oder .tar.gz)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz">
        </div>
        <button type="submit">Mit Datei reparieren</button>
    </form>
<?php
    } else {
        $packageIdentifier = null;

        // Package-Identifier ermitteln (aus POST, GET oder Upload)
        if (isset($_POST['package_identifier']) && !empty($_POST['package_identifier'])) {
            $packageIdentifier = trim($_POST['package_identifier']);
        } elseif (isset($_GET['package_identifier']) && !empty($_GET['package_identifier'])) {
            $packageIdentifier = trim($_GET['package_identifier']);
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
            // Ressourcen-Analyse durchführen wenn Upload vorhanden
            $resources = null;
            $extractDir = null;
            if (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads';
                $extractDir = $uploadDir . '/extracted';
                if (is_dir($extractDir)) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                    if ($resources && !empty($resources['acpMenu']['prefix'])) {
                        // Zeige gefundene ACP-Menü-Einträge aus Analyse
                        displayResourcePreview($resources, $resources['wcfN'], $packageIdentifier);
                    }
                }
            }

            // Package suchen
            $sql = "SELECT packageID, package, packageName
                    FROM wcf" . WCF_N . "_package
                    WHERE package = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageIdentifier]);
            $packageData = $statement->fetchArray();

            if (!$packageData && !isset($_POST['force_cleanup'])) {
            // App-Name extrahieren für Pattern-Suche
            // Versuche sowohl letzten als auch vorletzten Teil (für Erweiterungen)
            $parts = explode('.', $packageIdentifier);
            $appNames = [];
            if (count($parts) >= 2) {
                $appNames[] = $parts[count($parts) - 2]; // Vorletzter Teil (Basis-Plugin)
            }
            $appNames[] = end($parts); // Letzter Teil (Erweiterung oder direktes Plugin)
            $appNames = array_unique($appNames);

            // Prüfe alle möglichen Patterns
            $allMenuItems = [];
            $foundPatterns = [];
            
            foreach ($appNames as $appName) {
                $patterns = [
                    $appName . '.acp.menu.%',
                    strtolower($appName) . '.acp.menu.%',
                ];
                
                foreach ($patterns as $pattern) {
                    $sql = "SELECT menuItem, menuItemController
                            FROM wcf" . WCF_N . "_acp_menu_item
                            WHERE menuItem LIKE ?";
                    $statement = $db->prepareStatement($sql);
                    $statement->execute([$pattern]);
                    
                    while ($row = $statement->fetchArray()) {
                        if (!in_array($row['menuItem'], array_column($allMenuItems, 'menuItem'))) {
                            $allMenuItems[] = $row;
                            if (!in_array($pattern, $foundPatterns)) {
                                $foundPatterns[] = $pattern;
                            }
                        }
                    }
                }
            }

            $menuCount = count($allMenuItems);

            echo '<div class="alert alert-warning">';
            echo '<strong>Warnung:</strong> Plugin nicht in Datenbank gefunden.<br>';
            echo 'Dies kann bedeuten, dass die Installation fehlgeschlagen ist.<br><br>';

            if ($menuCount > 0) {
                echo '<strong>Gefundene ACP-Menüeinträge (' . $menuCount . '):</strong><br>';
                if (count($foundPatterns) > 0) {
                    echo '<small>Patterns: ' . htmlspecialchars(implode(', ', $foundPatterns)) . '</small><br><br>';
                }
                
                // Zeige gefundene Einträge
                echo '<table class="table" style="margin-top: 10px;">';
                echo '<thead><tr><th>Menu Item</th><th>Controller</th></tr></thead>';
                echo '<tbody>';
                foreach ($allMenuItems as $item) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['menuItem']) . '</td>';
                    echo '<td>' . htmlspecialchars($item['menuItemController'] ?: '-') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table><br>';
                
                echo '<form method="POST">';
                echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                echo '<input type="hidden" name="force_cleanup" value="1">';
                echo '<button type="submit" class="btn-danger">Diese ' . $menuCount . ' Menüeinträge löschen</button>';
                echo '</form>';
            } else {
                echo '<strong>Keine ACP-Menüeinträge mit diesen Patterns gefunden.</strong><br>';
                echo 'Es gibt nichts zu bereinigen.';
            }
            echo '</div>';
        } else {
            $packageID = $packageData ? $packageData['packageID'] : null;
            $wcfN = $resources ? $resources['wcfN'] : WCF_N;

            // ACP-Menüeinträge finden (aus Analyse oder Datenbank)
            if ($resources && !empty($resources['acpMenu']['prefix'])) {
                // Verwende Präfix aus Analyse
                $prefix = addslashes($resources['acpMenu']['prefix']);
                $sql = "SELECT menuItem, menuItemController
                        FROM wcf{$wcfN}_acp_menu_item
                        WHERE menuItem LIKE ?";
                $statement = $db->prepareStatement($sql);
                $statement->execute([$prefix . '%']);
            } elseif ($packageID) {
                $sql = "SELECT menuItem, menuItemController
                        FROM wcf{$wcfN}_acp_menu_item
                        WHERE packageID = ?";
                $statement = $db->prepareStatement($sql);
                $statement->execute([$packageID]);
            } else {
                // Fallback: Nach Pattern suchen (versuche mehrere App-Namen)
                $parts = explode('.', $packageIdentifier);
                $appNames = [];
                if (count($parts) >= 2) {
                    $appNames[] = $parts[count($parts) - 2]; // Vorletzter Teil
                }
                $appNames[] = end($parts); // Letzter Teil
                $appNames = array_unique($appNames);
                
                // Sammle alle Menüeinträge für alle Patterns
                $allMenuItems = [];
                foreach ($appNames as $appName) {
                    $patterns = [
                        $appName . '.acp.menu.%',
                        strtolower($appName) . '.acp.menu.%',
                    ];
                    
                    foreach ($patterns as $pattern) {
                        $sql = "SELECT menuItem, menuItemController
                                FROM wcf{$wcfN}_acp_menu_item
                                WHERE menuItem LIKE ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$pattern]);
                        
                        while ($row = $statement->fetchArray()) {
                            if (!in_array($row['menuItem'], array_column($allMenuItems, 'menuItem'))) {
                                $allMenuItems[] = $row;
                            }
                        }
                    }
                }
                
                // Erstelle ein Statement-Objekt für die spätere Verwendung
                // (Wir haben bereits die Daten, aber für Kompatibilität)
                $menuItems = $allMenuItems;
                $statement = null; // Wird nicht mehr benötigt, da wir $menuItems direkt haben
            }

            // Wenn $menuItems noch nicht gesetzt wurde (aus Fallback), hole Daten aus Statement
            if (!isset($menuItems)) {
                $menuItems = [];
                if ($statement) {
                    while ($row = $statement->fetchArray()) {
                        $menuItems[] = $row;
                    }
                }
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
                if ($extractDir) {
                    echo '<input type="hidden" name="extract_dir" value="' . htmlspecialchars($extractDir) . '">';
                }
                if (!$packageID) {
                    echo '<input type="hidden" name="force_cleanup" value="1">';
                }
                echo '<input type="hidden" name="confirm_delete" value="1">';
                echo '<button type="submit" class="btn-danger">Alle löschen</button>';
                echo '</form>';
                echo '</div>';
            } else {
                // Ressourcen erneut analysieren wenn Extract-Dir vorhanden
                $extractDir = isset($_POST['extract_dir']) ? $_POST['extract_dir'] : null;
                if ($extractDir && is_dir($extractDir)) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                }
                $wcfN = $resources ? $resources['wcfN'] : WCF_N;

                // Löschen ausführen
                $db->beginTransaction();

                try {
                    // Verwende Analyse-Ergebnisse wenn vorhanden
                    if ($resources && !empty($resources['acpMenu']['prefix'])) {
                        $prefix = addslashes($resources['acpMenu']['prefix']);
                        $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$prefix . '%']);
                    } elseif ($packageID) {
                        $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                    } else {
                        // Fallback: Nach Pattern suchen (versuche mehrere App-Namen)
                        $parts = explode('.', $packageIdentifier);
                        $appNames = [];
                        if (count($parts) >= 2) {
                            $appNames[] = $parts[count($parts) - 2]; // Vorletzter Teil
                        }
                        $appNames[] = end($parts); // Letzter Teil
                        $appNames = array_unique($appNames);
                        
                        $deletedTotal = 0;
                        foreach ($appNames as $appName) {
                            $patterns = [
                                $appName . '.acp.menu.%',
                                strtolower($appName) . '.acp.menu.%',
                            ];
                            
                            foreach ($patterns as $pattern) {
                                $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?";
                                $statement = $db->prepareStatement($sql);
                                $statement->execute([$pattern]);
                                $deletedTotal += $statement->getAffectedRows();
                            }
                        }
                        $deletedCount = $deletedTotal;
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
        } else {
            echo '<div class="alert alert-error">';
            echo '<strong>Fehler:</strong> Kein Package-Identifier konnte ermittelt werden. Bitte versuchen Sie es erneut.';
            echo '</div>';
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
    // Prüfe auch GET-Parameter für "SQL anzeigen" Funktionalität
    $hasPackageIdentifier = (isset($_POST['package_identifier']) && !empty($_POST['package_identifier'])) ||
                            (isset($_GET['package_identifier']) && !empty($_GET['package_identifier'])) ||
                            (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK);
    
    if (!$hasPackageIdentifier) {
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

        // Package-Identifier ermitteln (aus POST, GET oder Upload)
        if (isset($_POST['package_identifier']) && !empty($_POST['package_identifier'])) {
            $packageIdentifier = trim($_POST['package_identifier']);
        } elseif (isset($_GET['package_identifier']) && !empty($_GET['package_identifier'])) {
            $packageIdentifier = trim($_GET['package_identifier']);
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

            // Ressourcen-Analyse durchführen wenn Upload vorhanden
            $resources = null;
            $extractDir = null;
            
            // Prüfe ob Upload gerade erfolgt oder Extract-Dir bereits bekannt
            if (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads';
                $extractDir = $uploadDir . '/extracted';
            } elseif (isset($_GET['extract_dir']) || isset($_POST['extract_dir'])) {
                $extractDir = isset($_GET['extract_dir']) ? $_GET['extract_dir'] : $_POST['extract_dir'];
            }
            
            if ($extractDir && is_dir($extractDir)) {
                $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                if ($resources) {
                    displayResourcePreview($resources, $resources['wcfN'], $packageIdentifier);
                    
                    // SQL anzeigen Button
                    if (isset($_GET['show_sql'])) {
                        echo '<div class="alert alert-info" style="margin-top: 20px;">';
                        echo '<strong>SQL Cleanup Script:</strong><br>';
                        echo '<pre style="max-height: 400px; overflow-y: auto;">' . htmlspecialchars(generateCleanupSql($resources, $resources['wcfN'])) . '</pre>';
                        echo '</div>';
                    } else {
                        echo '<div style="margin-top: 10px;">';
                        $extractDirParam = $extractDir ? '&extract_dir=' . urlencode($extractDir) : '';
                        echo '<a href="?mode=' . RECOVERY_MODE_PLUGIN_UNINSTALL . '&t=' . $authHash . '&show_sql=1&package_identifier=' . urlencode($packageIdentifier) . $extractDirParam . '" class="button">SQL anzeigen</a>';
                        echo '</div>';
                    }
                }
            }

            // Tabellen finden (Fallback auf alte Methode wenn keine Analyse vorhanden)
            if ($resources && !empty($resources['tables'])) {
                $tables = $resources['tables'];
            } else {
                $wcfN = $resources ? $resources['wcfN'] : detectWcfN($db, $packageIdentifier);
                $tables = findPackageTables($db, $packageIdentifier, $wcfN);
            }

            if (!isset($_POST['confirm_uninstall'])) {
                echo '<div class="alert alert-warning">';
                echo '<strong>Zu löschende Daten:</strong><br><br>';

                if ($packageData) {
                    echo '✓ Package-Eintrag in wcf' . WCF_N . '_package<br>';
                    echo '✓ ACP-Menüeinträge<br>';
                    echo '✓ Package-Installationsqueue<br>';
                    echo '✓ Package Installation SQL Log<br>';
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
                echo '<input type="hidden" name="package_identifier" value="' . htmlspecialchars($packageIdentifier) . '">';
                if ($extractDir) {
                    echo '<input type="hidden" name="extract_dir" value="' . htmlspecialchars($extractDir) . '">';
                }
                echo '<input type="hidden" name="confirm_uninstall" value="1">';
                echo '<button type="submit" class="btn-danger">JETZT DEINSTALLIEREN</button>';
                echo '</form>';
                echo '</div>';

            } else {
                // Ressourcen erneut analysieren wenn Extract-Dir vorhanden
                $extractDir = isset($_POST['extract_dir']) ? $_POST['extract_dir'] : null;
                $resources = null;
                if ($extractDir && is_dir($extractDir)) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                }

                // Deinstallation durchführen
                $db->beginTransaction();

                try {
                    $log = [];
                    $wcfN = $resources ? $resources['wcfN'] : WCF_N;

                    if ($packageData) {
                        $packageID = $packageData['packageID'];

                        // ACP-Menüeinträge löschen (aus Analyse oder über packageID)
                        if ($resources && !empty($resources['acpMenu']['prefix'])) {
                            $prefix = addslashes($resources['acpMenu']['prefix']);
                            $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE '{$prefix}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'ACP-Menüeinträge gelöscht: ' . $statement->getAffectedRows();
                        } else {
                            $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE packageID = ?";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute([$packageID]);
                            $log[] = 'ACP-Menüeinträge gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Package-Eintrag löschen
                        $sql = "DELETE FROM wcf{$wcfN}_package WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                        $log[] = 'Package-Eintrag gelöscht';

                        // Installationsqueue bereinigen
                        $sql = "DELETE FROM wcf{$wcfN}_package_installation_queue WHERE package = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageIdentifier]);
                        $log[] = 'Installationsqueue bereinigt: ' . $statement->getAffectedRows();
                        
                        // Package Installation SQL Log bereinigen
                        $sql = "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE packageID = ?";
                        $statement = $db->prepareStatement($sql);
                        $statement->execute([$packageID]);
                        $log[] = 'Package Installation SQL Log bereinigt: ' . $statement->getAffectedRows();
                    } else {
                        // Auch ohne Package-Eintrag: SQL Log nach Tabellennamen bereinigen
                        if (!empty($tables)) {
                            foreach ($tables as $table) {
                                $sql = "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE sqlTable = ?";
                                $statement = $db->prepareStatement($sql);
                                $statement->execute([$table]);
                            }
                            $log[] = 'Package Installation SQL Log bereinigt (nach Tabellennamen)';
                        }
                    }

                    // Tabellen löschen
                    foreach ($tables as $table) {
                        try {
                            $sql = "DROP TABLE IF EXISTS `" . addslashes($table) . "`";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Tabelle gelöscht: ' . $table;
                        } catch (Exception $e) {
                            $log[] = 'Fehler beim Löschen der Tabelle ' . $table . ': ' . $e->getMessage();
                            throw $e; // Wirf Exception weiter, damit Transaction rollback wird
                        }
                    }

                    // Weitere Ressourcen löschen wenn Analyse vorhanden
                    if ($resources) {
                        // Optionen
                        if (!empty($resources['options']['prefix'])) {
                            $prefix = addslashes($resources['options']['prefix']);
                            $sql = "DELETE FROM wcf{$wcfN}_option WHERE optionName LIKE '{$prefix}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Optionen gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Permissions
                        if (!empty($resources['permissions']['prefix'])) {
                            $prefix = addslashes($resources['permissions']['prefix']);
                            $sql = "DELETE FROM wcf{$wcfN}_user_group_option WHERE optionName LIKE '{$prefix}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Permissions gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Cronjobs
                        if (!empty($resources['cronjobs']['namespace'])) {
                            $namespace = addslashes($resources['cronjobs']['namespace']);
                            $sql = "DELETE FROM wcf{$wcfN}_cronjob WHERE className LIKE '{$namespace}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Cronjobs gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Sprachvariablen
                        if (!empty($resources['language']['prefix'])) {
                            $prefix = addslashes($resources['language']['prefix']);
                            $sql = "DELETE FROM wcf{$wcfN}_language_item WHERE languageItem LIKE '{$prefix}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Sprachvariablen gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Objekttypen
                        if (!empty($resources['objectTypes']['prefix'])) {
                            $prefix = addslashes($resources['objectTypes']['prefix']);
                            $sql = "DELETE FROM wcf{$wcfN}_object_type WHERE objectType LIKE '{$prefix}%'";
                            $statement = $db->prepareStatement($sql);
                            $statement->execute();
                            $log[] = 'Objekttypen gelöscht: ' . $statement->getAffectedRows();
                        }

                        // Page Locations
                        if (!empty($resources['pageLocations']['items'])) {
                            foreach ($resources['pageLocations']['items'] as $identifier) {
                                $id = addslashes($identifier);
                                $sql = "DELETE FROM wcf{$wcfN}_page_location WHERE identifier = '{$id}'";
                                $statement = $db->prepareStatement($sql);
                                $statement->execute();
                            }
                            $log[] = 'Page Locations gelöscht: ' . count($resources['pageLocations']['items']);
                        }

                        // URL Rules
                        if (!empty($resources['urlRules']['items'])) {
                            foreach ($resources['urlRules']['items'] as $pattern) {
                                $pat = addslashes($pattern);
                                $sql = "DELETE FROM wcf{$wcfN}_url_rule WHERE pattern = '{$pat}'";
                                $statement = $db->prepareStatement($sql);
                                $statement->execute();
                            }
                            $log[] = 'URL Rules gelöscht: ' . count($resources['urlRules']['items']);
                        }
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
                    echo '<strong>Fehler bei Deinstallation:</strong><br>';
                    echo htmlspecialchars($e->getMessage()) . '<br><br>';
                    if (method_exists($e, 'getTraceAsString')) {
                        echo '<details><summary>Technische Details (für Debugging)</summary>';
                        echo '<pre style="font-size: 11px; max-height: 200px; overflow-y: auto;">';
                        echo htmlspecialchars($e->getTraceAsString());
                        echo '</pre></details>';
                    }
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
        <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank">Plugin Recovery Tool</a> &copy; 2025 Sunny C. |
        Inspiriert von <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank">WoltLab</a> |
        <a href="https://github.com/benjarogit/simple-woltlab-plugin-manager" target="_blank">Simple Plugin Manager</a> |
        <a href="https://www.woltlab.com/user/1350052-sunny-c/" target="_blank">WoltLab Profil</a> |
        <a href="https://github.com/benjarogit/photoshopCClinux" target="_blank">Photoshop CC Linux</a>
    </footer>
</body>
</html>
