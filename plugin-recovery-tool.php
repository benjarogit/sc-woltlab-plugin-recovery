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
 * @version 1.2.5
 *
 * Eine Datei: ins WoltLab-Hauptverzeichnis legen (neben global.php).
 * Kein global.php – funktioniert auch wenn das ACP durch ein Plugin kaputt ist.
 */

// ============================================================================
// KONFIGURATION
// ============================================================================

define('RECOVERY_VERSION', '1.2.5');
define('RECOVERY_MODE_SELECTION', 0);
define('RECOVERY_MODE_ACP_REPAIR', 1);
define('RECOVERY_MODE_PLUGIN_UNINSTALL', 2);
define('RECOVERY_MODE_USER_MANAGEMENT', 3);
define('RECOVERY_MODE_CACHE_CLEAR', 4);
define('RECOVERY_MODE_PACKAGE_LIST_REPAIR', 5);

/** Stack-Traces nur bei true oder ?debug=1 (mit gültigem Auth-Token). */
define('RECOVERY_ENABLE_DEBUG', false);
define('RECOVERY_PACKAGE_ID_MAX_LEN', 191);
define('RECOVERY_PACKAGE_ID_PATTERN', '/^[a-zA-Z0-9._-]+$/');
define('RECOVERY_MAX_UPLOAD_BYTES', 104857600); // 100 MiB

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

    // core.functions.php registriert WCF::destruct → CacheHandler ohne vollständige Konstanten.
    if (!\defined('NO_IMPORTS')) {
        \define('NO_IMPORTS', true);
    }

    require_once WCF_DIR . 'lib/system/WCF.class.php';
    recoveryDefineMinimalWcfConstants();

    // Nur WoltLab-Autoloader – kein global.php / kein WCF-Vollbootstrap.
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

function recoveryIsDebugEnabled(): bool
{
    if (\defined('RECOVERY_ENABLE_DEBUG') && RECOVERY_ENABLE_DEBUG) {
        return true;
    }

    return isset($_GET['debug']) && $_GET['debug'] === '1';
}

/**
 * @throws \InvalidArgumentException
 */
function recoveryValidatePackageIdentifier(?string $identifier): string
{
    $identifier = \trim((string) $identifier);
    if ($identifier === '') {
        throw new \InvalidArgumentException('Bitte einen Package-Identifier angeben.');
    }
    if (\strlen($identifier) > RECOVERY_PACKAGE_ID_MAX_LEN) {
        throw new \InvalidArgumentException(
            'Package-Identifier ist zu lang (max. ' . RECOVERY_PACKAGE_ID_MAX_LEN . ' Zeichen).'
        );
    }
    if (!\preg_match(RECOVERY_PACKAGE_ID_PATTERN, $identifier)) {
        throw new \InvalidArgumentException(
            'Ungültiger Package-Identifier. Erlaubt sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.'
        );
    }

    return $identifier;
}

function recoveryValidateSqlTableName(string $table): bool
{
    return (bool) \preg_match('/^[a-zA-Z0-9_]+$/', $table);
}

function recoveryValidateAppDirectoryName(string $dir): bool
{
    return $dir !== '' && (bool) \preg_match('/^[a-zA-Z0-9._-]+$/', $dir);
}

/**
 * @return list<string>
 */
function recoveryGetProtectedDirectoryNames(): array
{
    return [
        'wcf',
        'lib',
        'acp',
        'cache',
        'tmp',
        'templates',
        'images',
        'js',
        'style',
        'icons',
        'font',
        'fonts',
        'attachments',
        'media',
        'log',
        'language',
    ];
}

function recoveryFormatUserError(\Throwable $e, string $context = ''): string
{
    $message = $context !== '' ? $context . ': ' : '';
    $message .= $e->getMessage();

    if (recoveryIsDebugEnabled()) {
        $message .= "\n\n" . $e->getTraceAsString();
    }

    return $message;
}

function recoveryRenderExceptionDetails(\Throwable $e): void
{
    if (!recoveryIsDebugEnabled()) {
        return;
    }

    echo '<details><summary>Technische Details (Debug)</summary>';
    echo '<pre class="recoveryLog">' . \htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</details>';
}

function recoveryGetDatabaseSchemaName(\wcf\system\database\Database $db): string
{
    try {
        $statement = $db->prepareStatement('SELECT DATABASE() AS dbName');
        $statement->execute();
        $row = $statement->fetchArray();

        return (string) ($row['dbName'] ?? '');
    } catch (\Throwable) {
        return '';
    }
}

function recoveryIsUnsafeArchiveRelativePath(string $path): bool
{
    $path = \str_replace('\\', '/', $path);
    if ($path === '' || \str_starts_with($path, '/')) {
        return true;
    }

    foreach (\explode('/', $path) as $segment) {
        if ($segment === '..' || $segment === '') {
            return true;
        }
    }

    return false;
}

function recoveryValidateArchiveFilename(string $filename): bool
{
    return (bool) \preg_match('/\.(tar\.gz|tgz|tar)$/i', $filename);
}

/**
 * @return array{ok: bool, error?: string, packageIdentifier?: string, extractDir?: string, uploadedFile?: string}
 */
function recoveryHandlePackageUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Datei-Upload fehlgeschlagen (Fehlercode ' . (int) ($file['error'] ?? 0) . ').'];
    }

    if (($file['size'] ?? 0) > RECOVERY_MAX_UPLOAD_BYTES) {
        $maxMb = (int) \round(RECOVERY_MAX_UPLOAD_BYTES / 1048576);

        return ['ok' => false, 'error' => "Die Datei ist zu groß (max. {$maxMb} MiB)."];
    }

    $originalName = \basename((string) ($file['name'] ?? ''));
    if (!recoveryValidateArchiveFilename($originalName)) {
        return ['ok' => false, 'error' => 'Ungültiges Archivformat. Erlaubt: .tar, .tar.gz, .tgz'];
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!\is_dir($uploadDir) && !@\mkdir($uploadDir, 0755, true)) {
        return ['ok' => false, 'error' => 'Upload-Verzeichnis konnte nicht erstellt werden.'];
    }

    $safeName = \preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: 'package.tar';
    $uploadedFile = $uploadDir . '/' . $safeName;
    if (!\move_uploaded_file((string) $file['tmp_name'], $uploadedFile)) {
        return ['ok' => false, 'error' => 'Datei konnte nicht gespeichert werden.'];
    }

    $extractDir = $uploadDir . '/extracted_' . \bin2hex(\random_bytes(4));
    if (!\is_dir($extractDir) && !@\mkdir($extractDir, 0755, true)) {
        @\unlink($uploadedFile);

        return ['ok' => false, 'error' => 'Entpack-Verzeichnis konnte nicht erstellt werden.'];
    }

    if (!extractArchive($uploadedFile, $extractDir)) {
        recoveryCleanupUploadWorkspace($uploadDir);

        return ['ok' => false, 'error' => 'Archiv konnte nicht entpackt werden.'];
    }

    $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
    if (!$packageXml) {
        recoveryCleanupUploadWorkspace($uploadDir);

        return ['ok' => false, 'error' => 'package.xml wurde im Archiv nicht gefunden.'];
    }

    $packageIdentifier = extractPackageIdentifier($packageXml);
    if (!$packageIdentifier) {
        recoveryCleanupUploadWorkspace($uploadDir);

        return ['ok' => false, 'error' => 'package.xml konnte nicht gelesen werden.'];
    }

    try {
        $packageIdentifier = recoveryValidatePackageIdentifier($packageIdentifier);
    } catch (\InvalidArgumentException $e) {
        recoveryCleanupUploadWorkspace($uploadDir);

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'packageIdentifier' => $packageIdentifier,
        'extractDir' => $extractDir,
        'uploadedFile' => $uploadedFile,
    ];
}

function recoveryCleanupUploadWorkspace(?string $uploadDir = null): void
{
    $uploadDir ??= __DIR__ . '/uploads';
    if (!\is_dir($uploadDir)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($uploadDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
    }
}

/**
 * @param array<string, mixed>|null $packageData
 */
function recoveryResolvePluginDirectory(
    ?array $packageData,
    string $packageIdentifier,
    ?\wcf\system\database\Database $db = null,
    ?int $wcfN = null,
    ?string $extractDir = null
): ?string {
    if ($packageData) {
        $dir = \trim((string) ($packageData['packageDir'] ?? ''), '/\\');
        if ($dir !== '' && recoveryValidateAppDirectoryName($dir)) {
            return $dir;
        }
    }

    if ($db && $wcfN && $packageData && !empty($packageData['packageID'])) {
        try {
            $sql = "SELECT application FROM wcf{$wcfN}_application WHERE packageID = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([(int) $packageData['packageID']]);
            $row = $statement->fetchArray();
            $application = \trim((string) ($row['application'] ?? ''));
            if ($application !== '' && recoveryValidateAppDirectoryName($application)) {
                return $application;
            }
        } catch (\Throwable) {
        }
    }

    if ($extractDir && \is_dir($extractDir)) {
        $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
        if ($packageXml) {
            $parsed = parsePackageXml($packageXml);
            $application = \trim((string) ($parsed['application'] ?? ''));
            if ($application !== '' && recoveryValidateAppDirectoryName($application)) {
                return $application;
            }
        }
    }

    $parts = \explode('.', $packageIdentifier);
    if (\count($parts) < 2) {
        return null;
    }

    $guess = (string) \end($parts);

    return recoveryValidateAppDirectoryName($guess) ? $guess : null;
}

/**
 * @return list<string>
 */
function recoveryInferAcpMenuSearchPatterns(string $packageIdentifier, ?array $resources = null): array
{
    $patterns = [];

    if ($resources && !empty($resources['acpMenu']['prefix'])) {
        $patterns[] = $resources['acpMenu']['prefix'] . '%';
    }

    if ($resources && !empty($resources['acpMenu']['items'])) {
        $prefix = extractCommonPrefix($resources['acpMenu']['items'], '.');
        if ($prefix !== '') {
            $patterns[] = $prefix . '%';
        }
    }

    $parts = \explode('.', $packageIdentifier);
    $candidates = [];
    if (\count($parts) >= 1) {
        $candidates[] = (string) \end($parts);
    }
    if (\count($parts) >= 2) {
        $candidates[] = $parts[\count($parts) - 2];
    }
    if (\count($parts) >= 3) {
        $candidates[] = $parts[\count($parts) - 3];
    }
    $candidates = \array_values(\array_unique(\array_filter($candidates)));

    foreach ($candidates as $appName) {
        $patterns[] = $appName . '.acp.menu.%';
        $patterns[] = \strtolower($appName) . '.acp.menu.%';
        $patterns[] = $packageIdentifier . '.%';
    }

    return \array_values(\array_unique($patterns));
}

/**
 * @return list<array{menuItem: string, menuItemController: string|null}>
 */
function recoveryFetchAcpMenuItemsByPatterns(
    \wcf\system\database\Database $db,
    int $wcfN,
    array $patterns
): array {
    $items = [];
    $seen = [];

    foreach ($patterns as $pattern) {
        if ($pattern === '' || \strlen($pattern) > 255) {
            continue;
        }

        $sql = "SELECT menuItem, menuItemController FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([$pattern]);

        while ($row = $statement->fetchArray()) {
            $key = (string) $row['menuItem'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $row;
        }
    }

    return $items;
}

/**
 * @return array{packageIdentifier?: string, extractDir?: string|null, error?: string}
 */
function recoveryResolvePackageInputFromRequest(): array
{
    if (isset($_FILES['package_file']) && ($_FILES['package_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $upload = recoveryHandlePackageUpload($_FILES['package_file']);

        return $upload['ok']
            ? [
                'packageIdentifier' => $upload['packageIdentifier'],
                'extractDir' => $upload['extractDir'] ?? null,
            ]
            : ['error' => $upload['error'] ?? 'Upload fehlgeschlagen.'];
    }

    $raw = null;
    if (!empty($_POST['package_identifier'])) {
        $raw = \trim((string) $_POST['package_identifier']);
    } elseif (!empty($_GET['package_identifier'])) {
        $raw = \trim((string) $_GET['package_identifier']);
    }

    if ($raw !== null && $raw !== '') {
        try {
            return ['packageIdentifier' => recoveryValidatePackageIdentifier($raw), 'extractDir' => recoveryResolveTrustedExtractDir()];
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    return [];
}

function recoveryResolveTrustedExtractDir(): ?string
{
    $postedExtract = $_POST['extract_dir'] ?? $_GET['extract_dir'] ?? null;
    if (!$postedExtract) {
        return null;
    }

    $uploadBase = \realpath(__DIR__ . '/uploads');
    $extractReal = \realpath((string) $postedExtract);
    if (
        $uploadBase === false
        || $extractReal === false
        || !\str_starts_with($extractReal, $uploadBase . \DIRECTORY_SEPARATOR)
    ) {
        return null;
    }

    return $extractReal;
}

/**
 * @param array<string, mixed>|null $packageData
 * @return list<array{menuItem: string, menuItemController: string|null}>
 */
function recoveryFetchAcpMenuItemsForPackage(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $packageIdentifier,
    ?array $packageData,
    ?array $resources = null
): array {
    if ($packageData && !empty($packageData['packageID'])) {
        $sql = "SELECT menuItem, menuItemController FROM wcf{$wcfN}_acp_menu_item WHERE packageID = ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([(int) $packageData['packageID']]);
        $items = [];
        while ($row = $statement->fetchArray()) {
            $items[] = $row;
        }

        return $items;
    }

    if ($resources && !empty($resources['acpMenu']['prefix'])) {
        return recoveryFetchAcpMenuItemsByPatterns($db, $wcfN, [$resources['acpMenu']['prefix'] . '%']);
    }

    return recoveryFetchAcpMenuItemsByPatterns(
        $db,
        $wcfN,
        recoveryInferAcpMenuSearchPatterns($packageIdentifier, $resources)
    );
}

/**
 * @param array<string, mixed>|null $packageData
 */
function recoveryDeleteAcpMenuItemsForPackage(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $packageIdentifier,
    ?array $packageData,
    ?array $resources = null
): int {
    if ($packageData && !empty($packageData['packageID'])) {
        $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE packageID = ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([(int) $packageData['packageID']]);

        return $statement->getAffectedRows();
    }

    if ($resources && !empty($resources['acpMenu']['prefix'])) {
        $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([$resources['acpMenu']['prefix'] . '%']);

        return $statement->getAffectedRows();
    }

    $deletedTotal = 0;
    foreach (recoveryInferAcpMenuSearchPatterns($packageIdentifier, $resources) as $pattern) {
        $sql = "DELETE FROM wcf{$wcfN}_acp_menu_item WHERE menuItem LIKE ?";
        $statement = $db->prepareStatement($sql);
        $statement->execute([$pattern]);
        $deletedTotal += $statement->getAffectedRows();
    }

    return $deletedTotal;
}

/**
 * @return array{deletable: bool, reason: string, relativePath: string|null}
 */
function recoveryEvaluatePluginDirectoryDeletion(
    ?array $packageData,
    string $packageIdentifier,
    ?\wcf\system\database\Database $db = null,
    ?int $wcfN = null,
    ?string $extractDir = null
): array {
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $appDir = recoveryResolvePluginDirectory($packageData, $packageIdentifier, $db, $wcfN, $extractDir);
    if (!$appDir) {
        return [
            'deletable' => false,
            'reason' => 'Kein Plugin-Verzeichnis ermittelt (packageDir / application / package.xml).',
            'relativePath' => null,
        ];
    }

    if (\in_array(\strtolower($appDir), recoveryGetProtectedDirectoryNames(), true)) {
        return [
            'deletable' => false,
            'reason' => 'Geschütztes WoltLab-Verzeichnis: ' . $appDir . '/',
            'relativePath' => $appDir,
        ];
    }

    $wcfRoot = \rtrim(WCF_DIR, '/\\');
    $target = $wcfRoot . '/' . $appDir;

    if (!\is_dir($target)) {
        return [
            'deletable' => false,
            'reason' => 'Verzeichnis nicht vorhanden: ' . $appDir . '/',
            'relativePath' => $appDir,
        ];
    }

    $wcfReal = \realpath($wcfRoot);
    $targetReal = \realpath($target);
    if (
        $wcfReal === false
        || $targetReal === false
        || (!\str_starts_with($targetReal, $wcfReal . \DIRECTORY_SEPARATOR) && $targetReal !== $wcfReal)
    ) {
        return [
            'deletable' => false,
            'reason' => 'Sicherheitsprüfung fehlgeschlagen (Pfad außerhalb von WCF_DIR).',
            'relativePath' => $appDir,
        ];
    }

    return [
        'deletable' => true,
        'reason' => 'Wird entfernt: ' . $appDir . '/',
        'relativePath' => $appDir,
    ];
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

function recoveryDefineMinimalWcfConstants(): void
{
    if (\defined('CACHE_SOURCE_TYPE')) {
        return;
    }

    if (\defined('WCF_DIR') && \is_file(WCF_DIR . 'options.inc.php')) {
        require_once WCF_DIR . 'options.inc.php';
    }

    if (!\defined('CACHE_SOURCE_TYPE')) {
        \define('CACHE_SOURCE_TYPE', 'disk');
    }
}

function recoveryRebuildOptionsIncPhp(): bool
{
    try {
        recoveryDefineMinimalWcfConstants();
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
 * @return list<string> Tabellennamen ohne wcf{N}_-Präfix
 */
function recoveryDiscoverPackageIdTables(\wcf\system\database\Database $db, int $wcfN): array
{
    if ($wcfN < 1 || $wcfN > 99) {
        return [];
    }

    $schema = recoveryGetDatabaseSchemaName($db);
    if ($schema === '') {
        return [];
    }

    $prefix = "wcf{$wcfN}_";
    $tables = [];

    try {
        $sql = 'SELECT TABLE_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? AND COLUMN_NAME = ?';
        $statement = $db->prepareStatement($sql);
        $statement->execute([$schema, $prefix . '%', 'packageID']);

        while ($row = $statement->fetchArray()) {
            $fullName = (string) ($row['TABLE_NAME'] ?? '');
            if (!\str_starts_with($fullName, $prefix)) {
                continue;
            }

            $shortName = \substr($fullName, \strlen($prefix));
            if ($shortName === '' || $shortName === 'package' || !recoveryValidateSqlTableName($shortName)) {
                continue;
            }

            $tables[] = $shortName;
        }
    } catch (\Throwable) {
    }

    return \array_values(\array_unique($tables));
}

function recoveryDeleteDirectoryRecursive(string $directory): bool
{
    if (!\is_dir($directory)) {
        return false;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @\rmdir($file->getPathname());
        } else {
            @\unlink($file->getPathname());
        }
    }

    return @\rmdir($directory);
}

function recoveryDeletePluginFilesOnDisk(
    ?array $packageData,
    string $packageIdentifier,
    array &$log,
    bool $performDelete = false,
    ?\wcf\system\database\Database $db = null,
    ?int $wcfN = null,
    ?string $extractDir = null
): void {
    $evaluation = recoveryEvaluatePluginDirectoryDeletion(
        $packageData,
        $packageIdentifier,
        $db,
        $wcfN,
        $extractDir
    );

    if (!$evaluation['deletable']) {
        $log[] = 'Dateisystem: ' . $evaluation['reason'];

        return;
    }

    $appDir = (string) $evaluation['relativePath'];
    if (!$performDelete) {
        $log[] = 'Dateisystem (Vorschau): ' . $evaluation['reason'];

        return;
    }

    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $targetReal = \realpath(\rtrim(WCF_DIR, '/\\') . '/' . $appDir);
    if ($targetReal === false || !\is_dir($targetReal)) {
        $log[] = 'Dateisystem: Verzeichnis nicht mehr vorhanden (' . $appDir . '/)';

        return;
    }

    if (recoveryDeleteDirectoryRecursive($targetReal)) {
        $log[] = 'Dateisystem gelöscht: ' . $appDir . '/';
    } else {
        $log[] = 'Dateisystem: Verzeichnis konnte nicht vollständig gelöscht werden (' . $appDir . '/)';
    }
}

/**
 * @return array{rows: list<array{label: string, count: int, error?: string}>, dropTables: list<string>}
 */
function recoveryPreviewDbCleanupByPackageId(
    \wcf\system\database\Database $db,
    int $wcfN,
    int $packageID,
    string $packageIdentifier
): array {
    $rows = [];
    foreach (recoveryDiscoverPackageIdTables($db, $wcfN) as $table) {
        try {
            $sql = "SELECT COUNT(*) AS cnt FROM wcf{$wcfN}_{$table} WHERE packageID = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageID]);
            $row = $statement->fetchArray();
            $count = (int) ($row['cnt'] ?? 0);
            if ($count > 0) {
                $rows[] = ['label' => $table, 'count' => $count];
            }
        } catch (\Throwable $e) {
            $rows[] = ['label' => $table, 'count' => -1, 'error' => $e->getMessage()];
        }
    }

    $dropTables = \function_exists('findPackageTables')
        ? findPackageTables($db, $packageIdentifier, $wcfN)
        : [];

    return ['rows' => $rows, 'dropTables' => $dropTables];
}

function recoveryDisplayDbCleanupPreview(
    \wcf\system\database\Database $db,
    int $wcfN,
    array $packageData,
    string $packageIdentifier,
    ?string $extractDir = null
): void {
    $packageID = (int) $packageData['packageID'];
    $preview = recoveryPreviewDbCleanupByPackageId($db, $wcfN, $packageID, $packageIdentifier);

    echo '<div class="alert alert-info"><strong>Datenbank-Bereinigung (Package-ID ' . $packageID . '):</strong><br>';
    echo '<small>Auch ohne Package-Archiv werden alle wcf' . $wcfN . '_*-Tabellen mit Spalte <code>packageID</code> bereinigt.</small><br><br>';

    if (!empty($preview['rows'])) {
        echo '<ul>';
        foreach ($preview['rows'] as $row) {
            if (isset($row['error'])) {
                echo '<li><code>wcf' . $wcfN . '_' . \htmlspecialchars($row['label']) . '</code> – Prüfung fehlgeschlagen</li>';
            } else {
                echo '<li><code>wcf' . $wcfN . '_' . \htmlspecialchars($row['label']) . '</code> – '
                    . (int) $row['count'] . ' Einträge</li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<em>Keine packageID-verknüpften Einträge in anderen Tabellen gefunden.</em><br>';
    }

    echo '<br><strong>Package-Eintrag:</strong> wcf' . $wcfN . '_package (1 Zeile)<br>';

    if (!empty($preview['dropTables'])) {
        echo '<br><strong>Zusätzlich DROP TABLE (' . \count($preview['dropTables']) . '):</strong><br><ul>';
        foreach ($preview['dropTables'] as $table) {
            echo '<li><code>' . \htmlspecialchars($table) . '</code></li>';
        }
        echo '</ul>';
    }

    $fsEval = recoveryEvaluatePluginDirectoryDeletion(
        $packageData,
        $packageIdentifier,
        $db,
        $wcfN,
        $extractDir
    );
    echo '<br><strong>Dateisystem:</strong> ';
    if ($fsEval['relativePath']) {
        echo '<code>' . \htmlspecialchars((string) $fsEval['relativePath']) . '/</code> – ';
    }
    echo \htmlspecialchars($fsEval['reason']);
    if ($fsEval['deletable']) {
        echo '<br><small>Entfernung nur nach expliziter Bestätigung im Deinstallationsformular.</small>';
    }

    echo '</div>';
}

function recoverySafeCommitTransaction(\wcf\system\database\Database $db): void
{
    try {
        $db->commitTransaction();
    } catch (\Throwable $e) {
        // MySQL beendet Transaktionen bei DDL (DROP TABLE) implizit.
        if (
            \str_contains($e->getMessage(), 'no active transaction')
            || \str_contains($e->getMessage(), 'Could not commit transaction')
        ) {
            return;
        }

        throw $e;
    }
}

function recoverySafeRollBackTransaction(\wcf\system\database\Database $db): void
{
    try {
        $db->rollBackTransaction();
    } catch (\Throwable) {
    }
}

/**
 * @return list<int>
 */
function recoveryFetchQueueIdsForPackage(
    \wcf\system\database\Database $db,
    int $wcfN,
    ?int $packageID,
    string $packageIdentifier
): array {
    $queueIds = [];

    try {
        $sql = "SELECT queueID FROM wcf{$wcfN}_package_installation_queue WHERE package = ?";
        $params = [$packageIdentifier];
        if ($packageID !== null) {
            $sql .= ' OR packageID = ?';
            $params[] = $packageID;
        }

        $statement = $db->prepareStatement($sql);
        $statement->execute($params);

        while ($row = $statement->fetchArray()) {
            $queueIds[] = (int) $row['queueID'];
        }
    } catch (\Throwable) {
    }

    return \array_values(\array_unique($queueIds));
}

/**
 * Entfernt Installations-/Deinstallations-Warteschlangen inkl. Knoten (generisch).
 */
function recoveryCleanupPackageInstallationArtifacts(
    \wcf\system\database\Database $db,
    int $wcfN,
    ?int $packageID,
    string $packageIdentifier,
    array &$log
): void {
    $queueIds = recoveryFetchQueueIdsForPackage($db, $wcfN, $packageID, $packageIdentifier);

    if (!empty($queueIds)) {
        $placeholders = \implode(',', \array_fill(0, \count($queueIds), '?'));

        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package_installation_node WHERE queueID IN ({$placeholders})",
            $queueIds,
            'Package-Installationsknoten',
            $log
        );

        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package_installation_form WHERE queueID IN ({$placeholders})",
            $queueIds,
            'Package-Installationsformulare',
            $log
        );
    }

    recoveryExecuteDelete(
        $db,
        "DELETE FROM wcf{$wcfN}_package_installation_queue WHERE package = ?"
            . ($packageID !== null ? ' OR packageID = ?' : ''),
        $packageID !== null ? [$packageIdentifier, $packageID] : [$packageIdentifier],
        'Installationsqueue',
        $log
    );
}

/**
 * Entfernt Update-Metadaten für ein Package (package_update / *_version per CASCADE).
 */
function recoveryCleanupPackageUpdateEntries(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $packageIdentifier,
    array &$log
): void {
    recoveryExecuteDelete(
        $db,
        "DELETE FROM wcf{$wcfN}_package_update WHERE package = ?",
        [$packageIdentifier],
        'Package-Updates',
        $log
    );
}

/**
 * Bereinigt verwaiste Package-Referenzen (ACP-Paketliste, hängende Deinstallation).
 *
 * @return array{log: list<string>, sql: string}
 */
function recoveryRepairOrphanedPackageReferences(
    \wcf\system\database\Database $db,
    int $wcfN
): array {
    if ($wcfN < 1 || $wcfN > 99) {
        throw new \InvalidArgumentException('Ungültige WCF-Instanznummer.');
    }

    $log = [];
    $prefix = "wcf{$wcfN}_";

    // Verwaiste Applications (PackageListPage: getPackage() → null)
    recoveryExecuteDelete(
        $db,
        "DELETE a FROM {$prefix}application a
         LEFT JOIN {$prefix}package p ON a.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Applications',
        $log
    );

    // Verwaiste Installationsqueue (packageID zeigt auf gelöschtes Paket)
    $orphanQueueIds = [];
    try {
        $sql = "SELECT q.queueID FROM {$prefix}package_installation_queue q
                LEFT JOIN {$prefix}package p ON q.packageID = p.packageID
                WHERE q.packageID IS NOT NULL AND p.packageID IS NULL";
        $statement = $db->prepareStatement($sql);
        $statement->execute();

        while ($row = $statement->fetchArray()) {
            $orphanQueueIds[] = (int) $row['queueID'];
        }
    } catch (\Throwable $e) {
        $log[] = 'Verwaiste Queue-Prüfung übersprungen: ' . $e->getMessage();
    }

    if (!empty($orphanQueueIds)) {
        $placeholders = \implode(',', \array_fill(0, \count($orphanQueueIds), '?'));

        recoveryExecuteDelete(
            $db,
            "DELETE FROM {$prefix}package_installation_node WHERE queueID IN ({$placeholders})",
            $orphanQueueIds,
            'Verwaiste Installationsknoten',
            $log
        );

        recoveryExecuteDelete(
            $db,
            "DELETE FROM {$prefix}package_installation_form WHERE queueID IN ({$placeholders})",
            $orphanQueueIds,
            'Verwaiste Installationsformulare',
            $log
        );

        recoveryExecuteDelete(
            $db,
            "DELETE FROM {$prefix}package_installation_queue WHERE queueID IN ({$placeholders})",
            $orphanQueueIds,
            'Verwaiste Installationsqueue',
            $log
        );
    }

    recoveryExecuteDelete(
        $db,
        "DELETE r FROM {$prefix}package_requirements r
         LEFT JOIN {$prefix}package p ON r.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-Requirements (packageID)',
        $log
    );

    recoveryExecuteDelete(
        $db,
        "DELETE r FROM {$prefix}package_requirements r
         LEFT JOIN {$prefix}package p ON r.requirement = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-Requirements (requirement)',
        $log
    );

    recoveryExecuteDelete(
        $db,
        "DELETE e FROM {$prefix}package_exclusion e
         LEFT JOIN {$prefix}package p ON e.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-Exclusions',
        $log
    );

    recoveryExecuteDelete(
        $db,
        "DELETE l FROM {$prefix}package_installation_file_log l
         LEFT JOIN {$prefix}package p ON l.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-File-Logs',
        $log
    );

    recoveryExecuteDelete(
        $db,
        "DELETE pl FROM {$prefix}package_installation_plugin pl
         LEFT JOIN {$prefix}package p ON pl.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-Installation-Plugins',
        $log
    );

    return [
        'log' => $log,
        'sql' => recoveryGenerateOrphanRepairSql($wcfN),
    ];
}

function recoveryGenerateOrphanRepairSql(int $wcfN): string
{
    $p = "wcf{$wcfN}_";

    return <<<SQL
-- WoltLab Recovery Tool: Paketliste reparieren (verwaiste Referenzen)
-- WCF_N: {$wcfN} – vor Ausführung Backup anlegen!

-- ACP-Paketliste: tainted application ohne Package-Zeile
DELETE a FROM {$p}application a
LEFT JOIN {$p}package p ON a.packageID = p.packageID
WHERE p.packageID IS NULL;

-- Hängende Deinstallation / Installation (z. B. packageID 3 oder 4 fehlt)
DELETE n FROM {$p}package_installation_node n
INNER JOIN {$p}package_installation_queue q ON n.queueID = q.queueID
LEFT JOIN {$p}package p ON q.packageID = p.packageID
WHERE q.packageID IS NOT NULL AND p.packageID IS NULL;

DELETE f FROM {$p}package_installation_form f
INNER JOIN {$p}package_installation_queue q ON f.queueID = q.queueID
LEFT JOIN {$p}package p ON q.packageID = p.packageID
WHERE q.packageID IS NOT NULL AND p.packageID IS NULL;

DELETE q FROM {$p}package_installation_queue q
LEFT JOIN {$p}package p ON q.packageID = p.packageID
WHERE q.packageID IS NOT NULL AND p.packageID IS NULL;

DELETE r FROM {$p}package_requirements r
LEFT JOIN {$p}package p ON r.packageID = p.packageID
WHERE p.packageID IS NULL;

DELETE r FROM {$p}package_requirements r
LEFT JOIN {$p}package p ON r.requirement = p.packageID
WHERE p.packageID IS NULL;

DELETE e FROM {$p}package_exclusion e
LEFT JOIN {$p}package p ON e.packageID = p.packageID
WHERE p.packageID IS NULL;

SQL;
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
    array &$log,
    bool $deleteFilesOnDisk = false,
    ?string $extractDir = null
): void {
    $packageIdentifier = recoveryValidatePackageIdentifier($packageIdentifier);

    if ($wcfN < 1 || $wcfN > 99) {
        throw new \InvalidArgumentException('Ungültige WCF-Instanznummer.');
    }

    $packageID = $packageData ? (int) $packageData['packageID'] : null;

    $optionConstants = recoveryCollectOptionConstantNames($db, $wcfN, $packageID);
    if ($resources && !empty($resources['options']['items'])) {
        foreach ($resources['options']['items'] as $name) {
            $optionConstants[] = \strtoupper((string) $name);
        }
    }
    $optionConstants = \array_values(\array_unique($optionConstants));

    recoveryCleanupPackageInstallationArtifacts($db, $wcfN, $packageID, $packageIdentifier, $log);
    recoveryCleanupPackageUpdateEntries($db, $wcfN, $packageIdentifier, $log);

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
            'application' => 'Application',
            'package_exclusion' => 'Package-Exclusions',
            'package_installation_plugin' => 'Package-Installation-Plugins',
            'package_installation_file_log' => 'Package-File-Log',
        ];

        foreach (recoveryDiscoverPackageIdTables($db, $wcfN) as $discoveredTable) {
            if (!isset($packageIdTables[$discoveredTable])) {
                $packageIdTables[$discoveredTable] = 'DB: ' . $discoveredTable;
            }
        }

        foreach ($packageIdTables as $table => $label) {
            recoveryTryDeleteByPackageId($db, $wcfN, $table, $packageID, $label, $log);
        }

        recoveryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package_requirements WHERE packageID = ? OR requirement = ?",
            [$packageID, $packageID],
            'Package-Requirements',
            $log
        );

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

    $tables = [];
    if ($resources && !empty($resources['tables'])) {
        $tables = $resources['tables'];
    } elseif ($packageData && !empty($packageData['packageDir'])) {
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
        if (!recoveryValidateSqlTableName($safeTable)) {
            $log[] = 'Tabelle übersprungen (ungültiger Name): ' . $safeTable;

            continue;
        }

        $baseTables = \array_map(
            static fn(string $name): string => \str_replace('`', '', $name),
            getBasePluginTables($wcfN)
        );
        if (\in_array($safeTable, $baseTables, true)) {
            $log[] = 'Tabelle übersprungen (WoltLab-Basistabelle): ' . $safeTable;

            continue;
        }

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

    recoveryDeletePluginFilesOnDisk(
        $packageData,
        $packageIdentifier,
        $log,
        $deleteFilesOnDisk,
        $db,
        $wcfN,
        $extractDir
    );
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

function recoveryRenderPageStart(string $documentTitle, string $contentTitle = '', ?array $assets = null): void
{
    $assets ??= recoveryGetSetupAssets();
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($documentTitle) ?></title>
    <?php if ($assets['WCFSetup.css'] !== ''): ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($assets['WCFSetup.css']) ?>">
    <?php endif; ?>
    <style>
        /* WCFSetup.css neutralisieren – einfaches Container-Layout beibehalten */
        .pageHeaderContainer, .pageHeaderFacade, .pageHeader, .pageHeaderLogo,
        .pageContainer, #pageContainer, .pageNavigation,
        #acpPageContentContainer, .acpPageContentContainer,
        .layoutBoundary, .contentHeader, .contentTitle, .content,
        #pageFooter, .pageFooter, .pageFooterCopyright, .copyright {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0 !important;
            max-width: none !important;
            min-height: 0 !important;
        }
        .section, .sectionHeader, .sectionTitle, .sectionDescription,
        .formSubmit, .recoveryModeGrid, .recoveryModeCard, .recoveryBackLink {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }

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
        footer a { color: inherit; text-decoration: none; }
        footer a:hover { color: #fff; }
        h1 { color: #fff; margin-bottom: 10px; font-size: 32px; font-weight: 300; }
        h2 { color: #fff; margin: 40px 0 10px 0; font-size: 24px; font-weight: 300; }
        .subtitle { color: #9D9D9D; margin-bottom: 30px; font-size: 14px; }
        code { color: #fff; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; word-break: break-word; }
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
        .mode-button:hover { background: rgba(0, 0, 0, .25); border-color: #666; }
        .mode-button strong { display: block; font-size: 18px; margin-bottom: 8px; color: #fff; }
        .mode-button span { font-size: 13px; color: #9D9D9D; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #fff; }
        input[type="text"], input[type="password"], textarea, select {
            width: 100%; padding: 10px; border: 1px solid #444444; border-radius: 3px;
            font-size: 14px; background: #2D2D2D; color: #c0c0c0;
        }
        input[type="file"] {
            width: 100%; padding: 10px; border: 1px dashed #444444; border-radius: 3px;
            background: #2D2D2D; color: #c0c0c0;
        }
        button, .button {
            background: #369; color: white; padding: 12px 24px; border: none; border-radius: 3px;
            font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s;
            display: inline-block; text-decoration: none;
        }
        button:hover, .button:hover { background: #258; }
        .btn-danger { background: #c33; }
        .btn-danger:hover { background: #a22; }
        .btn-success { background: #3c3; }
        .btn-success:hover { background: #2a2; }
        .alert { padding: 15px 20px; margin-bottom: 20px; border-radius: 3px; color: #fff; }
        .alert-success { background: rgba(60, 204, 60, 0.3); border: 1px solid #3c3; }
        .alert-error { background: rgba(204, 51, 51, 0.3); border: 1px solid #c33; }
        .alert-info { background: rgba(51, 102, 153, 0.3); border: 1px solid #369; }
        .alert-warning { background: rgba(204, 153, 51, 0.3); border: 1px solid #c93; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #fff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        pre {
            background: #2D2D2D; padding: 15px; border-radius: 3px; overflow-x: auto;
            font-size: 13px; color: #c0c0c0; border: 1px solid #444444;
        }
        pre.recoveryLog { max-height: 340px; }
        small { color: #9D9D9D; }
        .table, table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .table th, .table td, table th, table td {
            padding: 10px 20px; text-align: left; border-bottom: 1px solid #444444; color: #c0c0c0;
        }
        .table th, table th { border-top: 1px solid #444444; border-bottom-width: 2px; font-weight: 600; color: #fff; }
        .table tbody tr:nth-child(odd), table tbody tr:nth-child(odd) { background: rgba(0, 0, 0, .125); }
        .table tbody tr:hover, table tbody tr:hover { background: rgba(0, 0, 0, .25); }
        hr { border: none; border-top: 1px solid #444444; margin: 30px 0; }

        @media (prefers-color-scheme: light) {
            body { background: #f0f0f0; color: #333; }
            .container { background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
            footer { color: #777; }
            footer a:hover { color: #369; }
            h1, h2 { color: #333; }
            .subtitle { color: #666; }
            code { color: #369; background: #f0f4ff; padding: 1px 5px; border-radius: 2px; }
            .mode-button { background: #f9f9f9; border-color: #ddd; color: #333; }
            .mode-button:hover { background: #f0f4ff; border-color: #369; }
            .mode-button strong { color: #333; }
            .mode-button span { color: #666; }
            label { color: #333; }
            input[type="text"], input[type="password"], textarea, select { background: #fff; border-color: #ccc; color: #333; }
            input[type="file"] { background: #fff; border-color: #ccc; color: #333; }
            .alert { color: #333; }
            .alert-info { background: rgba(51,102,153,.08); border-color: #369; }
            .alert-success { background: rgba(51,153,51,.08); border-color: #3a3; }
            .alert-error { background: rgba(204,51,51,.08); border-color: #c33; }
            .alert-warning { background: rgba(200,120,40,.08); border-color: #c83; }
            .back-link { color: #369; }
            pre { background: #fafafa; border-color: #ddd; color: #333; }
            .table th, .table td, table th, table td { border-color: #ddd; color: #333; }
            .table th, table th { color: #555; border-top-color: #ddd; }
            .table tbody tr:nth-child(odd), table tbody tr:nth-child(odd) { background: rgba(0,0,0,.03); }
            .table tbody tr:hover, table tbody tr:hover { background: rgba(0,0,0,.06); }
            hr { border-top-color: #ddd; }
            small { color: #777; }
            .recovery-global-nav { border-bottom-color: #ddd; }
            .recovery-nav-link { color: #369; }
        }
        .recovery-global-nav {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #444444;
        }
        .recovery-nav-link {
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .recovery-nav-link:hover { text-decoration: underline; }
        .recovery-nav-acp { margin-left: auto; }
        .recovery-top-actions { text-align: right; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
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
<footer>
    <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank" rel="noopener">Plugin Recovery Tool</a> &copy; <?= \date('Y') ?> Sunny C.
    <?php if ($baseUrl !== ''): ?>
    | <a href="<?= \htmlspecialchars($baseUrl) ?>">Installation</a>
    <?php endif; ?>
    | <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">WoltLab Recovery</a>
</footer>
</body>
</html>
<?php
}

function recoveryRenderBackLink(string $href): void
{
    echo '<a href="' . \htmlspecialchars($href) . '" class="back-link">&#8592; Zurück zur Auswahl</a>';
}

function recoveryRenderGlobalNav(int $mode, string $authHash, string $baseUrl): void
{
    $acpUrl = $baseUrl . 'acp/';
    echo '<nav class="recovery-global-nav" aria-label="Recovery-Navigation">';
    if ($mode !== RECOVERY_MODE_SELECTION) {
        echo '<a href="?t=' . \htmlspecialchars($authHash) . '" class="recovery-nav-link">&#8592; Zurück zur Modus-Auswahl</a>';
    }
    echo '<a href="' . \htmlspecialchars($acpUrl) . '" class="recovery-nav-link recovery-nav-acp">&#8594; Zum ACP</a>';
    echo '</nav>';
}

function recoveryRenderAcpSuccessLink(string $baseUrl, string $label = '→ Zum ACP'): void
{
    echo '<br><a href="' . \htmlspecialchars($baseUrl . 'acp/') . '" class="button btn-success">'
        . \htmlspecialchars($label) . '</a>';
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

// Auth-Datei prüfen (fester Dateiname in __DIR__, kein Benutzer-Pfad)
$isAuthenticated = false;
$authFilePath = __DIR__ . '/plugin-recovery-auth.php';
if (\is_file($authFilePath) && \is_readable($authFilePath)) {
    $authFileContent = \file_get_contents($authFilePath);
    if ($authFileContent !== false) {
        $lines = \explode("\n", $authFileContent);

        if (\count($lines) >= 3) {
            $expiresTimestamp = (int) $lines[1];
            $storedHash = \trim($lines[2]);

            if ($expiresTimestamp > \time() && \hash_equals($storedHash, $authHash)) {
                $isAuthenticated = true;
            }
        }
    }
}

// Cleanup-Action: Hilfsdateien löschen, Tool per Shutdown entfernen, Weiterleitung ins ACP
if ($action === 'cleanup') {
    $cleanupAcpUrl = recoveryGetSiteBaseUrl() . 'acp/';
    cleanupRecoveryAuxiliaryFiles();
    \register_shutdown_function(static function (): void {
        @\unlink(__DIR__ . '/plugin-recovery-tool.php');
    });
    \header('Location: ' . $cleanupAcpUrl);
    exit;
}

// Auth-Status (JSON) für Auto-Fortsetzung nach Upload der Auth-Datei
if ($action === 'auth-status') {
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode(['ok' => $isAuthenticated]);
    exit;
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
 * Entfernt unsichere Pfade nach dem Entpacken (Path-Traversal).
 */
function recoverySanitizeExtractedArchive(string $destination): void
{
    if (!\is_dir($destination)) {
        return;
    }

    $baseReal = \realpath($destination);
    if ($baseReal === false) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($destination, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        $pathname = $file->getPathname();
        $relative = \ltrim(\str_replace('\\', '/', \substr($pathname, \strlen($baseReal))), '/');
        if (recoveryIsUnsafeArchiveRelativePath($relative)) {
            $file->isDir() ? @\rmdir($pathname) : @\unlink($pathname);
        }
    }
}

/**
 * Entpackt TAR/TAR.GZ Archive (mit nachträglicher Pfad-Validierung).
 */
function extractArchive($archivePath, $destination) {
    if (!\is_file($archivePath) || !recoveryValidateArchiveFilename(\basename($archivePath))) {
        return false;
    }

    try {
        if (!\is_dir($destination) && !@\mkdir($destination, 0755, true)) {
            return false;
        }

        $phar = new \PharData($archivePath);
        $phar->extractTo($destination, null, true);
        recoverySanitizeExtractedArchive($destination);

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Findet alle Tabellen eines Plugins anhand des Präfixes
 */
function findPackageTables($db, $packageIdentifier, $wcfN = null) {
    try {
        $packageIdentifier = recoveryValidatePackageIdentifier($packageIdentifier);
    } catch (\InvalidArgumentException) {
        return [];
    }

    $parts = \explode('.', $packageIdentifier);
    $appNames = [];
    if (\count($parts) >= 2) {
        $appNames[] = $parts[\count($parts) - 2];
    }
    $appNames[] = (string) \end($parts);
    $appNames = \array_values(\array_unique(\array_filter($appNames)));

    $sql = 'SHOW TABLES';
    $statement = $db->prepareStatement($sql);
    $statement->execute();

    $tables = [];
    $allBaseTables = [];
    if ($wcfN !== null && $wcfN >= 1 && $wcfN <= 99) {
        $allBaseTables = getBasePluginTables((int) $wcfN);
    } else {
        for ($n = 1; $n <= 10; $n++) {
            $allBaseTables = \array_merge($allBaseTables, getBasePluginTables($n));
        }
    }
    $allBaseTables = \array_unique($allBaseTables);

    while ($row = $statement->fetchArray()) {
        $tableName = (string) \reset($row);
        if (!recoveryValidateSqlTableName($tableName)) {
            continue;
        }

        if (\in_array($tableName, $allBaseTables, true)) {
            continue;
        }

        foreach ($appNames as $appName) {
            if ($appName === '' || !\preg_match('/^[a-zA-Z0-9._-]+$/', $appName)) {
                continue;
            }

            if (\preg_match('/^' . \preg_quote($appName, '/') . '\d+_/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            if (\preg_match('/^' . \preg_quote($appName, '/') . '_/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            if (\preg_match('/^' . \preg_quote($appName, '/') . '\d+/i', $tableName)) {
                $tables[] = $tableName;
                break;
            }
            if (\stripos($tableName, $appName) === 0) {
                $tables[] = $tableName;
                break;
            }
        }
    }

    return \array_values(\array_unique($tables));
}

/**
 * Löscht kompilierte Templates und Datei-Caches per Filesystem (ohne WCF/CacheHandler).
 */
function clearCompiledTemplates(): int
{
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $wcfRoot = \rtrim(WCF_DIR, '/\\') . '/';
    $dirs = [
        $wcfRoot . 'tmp',
        $wcfRoot . 'cache',
        $wcfRoot . 'templates/compiled',
        $wcfRoot . 'acp/templates/compiled',
    ];

    $deletedFiles = 0;
    foreach ($dirs as $dir) {
        if (!\is_dir($dir)) {
            continue;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDir()) {
                @\rmdir($fileinfo->getRealPath());
            } else {
                @\unlink($fileinfo->getRealPath());
            }
            $deletedFiles++;
        }
    }

    return $deletedFiles;
}

/**
 * Löscht Recovery-Hilfsdateien (nicht plugin-recovery-tool.php – das erfolgt per Shutdown).
 */
function cleanupRecoveryAuxiliaryFiles(): void
{
    $files = [
        __DIR__ . '/plugin-recovery.php',
        __DIR__ . '/universal-recovery.php',
        __DIR__ . '/acp-repair.php',
        __DIR__ . '/wsc-recovery.php',
        __DIR__ . '/recovery-tool.php',
        __DIR__ . '/plugin-recovery-auth.php',
        __DIR__ . '/uploads',
    ];

    foreach ($files as $file) {
        if (\is_file($file)) {
            @\unlink($file);
        } elseif (\is_dir($file)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($file, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @\rmdir($f) : @\unlink($f);
            }
            @\rmdir($file);
        }
    }
}

/** @deprecated Verwende cleanupRecoveryAuxiliaryFiles() */
function cleanupRecoveryFiles(): void
{
    cleanupRecoveryAuxiliaryFiles();
    @\unlink(__DIR__ . '/plugin-recovery-tool.php');
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
    echo '<div class="alert alert-info"><strong>Gefundene Ressourcen aus Package-Datei:</strong><br>';
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

    echo '</div>';
}

recoveryRenderPageStart('Plugin Recovery Tool', 'Plugin Recovery Tool');

// Auth-Screen anzeigen wenn nicht authentifiziert
if (!$isAuthenticated) {
?>
    <h1>Plugin Recovery Tool</h1>
    <p class="subtitle">Authentifizierung erforderlich</p>

    <div class="alert alert-warning">
        <strong>Schritt 1:</strong> Auth-Datei herunterladen<br>
        <a href="?action=download-auth-file&amp;t=<?= htmlspecialchars($authHash) ?>" class="button" style="display: inline-block; margin-top: 10px;" id="downloadBtn">
            plugin-recovery-auth.php herunterladen
        </a>
    </div>

    <div class="alert alert-info" style="margin-top: 20px;" id="step2" style="display: none;">
        <strong>Schritt 2:</strong> Datei hochladen<br>
        Laden Sie die heruntergeladene Datei <code><?= htmlspecialchars($authFilename) ?></code> in dasselbe Verzeichnis hoch,
        in dem sich diese <code>plugin-recovery-tool.php</code> befindet.
    </div>

    <div class="alert alert-info" style="margin-top: 20px;" id="step3" style="display: none;">
        <strong>Schritt 3:</strong> Recovery starten<br>
        <a href="?t=<?= htmlspecialchars($authHash) ?>&amp;auth_ok=1" class="button btn-success" style="margin-top: 10px;">
            Recovery Tool starten
        </a>
    </div>

    <div class="alert alert-error" style="margin-top: 30px;">
        <strong>Sicherheitshinweis:</strong><br>
        Löschen Sie beide Dateien (<code>plugin-recovery-tool.php</code> und <code><?= htmlspecialchars($authFilename) ?></code>)
        nach der Verwendung. Diese Dateien können ein Sicherheitsrisiko darstellen!
    </div>

    <script>
    document.getElementById('downloadBtn').addEventListener('click', function() {
        setTimeout(function() {
            document.getElementById('step2').style.display = 'block';
            document.getElementById('step3').style.display = 'block';
            startAuthPolling();
        }, 500);
    });
    function startAuthPolling() {
        var token = new URLSearchParams(window.location.search).get('t');
        if (!token) return;
        var interval = setInterval(function() {
            fetch('?action=auth-status&t=' + encodeURIComponent(token))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        clearInterval(interval);
                        window.location.href = '?t=' + encodeURIComponent(token) + '&auth_ok=1';
                    }
                })
                .catch(function() {});
        }, 2000);
    }
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
    echo '<div class="alert alert-error"><strong>Bootstrap-Fehler:</strong> '
        . \nl2br(\htmlspecialchars(recoveryFormatUserError($e))) . '</div>';
    recoveryRenderExceptionDetails($e);
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

recoveryRenderGlobalNav($mode, $authHash, $recoveryBaseUrl);

?>

<?php
// ============================================================================
// MODUS 0: AUSWAHL
// ============================================================================

if ($mode === RECOVERY_MODE_SELECTION) {
?>
    <?php if (isset($_GET['auth_ok'])): ?>
    <div class="alert alert-success"><strong>Authentifizierung erfolgreich.</strong> Sie können jetzt einen Recovery-Modus wählen.</div>
    <?php endif; ?>

    <div class="recovery-top-actions">
        <a href="<?= htmlspecialchars($recoveryBaseUrl) ?>acp/" class="button">&#8594; Zum ACP</a>
    </div>

    <h1>WoltLab Suite Recovery Tool</h1>
    <p class="subtitle">Wählen Sie den gewünschten Recovery-Modus</p>

    <div class="mode-grid">
        <a href="?mode=<?= RECOVERY_MODE_ACP_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <strong>ACP Repair</strong>
            <span>Repariert defekte ACP-Menüeinträge eines Plugins</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PLUGIN_UNINSTALL ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <strong>Plugin Uninstall</strong>
            <span>Deinstalliert Plugin komplett (DB + Dateien)</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_USER_MANAGEMENT ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <strong>User Management</strong>
            <span>Admin-Passwort zurücksetzen &amp; Berechtigungen</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_CACHE_CLEAR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <strong>Cache Clear</strong>
            <span>Löscht alle Caches &amp; kompilierte Templates</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PACKAGE_LIST_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <strong>Paketliste reparieren</strong>
            <span>Entfernt verwaiste Queue-/Application-Einträge (ACP-Paketliste)</span>
        </a>
    </div>

    <div class="alert alert-info">
        <strong>Hinweis:</strong> Dieses Tool arbeitet direkt auf Datenbank-Ebene und sollte nur im Notfall verwendet werden.
    </div>

    <div class="alert alert-warning" style="margin-top: 30px;">
        <strong>Fertig mit Recovery?</strong><br>
        Wenn Sie alle Reparaturen abgeschlossen haben, sollten Sie das Recovery Tool und alle zugehörigen Dateien löschen.<br><br>
        <a href="?action=cleanup&amp;t=<?= htmlspecialchars($authHash) ?>" class="button btn-danger" onclick="return confirm('ACHTUNG: Das Recovery Tool wird entfernt (Auth-Datei, Uploads, diese PHP-Datei) und Sie werden ins ACP weitergeleitet. Fortfahren?')">
            Recovery Tool vollständig entfernen
        </a>
    </div>

<?php
}

elseif ($mode === RECOVERY_MODE_ACP_REPAIR) {
?>
    <h1>ACP Repair</h1>
    <p class="subtitle">Repariert defekte ACP-Menüeinträge eines Plugins</p>

<?php
    // Schritt 1: Package-Identifier eingeben oder hochladen
    if (!isset($_POST['package_identifier']) && !isset($_FILES['package_file'])) {
?>
    <form method="POST">
        <div class="form-group">
            <label>Option 1: Package-Identifier manuell eingeben</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.example.my-plugin" autocomplete="off">
            <small style="display: block; margin-top: 5px;">
                Der eindeutige Bezeichner des Plugins, dessen ACP-Menüeinträge repariert werden sollen.
            </small>
        </div>
        <button type="submit">Mit Identifier reparieren</button>
    </form>

    <hr>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar, .tar.gz, .tgz – max. 100 MiB)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz">
        </div>
        <button type="submit">Mit Datei reparieren</button>
    </form>
<?php
    } else {
        $packageInput = recoveryResolvePackageInputFromRequest();
        if (isset($packageInput['error'])) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars($packageInput['error']) . '</div>';
        }

        $packageIdentifier = $packageInput['packageIdentifier'] ?? null;
        $extractDir = $packageInput['extractDir'] ?? recoveryResolveTrustedExtractDir();

        if ($packageIdentifier) {
            $resources = null;
            if ($extractDir && \is_dir($extractDir)) {
                $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                if ($resources && !empty($resources['acpMenu']['prefix'])) {
                    displayResourcePreview($resources, $resources['wcfN'], $packageIdentifier);
                }
            }

            // Package suchen
            $sql = "SELECT packageID, package, packageName, packageDir, isApplication
                    FROM wcf" . WCF_N . "_package
                    WHERE package = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageIdentifier]);
            $packageData = $statement->fetchArray();

            $wcfN = $resources ? (int) $resources['wcfN'] : WCF_N;

            if (!$packageData && !isset($_POST['force_cleanup'])) {
                $menuItems = recoveryFetchAcpMenuItemsForPackage($db, $wcfN, $packageIdentifier, null, $resources);
                $foundPatterns = recoveryInferAcpMenuSearchPatterns($packageIdentifier, $resources);
                $menuCount = \count($menuItems);

                echo '<div class="alert alert-info"><strong>Warnung:</strong> Plugin nicht in Datenbank gefunden.<br>';
                echo 'Dies kann bedeuten, dass die Installation fehlgeschlagen ist.<br><br>';

                if ($menuCount > 0) {
                    echo '<strong>Gefundene ACP-Menüeinträge (' . $menuCount . '):</strong><br>';
                    if ($foundPatterns !== []) {
                        echo '<small>Suchmuster: ' . \htmlspecialchars(\implode(', ', $foundPatterns)) . '</small><br><br>';
                    }
                    echo '<table><thead><tr><th>Menu Item</th><th>Controller</th></tr></thead><tbody>';
                    foreach ($menuItems as $item) {
                        echo '<tr><td>' . \htmlspecialchars($item['menuItem']) . '</td>';
                        echo '<td>' . \htmlspecialchars($item['menuItemController'] ?: '-') . '</td></tr>';
                    }
                    echo '</tbody></table><br>';
                    echo '<form method="POST">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    echo '<input type="hidden" name="force_cleanup" value="1">';
                    echo '<button type="submit" class="btn-danger">Diese ' . $menuCount . ' Menüeinträge löschen</button>';
                    echo '</form>';
                } else {
                    echo '<strong>Keine ACP-Menüeinträge mit den ermittelten Mustern gefunden.</strong><br>';
                    echo 'Es gibt nichts zu bereinigen.';
                }
                echo '</div>';
            } else {
                $menuItems = recoveryFetchAcpMenuItemsForPackage($db, $wcfN, $packageIdentifier, $packageData ?: null, $resources);

                if (empty($menuItems)) {
                    echo '<div class="alert alert-info">';
                    echo '<strong>Keine ACP-Menüeinträge gefunden</strong><br>';
                    echo 'Für dieses Plugin existieren keine ACP-Menüeinträge in der Datenbank.';
                    echo '</div>';
                } elseif (!isset($_POST['confirm_delete'])) {
                    echo '<div class="alert alert-info">';
                    echo '<strong>Gefundene ACP-Menüeinträge (' . \count($menuItems) . '):</strong>';
                    echo '<table><thead><tr><th>Menu Item</th><th>Controller</th></tr></thead><tbody>';
                    foreach ($menuItems as $item) {
                        echo '<tr><td>' . \htmlspecialchars($item['menuItem']) . '</td>';
                        echo '<td>' . \htmlspecialchars($item['menuItemController'] ?: '-') . '</td></tr>';
                    }
                    echo '</tbody></table>';
                    echo '<form method="POST">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    if (!$packageData) {
                        echo '<input type="hidden" name="force_cleanup" value="1">';
                    }
                    echo '<input type="hidden" name="confirm_delete" value="1">';
                    echo '<button type="submit" class="btn-danger">Alle löschen</button>';
                    echo '</form>';
                    echo '</div>';
                } else {
                    $extractDir = recoveryResolveTrustedExtractDir();
                    if ($extractDir && \is_dir($extractDir)) {
                        $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                    }
                    $wcfN = $resources ? (int) $resources['wcfN'] : WCF_N;

                    $db->beginTransaction();
                    try {
                        $deletedCount = recoveryDeleteAcpMenuItemsForPackage(
                            $db,
                            $wcfN,
                            $packageIdentifier,
                            $packageData ?: null,
                            $resources
                        );
                        clearCompiledTemplates();
                        $db->commitTransaction();
                        recoveryCleanupUploadWorkspace();

                        echo '<div class="alert alert-success">';
                        echo '<strong>✓ ACP-Repair erfolgreich!</strong><br>';
                        echo 'Gelöschte Menüeinträge: ' . $deletedCount . '<br>';
                        echo 'Cache wurde geleert.<br><br>';
                        recoveryRenderAcpSuccessLink($recoveryBaseUrl, '→ Zum ACP');
                        echo '</div>';
                    } catch (\Throwable $e) {
                        recoverySafeRollBackTransaction($db);
                        echo '<div class="alert alert-error">';
                        echo '<strong>Fehler:</strong> ' . \nl2br(\htmlspecialchars(recoveryFormatUserError($e)));
                        recoveryRenderExceptionDetails($e);
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
    <h1>Plugin Uninstall</h1>
    <p class="subtitle">Deinstalliert Plugin komplett aus Datenbank und Dateisystem</p>

<?php
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
            <input type="text" name="package_identifier" placeholder="z.B. de.example.my-plugin" autocomplete="off">
        </div>
        <button type="submit">Mit Identifier deinstallieren</button>
    </form>

    <hr>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar oder .tar.gz)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz">
        </div>
        <button type="submit">Mit Datei deinstallieren</button>
    </form>
<?php
    } else {
        $packageInput = recoveryResolvePackageInputFromRequest();
        if (isset($packageInput['error'])) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars($packageInput['error']) . '</div>';
        }

        $packageIdentifier = $packageInput['packageIdentifier'] ?? null;
        $extractDir = $packageInput['extractDir'] ?? recoveryResolveTrustedExtractDir();

        if ($packageIdentifier) {
            // Package in DB suchen
            $sql = "SELECT packageID, package, packageName, packageDir, isApplication
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

            $resources = null;
            if ($extractDir && \is_dir($extractDir)) {
                $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
            }

            if ($packageData) {
                recoveryDisplayDbCleanupPreview($db, WCF_N, $packageData, $packageIdentifier, $extractDir);
            }

            if ($resources) {
                    displayResourcePreview($resources, $resources['wcfN'], $packageIdentifier);
                    
                    // SQL anzeigen Button
                    if (isset($_GET['show_sql'])) {
                        echo '<div class="alert alert-info"><strong>SQL Cleanup Script:</strong><br>';
                        echo '<pre class="recoveryLog">' . htmlspecialchars(generateCleanupSql($resources, $resources['wcfN'])) . '</pre>';
                        echo '</div>';
                    } else {
                        $extractDirParam = $extractDir ? '&extract_dir=' . urlencode($extractDir) : '';
                        echo '<a href="?mode=' . RECOVERY_MODE_PLUGIN_UNINSTALL . '&t=' . $authHash . '&show_sql=1&package_identifier=' . urlencode($packageIdentifier) . $extractDirParam . '" class="button">SQL anzeigen</a>';
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
                echo '<div class="alert alert-info">';
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

                $fsEval = recoveryEvaluatePluginDirectoryDeletion(
                    $packageData ?: null,
                    $packageIdentifier,
                    $db,
                    $resources ? (int) $resources['wcfN'] : WCF_N,
                    $extractDir
                );
                echo '<br><strong>Dateisystem:</strong> ' . \htmlspecialchars($fsEval['reason']) . '<br>';

                echo '<br><form method="POST">';
                echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                if ($extractDir) {
                    echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                }
                echo '<input type="hidden" name="confirm_uninstall" value="1">';
                if ($fsEval['deletable']) {
                    echo '<label style="display:block;margin:12px 0;">';
                    echo '<input type="checkbox" name="confirm_delete_files" value="1"> ';
                    echo 'Plugin-Verzeichnis <code>' . \htmlspecialchars((string) $fsEval['relativePath']) . '/</code> auf dem Server löschen';
                    echo '</label>';
                }
                echo '<button type="submit" class="btn-danger">Datenbank bereinigen</button>';
                echo '</form>';
                echo '</div>';

            } else {
                $extractDir = recoveryResolveTrustedExtractDir();
                if ($extractDir && \is_dir($extractDir) && !$resources) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                }

                $deleteFilesOnDisk = isset($_POST['confirm_delete_files']) && $_POST['confirm_delete_files'] === '1';

                try {
                    $log = [];
                    $wcfN = $resources ? (int) $resources['wcfN'] : WCF_N;

                    recoveryPerformFullPluginCleanup(
                        $db,
                        $wcfN,
                        $packageIdentifier,
                        $packageData ?: null,
                        $resources,
                        $log,
                        $deleteFilesOnDisk,
                        $extractDir
                    );

                    $deletedFiles = clearCompiledTemplates();
                    $log[] = 'Cache gelöscht: ' . $deletedFiles . ' Dateien';
                    recoveryCleanupUploadWorkspace();

                    echo '<div class="alert alert-success">';
                    echo '<strong>✓ Plugin-Bereinigung abgeschlossen!</strong><br><br>';
                    echo '<strong>Durchgeführte Aktionen:</strong><br>';
                    foreach ($log as $entry) {
                        echo '• ' . \htmlspecialchars($entry) . '<br>';
                    }
                    recoveryRenderAcpSuccessLink($recoveryBaseUrl);
                    echo '</div>';

                } catch (\Throwable $e) {
                    echo '<div class="alert alert-error">';
                    echo '<strong>Fehler bei Deinstallation:</strong><br>';
                    echo \nl2br(\htmlspecialchars(recoveryFormatUserError($e)));
                    recoveryRenderExceptionDetails($e);
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
    <h1>User Management</h1>
    <p class="subtitle">Für User-Management nutzen Sie das offizielle WoltLab Recovery Tool</p>

    <div class="alert alert-info">
        <strong>WoltLab Recovery Tool (wsc-recovery.php)</strong><br><br>
        Für Admin-Passwort-Reset und Benutzer-Management verwenden Sie bitte das offizielle Tool von WoltLab:<br><br>
        <strong>Download &amp; Anleitung:</strong><br>
        <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">manual.woltlab.com/de/recovery-tool/</a><br><br>
        <strong>Funktionen des WoltLab Tools:</strong><br>
        Admin-Passwort zurücksetzen<br>
        Benutzer zu Administrator-Gruppe hinzufügen<br>
        E-Mail-Adressen ändern<br>
        Benutzer aktivieren/deaktivieren
    </div>

<?php
}

// ============================================================================
// MODUS 4: CACHE CLEAR
// ============================================================================

elseif ($mode === RECOVERY_MODE_CACHE_CLEAR) {
?>
    <h1>Cache Clear</h1>
    <p class="subtitle">Löscht alle Caches und kompilierte Templates</p>

<?php
    if (!isset($_POST['confirm_clear'])) {
?>
    <div class="alert alert-warning">
        <strong>Folgende Verzeichnisse werden geleert:</strong><br>
        tmp/<br>
        cache/<br>
        templates/compiled/<br>
        acp/templates/compiled/
    </div>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <button type="submit" class="btn-danger">Cache jetzt löschen</button>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();

        echo '<div class="alert alert-success">';
        echo '<strong>Cache erfolgreich geleert.</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br><br>';
        recoveryRenderAcpSuccessLink($recoveryBaseUrl);
        echo '</div>';
    }
}

// ============================================================================
// MODUS 5: PAKETLISTE REPARIEREN
// ============================================================================

elseif ($mode === RECOVERY_MODE_PACKAGE_LIST_REPAIR) {
?>
    <h1>Paketliste reparieren</h1>
    <p class="subtitle">Entfernt verwaiste Datenbankeinträge, die die ACP-Paketliste oder Deinstallation blockieren</p>

<?php
    $orphanSql = recoveryGenerateOrphanRepairSql(WCF_N);

    if (!isset($_POST['confirm_repair'])) {
?>
    <div class="alert alert-warning">
        <strong>Typische Symptome:</strong><br>
        • ACP-Paketliste: <code>Attempt to read property "packageID" on null</code><br>
        • Deinstallation: <code>assert($package !== null)</code> bei hängender Queue<br><br>
        <strong>Bereinigt (generisch, alle Plugins):</strong> verwaiste <code>application</code>-Zeilen,
        <code>package_installation_queue</code> / <code>node</code> / <code>form</code>,
        <code>package_requirements</code>, <code>package_exclusion</code>, File-Logs.
    </div>

    <details style="margin: 1rem 0;">
        <summary>SQL für phpMyAdmin / manuelle Ausführung (WCF_N=<?= (int) WCF_N ?>)</summary>
        <pre class="recoveryLog"><?= \htmlspecialchars($orphanSql) ?></pre>
    </details>

    <form method="POST">
        <input type="hidden" name="confirm_repair" value="1">
        <button type="submit" class="btn-danger">Verwaiste Einträge jetzt bereinigen</button>
    </form>
<?php
    } else {
        try {
            $result = recoveryRepairOrphanedPackageReferences($db, WCF_N);
            $deletedFiles = clearCompiledTemplates();

            echo '<div class="alert alert-success">';
            echo '<strong>Paketliste-Reparatur abgeschlossen.</strong><br><br>';
            foreach ($result['log'] as $entry) {
                echo '• ' . \htmlspecialchars($entry) . '<br>';
            }
            echo '<br>Cache-Dateien gelöscht: ' . (int) $deletedFiles . '<br>';
            recoveryRenderAcpSuccessLink($recoveryBaseUrl, '→ ACP-Paketliste testen');
            echo '</div>';
        } catch (\Throwable $e) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars(recoveryFormatUserError($e)) . '</div>';
            recoveryRenderExceptionDetails($e);
        }
    }
}

?>
<?php
recoveryRenderPageEnd();
