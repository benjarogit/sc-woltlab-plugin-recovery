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
 * @version 1.2.2
 *
 * Eine Datei: ins WoltLab-Hauptverzeichnis legen (neben global.php).
 * Kein global.php – funktioniert auch wenn das ACP durch ein Plugin kaputt ist.
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
// RECOVERY CORE (Minimal-Bootstrap + generische Plugin-Bereinigung)
// ============================================================================

function recoveryResolveWcfDir(): string
{
    foreach ([__DIR__, \dirname(__DIR__), \dirname(__DIR__, 2)] as $dir) {
        if (\is_file($dir . '/global.php') && \is_file($dir . '/config.inc.php')) {
            return \rtrim($dir, '/') . '/';
        }
    }

    throw new \RuntimeException(
        'WoltLab nicht gefunden. Legen Sie nur plugin-recovery-tool.php ins Hauptverzeichnis (neben global.php).'
    );
}

/**
 * @return \wcf\system\database\Database
 */
function recoveryBootstrapDatabase()
{
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    require_once WCF_DIR . 'lib/system/api/autoload.php';
    require_once WCF_DIR . 'lib/system/WCF.class.php';

    // Nur WoltLab-Autoloader – core.functions.php setzt Exception-Handler für WCF,
    // bevor die Klasse in älteren Ladereihenfolgen verfügbar war.
    if (!\defined('RECOVERY_WCF_AUTOLOAD')) {
        \define('RECOVERY_WCF_AUTOLOAD', true);
        \spl_autoload_register([\wcf\system\WCF::class, 'autoload'], true, true);
    }

    $dbHost = $dbUser = $dbPassword = $dbName = '';
    $dbPort = 0;
    $defaultDriverOptions = [];
    require WCF_DIR . 'config.inc.php';

    $db = new \wcf\system\database\MySQLDatabase(
        $dbHost,
        $dbUser,
        $dbPassword,
        $dbName,
        $dbPort,
        false,
        false,
        $defaultDriverOptions
    );

    if (!\defined('WCF_N')) {
        \define('WCF_N', recoveryDetectWcfN($db));
    }

    if (!\defined('PACKAGE_ID')) {
        \define('PACKAGE_ID', 1);
    }

    recoveryInjectDatabaseIntoWcf($db);

    return $db;
}

function recoveryDetectWcfN(\wcf\system\database\Database $db): int
{
    for ($n = 1; $n <= 10; $n++) {
        try {
            $sql = "SELECT packageID FROM wcf{$n}_package WHERE package = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute(['com.woltlab.wcf']);
            if ($statement->fetchArray()) {
                return $n;
            }
        } catch (\Throwable) {
        }
    }

    return 1;
}

function recoveryInjectDatabaseIntoWcf(\wcf\system\database\Database $db): void
{
    $reflection = new \ReflectionClass(\wcf\system\WCF::class);
    $property = $reflection->getProperty('dbObj');
    $property->setAccessible(true);
    $property->setValue(null, $db);
}

function recoveryGetSiteBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/plugin-recovery-tool.php';
    $base = \rtrim(\str_replace('\\', '/', \dirname($script)), '/');

    return $scheme . '://' . $host . ($base === '' || $base === '.' ? '' : $base) . '/';
}

/**
 * @return list<string>
 */
function recoveryCollectOptionConstantNames(\wcf\system\database\Database $db, int $wcfN, ?int $packageID): array
{
    if (!$packageID) {
        return [];
    }

    $constants = [];
    $sql = "SELECT optionName FROM wcf{$wcfN}_option WHERE packageID = ?";
    $statement = $db->prepareStatement($sql);
    $statement->execute([$packageID]);
    while ($row = $statement->fetchArray()) {
        $constants[] = \strtoupper((string) $row['optionName']);
    }

    return $constants;
}

function recoveryRebuildOptionsIncPhp(): bool
{
    try {
        require_once WCF_DIR . 'lib/data/option/OptionEditor.class.php';
        \wcf\data\option\OptionEditor::rebuild();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * @param list<string> $constantNames
 */
function recoveryStripConstantsFromOptionsIncPhp(array $constantNames): void
{
    if (empty($constantNames)) {
        return;
    }

    $file = WCF_DIR . 'options.inc.php';
    if (!\is_file($file) || !\is_writable($file)) {
        return;
    }

    $lines = \file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    $filtered = [];
    foreach ($lines as $line) {
        $remove = false;
        foreach ($constantNames as $constant) {
            if ($constant !== '' && \str_contains($line, $constant)) {
                $remove = true;
                break;
            }
        }
        if (!$remove) {
            $filtered[] = $line;
        }
    }

    \file_put_contents($file, \implode("\n", $filtered) . "\n");
}

function recoveryExecuteDelete(
    \wcf\system\database\Database $db,
    string $sql,
    array $parameters,
    string $logLabel,
    array &$log
): void {
    $statement = $db->prepareStatement($sql);
    $statement->execute($parameters);
    $log[] = $logLabel . ': ' . $statement->getAffectedRows();
}

function recoveryTryDeleteByPackageId(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $tableName,
    int $packageID,
    string $logLabel,
    array &$log
): void {
    try {
        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_{$tableName} WHERE packageID = ?",
            [$packageID],
            $logLabel,
            $log
        );
    } catch (\Throwable $e) {
        $log[] = $logLabel . ' übersprungen: ' . $e->getMessage();
    }
}

/**
 * Generische Vollbereinigung für jedes Plugin (ohne WoltLab-Paket-Deinstaller).
 *
 * @param array<string, mixed>|null $resources
 * @param array<string, mixed> $log
 */
function recoveryPerformFullPluginCleanup(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $packageIdentifier,
    ?array $packageData,
    ?array $resources,
    array &$log
): void {
    $packageID = $packageData ? (int) $packageData['packageID'] : null;

    $optionConstants = recoveryCollectOptionConstantNames($db, $wcfN, $packageID);
    if ($resources && !empty($resources['options']['items'])) {
        foreach ($resources['options']['items'] as $name) {
            $optionConstants[] = \strtoupper((string) $name);
        }
    }
    $optionConstants = \array_values(\array_unique($optionConstants));

    if ($packageID) {
        $packageIdTables = [
            'template_listener' => 'Template-Listener',
            'event_listener' => 'Event-Listener',
            'option' => 'Optionen',
            'acp_menu_item' => 'ACP-Menü',
            'user_group_option' => 'Berechtigungen',
            'cronjob' => 'Cronjobs',
            'object_type' => 'Objekttypen',
            'page' => 'Seiten',
            'language_item' => 'Sprachvariablen',
            'box' => 'Boxen',
            'template' => 'Templates',
            'core_object' => 'Core-Objekte',
            'user_notification_event' => 'Benachrichtigungen',
            'bbcode' => 'BBCodes',
            'smiley' => 'Smileys',
        ];

        foreach ($packageIdTables as $table => $label) {
            recoveryTryDeleteByPackageId($db, $wcfN, $table, $packageID, $label, $log);
        }

        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE packageID = ?",
            [$packageID],
            'Package SQL-Log',
            $log
        );

        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package WHERE packageID = ?",
            [$packageID],
            'Package-Eintrag',
            $log
        );
    } else {
        if ($resources && !empty($resources['acpMenu']['prefix'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?",
                [$resources['acpMenu']['prefix'] . '%'],
                'ACP-Menü (Analyse)',
                $log
            );
        }

        if ($resources && !empty($resources['options']['prefix'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_option WHERE optionName LIKE ?",
                [$resources['options']['prefix'] . '%'],
                'Optionen (Analyse)',
                $log
            );
        }

        if ($resources && !empty($resources['permissions']['prefix'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_user_group_option WHERE optionName LIKE ?",
                [$resources['permissions']['prefix'] . '%'],
                'Berechtigungen (Analyse)',
                $log
            );
        }

        if ($resources && !empty($resources['language']['prefix'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_language_item WHERE languageItem LIKE ?",
                [$resources['language']['prefix'] . '%'],
                'Sprachvariablen (Analyse)',
                $log
            );
        }

        if ($resources && !empty($resources['cronjobs']['namespace'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_cronjob WHERE className LIKE ?",
                [$resources['cronjobs']['namespace'] . '%'],
                'Cronjobs (Analyse)',
                $log
            );
        }

        if ($resources && !empty($resources['objectTypes']['prefix'])) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_object_type WHERE objectType LIKE ?",
                [$resources['objectTypes']['prefix'] . '%'],
                'Objekttypen (Analyse)',
                $log
            );
        }

        $parts = \explode('.', $packageIdentifier);
        $appGuess = \count($parts) >= 2 ? $parts[\count($parts) - 2] : \end($parts);
        if ($appGuess) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_template_listener WHERE listenerName LIKE ?",
                [$appGuess . '%'],
                'Template-Listener (Vermutung)',
                $log
            );
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_page WHERE identifier LIKE ?",
                [$packageIdentifier . '%'],
                'Seiten (Package-Identifier)',
                $log
            );
        }
    }

    if ($resources && !empty($resources['pageLocations']['items'])) {
        foreach ($resources['pageLocations']['items'] as $identifier) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_page_location WHERE identifier = ?",
                [$identifier],
                'Page Location',
                $log
            );
        }
    }

    if ($resources && !empty($resources['urlRules']['items'])) {
        foreach ($resources['urlRules']['items'] as $pattern) {
            recoveryExecuteDelete(
                $db,
                "DELETE FROM wcf{$wcfN}_url_rule WHERE pattern = ?",
                [$pattern],
                'URL-Regel',
                $log
            );
        }
    }

    recoveryExecuteDelete(
        $db,
        "DELETE FROM wcf{$wcfN}_package_installation_queue WHERE package = ?",
        [$packageIdentifier],
        'Installationsqueue',
        $log
    );

    $tables = [];
    if ($resources && !empty($resources['tables'])) {
        $tables = $resources['tables'];
    } elseif ($packageData && !empty($packageData['applicationDirectory'])) {
        $tables = \function_exists('findPackageTables')
            ? findPackageTables($db, $packageIdentifier, $wcfN)
            : [];
    } else {
        $tables = \function_exists('findPackageTables')
            ? findPackageTables($db, $packageIdentifier, $wcfN)
            : [];
    }

    foreach ($tables as $table) {
        $safeTable = \str_replace('`', '', (string) $table);
        $sql = 'DROP TABLE IF EXISTS `' . $safeTable . '`';
        $statement = $db->prepareStatement($sql);
        $statement->execute();
        $log[] = 'Tabelle gelöscht: ' . $safeTable;
    }

    if (recoveryRebuildOptionsIncPhp()) {
        $log[] = 'options.inc.php neu erzeugt';
    } elseif (!empty($optionConstants)) {
        recoveryStripConstantsFromOptionsIncPhp($optionConstants);
        $log[] = 'options.inc.php bereinigt (Plugin-Konstanten entfernt)';
    }
}


// ============================================================================
// UI (WoltLab WCFSetup.css – wie Rescue Mode / offizielles Recovery Tool)
// ============================================================================

/**
 * @return array{WCFSetup.css: string, woltlabSuite.png: string}
 */
function recoveryGetSetupAssets(): array
{
    if (!\defined('WCF_DIR')) {
        try {
            \define('WCF_DIR', recoveryResolveWcfDir());
        } catch (\Throwable) {
            return ['WCFSetup.css' => '', 'woltlabSuite.png' => ''];
        }
    }

    $assets = ['WCFSetup.css' => '', 'woltlabSuite.png' => ''];
    $cssPath = WCF_DIR . 'acp/style/setup/WCFSetup.css';
    $imgPath = WCF_DIR . 'acp/images/woltlabSuite.png';

    if (\is_readable($cssPath)) {
        $assets['WCFSetup.css'] = \sprintf(
            'data:text/css;base64,%s',
            \base64_encode((string) \file_get_contents($cssPath))
        );
    }
    if (\is_readable($imgPath)) {
        $assets['woltlabSuite.png'] = \sprintf(
            'data:image/png;base64,%s',
            \base64_encode((string) \file_get_contents($imgPath))
        );
    }

    return $assets;
}

function recoveryRenderStandaloneMessage(string $documentTitle, string $contentTitle, string $bodyHtml): void
{
    $assets = recoveryGetSetupAssets();
    recoveryRenderPageStart($documentTitle, $contentTitle, $assets);
    echo $bodyHtml;
    recoveryRenderPageEnd($assets);
}

function recoveryRenderPageStart(string $documentTitle, string $contentTitle, ?array $assets = null): void
{
    $assets ??= recoveryGetSetupAssets();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($documentTitle) ?> - WoltLab Suite</title>
    <?php if ($assets['WCFSetup.css'] !== ''): ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($assets['WCFSetup.css']) ?>">
    <?php endif; ?>
    <style>
        /* ── Recovery-spezifische Ergänzungen zu WCFSetup.css ── */
        .recoveryModeGrid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin: 20px 0 28px;
        }
        .recoveryModeCard {
            display: block;
            padding: 22px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        .recoveryModeCard:hover { border-color: #369; box-shadow: 0 2px 8px rgba(51,102,153,.12); }
        .recoveryModeCard strong { display: block; font-size: 15px; font-weight: 600; margin-bottom: 6px; }
        .recoveryModeCard span   { font-size: 13px; opacity: .75; }
        .recoveryBackLink { display: inline-flex; align-items: center; gap: 5px; text-decoration: none; font-size: 13px; opacity: .7; margin-bottom: 20px; }
        .recoveryBackLink:hover { opacity: 1; }
        pre.recoveryLog { overflow-x: auto; max-height: 340px; }

        /* ── Dark Mode (automatisch per prefers-color-scheme) ─── */
        @media (prefers-color-scheme: dark) {
            html, body { background: #1a1a2e; color: #cdd6f4; }

            .pageHeaderContainer,
            .pageHeaderFacade { background: #0f0f1a !important; border-bottom-color: #2e2e45 !important; }

            .pageFooter,
            #pageFooter { background: #0f0f1a; border-top-color: #2e2e45; }
            .copyright, .copyright a { color: #6c7086; }
            .copyright a:hover { color: #89b4fa; }

            #acpPageContentContainer,
            .acpPageContentContainer { background: #1a1a2e; }

            .contentHeader { border-bottom-color: #2e2e45; }
            .contentTitle { color: #e6edf3; }

            .section { background: #1e1e2e; border-color: #2e2e45; }
            .sectionTitle { color: #cdd6f4; }
            .sectionDescription { color: #6c7086; }

            p.info    { background: rgba(137,220,235,.08); border-left: 3px solid #89dceb; color: #cdd6f4; }
            p.success { background: rgba(166,227,161,.10); border-left: 3px solid #a6e3a1; color: #cdd6f4; }
            p.error   { background: rgba(243,139,168,.10); border-left: 3px solid #f38ba8; color: #cdd6f4; }
            p.warning { background: rgba(250,179,135,.10); border-left: 3px solid #fab387; color: #cdd6f4; }

            input[type="text"], input[type="password"], input[type="file"],
            input[type="url"], input[type="date"], textarea, select {
                background: #13131f;
                border-color: #2e2e45;
                color: #cdd6f4;
            }
            input[type="text"]:focus, input[type="password"]:focus {
                border-color: #89b4fa;
                box-shadow: 0 0 0 3px rgba(137,180,250,.15);
                outline: none;
            }
            label { color: #cdd6f4; }
            small { color: #6c7086; }

            .formSubmit input[type="submit"],
            .formSubmit a,
            button[type="submit"], button {
                background: #89b4fa;
                color: #1e1e2e;
                border-color: transparent;
            }
            .formSubmit input[type="submit"]:hover,
            .formSubmit a:hover,
            button:hover { opacity: .85; }

            table th, table td { border-bottom-color: #2e2e45; color: #cdd6f4; }
            table th { color: #6c7086; border-top-color: #2e2e45; }
            table tbody tr:hover { background: rgba(255,255,255,.03); }

            code { background: rgba(255,255,255,.07); color: #89b4fa; }
            pre, pre.recoveryLog { background: #13131f; border-color: #2e2e45; color: #cdd6f4; }
            hr { border-top-color: #2e2e45; }

            .recoveryModeCard { border-color: #2e2e45; color: #cdd6f4; background: #1e1e2e; }
            .recoveryModeCard:hover { border-color: #89b4fa; box-shadow: 0 2px 10px rgba(137,180,250,.1); }
            .recoveryModeCard strong { color: #e6edf3; }
        }
    </style>
</head>
<body id="tplPluginRecovery" data-template="pluginRecovery" data-application="wcf" class="wcfAcp">
<a id="top"></a>
<div id="pageContainer" class="pageContainer acpPageHiddenMenu">
    <div class="pageHeaderContainer">
        <header id="pageHeaderFacade" class="pageHeaderFacade">
            <div class="layoutBoundary">
                <div id="pageHeaderLogo" class="pageHeaderLogo">
                    <?php if ($assets['woltlabSuite.png'] !== ''): ?>
                    <img src="<?= \htmlspecialchars($assets['woltlabSuite.png']) ?>" alt="" class="pageHeaderLogoLarge" style="width: 281px;height: 40px;display: inline !important;">
                    <?php else: ?>
                    <strong>WoltLab Suite</strong>
                    <?php endif; ?>
                </div>
            </div>
        </header>
    </div>
    <div id="acpPageContentContainer" class="acpPageContentContainer">
        <section id="main" class="main" role="main">
            <div class="layoutBoundary">
                <div id="content" class="content">
                    <header class="contentHeader">
                        <h1 class="contentTitle"><?= \htmlspecialchars($contentTitle) ?></h1>
                    </header>
<?php
}

function recoveryRenderPageEnd(?array $assets = null): void
{
    $assets ??= recoveryGetSetupAssets();
    $baseUrl = '';
    try {
        $baseUrl = recoveryGetSiteBaseUrl();
    } catch (\Throwable) {
    }
    ?>
                </div>
            </div>
        </section>
    </div>
</div>
<footer id="pageFooter" class="pageFooter">
    <div id="pageFooterCopyright" class="pageFooterCopyright">
        <div class="layoutBoundary">
            <div class="copyright">
                <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank" rel="noopener">Plugin Recovery Tool</a>
                &copy; <?= \date('Y') ?> Sunny C.
                <?php if ($baseUrl !== ''): ?>
                · <a href="<?= \htmlspecialchars($baseUrl) ?>">Installation</a>
                <?php endif; ?>
                · <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">WoltLab Recovery</a>
            </div>
        </div>
    </div>
</footer>
</body>
</html>
<?php
}

function recoveryRenderBackLink(string $href): void
{
    echo '<a href="' . \htmlspecialchars($href) . '" class="recoveryBackLink">&#8592; Zurück zur Auswahl</a>';
}

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
    recoveryRenderStandaloneMessage(
        'Recovery Tool entfernt',
        'Recovery Tool entfernt',
        '<p class="success"><strong>Recovery Tool erfolgreich entfernt.</strong></p>'
        . '<p>Folgende Dateien wurden gelöscht:</p>'
        . '<ul><li>plugin-recovery-tool.php</li><li>plugin-recovery-auth.php</li><li>uploads/</li></ul>'
        . '<p class="info"><strong>Diese Seite ist nicht mehr erreichbar.</strong></p>'
    );
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
    echo '<section class="section"><p class="info"><strong>Gefundene Ressourcen aus Package-Datei:</strong><br>';
    echo '<small>WCF_N: ' . htmlspecialchars($wcfN) . '</small><br><br>';

    $hasResources = false;

    // Tabellen
    if (!empty($resources['tables'])) {
        $hasResources = true;
        echo '<strong>Datenbank-Tabellen (' . count($resources['tables']) . '):</strong><br>';
        echo '<ul>';
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
        echo '<ul>';
        foreach ($resources['pageLocations']['items'] as $identifier) {
            echo '<li><code>' . htmlspecialchars($identifier) . '</code></li>';
        }
        echo '</ul><br>';
    }

    // URL Rules
    if (!empty($resources['urlRules']['items'])) {
        $hasResources = true;
        echo '<strong>URL Rules (' . $resources['urlRules']['count'] . '):</strong><br>';
        echo '<ul>';
        foreach ($resources['urlRules']['items'] as $pattern) {
            echo '<li><code>' . htmlspecialchars($pattern) . '</code></li>';
        }
        echo '</ul><br>';
    }

    if (!$hasResources) {
        echo '<em>Keine zusätzlichen Ressourcen in Package-Datei gefunden.</em><br>';
    }

    echo '</p></section>';
}

recoveryRenderPageStart('Plugin Recovery Tool', 'Plugin Recovery Tool');

// Auth-Screen anzeigen wenn nicht authentifiziert
if (!$isAuthenticated) {
?>
    <p class="info">Authentifizierung erforderlich. Folgen Sie den drei Schritten.</p>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Schritt 1: Auth-Datei herunterladen</h2>
        </header>
        <p class="info">
            <a href="?action=download-auth-file&amp;t=<?= htmlspecialchars($authHash) ?>" id="downloadBtn">plugin-recovery-auth.php herunterladen</a>
        </p>
    </section>

    <section class="section" id="step2" hidden>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Schritt 2: Datei hochladen</h2>
            <p class="sectionDescription">Laden Sie <code><?= htmlspecialchars($authFilename) ?></code> in dasselbe Verzeichnis wie <code>plugin-recovery-tool.php</code> hoch.</p>
        </header>
    </section>

    <section class="section" id="step3" hidden>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Schritt 3: Recovery starten</h2>
        </header>
        <div class="formSubmit">
            <a href="?t=<?= htmlspecialchars($authHash) ?>">Recovery Tool starten</a>
        </div>
    </section>

    <p class="error"><strong>Sicherheitshinweis:</strong> Löschen Sie <code>plugin-recovery-tool.php</code> und <code><?= htmlspecialchars($authFilename) ?></code> nach der Verwendung.</p>

    <script>
    document.getElementById('downloadBtn').addEventListener('click', function() {
        setTimeout(function() {
            document.getElementById('step2').hidden = false;
            document.getElementById('step3').hidden = false;
        }, 500);
    });
    </script>
<?php
    recoveryRenderPageEnd();
    exit;
}

// Ab hier ist der User authentifiziert

// ============================================================================
// WOLTLAB BOOTSTRAP (minimal – kein global.php, kaputte Apps crashen nicht)
// ============================================================================

try {
    $recoveryDb = recoveryBootstrapDatabase();
} catch (\Throwable $e) {
    echo '<p class="error"><strong>Bootstrap-Fehler:</strong> '
        . htmlspecialchars($e->getMessage()) . '</p>';
    recoveryRenderPageEnd();
    exit;
}

use wcf\system\WCF;

$recoveryBaseUrl = recoveryGetSiteBaseUrl();
$db = WCF::getDB();

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
    <p class="info">Wählen Sie den gewünschten Recovery-Modus. Dieses Tool arbeitet direkt auf Datenbank-Ebene und sollte nur im Notfall verwendet werden.</p>

    <div class="recoveryModeGrid">
        <a href="?mode=<?= RECOVERY_MODE_ACP_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="recoveryModeCard">
            <strong>ACP Repair</strong>
            <span>Repariert defekte ACP-Menüeinträge eines Plugins</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PLUGIN_UNINSTALL ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="recoveryModeCard">
            <strong>Plugin Uninstall</strong>
            <span>Deinstalliert Plugin komplett (DB + Dateien)</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_USER_MANAGEMENT ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="recoveryModeCard">
            <strong>User Management</strong>
            <span>Hinweis zum offiziellen WoltLab Recovery Tool</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_CACHE_CLEAR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="recoveryModeCard">
            <strong>Cache Clear</strong>
            <span>Löscht alle Caches und kompilierte Templates</span>
        </a>
    </div>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Recovery Tool entfernen</h2>
            <p class="sectionDescription">Nach Abschluss aller Reparaturen sollten Sie diese Datei löschen.</p>
        </header>
        <div class="formSubmit">
            <a href="?action=cleanup&amp;t=<?= htmlspecialchars($authHash) ?>" onclick="return confirm('ACHTUNG: Dies löscht plugin-recovery-tool.php, plugin-recovery-auth.php und alle Upload-Verzeichnisse. Fortfahren?')">Recovery Tool vollständig entfernen</a>
        </div>
    </section>

<?php
}

// ============================================================================
// MODUS 1: ACP REPAIR
// ============================================================================

elseif ($mode === RECOVERY_MODE_ACP_REPAIR) {
?>
    <?php recoveryRenderBackLink("?t=" . $authHash); ?>
    <p class="info">Repariert defekte ACP-Menüeinträge eines Plugins.</p>

<?php
    // Schritt 1: Package-Identifier eingeben oder hochladen
    if (!isset($_POST['package_identifier']) && !isset($_FILES['package_file'])) {
?>
    <form method="POST">
        <section class="section">
            <header class="sectionHeader">
                <h2 class="sectionTitle">Package-Identifier eingeben</h2>
                <p class="sectionDescription">Der eindeutige Bezeichner des Plugins (z.&thinsp;B. <code>de.example.my-plugin</code>).</p>
            </header>
            <dl>
                <dt><label for="package_identifier_acp">Identifier</label></dt>
                <dd><input type="text" id="package_identifier_acp" name="package_identifier" class="long" placeholder="de.example.my-plugin" autocomplete="off"></dd>
            </dl>
            <div class="formSubmit">
                <input type="submit" value="Mit Identifier reparieren">
            </div>
        </section>
    </form>

    <form method="POST" enctype="multipart/form-data">
        <section class="section">
            <header class="sectionHeader">
                <h2 class="sectionTitle">Package-Datei hochladen</h2>
                <p class="sectionDescription">Lädt das Archiv hoch und erkennt automatisch alle Ressourcen.</p>
            </header>
            <dl>
                <dt><label for="package_file_acp">Archiv (.tar, .tar.gz)</label></dt>
                <dd><input type="file" id="package_file_acp" name="package_file" accept=".tar,.tar.gz,.tgz"></dd>
            </dl>
            <div class="formSubmit">
                <input type="submit" value="Mit Datei reparieren">
            </div>
        </section>
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
                        echo '<p class="error">Fehler: package.xml konnte nicht gelesen werden.</p>';
                    }
                } else {
                    echo '<p class="error">Fehler: Archiv konnte nicht entpackt werden.</p>';
                }
            } else {
                echo '<p class="error">Fehler: Datei-Upload fehlgeschlagen.</p>';
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

            echo '<section class="section"><p class="info"><strong>Warnung:</strong> Plugin nicht in Datenbank gefunden.<br>';
            echo 'Dies kann bedeuten, dass die Installation fehlgeschlagen ist.<br><br>';

            if ($menuCount > 0) {
                echo '<strong>Gefundene ACP-Menüeinträge (' . $menuCount . '):</strong><br>';
                if (count($foundPatterns) > 0) {
                    echo '<small>Patterns: ' . htmlspecialchars(implode(', ', $foundPatterns)) . '</small><br><br>';
                }
                
                // Zeige gefundene Einträge
                echo '<table>';
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
                echo '<div class="formSubmit"><input type="submit" value="Diese ' . $menuCount . ' Menüeinträge löschen"></div>';
                echo '</form>';
            } else {
                echo '<strong>Keine ACP-Menüeinträge mit diesen Patterns gefunden.</strong><br>';
                echo 'Es gibt nichts zu bereinigen.';
            }
            echo '</p>';
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
                echo '<p class="info">';
                echo '<strong>Keine ACP-Menüeinträge gefunden</strong><br>';
                echo 'Für dieses Plugin existieren keine ACP-Menüeinträge in der Datenbank.';
                echo '</p>';
            } elseif (!isset($_POST['confirm_delete'])) {
                echo '<p class="info">';
                echo '<strong>Gefundene ACP-Menüeinträge (' . count($menuItems) . '):</strong>';
                echo '<table>';
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
                echo '<div class="formSubmit"><input type="submit" value="Alle löschen"></div>';
                echo '</form>';
                echo '</p>';
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

                    echo '<p class="success">';
                    echo '<strong>✓ ACP-Repair erfolgreich!</strong><br>';
                    echo 'Gelöschte Menüeinträge: ' . $deletedCount . '<br>';
                    echo 'Cache wurde geleert.<br><br>';
                    echo '<a href="' . htmlspecialchars($recoveryBaseUrl) . 'acp/">Zum ACP</a>';
                    echo '</p>';

                } catch (Exception $e) {
                    $db->rollBackTransaction();
                    echo '<p class="error">';
                    echo '<strong>Fehler:</strong> ' . htmlspecialchars($e->getMessage());
                    echo '</p>';
                }
            }
        }
        } else {
            echo '<p class="error">';
            echo '<strong>Fehler:</strong> Kein Package-Identifier konnte ermittelt werden. Bitte versuchen Sie es erneut.';
            echo '</p>';
        }
    }
}

// ============================================================================
// MODUS 2: PLUGIN UNINSTALL
// ============================================================================

elseif ($mode === RECOVERY_MODE_PLUGIN_UNINSTALL) {
?>
    <?php recoveryRenderBackLink("?t=" . $authHash); ?>
    <p class="info">Deinstalliert ein Plugin komplett aus Datenbank und Dateisystem.</p>

<?php
    // Schritt 1: Package-Identifier eingeben oder hochladen
    // Prüfe auch GET-Parameter für "SQL anzeigen" Funktionalität
    $hasPackageIdentifier = (isset($_POST['package_identifier']) && !empty($_POST['package_identifier'])) ||
                            (isset($_GET['package_identifier']) && !empty($_GET['package_identifier'])) ||
                            (isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK);
    
    if (!$hasPackageIdentifier) {
?>
    <form method="POST">
        <section class="section">
            <header class="sectionHeader">
                <h2 class="sectionTitle">Package-Identifier eingeben</h2>
                <p class="sectionDescription">Der eindeutige Bezeichner des Plugins (z.&thinsp;B. <code>de.example.my-plugin</code>).</p>
            </header>
            <dl>
                <dt><label for="package_identifier_uni">Identifier</label></dt>
                <dd><input type="text" id="package_identifier_uni" name="package_identifier" class="long" placeholder="de.example.my-plugin" autocomplete="off"></dd>
            </dl>
            <div class="formSubmit">
                <input type="submit" value="Mit Identifier deinstallieren">
            </div>
        </section>
    </form>

    <form method="POST" enctype="multipart/form-data">
        <section class="section">
            <header class="sectionHeader">
                <h2 class="sectionTitle">Package-Datei hochladen</h2>
                <p class="sectionDescription">Lädt das Archiv hoch und erkennt automatisch alle Ressourcen.</p>
            </header>
            <dl>
                <dt><label for="package_file_uni">Archiv (.tar, .tar.gz)</label></dt>
                <dd><input type="file" id="package_file_uni" name="package_file" accept=".tar,.tar.gz,.tgz"></dd>
            </dl>
            <div class="formSubmit">
                <input type="submit" value="Mit Datei deinstallieren">
            </div>
        </section>
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
                        echo '<p class="error">Fehler: package.xml konnte nicht gelesen werden.</p>';
                    }
                } else {
                    echo '<p class="error">Fehler: Archiv konnte nicht entpackt werden.</p>';
                }
            } else {
                echo '<p class="error">Fehler: Datei-Upload fehlgeschlagen.</p>';
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

            echo '<p class="info">';
            echo '<strong>Package:</strong> ' . htmlspecialchars($packageIdentifier) . '<br>';
            if ($packageData) {
                echo '<strong>Status:</strong> In Datenbank gefunden (ID: ' . $packageData['packageID'] . ')<br>';
                echo '<strong>Name:</strong> ' . htmlspecialchars($packageData['packageName']) . '<br>';
            } else {
                echo '<strong>Status:</strong> Nicht in Datenbank (Installation fehlgeschlagen?)<br>';
            }
            echo '</p>';

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
                        echo '<section class="section"><p class="info"><strong>SQL Cleanup Script:</strong></p>';
                        echo '<pre class="recoveryLog">' . htmlspecialchars(generateCleanupSql($resources, $resources['wcfN'])) . '</pre>';
                        echo '</section>';
                    } else {
                        echo '<div class="formSubmit">';
                        $extractDirParam = $extractDir ? '&extract_dir=' . urlencode($extractDir) : '';
                        echo '<a href="?mode=' . RECOVERY_MODE_PLUGIN_UNINSTALL . '&t=' . $authHash . '&show_sql=1&package_identifier=' . urlencode($packageIdentifier) . $extractDirParam . '">SQL anzeigen</a>';
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
                echo '<p class="info">';
                echo '<strong>Zu löschende Daten:</strong><br><br>';

                if ($packageData) {
                    echo '✓ Package-Eintrag in wcf' . WCF_N . '_package<br>';
                }
                echo '✓ ACP-Menüeinträge<br>';
                echo '✓ Template-Listener (häufige ACP-Ursache)<br>';
                echo '✓ Event-Listener, Optionen, Menüs, Sprachen, Seiten<br>';
                echo '✓ Package-Installationsqueue<br>';
                echo '✓ options.inc.php wird neu erzeugt<br>';

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
                echo '<div class="formSubmit"><input type="submit" value="Jetzt deinstallieren"></div>';
                echo '</form>';
                echo '</p>';

            } else {
                // Ressourcen erneut analysieren wenn Extract-Dir vorhanden
                $extractDir = isset($_POST['extract_dir']) ? $_POST['extract_dir'] : null;
                $resources = null;
                if ($extractDir && is_dir($extractDir)) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                }

                // Deinstallation durchführen (vollständig, ohne global.php)
                $db->beginTransaction();

                try {
                    $log = [];
                    $wcfN = $resources ? (int) $resources['wcfN'] : WCF_N;

                    recoveryPerformFullPluginCleanup(
                        $db,
                        $wcfN,
                        $packageIdentifier,
                        $packageData ?: null,
                        $resources,
                        $log
                    );

                    $deletedFiles = clearCompiledTemplates();
                    $log[] = 'Cache gelöscht: ' . $deletedFiles . ' Dateien';

                    $db->commitTransaction();

                    echo '<p class="success">';
                    echo '<strong>✓ Plugin erfolgreich deinstalliert!</strong><br><br>';
                    echo '<strong>Durchgeführte Aktionen:</strong><br>';
                    foreach ($log as $entry) {
                        echo '• ' . htmlspecialchars($entry) . '<br>';
                    }
                    echo '</p>';

                } catch (Exception $e) {
                    $db->rollBackTransaction();
                    echo '<p class="error">';
                    echo '<strong>Fehler bei Deinstallation:</strong><br>';
                    echo htmlspecialchars($e->getMessage()) . '<br><br>';
                    if (method_exists($e, 'getTraceAsString')) {
                        echo '<details><summary>Technische Details (für Debugging)</summary>';
                        echo '<pre style="font-size: 11px; max-height: 200px; overflow-y: auto;">';
                        echo htmlspecialchars($e->getTraceAsString());
                        echo '</pre></details>';
                    }
                    echo '</p>';
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
    <?php recoveryRenderBackLink("?t=" . $authHash); ?>
    <p class="info">Für User-Management nutzen Sie das offizielle WoltLab Recovery Tool.</p>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">WoltLab Recovery Tool (wsc-recovery.php)</h2>
            <p class="sectionDescription">Für Admin-Passwort-Reset und Benutzer-Management.</p>
        </header>
        <p class="info">
            <strong>Download &amp; Anleitung:</strong>
            <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">manual.woltlab.com/de/recovery-tool/</a>
        </p>
        <p class="info">
            <strong>Funktionen:</strong> Admin-Passwort zurücksetzen, Benutzer zur Administrator-Gruppe hinzufügen,
            E-Mail-Adressen ändern, Benutzer aktivieren/deaktivieren.
        </p>
    </section>

<?php
}

// ============================================================================
// MODUS 4: CACHE CLEAR
// ============================================================================

elseif ($mode === RECOVERY_MODE_CACHE_CLEAR) {
?>
    <?php recoveryRenderBackLink("?t=" . $authHash); ?>
    <p class="info">Löscht alle Caches und kompilierte Templates.</p>

<?php
    if (!isset($_POST['confirm_clear'])) {
?>
    <p class="info">
        <strong>Folgende Verzeichnisse werden geleert:</strong><br>
        • tmp/<br>
        • cache/<br>
        • templates/compiled/<br>
        • acp/templates/compiled/<br>
    </p>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <div class="formSubmit">
            <input type="submit" value="Cache jetzt löschen" accesskey="s">
        </div>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();

        echo '<p class="success">';
        echo '<strong>Cache erfolgreich geleert.</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br><br>';
        echo '<a href="' . htmlspecialchars($recoveryBaseUrl) . '">Zur Hauptseite</a>';
        echo '</p>';
    }
}

?>
<?php
recoveryRenderPageEnd();
