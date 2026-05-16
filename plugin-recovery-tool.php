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
 * @version 1.5.22
 * @requires PHP >= 8.1 (wie WoltLab Suite 6.x; kein künstliches 8.3-Minimum)
 *
 * Eine Datei: ins WoltLab-Hauptverzeichnis legen (neben global.php).
 * Universelles Recovery nach Stressoren wie kaputter Installation: DB gemäß WoltLab-PIP-Zuordnung,
 * Cache/Pfade aller Apps, Option-Konstanten-Fallback für sämtliche Plugins (nicht nur einzelne Pakete).
 */

// ============================================================================
// KONFIGURATION
// ============================================================================

define('RECOVERY_VERSION', '1.5.22');
define('RECOVERY_DEBUG_LOG_PREFIX', 'recovery-tool-');
define('RECOVERY_MIN_PHP_VERSION', '8.1.0');

if (\PHP_VERSION_ID < 80100) {
    \header('Content-Type: text/html; charset=utf-8');
    \http_response_code(500);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Recovery Tool</title></head><body>';
    echo '<h1>PHP-Version zu alt</h1>';
    echo '<p>Dieses Recovery Tool benötigt mindestens <strong>PHP 8.1</strong> (wie WoltLab Suite 6.x).</p>';
    echo '<p>Aktuell: <code>' . \htmlspecialchars(\PHP_VERSION) . '</code></p>';
    echo '<p>Bitte PHP beim Hoster auf 8.1 oder neuer stellen.</p>';
    echo '</body></html>';
    exit;
}

// #region agent log
/**
 * NDJSON-Debug: optional RECOVERY_AGENT_LOG_PATH, sonst WoltLab log/ (recovery-tool-YYYY-MM-DD.ndjson).
 * Vor WCF-Auflösung: __DIR__/log/, danach sys_get_temp_dir().
 */
function recoveryAgentDebugLogBasename(): string
{
    return RECOVERY_DEBUG_LOG_PREFIX . \date('Y-m-d') . '.ndjson';
}

function recoveryEnsureLogDirectory(string $dir): bool
{
    $dir = \rtrim(\str_replace('\\', '/', $dir), '/') . '/';
    if ($dir === '/') {
        return false;
    }
    if (\is_dir($dir)) {
        return @\is_writable($dir);
    }

    return @\mkdir($dir, 0775, true) || \is_dir($dir);
}

/**
 * WCF-Hauptverzeichnis nur für Log-Pfad (ohne Exception, ohne Bootstrap).
 */
function recoveryResolveWcfDirForLogging(): ?string
{
    static $cached = false;
    static $result = null;
    if ($cached) {
        return $result;
    }
    $cached = true;

    if (\defined('WCF_DIR')) {
        return $result = \rtrim((string) \constant('WCF_DIR'), '/\\') . '/';
    }

    foreach ([__DIR__, \dirname(__DIR__), \dirname(__DIR__, 2)] as $dir) {
        if (\is_file($dir . '/global.php') && \is_file($dir . '/config.inc.php')) {
            return $result = \rtrim($dir, '/') . '/';
        }
    }

    return $result = null;
}

function recoveryAgentDebugLogPath(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $fromEnv = \getenv('RECOVERY_AGENT_LOG_PATH');
    if (\is_string($fromEnv) && $fromEnv !== '') {
        return $resolved = $fromEnv;
    }

    $basename = recoveryAgentDebugLogBasename();

    $wcfDir = recoveryResolveWcfDirForLogging();
    if ($wcfDir !== null) {
        $logDir = \rtrim($wcfDir, '/\\') . '/log/';
        if (recoveryEnsureLogDirectory($logDir)) {
            return $resolved = $logDir . $basename;
        }
    }

    $besideLogDir = __DIR__ . '/log/';
    if (recoveryEnsureLogDirectory($besideLogDir)) {
        return $resolved = $besideLogDir . $basename;
    }

    $tmp = \rtrim((string) \sys_get_temp_dir(), \DIRECTORY_SEPARATOR)
        . \DIRECTORY_SEPARATOR . RECOVERY_DEBUG_LOG_PREFIX
        . \substr(\sha1(__DIR__), 0, 16) . '_' . \date('Y-m-d') . '.ndjson';

    return $resolved = $tmp;
}

function recoveryAgentExposeDebugHeaders(): void
{
    static $sent = false;
    if ($sent || \headers_sent()) {
        return;
    }
    $sent = true;
    \header('X-WFL-Recovery-Agent-Log-B64: ' . \base64_encode(recoveryAgentDebugLogPath()));
}

/** @internal NDJSON-Zeilen für gezieltes Debugging (Hypothesen / Fatals). */
function recoveryAgentDebugLog(string $hypothesisId, string $location, string $message, array $data = []): void
{
    recoveryAgentExposeDebugHeaders();
    $path = recoveryAgentDebugLogPath();
    $payload = [
        'sessionId' => '54d5f7',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) \round(\microtime(true) * 1000),
        'runId' => $_SERVER['RECOVERY_DEBUG_RUN'] ?? 'pre-fix',
    ];
    $line = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        @\error_log('[wfl-recovery-agent] ndjson_encode_failed');

        return;
    }
    $wrote = @\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX);
    if ($wrote === false) {
        @\error_log('[wfl-recovery-agent] ndjson_write_failed path=' . $path);
    }
}

\register_shutdown_function(static function (): void {
    $path = recoveryAgentDebugLogPath();
    $e = \error_get_last();
    if ($e === null) {
        return;
    }
    $fatalTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR];
    if (!\in_array((int) $e['type'], $fatalTypes, true)) {
        return;
    }
    @\error_log('[wfl-recovery-agent] fatal type=' . $e['type'] . ' msg=' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    $line = \json_encode([
        'sessionId' => '54d5f7',
        'hypothesisId' => 'H-FATAL',
        'location' => 'shutdown',
        'message' => 'php_fatal',
        'data' => [
            'type' => $e['type'],
            'message' => $e['message'],
            'file' => $e['file'],
            'line' => $e['line'],
        ],
        'timestamp' => (int) \round(\microtime(true) * 1000),
        'runId' => $_SERVER['RECOVERY_DEBUG_RUN'] ?? 'pre-fix',
    ], \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line !== false) {
        @\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }
});
recoveryAgentDebugLog('H1', 'tool:boot', 'php_version_gate_passed', [
    'phpVersion' => \PHP_VERSION,
    'sapi' => \PHP_SAPI,
    'debugLogPath' => recoveryAgentDebugLogPath(),
]);
@\error_log('[wfl-recovery-agent] ndjson_path=' . recoveryAgentDebugLogPath());
\set_exception_handler(static function (\Throwable $e): void {
    recoveryAgentDebugLog('H-EXCEPTION', 'tool:uncaught', 'uncaught_exception', [
        'class' => \get_class($e),
        'message' => $e->getMessage(),
        'file' => \basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    @\error_log('[wfl-recovery-agent] uncaught ' . \get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $logHint = 'log/' . recoveryAgentDebugLogBasename();
    if (!\headers_sent()) {
        \http_response_code(500);
        \header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Recovery Tool – Fehler</title></head><body>';
        echo '<h1>Recovery Tool – interner Fehler</h1>';
        echo '<p>Es ist ein unerwarteter Fehler aufgetreten. Details stehen in der Debug-Logdatei im WoltLab-Verzeichnis:</p>';
        echo '<p><code>' . \htmlspecialchars($logHint, \ENT_QUOTES, 'UTF-8') . '</code></p>';
        echo '<p>Zusätzlich: Host-<code>error_log</code> nach „wfl-recovery-agent“ durchsuchen.</p>';
        echo '</body></html>';
    }
    exit(1);
});
// #endregion

define('RECOVERY_MODE_SELECTION', 0);
define('RECOVERY_MODE_ACP_REPAIR', 1);
define('RECOVERY_MODE_PLUGIN_UNINSTALL', 2);
define('RECOVERY_MODE_USER_MANAGEMENT', 3);
define('RECOVERY_MODE_CACHE_CLEAR', 4);
define('RECOVERY_MODE_PACKAGE_LIST_REPAIR', 5);
define('RECOVERY_MODE_PACKAGE_FILE_REPAIR', 6);
define('RECOVERY_MODE_RECOVERY_WIZARD', 7);

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

    // Vor options.inc.php: verhindert Aufruf von wcf\getRequestId() ohne core.functions.php (NO_IMPORTS).
    if (!\defined('ENABLE_PRODUCTION_DEBUG_MODE')) {
        \define('ENABLE_PRODUCTION_DEBUG_MODE', false);
    }

    recoveryDefineMinimalWcfConstants();
    recoveryDefineMinimalWcfFunctions();

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
        } catch (\Throwable $ignored) {
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
    // Dots are not valid in WoltLab app directory names (e.g. 'wbb', 'gallery', not 'com.woltlab.wbb')
    return $dir !== '' && (bool) \preg_match('/^[a-zA-Z0-9_-]+$/', $dir);
}

/**
 * @return list<string>
 */
function recoveryGetProtectedDirectoryNames(): array
{
    return [
        // WoltLab core directories
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
        // Additional protected directories (v1.2.7)
        'admin',
        'install',
        'wcfsetup',
        'setup',
        'upload',
        'uploads',
        'files',
        'core',
        'vendor',
    ];
}

/**
 * Files that must never be deleted during plugin cleanup.
 *
 * @return list<string>
 */
function recoveryGetProtectedFileNames(): array
{
    return [
        'global.php',
        'index.php',
        'config.inc.php',
        'options.inc.php',
        'constants.inc.php',
        'composer.json',
        'composer.lock',
        '.htaccess',
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
    } catch (\Throwable $ignored) {
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
        } catch (\Throwable $ignored) {
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
function recoveryResolvePackageInputFromRequest(string $authHash = ''): array
{
    if (recoveryHasUploadedPackageFile()) {
        $upload = recoveryHandlePackageUpload($_FILES['package_file']);

        if (!$upload['ok']) {
            return ['error' => $upload['error'] ?? 'Upload fehlgeschlagen.'];
        }

        $identifier = $upload['packageIdentifier'];
        $extractDir = $upload['extractDir'] ?? null;
        if ($authHash !== '' && $identifier) {
            recoveryStorePackageContext($authHash, $identifier, $extractDir);
        }

        return [
            'packageIdentifier' => $identifier,
            'extractDir' => $extractDir,
        ];
    }

    $raw = null;
    if (isset($_POST['package_identifier']) && \trim((string) $_POST['package_identifier']) !== '') {
        $raw = \trim((string) $_POST['package_identifier']);
    } elseif (isset($_GET['package_identifier']) && \trim((string) $_GET['package_identifier']) !== '') {
        $raw = \trim((string) $_GET['package_identifier']);
    }

    if ($raw !== null && $raw !== '') {
        try {
            $identifier = recoveryValidatePackageIdentifier($raw);
            $extractDir = recoveryResolveTrustedExtractDir();
            if ($authHash !== '') {
                recoveryStorePackageContext($authHash, $identifier, $extractDir);
            }

            return ['packageIdentifier' => $identifier, 'extractDir' => $extractDir];
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    if ($authHash !== '') {
        $ctx = recoveryLoadPackageContext($authHash);
        if ($ctx && !empty($ctx['packageIdentifier'])) {
            return [
                'packageIdentifier' => $ctx['packageIdentifier'],
                'extractDir' => $ctx['extractDir'] ?? recoveryResolveTrustedExtractDir(),
            ];
        }
    }

    return [];
}

function recoveryWasPostTruncated(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST)
        && empty($_FILES)
        && isset($_SERVER['CONTENT_LENGTH'])
        && (int) $_SERVER['CONTENT_LENGTH'] > 0;
}

function recoveryResolveRequestMode(): int
{
    if (isset($_GET['mode'])) {
        return (int) $_GET['mode'];
    }
    if (isset($_POST['mode'])) {
        return (int) $_POST['mode'];
    }

    return RECOVERY_MODE_SELECTION;
}

function recoveryHasUploadedPackageFile(): bool
{
    return isset($_FILES['package_file'])
        && ($_FILES['package_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

function recoveryResolveUninstallStep(): string
{
    if (isset($_POST['uninstall_step'])) {
        return \trim((string) $_POST['uninstall_step']);
    }
    if (isset($_GET['uninstall_step'])) {
        return \trim((string) $_GET['uninstall_step']);
    }

    return '';
}

function recoveryBuildModeUrl(int $mode, string $authHash, array $params = []): string
{
    $query = \array_merge(['mode' => $mode, 't' => $authHash], $params);

    return 'plugin-recovery-tool.php?' . \http_build_query($query);
}

function recoveryEnsureSession(): void
{
    if (\session_status() === PHP_SESSION_NONE) {
        \session_start();
    }
}

function recoveryStorePackageContext(string $authHash, string $packageIdentifier, ?string $extractDir): void
{
    recoveryEnsureSession();
    $_SESSION['recovery_pkg'] ??= [];
    $_SESSION['recovery_pkg'][$authHash] = [
        'packageIdentifier' => $packageIdentifier,
        'extractDir' => $extractDir,
        'savedAt' => \time(),
    ];
}

function recoveryLoadPackageContext(string $authHash): ?array
{
    recoveryEnsureSession();
    $ctx = $_SESSION['recovery_pkg'][$authHash] ?? null;
    if (!$ctx || (\time() - (int) ($ctx['savedAt'] ?? 0)) > 7200) {
        return null;
    }

    if (!empty($ctx['extractDir'])) {
        $uploadBase = \realpath(__DIR__ . '/uploads');
        $extractReal = \realpath((string) $ctx['extractDir']);
        if (
            $uploadBase === false
            || $extractReal === false
            || !\str_starts_with($extractReal, $uploadBase . \DIRECTORY_SEPARATOR)
        ) {
            $ctx['extractDir'] = null;
        } else {
            $ctx['extractDir'] = $extractReal;
        }
    }

    return $ctx;
}

function recoveryRenderPostTruncatedWarning(): void
{
    echo '<div class="alert alert-error"><strong>Upload fehlgeschlagen:</strong> '
        . 'Die Anfrage wurde vom Server abgeschnitten (post_max_size / upload_max_filesize). '
        . 'Bitte kleinere Datei wählen oder die PHP-Limits erhöhen.</div>';
}

function recoveryRenderProcessingError(\Throwable $e): void
{
    echo '<div class="alert alert-error"><strong>Fehler bei der Verarbeitung:</strong> '
        . \nl2br(\htmlspecialchars(recoveryFormatUserError($e))) . '</div>';
    recoveryRenderExceptionDetails($e);
}

function recoveryFormLoadingScript(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<script>
(function () {
    document.querySelectorAll('form[data-recovery-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var container = document.querySelector('.container');
            if (!container) { return; }
            var el = document.getElementById('recovery-loading-overlay');
            if (!el) {
                el = document.createElement('div');
                el.id = 'recovery-loading-overlay';
                el.className = 'recovery-loading';
                container.appendChild(el);
            }
            el.textContent = form.getAttribute('data-recovery-loading') || 'Bitte warten …';
            el.style.display = 'block';
        });
    });
})();
</script>
<?php
}

function recoveryRenderFormModeHiddenFields(int $mode, string $authHash): void
{
    echo '<input type="hidden" name="mode" value="' . (int) $mode . '">';
    echo '<input type="hidden" name="t" value="' . \htmlspecialchars($authHash, ENT_QUOTES, 'UTF-8') . '">';
}

function recoveryAcpShouldShowInputForm(): bool
{
    if (recoveryWasPostTruncated()) {
        return true;
    }
    if (isset($_POST['confirm_delete']) || isset($_POST['force_cleanup'])) {
        return false;
    }
    if (isset($_GET['package_identifier']) && \trim((string) $_GET['package_identifier']) !== '') {
        return false;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    if (recoveryHasUploadedPackageFile()) {
        return false;
    }

    return !isset($_POST['package_identifier']) || \trim((string) $_POST['package_identifier']) === '';
}

function recoveryUninstallShouldShowInputForm(): bool
{
    if (recoveryWasPostTruncated()) {
        return true;
    }
    if (recoveryResolveUninstallStep() !== '') {
        return false;
    }
    if (isset($_GET['package_identifier']) && \trim((string) $_GET['package_identifier']) !== '') {
        return false;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    if (recoveryHasUploadedPackageFile()) {
        return false;
    }

    return !isset($_POST['package_identifier']) || \trim((string) $_POST['package_identifier']) === '';
}

/**
 * POST-Redirect-GET nach erfolgreicher Erstanalyse (vor HTML-Ausgabe).
 */
function recoveryMaybeRedirectUninstallAnalyse(string $authHash): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (recoveryResolveRequestMode() !== RECOVERY_MODE_PLUGIN_UNINSTALL) {
        return;
    }
    if (recoveryResolveUninstallStep() !== '') {
        return;
    }
    if (recoveryWasPostTruncated()) {
        return;
    }
    if (!recoveryHasUploadedPackageFile()
        && (!isset($_POST['package_identifier']) || \trim((string) $_POST['package_identifier']) === '')
    ) {
        return;
    }

    $packageInput = recoveryResolvePackageInputFromRequest($authHash);
    if (isset($packageInput['error']) || empty($packageInput['packageIdentifier'])) {
        return;
    }

    $params = ['package_identifier' => $packageInput['packageIdentifier']];
    if (!empty($packageInput['extractDir'])) {
        $params['extract_dir'] = $packageInput['extractDir'];
    }

    \header('Location: ' . recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash, $params));
    exit;
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

/**
 * Minimaler WCF-Hilfsfunktionen-Stub-Pfad (ohne global.php / core.functions.php).
 * Database::prepareStatement() ruft bei ENABLE_PRODUCTION_DEBUG_MODE \wcf\getRequestId() auf –
 * wird durch frühes ENABLE_PRODUCTION_DEBUG_MODE=false in recoveryBootstrapDatabase() vermieden.
 */
function recoveryDefineMinimalWcfFunctions(): void
{
    if (!\defined('ENABLE_PRODUCTION_DEBUG_MODE')) {
        \define('ENABLE_PRODUCTION_DEBUG_MODE', false);
    }
}

function recoveryRebuildOptionsIncPhp(): bool
{
    try {
        recoveryDefineMinimalWcfConstants();
        recoveryDefineMinimalWcfFunctions();
        require_once WCF_DIR . 'lib/data/option/OptionEditor.class.php';
        \wcf\data\option\OptionEditor::rebuild();

        return true;
    } catch (\Throwable $ignored) {
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

/**
 * WoltLab speichert Optionen als Konstanten (UPPERCASE, Punkt zu Unterstrich). Kompilierte ACP-Templates
 * können diese Konstanten ohne defined()-Check nutzen → PHP 8+: Fatal Error wenn options.inc.php
 * unvollständig oder nach beschädigter Deinstallation ohne Option-Zeilen.
 */
function recoveryOptionNameToConstant(string $optionName): string
{
    return \strtoupper(\str_replace('.', '_', $optionName));
}

/**
 * Ausgabe eines Skalars für \define(, …) ohne abschließendes Semikolon.
 */
function recoveryPhpScalarExpressionForOptionType(string $optionType, string $optionValue): string
{
    $t = \strtolower($optionType);

    if ($t === 'boolean' || \str_ends_with($t, 'boolean')) {
        $on = ($optionValue === '1' || \strtolower($optionValue) === 'true'
            || \strtolower($optionValue) === 'yes' || \strtolower($optionValue) === 'on');

        return $on ? '1' : '0';
    }

    if (\str_contains($t, 'integer') || $t === 'negativeinteger' || \str_contains($t, 'bigint')) {
        return (string) (int) $optionValue;
    }

    if (\str_contains($t, 'float') || $t === 'currency'
        || $t === 'number' || \str_contains($t, 'fraction')) {
        $f = (float) $optionValue;
        if (!\is_finite($f)) {
            return '0.0';
        }

        return \rtrim(\rtrim(\sprintf('%.8F', $f), '0'), '.');
    }

    return \var_export((string) $optionValue, true);
}

/**
 * PHP-Schlüsselwörter und typisches Rauschen in kompilierten Templates (keine Plugin-Option-Konstanten).
 *
 * @return array<string, true>
 */
function recoveryGetCompiledTemplateConstantIgnoreList(): array
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }

    $keys = [
        'ABSTRACT', 'ARRAY', 'AS', 'BREAK', 'CALLABLE', 'CASE', 'CATCH', 'CLASS', 'CLONE', 'CONST',
        'CONTINUE', 'DECLARE', 'DEFAULT', 'DIE', 'DO', 'ECHO', 'ELSE', 'ELSEIF', 'EMPTY', 'ENDDECLARE',
        'ENDFOR', 'ENDFOREACH', 'ENDIF', 'ENDSWITCH', 'ENDWHILE', 'ENUM', 'EVAL', 'EXIT', 'EXTENDS',
        'FALSE', 'FINAL', 'FINALLY', 'FN', 'FOR', 'FOREACH', 'FUNCTION', 'GLOBAL', 'GOTO', 'IF',
        'IMPLEMENTS', 'INCLUDE', 'INCLUDE_ONCE', 'INSTANCEOF', 'INSTEADOF', 'INTERFACE', 'ISSET',
        'ITERABLE', 'LIST', 'MATCH', 'MIXED', 'NAMESPACE', 'NEW', 'NULL', 'OBJECT', 'PARENT',
        'PRINT', 'PRIVATE', 'PROTECTED', 'PUBLIC', 'READONLY', 'REQUIRE', 'REQUIRE_ONCE', 'RESOURCE',
        'RETURN', 'SELF', 'STATIC', 'STRING', 'SWITCH', 'THROW', 'TRAIT', 'TRUE', 'TRY', 'UNSET',
        'USE', 'VAR', 'VOID', 'WHILE', 'YIELD', 'YIELD_FROM',
        'HTML', 'SESSION', 'COOKIE', 'REQUEST', 'RESPONSE', 'TEMPLATE', 'LANGUAGE', 'EXCEPTION',
        'CALLBACK', 'CONTEXT', 'HANDLER', 'LISTENER', 'CONTROLLER', 'ACTION', 'PARAMETER',
        'ATTRIBUTE', 'SANITIZE', 'SANITIZED', 'UNSUPPORTED', 'UNKNOWN', 'DEFAULTS', 'BOOLEAN',
        'DOUBLE', 'INTEGER', 'NUMBER', 'PACKAGE', 'HEADER', 'FOOTER', 'STRINGUTIL', 'ARRAYLIST',
        'BASELINE', 'PIPELINE', 'MIDDLEWARE', 'REDIRECT', 'LOCATION', 'SECURITY', 'SIGNATURE',
        'INTERNAL', 'EXTERNAL', 'PRIMARY', 'SECONDARY', 'OFFSETGET', 'OFFSETSET', 'OFFSETUNSET',
        'SERIALIZE', 'UNSERIALIZE', 'INVOKABLE', 'BACKTRACE', 'FILENAME', 'LINENUMBER',
    ];

    $set = \array_fill_keys($keys, true);

    return $set;
}

/**
 * Einzelner Kennzeichner für PHP define('…') mit möglichen Backslashes im Namen (namespaced Konstanten).
 */
function recoveryPhpSingleQuotedDefineNameLiteral(string $name): string
{
    return "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $name) . "'";
}

/**
 * Erstes Segment OPTION_KONSTANTE → Kleinbuchstaben (z. B. SHRINKR_ACTIVE → shrinkr), nur bei Unterstrich.
 */
function recoveryLeadingPrefixSegmentLowerFromConstant(string $constant): ?string
{
    if (!\str_contains($constant, '_')) {
        return null;
    }
    $seg = \explode('_', $constant, 2)[0];
    if ($seg === '' || !\preg_match('/^[A-Z][A-Z0-9]*$/', $seg)) {
        return null;
    }

    return \strtolower($seg);
}

/**
 * Liest aus App-Unterverzeichnissen …/lib (rekursiv, PHP-Dateien) Namespace-Zeilen (Plugin-neutral).
 *
 * @return list<string>
 */
function recoveryDiscoverPhpNamespacesInApplicationLibs(string $wcfRoot, int $maxPhpFiles): array
{
    $found = [];
    $filesRead = 0;
    $protectedDirs = \array_flip(recoveryGetProtectedDirectoryNames());
    $wcfRoot = \rtrim(\str_replace('\\', '/', $wcfRoot), '/') . '/';

    foreach (\scandir($wcfRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!recoveryValidateAppDirectoryName($entry) || isset($protectedDirs[$entry])) {
            continue;
        }

        $libDir = $wcfRoot . $entry . '/lib';
        if (!\is_dir($libDir)) {
            continue;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $libDir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $pathname) {
                if (!\is_string($pathname) || !\is_file($pathname)) {
                    continue;
                }
                if (\strcasecmp((string) \pathinfo($pathname, \PATHINFO_EXTENSION), 'php') !== 0) {
                    continue;
                }

                $head = @\file_get_contents($pathname, false, null, 0, 12288);
                if ($head === false || $head === '') {
                    continue;
                }
                if (\preg_match('/^\s*namespace\s+([a-zA-Z0-9_\\\\]+)\s*;/m', $head, $matches)) {
                    $found[$matches[1]] = true;
                }
                $filesRead++;
                if ($filesRead >= $maxPhpFiles) {
                    return \array_keys($found);
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    return \array_keys($found);
}

/**
 * Namespaces, deren Root-Segment (vor erstem \\) zum Konstanten-Präfix passt (shrinkr ↔ shrinkr\\system\\…).
 *
 * @param list<string> $namespaces
 * @return list<string>
 */
function recoveryNamespacesWhoseRootMatchesPrefix(array $namespaces, string $prefixLower): array
{
    $out = [];
    foreach ($namespaces as $ns) {
        $root = \strtolower(\explode('\\', $ns, 2)[0]);
        if ($root === $prefixLower) {
            $out[] = $ns;
        }
    }

    return $out;
}

/**
 * Namespace-Spiegelung nur für Plugin-artige Konstanten (z. B. SHRINKR_* → shrinkr\\…).
 * {@see WCF_*} liefert Präfix „wcf“ — dann würden tausende {@see define()} ins gesamte Core-Namespace
 * geschrieben (Kapazität/Konflikte/Parse-Zeit). Dieselben Ausschlüsse wie bei gefährlichen Globals.
 */
function recoveryShouldEmitNamespaceMirrorDefines(string $constant): bool
{
    if (\str_starts_with($constant, 'WCF_') || \str_starts_with($constant, 'PHP_')
        || \str_starts_with($constant, 'MYSQL_') || \str_starts_with($constant, 'PDO')) {
        return false;
    }

    return true;
}

/**
 * @return list<string>
 */
function recoveryCollectCompiledPhpTemplatePaths(string $wcfRoot, int $maxFiles): array
{
    $paths = [];
    $gatherCap = \max($maxFiles * 5, 1200);

    foreach (recoveryGetFilesystemCacheDirectoryList($wcfRoot) as $dir) {
        $norm = \str_replace('\\', '/', $dir);
        if (!\str_contains($norm, 'templates/compiled')) {
            continue;
        }
        if (!\is_dir($dir)) {
            continue;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $pathname) {
                if (!\is_string($pathname) || !\is_file($pathname)) {
                    continue;
                }
                if (\strcasecmp((string) \pathinfo($pathname, \PATHINFO_EXTENSION), 'php') !== 0) {
                    continue;
                }
                $paths[] = $pathname;
                if (\count($paths) >= $gatherCap) {
                    break 2;
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    $scorePath = static function (string $p): int {
        $b = \strtolower(\basename(\str_replace('\\', '/', $p)));
        $s = 4;
        if (\str_contains($b, 'index')) {
            $s -= 3;
        }
        if (\str_contains($b, 'package')) {
            $s -= 2;
        }
        if (\str_contains($b, 'option')) {
            $s -= 1;
        }

        return $s;
    };

    \usort($paths, static function ($a, $b) use ($scorePath): int {
        return $scorePath((string) $a) <=> $scorePath((string) $b);
    });

    if (\count($paths) > $maxFiles) {
        $paths = \array_slice($paths, 0, $maxFiles);
    }

    return $paths;
}

/**
 * Grobes Pattern wie bei WoltLab-Option-Konstanten in Templates (mindestens 6 Zeichen).
 *
 * @return list<string>
 */
function recoveryExtractCandidateConstantsFromPhpSource(string $source): array
{
    if (!\preg_match_all('/\b([A-Z][A-Z0-9_]{5,})\b/', $source, $matches)) {
        return [];
    }

    return $matches[1];
}

function recoveryShouldIgnoreDiscoveredTemplateConstant(string $constant): bool
{
    if ($constant === '' || !\preg_match('/^[A-Z][A-Z0-9_]+$/', $constant)) {
        return true;
    }
    if (\strlen($constant) > 120) {
        return true;
    }
    if (\str_starts_with($constant, 'WCF_') || \str_starts_with($constant, 'PHP_')
        || \str_starts_with($constant, 'MYSQL_') || \str_starts_with($constant, 'PDO')) {
        return true;
    }

    return isset(recoveryGetCompiledTemplateConstantIgnoreList()[$constant]);
}

/**
 * Skalar für „verwaiste“ Konstanten (kein Eintrag mehr in wcf_option), nach Namensheuristik.
 * Schützt das ACP vor Fatal Errors – konservativ (eher 0/leerer String).
 */
function recoveryHeuristicScalarExpressionForOrphanPluginConstant(string $constant): string
{
    if (\preg_match('/_VERSION$/', $constant)) {
        return \var_export('0.0.0', true);
    }
    if (\preg_match('/_PATTERN$/', $constant)) {
        return \var_export('[a-zA-Z0-9_-]{1,64}', true);
    }

    $upper = \strtoupper($constant);
    foreach (['_ENABLED', '_ACTIVE', '_DISABLE', '_VISIBLE', '_ALLOW', '_DENY', '_REQUIRED', '_OPTIONAL', '_DEBUG', '_SHOW', '_HIDE', '_FREE', '_CONFIRM', '_MUST'] as $needle) {
        if (\str_contains($upper, $needle)) {
            return '0';
        }
    }
    foreach (['_URL', '_PATH', '_DIR', '_URI', '_HTML', '_TEXT', '_MESSAGE', '_PREFIX', '_SUFFIX', '_TOKEN', '_HASH', '_ICON', '_CSS', '_JS', '_KEY', '_SECRET', '_EMAIL', '_TITLE', '_DESCRIPTION', '_BODY'] as $needle) {
        if (\str_contains($upper, $needle)) {
            return \var_export('', true);
        }
    }
    foreach (['_COUNT', '_LENGTH', '_LIMIT', '_SIZE', '_TIME', '_DELAY', '_PORT', '_MIN', '_MAX', '_STEP', '_WIDTH', '_HEIGHT', '_TOTAL', '_OFFSET', '_INDEX', '_NUMBER'] as $needle) {
        if (\str_contains($upper, $needle)) {
            return '0';
        }
    }

    return '0';
}

/**
 * Versucht Option-Zeile aus Konstantennamen (Konvention: CONSTANT ↔ option_name kleingeschrieben).
 *
 * @return array{optionValue: string, optionType: string}|null
 */
function recoveryTryFetchOptionRowForConstantGuess(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $constant
): ?array {
    $guess = \strtolower($constant);
    if ($guess === '' || !\preg_match('/^[a-z0-9._]+$/', $guess)) {
        return null;
    }

    try {
        $statement = $db->prepareStatement(
            "SELECT optionValue, optionType FROM wcf{$wcfN}_option WHERE optionName = ?"
        );
        $statement->execute([$guess]);
        $row = $statement->fetchArray();

        return \is_array($row) ? $row : null;
    } catch (\Throwable $ignored) {
        return null;
    }
}

/**
 * Findet in kompilierten WoltLab-Templates vorkommende Kandidaten-Konstanten (plugin-neutral).
 *
 * @return list<string>
 */
function recoveryDiscoverOrphanOptionLikeConstantsFromCompiledTemplates(
    string $wcfRoot,
    int $maxFiles,
    int $maxBytesPerFile,
    array &$detailLog
): array {
    $detailLog = [];
    $paths = recoveryCollectCompiledPhpTemplatePaths($wcfRoot, $maxFiles);
    $detailLog[] = 'Template-Konstanten-Scan: ' . \count($paths) . ' PHP-Dateien unter templates/compiled';

    $found = [];
    foreach ($paths as $path) {
        $content = @\file_get_contents($path, false, null, 0, $maxBytesPerFile);
        if ($content === false || $content === '') {
            continue;
        }
        foreach (recoveryExtractCandidateConstantsFromPhpSource($content) as $c) {
            if (recoveryShouldIgnoreDiscoveredTemplateConstant($c)) {
                continue;
            }
            $found[$c] = true;
        }
    }

    $list = \array_keys($found);
    \sort($list);

    return $list;
}

function recoveryStripPluginRecoveryOptionFallbackBlock(): void
{
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $file = WCF_DIR . 'options.inc.php';
    if (!\is_file($file) || !\is_readable($file)) {
        return;
    }

    $content = (string) \file_get_contents($file);
    $pattern = '~// <plugin-recovery-tool> option constant fallbacks begin.*// <plugin-recovery-tool> option constant fallbacks end\s*~sU';
    $trimmed = \preg_replace($pattern, '', $content);
    if ($trimmed !== null && $trimmed !== $content) {
        \file_put_contents($file, \rtrim($trimmed) . "\n");
    }
}

/**
 * Schreibt einen markierten Fallback-Block in options.inc.php – **plugin-neutral**:
 * 1) alle Zeilen aus {@see wcf{N}_option} (Core + sämtliche Plugins),
 * 2) Konstanten aus kompilierten Templates (Heuristik),
 * 3) zusätzlich **namespaced** {@see define()} für PHP 8 (unqualifizierte Konstanten im Namespace `foo\\bar`
 *    lösen zu `foo\\bar\\CONST`; globales define('CONST') reicht dann nicht — daher Spiegelung nach Präfix-Match).
 * Keine Spiegelung für {@see WCF_*}/{@see PHP_*}/MySQL/PDO (Core-Globals; Präfix „wcf“ würde massenhaft Core-Namespaces treffen).
 */
function recoveryEnsureOptionConstantFallbacks(\wcf\system\database\Database $db, int $wcfN, array &$log): void
{
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $file = WCF_DIR . 'options.inc.php';
    if (!\is_file($file)) {
        $log[] = 'Option-Konstanten-Fallback: options.inc.php nicht gefunden';

        return;
    }
    if (!\is_writable($file)) {
        $log[] = 'Option-Konstanten-Fallback: options.inc.php nicht beschreibbar';

        return;
    }

    recoveryStripPluginRecoveryOptionFallbackBlock();

    /** @var array<string, string> $globalExpr Konstantenname → PHP-Skalarausdruck für define */
    $globalExpr = [];
    $dbBackedCount = 0;

    try {
        $statement = $db->prepareStatement("SELECT optionName, optionValue, optionType FROM wcf{$wcfN}_option");
        $statement->execute();
        while ($row = $statement->fetchArray()) {
            $optionName = (string) ($row['optionName'] ?? '');
            if ($optionName === '' || !\preg_match('/^[a-zA-Z0-9._]+$/', $optionName)) {
                continue;
            }
            $const = recoveryOptionNameToConstant($optionName);
            if ($const === '' || !\preg_match('/^[A-Z0-9_]+$/', $const)) {
                continue;
            }
            if (isset($globalExpr[$const])) {
                continue;
            }
            $type = (string) ($row['optionType'] ?? 'text');
            $value = isset($row['optionValue']) ? (string) $row['optionValue'] : '';

            $globalExpr[$const] = recoveryPhpScalarExpressionForOptionType($type, $value);
            $dbBackedCount++;
        }
    } catch (\Throwable $e) {
        $log[] = 'Option-Konstanten-Fallback: Lesen aus wcf' . $wcfN . '_option fehlgeschlagen (' . $e->getMessage()
            . ') – es werden nur Template-Scan/Fallbacks geschrieben';
    }

    $wcfRoot = \rtrim(WCF_DIR, '/\\') . \DIRECTORY_SEPARATOR;
    $scanDetail = [];
    $orphanCandidates = recoveryDiscoverOrphanOptionLikeConstantsFromCompiledTemplates(
        $wcfRoot,
        450,
        131072,
        $scanDetail
    );
    foreach ($scanDetail as $detailLine) {
        $log[] = 'Option-Konstanten-Fallback: ' . $detailLine;
    }

    $templateScanCount = 0;
    $maxOrphanDefines = 300;
    foreach ($orphanCandidates as $const) {
        if (isset($globalExpr[$const])) {
            continue;
        }
        if ($templateScanCount >= $maxOrphanDefines) {
            $log[] = 'Option-Konstanten-Fallback: Template-Scan nach ' . $maxOrphanDefines
                . ' Zusatzkonstanten gestoppt (Obergrenze; Installation hat sehr viele Kandidaten)';

            break;
        }

        $guessRow = recoveryTryFetchOptionRowForConstantGuess($db, $wcfN, $const);
        if (\is_array($guessRow)) {
            $expr = recoveryPhpScalarExpressionForOptionType(
                (string) ($guessRow['optionType'] ?? 'text'),
                isset($guessRow['optionValue']) ? (string) $guessRow['optionValue'] : ''
            );
        } else {
            $expr = recoveryHeuristicScalarExpressionForOrphanPluginConstant($const);
        }

        $globalExpr[$const] = $expr;
        $templateScanCount++;
    }

    $lines = [];
    $lines[] = '// <plugin-recovery-tool> option constant fallbacks begin';
    $lines[] = '// Notfall-Fallback für fehlende Option-Konstanten (Recovery Tool ' . RECOVERY_VERSION . ').';
    $lines[] = '// Alle Plugins: DB + Template-Scan + Namespace-Spiegelung (PHP 8). Block beim nächsten Lauf ersetzt.';

    $sortedConstants = \array_keys($globalExpr);
    \sort($sortedConstants);

    foreach ($sortedConstants as $const) {
        $expr = $globalExpr[$const];
        $lines[] = "if (!\\defined('" . $const . "')) {\n\t\\define('" . $const . "', " . $expr . ');' . "\n}";
    }

    $libNamespaces = recoveryDiscoverPhpNamespacesInApplicationLibs($wcfRoot, 550);
    $log[] = 'Option-Konstanten-Fallback: Namespace-Spiegelung — ' . \count($libNamespaces)
        . ' PHP-Namespaces unter App-lib/ (Präfix-Match zur Konstante, z. B. shrinkr ↔ shrinkr\\\\…)';

    /** @var array<string, true> $fqSeen */
    $fqSeen = [];
    $mirrorCount = 0;
    $maxMirror = 650;
    $maxMirrorPerConstant = 48;
    foreach ($sortedConstants as $const) {
        if (!recoveryShouldEmitNamespaceMirrorDefines($const)) {
            continue;
        }
        $pfx = recoveryLeadingPrefixSegmentLowerFromConstant($const);
        if ($pfx === null) {
            continue;
        }
        $expr = $globalExpr[$const];
        $mirroredForConst = 0;
        foreach (recoveryNamespacesWhoseRootMatchesPrefix($libNamespaces, $pfx) as $ns) {
            if ($mirrorCount >= $maxMirror) {
                $log[] = 'Option-Konstanten-Fallback: Namespace-Spiegelung nach ' . $maxMirror . ' defines gestoppt (Obergrenze)';

                break 2;
            }
            if ($mirroredForConst >= $maxMirrorPerConstant) {
                break;
            }
            $fq = $ns . '\\' . $const;
            if (\strlen($fq) > 240 || isset($fqSeen[$fq])) {
                continue;
            }
            $fqSeen[$fq] = true;
            $lit = recoveryPhpSingleQuotedDefineNameLiteral($fq);
            $lines[] = 'if (!\\defined(' . $lit . ')) {' . "\n\t\\define(" . $lit . ', ' . $expr . ');' . "\n}";
            $mirrorCount++;
            $mirroredForConst++;
        }
    }

    $lines[] = '// <plugin-recovery-tool> option constant fallbacks end';

    $snippet = "\n" . \implode("\n", $lines) . "\n";
    \file_put_contents($file, \rtrim((string) \file_get_contents($file)) . $snippet, \LOCK_EX);

    $totalGlobals = \count($globalExpr);
    $log[] = 'Option-Konstanten-Fallback: options.inc.php ergänzt (globale Konstanten: ' . $totalGlobals
        . ', davon ' . $dbBackedCount . ' aus DB, ' . $templateScanCount . ' aus Template-Scan; '
        . 'Namespace-Spiegel: ' . $mirrorCount . ')';
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

function recoveryTryExecuteDelete(
    \wcf\system\database\Database $db,
    string $sql,
    array $parameters,
    string $logLabel,
    array &$log
): void {
    try {
        recoveryExecuteDelete($db, $sql, $parameters, $logLabel, $log);
    } catch (\Throwable $e) {
        $log[] = $logLabel . ' übersprungen: ' . $e->getMessage();
    }
}

function recoveryTryDeletePackageRequirements(
    \wcf\system\database\Database $db,
    int $wcfN,
    int $packageID,
    array &$log
): void {
    recoveryTryExecuteDelete(
        $db,
        "DELETE FROM wcf{$wcfN}_package_requirement WHERE packageID = ? OR requirement = ?",
        [$packageID, $packageID],
        'Package-Requirements',
        $log
    );
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function recoveryGuessTableLabelColumns(array $row): array
{
    $preferred = [
        'title', 'name', 'menuItem', 'optionName', 'objectType', 'identifier',
        'package', 'templateName', 'cronjobName', 'eventName', 'languageItem',
    ];
    $cols = [];
    foreach ($preferred as $key) {
        if (\array_key_exists($key, $row)) {
            $cols[] = $key;
        }
    }
    if ($cols === []) {
        foreach (\array_keys($row) as $key) {
            if ($key === 'packageID' || \str_ends_with($key, 'ID')) {
                continue;
            }
            $cols[] = $key;
            if (\count($cols) >= 4) {
                break;
            }
        }
    }

    return \array_slice($cols, 0, 5);
}

/**
 * @param mixed $value
 * @return mixed
 */
function recoverySanitizeJsonValue($value)
{
    if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
        return $value;
    }
    if (\is_string($value)) {
        if (!\mb_check_encoding($value, 'UTF-8')) {
            $value = \mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }
        if (\strlen($value) > 8192) {
            return \substr($value, 0, 8192) . '… [' . \strlen($value) . ' Zeichen]';
        }

        return $value;
    }
    if (\is_array($value)) {
        return '[Array]';
    }

    return (string) $value;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function recoverySanitizeRowForJson(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        $out[(string) $key] = recoverySanitizeJsonValue($value);
    }

    return $out;
}

function recoveryJsonResponse(array $data, int $statusCode = 200): void
{
    while (\ob_get_level() > 0) {
        \ob_end_clean();
    }

    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = \json_encode($data, $flags);
    if ($json === false) {
        $json = \json_encode(['ok' => false, 'error' => 'JSON-Ausgabe fehlgeschlagen: ' . \json_last_error_msg()], $flags);
        $statusCode = 500;
    }

    \http_response_code($statusCode);
    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: no-store');
    echo $json;
    exit;
}

function recoveryFormatUserGroupLabel(int $groupID, string $groupName): string
{
    $known = [
        1 => 'Everyone (Alle)',
        2 => 'Registered Users (Registrierte)',
        3 => 'Moderators (Moderatoren)',
        4 => 'Administrators (Administratoren)',
        5 => 'Guests (Gäste)',
        6 => 'Super-Moderators',
    ];
    if (isset($known[$groupID])) {
        return $known[$groupID];
    }

    if (\str_contains($groupName, '.')) {
        return $groupName . ' <small style="color:#9D9D9D">(Sprachvariable)</small>';
    }

    return $groupName;
}

/**
 * Pip-Vorschau: erste Zeilen einer Tabelle mit packageID (AJAX).
 *
 * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int, table: string, error?: string}
 */
function recoveryFetchPackageIdTablePreview(
    \wcf\system\database\Database $db,
    int $wcfN,
    string $tableName,
    int $packageID,
    int $limit = 30
): array {
    $tableName = \str_replace('`', '', $tableName);
    if (!recoveryValidateSqlTableName($tableName) || $packageID <= 0) {
        return ['columns' => [], 'rows' => [], 'total' => 0, 'table' => $tableName];
    }

    $tableFull = "wcf{$wcfN}_{$tableName}";
    $total = 0;

    try {
        $countStmt = $db->prepareStatement("SELECT COUNT(*) AS cnt FROM {$tableFull} WHERE packageID = ?");
        $countStmt->execute([$packageID]);
        $total = (int) ($countStmt->fetchArray()['cnt'] ?? 0);

        $stmt = $db->prepareStatement("SELECT * FROM {$tableFull} WHERE packageID = ? LIMIT " . (int) $limit);
        $stmt->execute([$packageID]);
        $rows = [];
        while ($row = $stmt->fetchArray()) {
            $rows[] = recoverySanitizeRowForJson($row);
        }

        $columns = $rows !== [] ? recoveryGuessTableLabelColumns($rows[0]) : [];

        return [
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'table' => $tableFull,
        ];
    } catch (\Throwable $e) {
        return [
            'columns' => [],
            'rows' => [],
            'total' => 0,
            'table' => $tableFull,
            'error' => $e->getMessage(),
        ];
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
    } catch (\Throwable $ignored) {
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
    } catch (\Throwable $ignored) {
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
    } catch (\Throwable $ignored) {
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
        "DELETE r FROM {$prefix}package_requirement r
         LEFT JOIN {$prefix}package p ON r.packageID = p.packageID
         WHERE p.packageID IS NULL",
        [],
        'Verwaiste Package-Requirements (packageID)',
        $log
    );

    recoveryExecuteDelete(
        $db,
        "DELETE r FROM {$prefix}package_requirement r
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

DELETE r FROM {$p}package_requirement r
LEFT JOIN {$p}package p ON r.packageID = p.packageID
WHERE p.packageID IS NULL;

DELETE r FROM {$p}package_requirement r
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

        recoveryTryDeletePackageRequirements($db, $wcfN, $packageID, $log);

        recoveryTryExecuteDelete(
            $db,
            "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE packageID = ?",
            [$packageID],
            'Package SQL-Log',
            $log
        );

        recoveryTryExecuteDelete(
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
// PIP RESOURCE MAP + DB-COUNTS + SQL-BACKUP (v1.2.7)
// ============================================================================

/**
 * WoltLab PIP → DB-Ressourcen-Matrix.
 * Inspiriert vom offiziellen PackageUninstallationDispatcher und den PIP-Klassen:
 * AbstractXMLPackageInstallationPlugin::uninstall() → DELETE WHERE packageID = ?
 *
 * Quellen:
 *   EventListenerPIP::tableName          = 'event_listener'
 *   TemplateListenerPIP::tableName       = 'template_listener'
 *   OptionPIP::tableName                 = 'option'
 *   UserGroupOptionPIP::tableName        = 'user_group_option'
 *   UserOptionPIP::tableName             = 'user_option'
 *   CronjobPIP::tableName                = 'cronjob'
 *   ObjectTypePIP::tableName             = 'object_type'
 *   BBCodePIP::tableName                 = 'bbcode'
 *   SmileyPIP::tableName                 = 'smiley'
 *   UserMenuPIP::tableName               = 'user_menu_item'
 *   UserNotificationEventPIP::tableName  = 'user_notification_event'
 *   ACLOptionPIP::tableName              = 'acl_option'
 *   BoxPIP::uninstall() → DELETE FROM wcf1_box WHERE … packageID = ?
 *   PagePIP → 'page' (packageID)
 *   MenuPIP, MenuItemPIP → 'menu', 'menu_item' (packageID)
 *
 * @return array<string, array{table: string, col: string, safe: bool, label: string}>
 */
function recoveryGetPipResourceMap(): array
{
    return [
        // ── Core PIPs – tableName explizit in Quellcode ──────────────────────
        'acpMenu'               => ['table' => 'acp_menu_item',              'col' => 'packageID', 'safe' => true,  'label' => 'ACP-Menüeinträge'],
        'eventListener'         => ['table' => 'event_listener',             'col' => 'packageID', 'safe' => true,  'label' => 'Event-Listener'],
        'templateListener'      => ['table' => 'template_listener',          'col' => 'packageID', 'safe' => true,  'label' => 'Template-Listener'],
        'option'                => ['table' => 'option',                     'col' => 'packageID', 'safe' => true,  'label' => 'Optionen (ACP)'],
        'userGroupOption'       => ['table' => 'user_group_option',          'col' => 'packageID', 'safe' => true,  'label' => 'Benutzergruppen-Optionen'],
        'userOption'            => ['table' => 'user_option',                'col' => 'packageID', 'safe' => true,  'label' => 'Benutzer-Optionen'],
        'cronjob'               => ['table' => 'cronjob',                    'col' => 'packageID', 'safe' => true,  'label' => 'Cronjobs'],
        'objectType'            => ['table' => 'object_type',                'col' => 'packageID', 'safe' => true,  'label' => 'Objekttypen'],
        'objectTypeDefinition'  => ['table' => 'object_type_definition',     'col' => 'packageID', 'safe' => true,  'label' => 'Objekttyp-Definitionen'],
        'language'              => ['table' => 'language_item',              'col' => 'packageID', 'safe' => true,  'label' => 'Sprachvariablen'],
        'template'              => ['table' => 'template',                   'col' => 'packageID', 'safe' => true,  'label' => 'Templates (Frontend)'],
        'acpTemplate'           => ['table' => 'acp_template',               'col' => 'packageID', 'safe' => true,  'label' => 'ACP-Templates'],
        'page'                  => ['table' => 'page',                       'col' => 'packageID', 'safe' => true,  'label' => 'Seiten (CMS)'],
        'box'                   => ['table' => 'box',                        'col' => 'packageID', 'safe' => true,  'label' => 'Boxen'],
        'userMenu'              => ['table' => 'user_menu_item',             'col' => 'packageID', 'safe' => true,  'label' => 'Benutzer-Menüeinträge'],
        'userNotificationEvent' => ['table' => 'user_notification_event',    'col' => 'packageID', 'safe' => true,  'label' => 'Benachrichtigungs-Events'],
        'bbcode'                => ['table' => 'bbcode',                     'col' => 'packageID', 'safe' => true,  'label' => 'BBCodes'],
        'smiley'                => ['table' => 'smiley',                     'col' => 'packageID', 'safe' => true,  'label' => 'Smileys'],
        'aclOption'             => ['table' => 'acl_option',                 'col' => 'packageID', 'safe' => true,  'label' => 'ACL-Optionen'],
        'coreObject'            => ['table' => 'core_object',                'col' => 'packageID', 'safe' => true,  'label' => 'Core-Objekte'],
        'clipboardAction'       => ['table' => 'clipboard_action',           'col' => 'packageID', 'safe' => true,  'label' => 'Zwischenablage-Aktionen'],
        'acpSearchProvider'     => ['table' => 'acp_search_provider',        'col' => 'packageID', 'safe' => true,  'label' => 'ACP-Suchanbieter'],
        'mediaProvider'         => ['table' => 'media_provider',             'col' => 'packageID', 'safe' => true,  'label' => 'Media-Anbieter'],
        'menu'                  => ['table' => 'menu',                       'col' => 'packageID', 'safe' => true,  'label' => 'Frontend-Menüs'],
        'menuItem'              => ['table' => 'menu_item',                  'col' => 'packageID', 'safe' => true,  'label' => 'Frontend-Menüeinträge'],
        'pip'                   => ['table' => 'package_installation_plugin','col' => 'packageID', 'safe' => true,  'label' => 'PIPs (package_installation_plugin)'],
        // ── Spezial-PIPs – kein direkter DB-Tabellen-Eintrag ─────────────────
        'file'                  => ['table' => '',                           'col' => '',          'safe' => false, 'label' => 'Dateien (Dateisystem)'],
        'database'              => ['table' => '',                           'col' => '',          'safe' => false, 'label' => 'Datenbank-Tabellen (DROP TABLE)'],
        'script'                => ['table' => '',                           'col' => '',          'safe' => false, 'label' => 'Install-Script'],
        'sql'                   => ['table' => '',                           'col' => '',          'safe' => false, 'label' => 'Rohe SQL-Anweisungen'],
    ];
}

/**
 * Zählt Datenbankzeilen pro PIP (WHERE packageID = $packageID).
 * Gibt -1 zurück wenn die Tabelle nicht existiert.
 *
 * @return array<string, int>
 */
function recoveryGetPipDbCounts(
    \wcf\system\database\Database $db,
    int $wcfN,
    int $packageID
): array {
    $map = recoveryGetPipResourceMap();
    $counts = [];

    foreach ($map as $pipName => $info) {
        if (!$info['safe'] || $info['col'] !== 'packageID' || $info['table'] === '') {
            continue;
        }

        try {
            $sql = "SELECT COUNT(*) AS cnt FROM wcf{$wcfN}_{$info['table']} WHERE packageID = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageID]);
            $row = $statement->fetchArray();
            $counts[$pipName] = (int)($row['cnt'] ?? 0);
        } catch (\Throwable $ignored) {
            $counts[$pipName] = -1;
        }
    }

    return $counts;
}

/**
 * Ergänzt pipMap/pipCounts um weitere Tabellen mit packageID-Spalte (z. B. Core-Tabellen).
 *
 * @param array<string, array{table: string, col: string, safe: bool, label: string}> $pipMap
 * @param array<string, int> $pipCounts
 */
function recoveryMergeDiscoveredPipTables(
    array &$pipMap,
    array &$pipCounts,
    \wcf\system\database\Database $db,
    int $wcfN,
    int $packageID
): void {
    $knownTables = [];
    foreach ($pipMap as $info) {
        if ($info['table'] !== '') {
            $knownTables[$info['table']] = true;
        }
    }

    foreach (recoveryDiscoverPackageIdTables($db, $wcfN) as $discTable) {
        if (isset($knownTables[$discTable])) {
            continue;
        }

        $pipKey = 'discovered_' . $discTable;
        try {
            $sql = "SELECT COUNT(*) AS cnt FROM wcf{$wcfN}_{$discTable} WHERE packageID = ?";
            $statement = $db->prepareStatement($sql);
            $statement->execute([$packageID]);
            $row = $statement->fetchArray();
            $pipMap[$pipKey] = [
                'table' => $discTable,
                'col' => 'packageID',
                'safe' => true,
                'label' => 'Weitere DB-Tabelle',
            ];
            $pipCounts[$pipKey] = (int) ($row['cnt'] ?? 0);
        } catch (\Throwable $ignored) {
            $pipCounts[$pipKey] = -1;
        }
    }
}

function recoveryRenderPipCountCell(int $count, string $tableName, int $packageID): string
{
    if ($count <= 0) {
        return '0';
    }

    return '<button type="button" class="recovery-pip-count-btn" data-table="'
        . \htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8') . '" data-package-id="'
        . $packageID . '" title="Einträge dieses Plugins anzeigen">' . $count . '</button>';
}

/**
 * Generiert SQL-INSERT-Backup aller betroffenen Zeilen (WHERE packageID = $packageID).
 * Nur für ausgewählte PIP-Kategorien aus recoveryGetPipResourceMap().
 * Pure PHP – kein mysqldump erforderlich.
 *
 * @param list<string> $selectedPips
 */
function recoveryGenerateSqlBackup(
    \wcf\system\database\Database $db,
    int $wcfN,
    int $packageID,
    array $selectedPips
): string {
    $map = recoveryGetPipResourceMap();
    $out  = "-- ============================================================\n";
    $out .= "-- WoltLab Recovery Tool v" . RECOVERY_VERSION . " – SQL-Backup\n";
    $out .= "-- Package-ID: {$packageID} | WCF_N: {$wcfN}\n";
    $out .= "-- Erstellt: " . \date('Y-m-d H:i:s') . "\n";
    $out .= "-- Nur Zeilen mit packageID = {$packageID} – kein Komplett-Dump!\n";
    $out .= "-- Zum Wiederherstellen: SQL in phpMyAdmin oder CLI ausführen.\n";
    $out .= "-- ============================================================\n\n";

    foreach ($selectedPips as $pipName) {
        if (!isset($map[$pipName])) {
            continue;
        }

        $info = $map[$pipName];
        if (!$info['safe'] || $info['col'] !== 'packageID' || $info['table'] === '') {
            continue;
        }

        $tableFull = "wcf{$wcfN}_{$info['table']}";

        try {
            $statement = $db->prepareStatement("SELECT * FROM {$tableFull} WHERE packageID = ?");
            $statement->execute([$packageID]);

            $rows = [];
            while ($row = $statement->fetchArray()) {
                $rows[] = $row;
            }

            if (empty($rows)) {
                continue;
            }

            $out .= "-- ── {$tableFull} ({$info['label']}) – " . \count($rows) . " Zeile(n) ──\n";

            foreach ($rows as $row) {
                $cols = \array_keys($row);
                $vals = \array_map(static function ($v): string {
                    if ($v === null) {
                        return 'NULL';
                    }
                    return "'" . \addslashes((string)$v) . "'";
                }, \array_values($row));

                $out .= 'INSERT INTO `' . $tableFull . '` (`'
                    . \implode('`, `', $cols) . '`) VALUES ('
                    . \implode(', ', $vals) . ");\n";
            }

            $out .= "\n";
        } catch (\Throwable $e) {
            $out .= "-- Backup für {$tableFull} fehlgeschlagen: " . $e->getMessage() . "\n\n";
        }
    }

    return $out;
}


// ============================================================================
// USER MANAGEMENT HELPERS
// ============================================================================

function recoveryUserHashPassword(string $password): string
{
    // WoltLab Suite 6.x: "Bcrypt:{php_bcrypt_hash}" (wie wsc-recovery.php multifactor-backup)
    return 'Bcrypt:' . \password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function recoveryUserGenerateRandomPassword(int $length = 16): string
{
    return \substr(
        \str_replace(['+', '/', '='], '', \base64_encode(\random_bytes(20))),
        0,
        $length
    );
}

function recoveryUserSearch(\wcf\system\database\Database $db, string $query): array
{
    $n = WCF_N;
    $sql = "SELECT userID, username, email, banned, activationCode, multifactorActive
            FROM wcf{$n}_user
            WHERE username LIKE ? OR email LIKE ?
            ORDER BY userID
            LIMIT 50";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$query . '%', $query . '%']);
    $users = [];
    while ($row = $stmt->fetchArray()) {
        $users[] = $row;
    }
    return $users;
}

function recoveryUserGetByID(\wcf\system\database\Database $db, int $userID): ?array
{
    $n = WCF_N;
    $sql = "SELECT userID, username, email, banned, activationCode, multifactorActive
            FROM wcf{$n}_user WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);
    $row = $stmt->fetchArray();
    return $row ?: null;
}

function recoveryUserGetAllGroups(\wcf\system\database\Database $db): array
{
    $n = WCF_N;
    $sql = "SELECT groupID, groupName, groupType FROM wcf{$n}_user_group ORDER BY groupID";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([]);
    $groups = [];
    while ($row = $stmt->fetchArray()) {
        $groups[] = $row;
    }
    return $groups;
}

function recoveryUserGetGroupIDs(\wcf\system\database\Database $db, int $userID): array
{
    $n = WCF_N;
    $sql = "SELECT groupID FROM wcf{$n}_user_to_group WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);
    $ids = [];
    while ($row = $stmt->fetchArray()) {
        $ids[] = (int)$row['groupID'];
    }
    return $ids;
}

function recoveryUserResetPassword(\wcf\system\database\Database $db, int $userID, string $newPassword): void
{
    $n = WCF_N;
    $hash = recoveryUserHashPassword($newPassword);
    // accessToken leeren → alle Sitzungen ungültig
    $sql = "UPDATE wcf{$n}_user SET password = ?, accessToken = '' WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$hash, $userID]);
}

function recoveryUserSetGroups(\wcf\system\database\Database $db, int $userID, array $groupIDs): void
{
    $n = WCF_N;
    // System-Gruppen (Everyone=1, Registered=2) immer behalten
    foreach ([1, 2] as $sys) {
        if (!\in_array($sys, $groupIDs, true)) {
            $groupIDs[] = $sys;
        }
    }
    $groupIDs = \array_unique(\array_map('intval', $groupIDs));

    $sql = "DELETE FROM wcf{$n}_user_to_group WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);

    foreach ($groupIDs as $gid) {
        $sql = "INSERT IGNORE INTO wcf{$n}_user_to_group (userID, groupID) VALUES (?, ?)";
        $stmt = $db->prepareStatement($sql);
        $stmt->execute([$userID, $gid]);
    }
}

function recoveryUserChangeEmail(\wcf\system\database\Database $db, int $userID, string $email): void
{
    $n = WCF_N;
    $sql = "UPDATE wcf{$n}_user SET email = ? WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$email, $userID]);
}

function recoveryUserActivate(\wcf\system\database\Database $db, int $userID): void
{
    $n = WCF_N;
    $sql = "UPDATE wcf{$n}_user SET activationCode = 0, banned = 0, banReason = '', banExpires = 0 WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);
}

function recoveryUserDisable2FA(\wcf\system\database\Database $db, int $userID): void
{
    $n = WCF_N;
    $sql = "UPDATE wcf{$n}_user SET multifactorActive = 0 WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);
    // Alle 2FA-Setups (inkl. Backup-Codes) löschen
    $sql = "DELETE FROM wcf{$n}_user_multifactor WHERE userID = ?";
    $stmt = $db->prepareStatement($sql);
    $stmt->execute([$userID]);
}

// ============================================================================
// UI (WoltLab WCFSetup.css – wie Rescue Mode / offizielles Recovery Tool)
// ============================================================================

/**
 * Setup-Assets für die Recovery-Oberfläche.
 *
 * Früher: komplette CSS/PNG als data:-URI (base64) — bei großen Dateien oder knappem memory_limit
 * häufig **fatal / HTTP 500 ohne Ausgabe** auf Shared Hosting.
 *
 * @return array{WCFSetup.css: string, woltlabSuite.png: string} Relative URLs zur Installations-Root (leer wenn nicht lesbar)
 */
function recoveryGetSetupAssets(): array
{
    // #region agent log
    recoveryAgentDebugLog('H4', 'recoveryGetSetupAssets', 'enter', ['wcfDirDefined' => \defined('WCF_DIR')]);
    // #endregion
    if (!\defined('WCF_DIR')) {
        try {
            \define('WCF_DIR', recoveryResolveWcfDir());
        } catch (\Throwable $ignored) {
            // #region agent log
            recoveryAgentDebugLog('H4', 'recoveryGetSetupAssets', 'wcf_resolve_failed', ['exceptionClass' => \get_class($ignored)]);
            // #endregion

            return ['WCFSetup.css' => '', 'woltlabSuite.png' => ''];
        }
    }

    $assets = ['WCFSetup.css' => '', 'woltlabSuite.png' => ''];
    $cssPath = WCF_DIR . 'acp/style/setup/WCFSetup.css';
    $imgPath = WCF_DIR . 'acp/images/woltlabSuite.png';

    if (\is_readable($cssPath)) {
        $assets['WCFSetup.css'] = 'acp/style/setup/WCFSetup.css';
    }
    if (\is_readable($imgPath)) {
        $assets['woltlabSuite.png'] = 'acp/images/woltlabSuite.png';
    }

    // #region agent log
    recoveryAgentDebugLog('H4', 'recoveryGetSetupAssets', 'exit', [
        'cssHrefLen' => \strlen($assets['WCFSetup.css']),
        'pngHrefLen' => \strlen($assets['woltlabSuite.png']),
        'wcfDirTail' => \substr(\str_replace('\\', '/', (string) (\defined('WCF_DIR') ? \constant('WCF_DIR') : '')), -48),
    ]);
    // #endregion

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
    // #region agent log
    recoveryAgentDebugLog('H4', 'recoveryRenderPageStart', 'enter', ['assetsProvided' => $assets !== null]);
    // #endregion
    try {
        $assets ??= recoveryGetSetupAssets();
    } catch (\Throwable $ignored) {
        // #region agent log
        recoveryAgentDebugLog('H4', 'recoveryRenderPageStart', 'getAssets_throw', ['exceptionClass' => \get_class($ignored)]);
        // #endregion
        $assets = ['WCFSetup.css' => '', 'woltlabSuite.png' => ''];
    }
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($documentTitle) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer">
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
        .recovery-card {
            background: rgba(0, 0, 0, .125); border: 1px solid #444; border-radius: 3px;
            padding: 20px; margin-bottom: 20px;
        }
        .recovery-option-cards {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;
        }
        @media (max-width: 720px) { .recovery-option-cards { grid-template-columns: 1fr; } }
        .recovery-option-card h3 { margin: 0 0 12px; font-size: 16px; color: #fff; }
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

        /* ── Wizard Step Indicator ─────────────────────────────────────── */
        .wizardSteps { display: flex; align-items: flex-start; margin: 0 0 30px; padding: 0; }
        .wizardStep { display: flex; flex-direction: column; align-items: center; position: relative; flex: 1; }
        .wizardStep::after { content: ''; position: absolute; top: 20px; left: 50%; width: 100%; height: 2px; background: #444; z-index: 0; }
        .wizardStep:last-child::after { display: none; }
        .wizardStepNumber { width: 40px; height: 40px; border-radius: 50%; background: #444; color: #9D9D9D; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; position: relative; z-index: 1; border: 2px solid #444; transition: background .3s, border-color .3s, color .3s; }
        .wizardStep.active .wizardStepNumber { background: #369; color: #fff; border-color: #369; }
        .wizardStep.completed .wizardStepNumber { background: #3c3; color: transparent; border-color: #3c3; }
        .wizardStep.completed .wizardStepNumber::after { content: '✓'; color: #fff; font-size: 16px; font-weight: 700; position: absolute; }
        .wizardStepLabel { margin-top: 8px; font-size: 12px; color: #9D9D9D; text-align: center; line-height: 1.3; }
        .wizardStep.active .wizardStepLabel { color: #fff; font-weight: 600; }
        .wizardStep.completed .wizardStepLabel { color: #5d5; }
        .wizardPanel { display: none; }
        .wizardPanel.active { display: block; }
        .recovery-loading {
            display: none;
            padding: 24px 20px;
            margin: 20px 0;
            text-align: center;
            color: #9D9D9D;
            background: rgba(51, 102, 153, 0.12);
            border: 1px solid #369;
            border-radius: 3px;
        }
        .recovery-loading::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            margin-left: 10px;
            vertical-align: middle;
            border: 2px solid #369;
            border-top-color: transparent;
            border-radius: 50%;
            animation: recovery-spin 0.8s linear infinite;
        }
        @keyframes recovery-spin { to { transform: rotate(360deg); } }

        .recovery-pip-count-btn {
            background: transparent; border: none; color: #fc6; font-weight: 700;
            cursor: pointer; text-decoration: underline; font-size: inherit; padding: 0;
        }
        .recovery-pip-count-btn:hover { color: #fff; }
        .recovery-pip-count-btn--zero { color: #888; cursor: default; text-decoration: none; }
        #recoveryPipPreviewModal {
            position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center;
            justify-content: center; padding: 20px; background: rgba(0, 0, 0, 0.65);
        }
        #recoveryPipPreviewModal[hidden] { display: none !important; }
        .recovery-pip-preview-dialog {
            width: 100%; max-width: 820px; max-height: 85vh; overflow: auto;
            background: #3D3D3D; border: 1px solid #555; border-radius: 4px; padding: 20px;
        }
        .recovery-pip-preview-dialog h3 { margin: 0 0 12px; color: #fff; font-size: 18px; }
        .recovery-dryrun-quick { display: none; margin-top: 14px; }

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
            .wizardStep::after { background: #ddd; }
            .wizardStepNumber { background: #e0e0e0; color: #888; border-color: #ddd; }
            .wizardStep.active .wizardStepNumber { background: #369; color: #fff; border-color: #369; }
            .wizardStep.completed .wizardStepNumber { background: #3a3; border-color: #3a3; }
            .wizardStepLabel { color: #888; }
            .wizardStep.active .wizardStepLabel { color: #369; font-weight: 600; }
            .wizardStep.completed .wizardStepLabel { color: #3a3; }
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

        /* Font Awesome Icon-Abstände */
        .fa-solid, .fas { margin-right: 6px; }
        .alert .fa-solid, p.info .fa-solid, p.error .fa-solid, p.success .fa-solid, p.warning .fa-solid { flex-shrink: 0; }
        .mode-button .fa-solid, .recoveryModeCard .fa-solid { display: block; font-size: 28px; margin: 0 auto 10px; }
        button .fa-solid, .button .fa-solid { margin-right: 6px; }
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
    } catch (\Throwable $ignored) {
    }
    ?>
</div>
<footer>
    <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank" rel="noopener"><i class="fa-solid fa-screwdriver-wrench"></i> Plugin Recovery Tool</a> &copy; <?= \date('Y') ?> Sunny C.
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
    echo '<a href="' . \htmlspecialchars($href) . '" class="back-link"><i class="fa-solid fa-arrow-left"></i> Zurück zur Auswahl</a>';
}

function recoveryRenderGlobalNav(int $mode, string $authHash, string $baseUrl): void
{
    $acpUrl = $baseUrl . 'acp/';
    echo '<nav class="recovery-global-nav" aria-label="Recovery-Navigation">';
    if ($mode !== RECOVERY_MODE_SELECTION) {
        echo '<a href="?t=' . \htmlspecialchars($authHash) . '" class="recovery-nav-link"><i class="fa-solid fa-arrow-left"></i> Zurück zur Modus-Auswahl</a>';
    }
    echo '<a href="' . \htmlspecialchars($acpUrl) . '" class="recovery-nav-link recovery-nav-acp"><i class="fa-solid fa-gauge-high"></i> Zum ACP</a>';
    echo '</nav>';
}


// ============================================================================
// AUTHENTIFIZIERUNG (wie WoltLab wsc-recovery.php)
// ============================================================================

// #region agent log
recoveryAgentDebugLog('H3', 'tool:auth', 'before_branch', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'hasTokenParam' => isset($_REQUEST['t']),
    'tokenLooksValid' => !empty($_REQUEST['t']) && (bool) \preg_match('~^[a-f0-9]{40}$~', (string) $_REQUEST['t']),
]);
// #endregion

// Hash generieren bei erstem Aufruf
if (empty($_REQUEST['t']) || !preg_match('~^[a-f0-9]{40}$~', $_REQUEST['t'])) {
    // #region agent log
    recoveryAgentDebugLog('H3', 'tool:auth', 'branch_redirect_new_token', []);
    // #endregion
    $authHash = bin2hex(random_bytes(20));
    header("Location: plugin-recovery-tool.php?t={$authHash}");
    exit;
} else {
    $authHash = $_REQUEST['t'];
    // #region agent log
    recoveryAgentDebugLog('H3', 'tool:auth', 'branch_using_existing_token', ['tokenLen' => \strlen((string) $authHash)]);
    // #endregion
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

// SQL-Backup-Download (base64-kodierter Inhalt aus POST)
if ($action === 'download-sql') {
    $raw = $_POST['sql_b64'] ?? '';
    $sqlContent = \base64_decode(\str_replace(["\n", "\r", ' '], '', (string)$raw), true);
    if ($sqlContent === false || $sqlContent === '') {
        http_response_code(400);
        echo 'Ungültiger Inhalt.';
        exit;
    }
    $filename = 'recovery-backup-' . \date('Y-m-d-His') . '.sql';
    \header('Content-Type: text/plain; charset=utf-8');
    \header('Content-Disposition: attachment; filename="' . \addslashes($filename) . '"');
    \header('Content-Length: ' . \strlen($sqlContent));
    echo $sqlContent;
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

// Vorschau: Zeilen einer Tabelle mit packageID (Plugin Uninstall Schritt 1)
if ($action === 'pip-preview') {
    if (!$isAuthenticated) {
        recoveryJsonResponse(['ok' => false, 'error' => 'Nicht authentifiziert.'], 403);
    }

    try {
        $db = recoveryBootstrapDatabase();
        $packageID = (int) ($_GET['package_id'] ?? 0);
        $tableName = \str_replace('`', '', (string) ($_GET['table'] ?? ''));
        $preview = recoveryFetchPackageIdTablePreview($db, WCF_N, $tableName, $packageID);
        if (isset($preview['error'])) {
            recoveryJsonResponse(['ok' => false, 'error' => $preview['error']], 500);
        }
        recoveryJsonResponse(['ok' => true] + $preview);
    } catch (\Throwable $e) {
        recoveryJsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
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
    } catch (\Throwable $ignored) {
        return false;
    }
}

/**
 * Findet alle Tabellen eines Plugins anhand des Präfixes
 */
function findPackageTables($db, $packageIdentifier, $wcfN = null) {
    try {
        $packageIdentifier = recoveryValidatePackageIdentifier($packageIdentifier);
    } catch (\InvalidArgumentException $ignored) {
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
 * Inhalt eines Verzeichnisses rekursiv löschen (das Verzeichnis selbst bleibt erhalten).
 */
function recoveryDeleteDirectoryContentsRecursive(string $dir): int
{
    if (!\is_dir($dir)) {
        return 0;
    }

    $deletedFiles = 0;
    try {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getPathname();
            if ($fileinfo->isDir()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
            $deletedFiles++;
        }
    } catch (\Throwable $ignored) {
    }

    return $deletedFiles;
}

/**
 * @return list<string>
 */
function recoveryGetFilesystemCacheDirectoryList(string $wcfRoot): array
{
    $wcfRoot = \rtrim(\str_replace('\\', '/', $wcfRoot), '/') . '/';
    $dirs = [
        $wcfRoot . 'tmp',
        $wcfRoot . 'cache',
        $wcfRoot . 'templates/compiled',
        $wcfRoot . 'acp/templates/compiled',
    ];

    $protectedDirs = \array_flip(recoveryGetProtectedDirectoryNames());

    foreach (\scandir($wcfRoot) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!recoveryValidateAppDirectoryName($name) || isset($protectedDirs[$name])) {
            continue;
        }

        $subdir = $wcfRoot . \str_replace('\\', '/', $name);
        if (!\is_dir($subdir)) {
            continue;
        }

        foreach (['templates/compiled', 'acp/templates/compiled'] as $rel) {
            $candidate = $subdir . '/' . $rel;
            if (\is_dir($candidate)) {
                $dirs[] = \rtrim($candidate, '/');
            }
        }
    }

    return \array_values(\array_unique($dirs));
}

/**
 * Löscht kompilierte Templates und Datei-Caches per Filesystem (ohne WCF/CacheHandler).
 * Inkl. Anwendungen im Installations-Stamm wie z.&nbsp;B. shrinkr/acp/templates/compiled.
 */
function clearCompiledTemplates(): int
{
    if (!\defined('WCF_DIR')) {
        \define('WCF_DIR', recoveryResolveWcfDir());
    }

    $wcfRoot = \rtrim(WCF_DIR, '/\\') . DIRECTORY_SEPARATOR;

    $deletedTotal = 0;
    foreach (recoveryGetFilesystemCacheDirectoryList($wcfRoot) as $dir) {
        $deletedTotal += recoveryDeleteDirectoryContentsRecursive(\str_replace('/', DIRECTORY_SEPARATOR, $dir));
    }

    return $deletedTotal;
}

// ============================================================================
// PLUGIN-DATEIEN REPARIEREN (fehlende Klassen aus Bootstrap + Paket-Archiv)
// ============================================================================

/**
 * @return array{package: string, applicationDirectory: string}|null
 */
function recoveryParsePackageMetaFromExtractDir(string $extractDir): ?array
{
    $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
    if (!$packageXml) {
        return null;
    }

    $xml = @\simplexml_load_file($packageXml);
    if ($xml === false) {
        return null;
    }

    $package = \trim((string) ($xml['name'] ?? ''));
    $applicationDirectory = '';
    if (isset($xml->packageinformation->applicationdirectory)) {
        $applicationDirectory = \trim((string) $xml->packageinformation->applicationdirectory);
    }

    if ($applicationDirectory === '' && $package !== '') {
        $parts = \explode('.', $package);
        $guess = (string) \end($parts);
        if (recoveryValidateAppDirectoryName($guess)) {
            $applicationDirectory = $guess;
        }
    }

    if ($package === '') {
        return null;
    }

    return [
        'package' => $package,
        'applicationDirectory' => $applicationDirectory,
    ];
}

/**
 * Entpackt files.tar / files_wcf.tar aus einem Plugin-Archiv in Unterordner.
 *
 * @return array{package: string, applicationDirectory: string, appRoot: string|null, wcfRoot: string|null}|null
 */
function recoveryExtractPackageInstructionTars(string $extractDir, array &$log): ?array
{
    $meta = recoveryParsePackageMetaFromExtractDir($extractDir);
    if ($meta === null) {
        $log[] = 'package.xml konnte nicht gelesen werden.';

        return null;
    }

    $payload = [
        'package' => $meta['package'],
        'applicationDirectory' => $meta['applicationDirectory'],
        'appRoot' => null,
        'wcfRoot' => null,
    ];

    $instructions = recoveryDiscoverFileInstructionTarsFromPackageXml($extractDir);
    foreach ($instructions as $instr) {
        $tarName = (string) $instr['tar'];
        $target = (string) $instr['target'];
        $tarPath = findFileInExtractDir($extractDir, '', $tarName, [$tarName]);
        if (!$tarPath || !\is_file($tarPath)) {
            $log[] = $tarName . ' im Archiv nicht gefunden (package.xml-Instruction).';

            continue;
        }
        $subdir = ($target === 'wcf') ? '_recovery_payload_wcf' : '_recovery_payload_app';
        $root = recoveryExtractPayloadRootForTar($extractDir, $tarPath, $subdir, $log);
        if ($root === null) {
            continue;
        }
        if ($target === 'wcf') {
            $payload['wcfRoot'] = $root;
            $log[] = $tarName . ' → WCF-Root entpackt.';
        } else {
            $payload['appRoot'] = $root;
            $log[] = $tarName . ' → App-Root entpackt.';
        }
    }

    if ($payload['appRoot'] === null && $payload['wcfRoot'] === null) {
        $log[] = 'Keine nutzbaren file-Instructions aus package.xml.';

        return null;
    }

    return $payload;
}

/**
 * WoltLab-Klassenname → lib/…/*.class.php (erstes Segment = App, z. B. shrinkr).
 *
 * @return array{application: string, relative: string}|null
 */
function recoveryClassNameToLibRelativePath(string $className): ?array
{
    $className = \ltrim($className, '\\');
    if (!\preg_match('/^[a-z][a-z0-9_\\\\]*\\\\[A-Za-z][A-Za-z0-9_]+$/', $className)) {
        return null;
    }

    $parts = \explode('\\', $className);
    $application = (string) \array_shift($parts);
    $shortClass = (string) \array_pop($parts);
    $middle = $parts !== [] ? \implode('/', $parts) . '/' : '';

    return [
        'application' => $application,
        'relative' => 'lib/' . $middle . $shortClass . '.class.php',
    ];
}

/**
 * @return list<string> FQCN aus lib/bootstrap/*.php (::class-Referenzen)
 */
function recoveryCollectBootstrapReferencedClasses(string $wcfDir): array
{
    $bootstrapDir = \rtrim($wcfDir, '/\\') . '/lib/bootstrap';
    if (!\is_dir($bootstrapDir)) {
        return [];
    }

    $classes = [];
    foreach (\glob($bootstrapDir . '/*.php') ?: [] as $bootstrapFile) {
        $content = @\file_get_contents($bootstrapFile);
        if ($content === false || $content === '') {
            continue;
        }
        if (!\preg_match_all(
            '/\\\\?([a-z][a-z0-9_\\\\]*\\\\[A-Za-z][A-Za-z0-9_]+)::class/',
            $content,
            $matches
        )) {
            continue;
        }
        foreach ($matches[1] as $raw) {
            $cn = \ltrim(\str_replace('\\\\', '\\', (string) $raw), '\\');
            if ($cn !== '') {
                $classes[$cn] = true;
            }
        }
    }

    $list = \array_keys($classes);
    \sort($list);

    return $list;
}

function recoveryIsPluginClassFilePresent(string $wcfDir, string $className): bool
{
    $map = recoveryClassNameToLibRelativePath($className);
    if ($map === null) {
        return true;
    }

    $wcfRoot = \rtrim($wcfDir, '/\\') . \DIRECTORY_SEPARATOR;
    if ($map['application'] === 'wcf') {
        $path = $wcfRoot . \str_replace('/', \DIRECTORY_SEPARATOR, $map['relative']);
    } else {
        if (!recoveryValidateAppDirectoryName($map['application'])) {
            return true;
        }
        $path = $wcfRoot . $map['application'] . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, $map['relative']);
    }

    return \is_file($path);
}

/**
 * Klassen, die in Bootstrap registriert sind, deren .class.php auf dem Server fehlt.
 *
 * @return list<string>
 */
function recoveryFindMissingBootstrapClasses(string $wcfDir): array
{
    $missing = [];
    foreach (recoveryCollectBootstrapReferencedClasses($wcfDir) as $class) {
        if (!recoveryIsPluginClassFilePresent($wcfDir, $class)) {
            $missing[] = $class;
        }
    }

    return $missing;
}

/**
 * @param list<string> $fqcnList
 * @return array<string, list<string>> App-Präfix (z. B. shrinkr) → Klassen
 */
function recoveryGroupFqcnByApplicationPrefix(array $fqcnList): array
{
    $groups = [];
    foreach ($fqcnList as $cn) {
        $app = \explode('\\', $cn, 2)[0] ?? 'unbekannt';
        $groups[$app][] = $cn;
    }
    \ksort($groups);

    return $groups;
}

/**
 * @param list<string> $fqcnList
 * @return list<string>
 */
function recoveryFilterFqcnByApplicationPrefix(array $fqcnList, string $applicationDirectory): array
{
    $applicationDirectory = \trim($applicationDirectory);
    if ($applicationDirectory === '') {
        return $fqcnList;
    }

    $needle = \strtolower($applicationDirectory) . '\\';

    return \array_values(\array_filter(
        $fqcnList,
        static fn (string $cn): bool => \str_starts_with(\strtolower($cn), $needle)
    ));
}

function recoveryGuessApplicationFromPackageIdentifier(string $packageIdentifier): string
{
    $packageIdentifier = \trim($packageIdentifier);
    if ($packageIdentifier === '') {
        return '';
    }

    $parts = \explode('.', $packageIdentifier);
    $guess = (string) \end($parts);

    return recoveryValidateAppDirectoryName($guess) ? $guess : '';
}

/**
 * Kopiert fehlende Klassen + Bootstrap aus Paket-Payload ins Installationsverzeichnis.
 *
 * @param array{package: string, applicationDirectory: string, appRoot: string|null, wcfRoot: string|null} $payload
 * @param list<string> $missingClasses
 * @return list<string> relative Pfade der kopierten Dateien
 */
function recoveryRepairMissingPluginFilesFromPayload(
    string $wcfDir,
    array $payload,
    array $missingClasses,
    array &$log
): array {
    $copied = [];
    $wcfRoot = \rtrim($wcfDir, '/\\') . \DIRECTORY_SEPARATOR;
    $expectedApp = (string) ($payload['applicationDirectory'] ?? '');

    foreach ($missingClasses as $class) {
        $map = recoveryClassNameToLibRelativePath($class);
        if ($map === null) {
            continue;
        }

        if ($map['application'] === 'wcf') {
            $srcRoot = $payload['wcfRoot'] ?? null;
            $destRoot = $wcfRoot;
        } else {
            if ($expectedApp !== '' && $map['application'] !== $expectedApp) {
                $log[] = 'Übersprungen (andere App): ' . $class;

                continue;
            }
            $srcRoot = $payload['appRoot'] ?? null;
            $destRoot = $wcfRoot . $map['application'] . \DIRECTORY_SEPARATOR;
        }

        if ($srcRoot === null) {
            $log[] = 'Kein Paket-Root für: ' . $class;

            continue;
        }

        $rel = \str_replace('\\', '/', $map['relative']);
        $src = \rtrim($srcRoot, '/\\') . '/' . $rel;
        $dest = \rtrim($destRoot, '/\\') . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);

        if (!\is_file($src)) {
            $log[] = 'Im Paket nicht gefunden: ' . $rel;

            continue;
        }

        $destDir = \dirname($dest);
        if (!\is_dir($destDir) && !@\mkdir($destDir, 0755, true)) {
            $log[] = 'Zielverzeichnis nicht anlegbar: ' . $destDir;

            continue;
        }

        if (@\copy($src, $dest)) {
            $copied[] = $rel;
            $log[] = 'Kopiert: ' . $map['application'] . '/' . $rel;
        } else {
            $log[] = 'Kopieren fehlgeschlagen: ' . $dest;
        }
    }

    $packageId = (string) ($payload['package'] ?? '');
    if ($packageId !== '' && !empty($payload['wcfRoot'])) {
        $bootstrapName = $packageId . '.php';
        $srcBootstrap = \rtrim((string) $payload['wcfRoot'], '/\\') . '/lib/bootstrap/' . $bootstrapName;
        $destBootstrap = $wcfRoot . 'lib/bootstrap/' . $bootstrapName;
        if (\is_file($srcBootstrap)) {
            $bootstrapDir = \dirname($destBootstrap);
            if (!\is_dir($bootstrapDir) && !@\mkdir($bootstrapDir, 0755, true)) {
                $log[] = 'lib/bootstrap/ nicht anlegbar.';
            } elseif (@\copy($srcBootstrap, $destBootstrap)) {
                $copied[] = 'lib/bootstrap/' . $bootstrapName;
                $log[] = 'Bootstrap synchronisiert: lib/bootstrap/' . $bootstrapName;
            }
        }
    }

    return $copied;
}

// ============================================================================
// RECOVERY-WIZARD (Diagnose → Plan → Ausführung, halbautomatisch)
// ============================================================================

/**
 * Ermittelt file-Instructions aus package.xml (angelehnt an wspackager / build.sh parse_package_instructions).
 *
 * @return list<array{tar: string, target: string}>
 */
function recoveryDiscoverFileInstructionTarsFromPackageXml(string $extractDir): array
{
    $packageXml = findFileInExtractDir($extractDir, '', 'package.xml');
    if (!$packageXml) {
        return [['tar' => 'files.tar', 'target' => 'app'], ['tar' => 'files_wcf.tar', 'target' => 'wcf']];
    }

    $parsed = parsePackageXml($packageXml);
    $found = [];
    $hasDefaultAppFile = false;

    if (\is_array($parsed) && !empty($parsed['instructions'])) {
        foreach ($parsed['instructions'] as $instr) {
            $type = (string) ($instr['type'] ?? '');
            if ($type !== 'file') {
                continue;
            }
            $path = \trim((string) ($instr['path'] ?? ''));
            $app = \trim((string) ($instr['application'] ?? ''));
            if ($path === '') {
                if ($app === 'wcf') {
                    $found['files_wcf.tar'] = 'wcf';
                } else {
                    $hasDefaultAppFile = true;
                }
                continue;
            }
            if (!\preg_match('/\.(tar|tar\.gz|tgz)$/i', $path)) {
                continue;
            }
            $target = ($app === 'wcf') ? 'wcf' : 'app';
            $found[$path] = $target;
        }
    }

    if ($hasDefaultAppFile || $found === []) {
        $found['files.tar'] = 'app';
    }
    if (!isset($found['files_wcf.tar'])) {
        $wcfTar = findFileInExtractDir($extractDir, '', 'files_wcf.tar', ['files_wcf.tar']);
        if ($wcfTar) {
            $found['files_wcf.tar'] = 'wcf';
        }
    }

    $out = [];
    foreach ($found as $tar => $target) {
        $out[] = ['tar' => $tar, 'target' => $target];
    }

    return $out;
}

/**
 * @param list<array{tar: string, target: string}> $instructions
 */
function recoveryExtractPayloadRootForTar(string $extractDir, string $tarFile, string $destSubdir, array &$log): ?string
{
    $dest = $extractDir . '/' . $destSubdir;
    if (!\is_dir($dest) && !@\mkdir($dest, 0755, true)) {
        $log[] = 'Entpack-Ziel nicht anlegbar: ' . $destSubdir;

        return null;
    }
    if (!extractArchive($tarFile, $dest)) {
        $log[] = 'Archiv konnte nicht entpackt werden: ' . \basename($tarFile);

        return null;
    }

    return $dest;
}

/**
 * System-Diagnose für Wizard Schritt 1.
 *
 * @return array{
 *   missingBootstrapClasses: list<string>,
 *   orphanApplicationCount: int,
 *   logExcerpts: list<string>,
 *   suggestedActions: array{orphans: bool, files: bool, cache: bool}
 * }
 */
function recoveryBuildSystemDiagnosis(
    string $wcfDir,
    \wcf\system\database\Database $db,
    int $wcfN,
    ?string $scopeApplicationDirectory = null
): array {
    $missing = recoveryFindMissingBootstrapClasses($wcfDir);
    if ($scopeApplicationDirectory !== null && $scopeApplicationDirectory !== '') {
        $missing = recoveryFilterFqcnByApplicationPrefix($missing, $scopeApplicationDirectory);
    }
    $orphanCount = 0;
    try {
        $sql = "SELECT COUNT(*) AS c FROM wcf{$wcfN}_application a
                LEFT JOIN wcf{$wcfN}_package p ON a.packageID = p.packageID
                WHERE p.packageID IS NULL";
        $statement = $db->prepareStatement($sql);
        $statement->execute();
        $row = $statement->fetchArray();
        $orphanCount = (int) ($row['c'] ?? 0);
    } catch (\Throwable $ignored) {
    }

    $logExcerpts = recoveryScanWoltLabLogForRecentErrors($wcfDir, 50);

    return [
        'missingBootstrapClasses' => $missing,
        'orphanApplicationCount' => $orphanCount,
        'logExcerpts' => $logExcerpts,
        'suggestedActions' => [
            'orphans' => $orphanCount > 0,
            'files' => $missing !== [],
            'cache' => true,
        ],
    ];
}

/**
 * @return list<string>
 */
function recoveryScanWoltLabLogForRecentErrors(string $wcfDir, int $maxLines = 40): array
{
    $logDir = \rtrim($wcfDir, '/\\') . '/log';
    if (!\is_dir($logDir)) {
        return [];
    }

    $files = \glob($logDir . '/*.txt') ?: [];
    if ($files === []) {
        return [];
    }

    \usort($files, static function ($a, $b): int {
        return (\filemtime((string) $b) ?: 0) <=> (\filemtime((string) $a) ?: 0);
    });

    $content = @\file_get_contents($files[0]);
    if ($content === false || $content === '') {
        return [];
    }

    $lines = \preg_split('/\r\n|\r|\n/', $content) ?: [];
    $hits = [];
    $needles = ['ClassNotFoundException', 'Undefined constant', 'Fatal error', 'Error Message:'];

    foreach (\array_slice($lines, -500) as $line) {
        $line = \trim((string) $line);
        if ($line === '') {
            continue;
        }
        foreach ($needles as $needle) {
            if (\str_contains($line, $needle)) {
                $hits[] = $line;
                break;
            }
        }
        if (\count($hits) >= $maxLines) {
            break;
        }
    }

    return \array_slice($hits, -$maxLines);
}

function recoveryWizardLoadState(string $authHash): array
{
    recoveryEnsureSession();

    return $_SESSION['recovery_wizard'][$authHash] ?? [];
}

function recoveryWizardSaveState(string $authHash, array $state): void
{
    recoveryEnsureSession();
    $_SESSION['recovery_wizard'][$authHash] = $state;
}

/**
 * @param array{orphans?: bool, files?: bool, cache?: bool, extractDir?: string|null, classes?: list<string>} $plan
 * @return array{log: list<string>, copiedFiles: list<string>, cacheDeleted: int}
 */
function recoveryWizardExecutePlan(
    string $wcfDir,
    \wcf\system\database\Database $db,
    int $wcfN,
    array $plan,
    array &$log
): array {
    $copiedFiles = [];
    $cacheDeleted = 0;

    if (!empty($plan['orphans'])) {
        $orphanResult = recoveryRepairOrphanedPackageReferences($db, $wcfN);
        foreach ($orphanResult['log'] as $entry) {
            $log[] = '[Paketliste] ' . $entry;
        }
    }

    if (!empty($plan['files'])) {
        $extractDir = isset($plan['extractDir']) ? (string) $plan['extractDir'] : '';
        if ($extractDir === '' || !\is_dir($extractDir)) {
            $log[] = '[Dateien] Kein gültiges Paket-Archiv – Schritt übersprungen.';
        } else {
            $extractLog = [];
            $payload = recoveryExtractPackageInstructionTars($extractDir, $extractLog);
            foreach ($extractLog as $entry) {
                $log[] = '[Dateien] ' . $entry;
            }
            if ($payload !== null) {
                $classes = $plan['classes'] ?? recoveryFindMissingBootstrapClasses($wcfDir);
                $copiedFiles = recoveryRepairMissingPluginFilesFromPayload(
                    $wcfDir,
                    $payload,
                    \is_array($classes) ? $classes : [],
                    $log
                );
            }
        }
    }

    if (!empty($plan['cache'])) {
        $cacheDeleted = clearCompiledTemplates();
        $optionFbLog = [];
        recoveryEnsureOptionConstantFallbacks($db, $wcfN, $optionFbLog);
        $log[] = '[Cache] Gelöschte Cache-Dateien: ' . $cacheDeleted;
        foreach ($optionFbLog as $entry) {
            $log[] = '[Cache] ' . $entry;
        }
    }

    return [
        'log' => $log,
        'copiedFiles' => $copiedFiles,
        'cacheDeleted' => $cacheDeleted,
    ];
}

/**
 * @param list<string> $labels
 */
function recoveryRenderWizardPhaseSteps(int $activeIndex, array $labels): void
{
    echo '<div class="wizardSteps" style="margin-bottom:24px">';
    foreach ($labels as $i => $label) {
        $cls = 'wizardStep';
        if ($i < $activeIndex) {
            $cls .= ' completed';
        } elseif ($i === $activeIndex) {
            $cls .= ' active';
        }
        echo '<div class="' . $cls . '">';
        echo '<div class="wizardStepNumber">' . ($i + 1) . '</div>';
        echo '<div class="wizardStepLabel">' . \htmlspecialchars($label) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Entfernt angelegte Recovery-NDJSON-Debug-Logs (WCF log/, Tool log/, Legacy neben Tool).
 */
function recoveryCleanupRecoveryDebugLogs(): void
{
    $globPatterns = [
        __DIR__ . '/log/recovery-tool-*.ndjson',
        __DIR__ . '/log/plugin-recovery-*.ndjson',
    ];

    $wcfDir = recoveryResolveWcfDirForLogging();
    if ($wcfDir !== null) {
        $wcfLogDir = \rtrim($wcfDir, '/\\') . '/log/';
        $globPatterns[] = $wcfLogDir . 'recovery-tool-*.ndjson';
        $globPatterns[] = $wcfLogDir . 'plugin-recovery-*.ndjson';
    }

    foreach ($globPatterns as $pattern) {
        foreach (\glob($pattern) ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    $legacyBeside = __DIR__ . '/plugin-recovery-agent-debug.ndjson';
    if (\is_file($legacyBeside)) {
        @\unlink($legacyBeside);
    }

    $besideLogDir = __DIR__ . '/log/';
    if (\is_dir($besideLogDir) && (\glob($besideLogDir . '*') ?: []) === []) {
        @\rmdir($besideLogDir);
    }
}

/**
 * Löscht Recovery-Hilfsdateien (nicht plugin-recovery-tool.php – das erfolgt per Shutdown).
 */
function cleanupRecoveryAuxiliaryFiles(): void
{
    recoveryCleanupRecoveryDebugLogs();

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
        } catch (\Throwable $e) {
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

$mode = recoveryResolveRequestMode();
$recoveryBootstrapError = null;
$recoveryDb = null;

if ($isAuthenticated) {
    try {
        $recoveryDb = recoveryBootstrapDatabase();
        recoveryMaybeRedirectUninstallAnalyse($authHash);
    } catch (\Throwable $e) {
        $recoveryBootstrapError = $e;
    }
}

// #region agent log
recoveryAgentDebugLog('H5', 'tool:main', 'pre_render', [
    'authenticated' => $isAuthenticated,
    'bootstrapError' => $recoveryBootstrapError !== null ? \get_class($recoveryBootstrapError) : null,
    'mode' => $mode,
]);
// #endregion

recoveryRenderPageStart('Plugin Recovery Tool', 'Plugin Recovery Tool');

// Auth-Screen anzeigen wenn nicht authentifiziert
if (!$isAuthenticated) {
?>
    <h1>Plugin Recovery Tool</h1>
    <p class="subtitle">Authentifizierung erforderlich</p>

    <div class="wizardSteps" id="authWizardSteps">
        <div class="wizardStep active">
            <div class="wizardStepNumber">1</div>
            <div class="wizardStepLabel">Auth-Datei laden</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">2</div>
            <div class="wizardStepLabel">Hochladen</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">3</div>
            <div class="wizardStepLabel">Starten</div>
        </div>
    </div>

    <div class="wizardPanel active" id="wp1">
        <div class="alert alert-info">
            <strong><i class="fa-solid fa-file-arrow-down"></i> Schritt 1: Auth-Datei herunterladen</strong><br>
            Laden Sie die Authentifizierungsdatei herunter. Sie enthält ein einmaliges Token
            und wird benötigt, um Ihren Zugriff auf dieses Tool zu verifizieren.
        </div>
        <a href="?action=download-auth-file&amp;t=<?= htmlspecialchars($authHash) ?>" class="button" id="downloadBtn">
            <i class="fa-solid fa-file-arrow-down"></i> plugin-recovery-auth.php herunterladen
        </a>
    </div>

    <div class="wizardPanel" id="wp2">
        <div class="alert alert-info">
            <strong><i class="fa-solid fa-file-arrow-up"></i> Schritt 2: Datei hochladen</strong><br>
            Laden Sie die heruntergeladene Datei <code><?= htmlspecialchars($authFilename) ?></code> in dasselbe
            Verzeichnis hoch, in dem sich diese <code>plugin-recovery-tool.php</code> befindet.<br><br>
            <small>Nutzen Sie FTP, SFTP oder den Dateimanager Ihres Hosters. Das Tool erkennt den Upload automatisch.</small>
        </div>
        <button type="button" class="button" id="uploadedBtn">
            <i class="fa-solid fa-circle-check"></i> Ich habe die Datei hochgeladen
        </button>
        <span id="pollStatus" style="display:inline-block;margin-left:14px;color:#9D9D9D;font-size:13px;"></span>
    </div>

    <div class="wizardPanel" id="wp3">
        <div class="alert alert-success">
            <strong><i class="fa-solid fa-circle-check"></i> Authentifizierung erfolgreich!</strong><br>
            Die Auth-Datei wurde erkannt. Sie können das Recovery Tool jetzt starten.
        </div>
        <a href="?t=<?= htmlspecialchars($authHash) ?>&amp;auth_ok=1" class="button btn-success" style="font-size:16px;padding:16px 32px;">
            <i class="fa-solid fa-rocket"></i> Recovery Tool starten
        </a>
    </div>

    <div class="alert alert-error" style="margin-top: 30px;">
        <i class="fa-solid fa-shield-halved"></i> <strong>Sicherheitshinweis:</strong><br>
        Löschen Sie beide Dateien (<code>plugin-recovery-tool.php</code> und <code><?= htmlspecialchars($authFilename) ?></code>)
        nach der Verwendung. Diese Dateien können ein Sicherheitsrisiko darstellen!
    </div>

    <script>
    (function () {
        var authToken = <?= \json_encode($authHash) ?>;
        var pollInterval = null;

        function goToStep(n) {
            document.querySelectorAll('#authWizardSteps .wizardStep').forEach(function (el, i) {
                el.classList.remove('active', 'completed');
                if (i + 1 < n) { el.classList.add('completed'); }
                if (i + 1 === n) { el.classList.add('active'); }
            });
            document.querySelectorAll('.wizardPanel').forEach(function (el, i) {
                el.classList.toggle('active', i + 1 === n);
            });
        }

        document.getElementById('downloadBtn').addEventListener('click', function () {
            setTimeout(function () { goToStep(2); }, 800);
        });

        document.getElementById('uploadedBtn').addEventListener('click', function () {
            document.getElementById('pollStatus').textContent = 'Prüfe Upload\u2026';
            startAuthPolling();
        });

        function startAuthPolling() {
            if (pollInterval) { clearInterval(pollInterval); }
            pollInterval = setInterval(function () {
                fetch('?action=auth-status&t=' + encodeURIComponent(authToken))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            clearInterval(pollInterval);
                            goToStep(3);
                        } else {
                            document.getElementById('pollStatus').textContent = 'Datei noch nicht gefunden \u2013 prüfe erneut\u2026';
                        }
                    })
                    .catch(function () {});
            }, 2000);
        }
    }());
    </script>
<?php
    recoveryRenderPageEnd();
    exit;
}

// Ab hier ist der User authentifiziert

if ($recoveryBootstrapError !== null) {
    echo '<div class="alert alert-error"><strong>Bootstrap-Fehler:</strong> '
        . \nl2br(\htmlspecialchars(recoveryFormatUserError($recoveryBootstrapError))) . '</div>';
    recoveryRenderExceptionDetails($recoveryBootstrapError);
    recoveryRenderPageEnd();
    exit;
}

$recoveryBaseUrl = recoveryGetSiteBaseUrl();
$db = \wcf\system\WCF::getDB();

recoveryRenderGlobalNav($mode, $authHash, $recoveryBaseUrl);

?>

<?php
// ============================================================================
// MODUS 0: AUSWAHL
// ============================================================================

if ($mode === RECOVERY_MODE_SELECTION) {
?>
    <?php if (isset($_GET['auth_ok'])): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Authentifizierung erfolgreich.</strong> Sie können jetzt einen Recovery-Modus wählen.</div>
    <?php endif; ?>

    <h1>WoltLab Suite Recovery Tool</h1>
    <p class="subtitle">Wählen Sie den gewünschten Recovery-Modus</p>

    <div class="mode-grid">
        <a href="?mode=<?= RECOVERY_MODE_ACP_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-wrench"></i>
            <strong>ACP Repair</strong>
            <span>Repariert defekte ACP-Menüeinträge eines Plugins</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PLUGIN_UNINSTALL ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-trash-can"></i>
            <strong>Plugin Uninstall</strong>
            <span>Deinstalliert Plugin komplett (DB + Dateien)</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_USER_MANAGEMENT ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-users-gear"></i>
            <strong>User Management</strong>
            <span>Admin-Passwort zurücksetzen &amp; Berechtigungen</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_CACHE_CLEAR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-broom"></i>
            <strong>Cache Clear</strong>
            <span>Löscht alle Caches &amp; kompilierte Templates</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PACKAGE_LIST_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-list-check"></i>
            <strong>Paketliste reparieren</strong>
            <span>Entfernt verwaiste Queue-/Application-Einträge (ACP-Paketliste)</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_PACKAGE_FILE_REPAIR ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button">
            <i class="fa-solid fa-file-circle-plus"></i>
            <strong>Plugin-Dateien reparieren</strong>
            <span>Fehlende Klassen aus Bootstrap erkennen, aus Paket wiederherstellen, Cache leeren</span>
        </a>
        <a href="?mode=<?= RECOVERY_MODE_RECOVERY_WIZARD ?>&amp;t=<?= htmlspecialchars($authHash) ?>" class="mode-button" style="border-color:#369">
            <i class="fa-solid fa-route"></i>
            <strong>Recovery-Wizard</strong>
            <span>Halbautomatisch: Diagnose → Plan wählen → in logischer Reihenfolge ausführen</span>
        </a>
    </div>

    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info"></i> <strong>Hinweis:</strong> Dieses Tool arbeitet direkt auf Datenbank-Ebene und sollte nur im Notfall verwendet werden.
    </div>

    <div class="alert alert-warning" style="margin-top: 30px;">
        <i class="fa-solid fa-triangle-exclamation"></i> <strong>Fertig mit Recovery?</strong><br>
        Wenn Sie alle Reparaturen abgeschlossen haben, sollten Sie das Recovery Tool und alle zugehörigen Dateien löschen.<br><br>
        <a href="?action=cleanup&amp;t=<?= htmlspecialchars($authHash) ?>" class="button btn-danger" onclick="return confirm('ACHTUNG: Das Recovery Tool wird entfernt (Auth-Datei, Uploads, diese PHP-Datei) und Sie werden ins ACP weitergeleitet. Fortfahren?')">
            <i class="fa-solid fa-xmark"></i> Recovery Tool vollständig entfernen
        </a>
    </div>

<?php
}

elseif ($mode === RECOVERY_MODE_ACP_REPAIR) {
?>
    <h1>ACP Repair</h1>
    <p class="subtitle">Repariert defekte ACP-Menüeinträge eines Plugins</p>

<?php
    recoveryFormLoadingScript();
    if (recoveryWasPostTruncated()) {
        recoveryRenderPostTruncatedWarning();
    }

    $acpModeUrl = recoveryBuildModeUrl(RECOVERY_MODE_ACP_REPAIR, $authHash);

    if (recoveryAcpShouldShowInputForm()) {
?>
    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($acpModeUrl) ?>" data-recovery-loading="Paket wird analysiert …">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_ACP_REPAIR, $authHash); ?>
        <div class="form-group">
            <label>Option 1: Package-Identifier manuell eingeben</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.example.my-plugin" autocomplete="off">
            <small style="display: block; margin-top: 5px;">
                Der eindeutige Bezeichner des Plugins, dessen ACP-Menüeinträge repariert werden sollen.
            </small>
        </div>
        <button type="submit"><i class="fa-solid fa-wrench"></i> Mit Identifier reparieren</button>
    </form>

    <hr>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($acpModeUrl) ?>" data-recovery-loading="Paket wird hochgeladen und analysiert …">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_ACP_REPAIR, $authHash); ?>
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar, .tar.gz, .tgz – max. 100 MiB)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz" required>
        </div>
        <button type="submit"><i class="fa-solid fa-wrench"></i> Mit Datei reparieren</button>
    </form>
<?php
    } else {
        echo '<div id="recovery-loading-overlay" class="recovery-loading" style="display:block">Paket wird analysiert …</div>';
        try {
        $packageInput = recoveryResolvePackageInputFromRequest($authHash);
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
                    echo '<form method="POST" enctype="multipart/form-data" action="' . \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_ACP_REPAIR, $authHash)) . '">';
                    echo '<input type="hidden" name="mode" value="' . RECOVERY_MODE_ACP_REPAIR . '">';
                    echo '<input type="hidden" name="t" value="' . \htmlspecialchars($authHash, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    echo '<input type="hidden" name="force_cleanup" value="1">';
                    echo '<button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> Diese ' . $menuCount . ' Menüeinträge löschen</button>';
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
                    echo '<form method="POST" enctype="multipart/form-data" action="' . \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_ACP_REPAIR, $authHash)) . '">';
                    echo '<input type="hidden" name="mode" value="' . RECOVERY_MODE_ACP_REPAIR . '">';
                    echo '<input type="hidden" name="t" value="' . \htmlspecialchars($authHash, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    if (!$packageData) {
                        echo '<input type="hidden" name="force_cleanup" value="1">';
                    }
                    echo '<input type="hidden" name="confirm_delete" value="1">';
                    echo '<button type="submit" class="btn-danger"><i class="fa-solid fa-trash-can"></i> Alle löschen</button>';
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
                        $optionFbLog = [];
                        recoveryEnsureOptionConstantFallbacks($db, $wcfN, $optionFbLog);
                        $db->commitTransaction();
                        recoveryCleanupUploadWorkspace();

                        echo '<div class="alert alert-success">';
                        echo '<strong>✓ ACP-Repair erfolgreich!</strong><br>';
                        echo 'Gelöschte Menüeinträge: ' . $deletedCount . '<br>';
                        echo 'Cache wurde geleert.<br>';
                        foreach ($optionFbLog as $fbEntry) {
                            echo \htmlspecialchars($fbEntry) . '<br>';
                        }
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
        } catch (\Throwable $e) {
            recoveryRenderProcessingError($e);
        }
        $loadingEl = 'var o=document.getElementById("recovery-loading-overlay");if(o){o.style.display="none";}';
        echo '<script>' . $loadingEl . '</script>';
        echo '<p style="margin-top:24px"><a href="' . \htmlspecialchars($acpModeUrl) . '" class="back-link"><i class="fa-solid fa-arrow-left"></i> Neue Analyse starten</a></p>';
    }
}

// ============================================================================
// MODUS 2: PLUGIN UNINSTALL (v1.2.7 – 3-Schritt-Flow mit Backup & Dry-Run)
// ============================================================================

elseif ($mode === RECOVERY_MODE_PLUGIN_UNINSTALL) {
?>
    <h1>Plugin Uninstall</h1>
    <p class="subtitle">Deinstalliert Plugin komplett – per-Ressource-Auswahl, SQL-Backup &amp; Dry-Run</p>

<?php
    recoveryFormLoadingScript();
    if (recoveryWasPostTruncated()) {
        recoveryRenderPostTruncatedWarning();
    }

    $uninstallModeUrl = recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash);
    $uninstallStep = recoveryResolveUninstallStep();
    $showEntryForms = recoveryUninstallShouldShowInputForm();

    if ($showEntryForms) {
?>
    <div class="wizardSteps">
        <div class="wizardStep active">
            <div class="wizardStepNumber">1</div>
            <div class="wizardStepLabel">Analyse &amp; Auswahl</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">2</div>
            <div class="wizardStepLabel">Backup</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">3</div>
            <div class="wizardStepLabel">Ausführen</div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($uninstallModeUrl) ?>" data-recovery-loading="Paket wird analysiert …">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash); ?>
        <div class="form-group">
            <label>Option 1: Package-Identifier manuell eingeben</label>
            <input type="text" name="package_identifier" placeholder="z.B. de.example.my-plugin" autocomplete="off">
            <small style="display:block;margin-top:5px">Der eindeutige Package-Identifier (Reverse-Domain-Notation).</small>
        </div>
        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Analysieren</button>
    </form>

    <hr>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($uninstallModeUrl) ?>" data-recovery-loading="Paket wird hochgeladen und analysiert …">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash); ?>
        <div class="form-group">
            <label>Option 2: Package-Datei hochladen (.tar, .tar.gz, .tgz – max. 100 MiB)</label>
            <input type="file" name="package_file" accept=".tar,.tar.gz,.tgz" required>
            <small style="display:block;margin-top:5px">package.xml wird automatisch ausgelesen – DB-Analyse folgt.</small>
        </div>
        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Analysieren</button>
    </form>
<?php
    } else {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $uninstallStep === '') {
            echo '<div id="recovery-loading-overlay" class="recovery-loading" style="display:block">Paket wird analysiert …</div>';
        }
        try {
        $packageInput = recoveryResolvePackageInputFromRequest($authHash);
        if (isset($packageInput['error'])) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars($packageInput['error']) . '</div>';
        } else {
            $packageIdentifier = $packageInput['packageIdentifier'] ?? null;
            $extractDir        = $packageInput['extractDir'] ?? recoveryResolveTrustedExtractDir();

            if (!$packageIdentifier) {
                echo '<div class="alert alert-error"><strong>Fehler:</strong> Kein Package-Identifier ermittelt. Bitte erneut versuchen.</div>';
            } else {
                // Package in DB suchen
                $sql = "SELECT packageID, package, packageName, packageDir, isApplication
                        FROM wcf" . WCF_N . "_package WHERE package = ?";
                $statement = $db->prepareStatement($sql);
                $statement->execute([$packageIdentifier]);
                $packageData = $statement->fetchArray() ?: null;
                $packageID   = $packageData ? (int)$packageData['packageID'] : null;

                // Ressourcen aus Archiv (falls vorhanden)
                $resources = null;
                if ($extractDir && \is_dir($extractDir)) {
                    $resources = analyzePackageResources($extractDir, $packageIdentifier, $db);
                }
                $wcfN = $resources ? (int)$resources['wcfN'] : WCF_N;

                // ── SCHRITT 1: ANALYSE + AUSWAHL ──────────────────────────────
                if ($uninstallStep === '') {
?>
    <div class="wizardSteps">
        <div class="wizardStep active">
            <div class="wizardStepNumber">1</div>
            <div class="wizardStepLabel">Analyse &amp; Auswahl</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">2</div>
            <div class="wizardStepLabel">Backup</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">3</div>
            <div class="wizardStepLabel">Ausführen</div>
        </div>
    </div>
<?php
                    // Paket-Info-Box
                    echo '<div class="alert alert-info">';
                    echo '<strong>Paket:</strong> <code>' . \htmlspecialchars($packageIdentifier) . '</code><br>';
                    if ($packageData) {
                        echo '<strong>Status:</strong> In Datenbank gefunden (ID: <strong>' . $packageID . '</strong>)<br>';
                        echo '<strong>Name:</strong> ' . \htmlspecialchars($packageData['packageName']) . '<br>';
                        echo '<strong>WCF_N:</strong> ' . $wcfN;
                    } else {
                        echo '<strong>Status:</strong> <em>Nicht in Datenbank gefunden</em> – Installation fehlgeschlagen?<br>';
                        echo '<small>Ohne packageID sind nur Tabellen-Drops und Datei-Löschungen möglich.</small>';
                    }
                    echo '</div>';

                    // PIP-Counts aus DB (+ weitere Tabellen mit packageID-Spalte)
                    $pipMap    = recoveryGetPipResourceMap();
                    $pipCounts = $packageID ? recoveryGetPipDbCounts($db, $wcfN, $packageID) : [];
                    if ($packageID) {
                        recoveryMergeDiscoveredPipTables($pipMap, $pipCounts, $db, $wcfN, $packageID);
                    }

                    // Plugin-eigene Tabellen ermitteln
                    $customTables = [];
                    if ($resources && !empty($resources['tables'])) {
                        $customTables = $resources['tables'];
                    } else {
                        $customTables = findPackageTables($db, $packageIdentifier, $wcfN);
                    }

                    // Dateisystem prüfen
                    $fsEval = recoveryEvaluatePluginDirectoryDeletion(
                        $packageData, $packageIdentifier, $db, $wcfN, $extractDir
                    );

                    echo '<form method="POST" enctype="multipart/form-data" action="' . \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash)) . '">';
                    echo '<input type="hidden" name="mode" value="' . RECOVERY_MODE_PLUGIN_UNINSTALL . '">';
                    echo '<input type="hidden" name="t" value="' . \htmlspecialchars($authHash, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    echo '<input type="hidden" name="uninstall_step" value="1">';

                    // Dry-Run Toggle
                    echo '<div class="alert alert-warning" style="margin-bottom:20px">';
                    echo '<label style="cursor:pointer"><input type="checkbox" name="dry_run" id="recoveryDryRunToggle" value="1" style="margin-right:6px">';
                    echo '<strong>Dry-Run-Modus:</strong> Zeigt was gelöscht WÜRDE, ohne tatsächliche Änderungen vorzunehmen</label>';
                    echo '<div id="recoveryDryRunQuick" class="recovery-dryrun-quick">';
                    echo '<button type="submit" class="button" style="margin-top:12px"><i class="fa-solid fa-play"></i> Dry-Run jetzt starten</button>';
                    echo '<br><small>Startet direkt mit Dry-Run – ohne nach unten zu scrollen.</small>';
                    echo '</div>';
                    echo '</div>';

                    // ── DB-Einträge nach packageID ────────────────────────────
                    if ($packageID) {
                        $hasSafeRows = false;
                        foreach ($pipCounts as $cnt) {
                            if ($cnt > 0) { $hasSafeRows = true; break; }
                        }

                        echo '<h2 style="margin-bottom:10px">DB-Einträge nach packageID</h2>';
                        echo '<p style="margin-bottom:12px"><small>Nur Einträge mit <code>packageID = ' . $packageID . '</code> werden gelöscht – keine Massenlöschungen. '
                            . 'Klicken Sie auf die <strong>Zahl</strong> in „Einträge“, um die betroffenen Zeilen zu sehen.</small></p>';
                        echo '<table class="table">';
                        echo '<thead><tr>';
                        echo '<th style="width:36px"><input type="checkbox" id="chkAllPip" title="Alle aus/abwählen"></th>';
                        echo '<th>Kategorie (PIP)</th><th>Tabelle</th><th style="text-align:right">Einträge</th>';
                        echo '</tr></thead><tbody>';

                        foreach ($pipMap as $pip => $info) {
                            if (!$info['safe'] || $info['col'] !== 'packageID' || $info['table'] === '') {
                                continue;
                            }
                            $count = $pipCounts[$pip] ?? 0;
                            if ($count < 0) {
                                // Tabelle existiert nicht
                                echo '<tr style="opacity:.4">';
                                echo '<td><input type="checkbox" name="pip_select[]" value="' . \htmlspecialchars($pip) . '" disabled></td>';
                                echo '<td>' . \htmlspecialchars($info['label']) . '</td>';
                                echo '<td><code>wcf' . $wcfN . '_' . \htmlspecialchars($info['table']) . '</code></td>';
                                echo '<td style="text-align:right"><small>–</small></td>';
                                echo '</tr>';
                            } else {
                                $checked = $count > 0 ? ' checked' : '';
                                $dim     = $count === 0 ? ' style="opacity:.55"' : '';
                                echo '<tr' . $dim . '>';
                                echo '<td><input type="checkbox" name="pip_select[]" value="' . \htmlspecialchars($pip) . '"' . $checked . '></td>';
                                echo '<td>' . \htmlspecialchars($info['label']) . '</td>';
                                echo '<td><code>wcf' . $wcfN . '_' . \htmlspecialchars($info['table']) . '</code></td>';
                                echo '<td style="text-align:right">'
                                    . recoveryRenderPipCountCell($count, $info['table'], $packageID) . '</td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table>';
                        echo '<div id="recoveryPipPreviewModal" hidden>';
                        echo '<div class="recovery-pip-preview-dialog" role="dialog" aria-modal="true">';
                        echo '<h3 id="recoveryPipPreviewTitle">Einträge</h3>';
                        echo '<div id="recoveryPipPreviewBody"></div>';
                        echo '<p style="margin-top:16px"><button type="button" class="button" id="recoveryPipPreviewClose">Schließen</button></p>';
                        echo '</div></div>';
                        echo '<script>
                            (function () {
                                var authToken = ' . \json_encode($authHash) . ';
                                var dryToggle = document.getElementById("recoveryDryRunToggle");
                                var dryQuick = document.getElementById("recoveryDryRunQuick");
                                if (dryToggle && dryQuick) {
                                    dryToggle.addEventListener("change", function () {
                                        dryQuick.style.display = dryToggle.checked ? "block" : "none";
                                    });
                                }
                                var counts = ' . \json_encode($pipCounts) . ';
                                var allChecked = Object.values(counts).some(function (v) { return v > 0; });
                                var chkAllPip = document.getElementById("chkAllPip");
                                if (chkAllPip) {
                                    chkAllPip.checked = allChecked;
                                    chkAllPip.addEventListener("change", function () {
                                        document.querySelectorAll("input[name=\\"pip_select[]\\"]:not(:disabled)").forEach(function (c) {
                                            c.checked = chkAllPip.checked;
                                        });
                                    });
                                }
                                var modal = document.getElementById("recoveryPipPreviewModal");
                                var modalBody = document.getElementById("recoveryPipPreviewBody");
                                var modalTitle = document.getElementById("recoveryPipPreviewTitle");
                                var modalClose = document.getElementById("recoveryPipPreviewClose");
                                function escapeHtml(s) {
                                    var d = document.createElement("div");
                                    d.textContent = s;
                                    return d.innerHTML;
                                }
                                function closeModal() { if (modal) { modal.hidden = true; } }
                                if (modalClose) { modalClose.addEventListener("click", closeModal); }
                                if (modal) {
                                    modal.addEventListener("click", function (e) {
                                        if (e.target === modal) { closeModal(); }
                                    });
                                }
                                document.querySelectorAll(".recovery-pip-count-btn").forEach(function (btn) {
                                    btn.addEventListener("click", function () {
                                        var table = btn.getAttribute("data-table");
                                        var packageId = btn.getAttribute("data-package-id");
                                        if (!table || !packageId) { return; }
                                        modalTitle.textContent = "Lade …";
                                        modalBody.innerHTML = "<p>Bitte warten …</p>";
                                        modal.hidden = false;
                                        var previewUrl = new URL(window.location.href);
                                        previewUrl.search = "";
                                        previewUrl.searchParams.set("action", "pip-preview");
                                        previewUrl.searchParams.set("t", authToken);
                                        previewUrl.searchParams.set("table", table);
                                        previewUrl.searchParams.set("package_id", packageId);
                                        fetch(previewUrl.toString(), { credentials: "same-origin" })
                                            .then(function (r) {
                                                return r.text().then(function (text) {
                                                    if (!text) {
                                                        throw new Error("Leere Server-Antwort (HTTP " + r.status + ")");
                                                    }
                                                    try {
                                                        return JSON.parse(text);
                                                    } catch (parseErr) {
                                                        throw new Error("Keine gültige JSON-Antwort: " + text.substring(0, 200));
                                                    }
                                                });
                                            })
                                            .then(function (data) {
                                                if (!data.ok) {
                                                    modalBody.innerHTML = "<p class=\\"alert alert-error\\">"
                                                        + escapeHtml(data.error || "Fehler") + "</p>";
                                                    return;
                                                }
                                                modalTitle.textContent = data.table + " (" + data.total + " Einträge)";
                                                if (!data.rows || data.rows.length === 0) {
                                                    modalBody.innerHTML = "<p><em>Keine Zeilen gefunden.</em></p>";
                                                    return;
                                                }
                                                var html = "<p><small>Vorschau (max. " + data.rows.length
                                                    + " von " + data.total + "):</small></p>";
                                                html += "<table class=\\"table\\"><thead><tr>";
                                                (data.columns || []).forEach(function (c) {
                                                    html += "<th>" + escapeHtml(c) + "</th>";
                                                });
                                                html += "</tr></thead><tbody>";
                                                data.rows.forEach(function (row) {
                                                    html += "<tr>";
                                                    (data.columns || []).forEach(function (c) {
                                                        var val = row[c];
                                                        if (val === null || val === undefined) { val = "—"; }
                                                        else if (String(val).length > 120) {
                                                            val = String(val).substring(0, 117) + "…";
                                                        }
                                                        html += "<td><code>" + escapeHtml(String(val)) + "</code></td>";
                                                    });
                                                    html += "</tr>";
                                                });
                                                html += "</tbody></table>";
                                                modalBody.innerHTML = html;
                                            })
                                            .catch(function (err) {
                                                modalBody.innerHTML = "<p class=\\"alert alert-error\\">"
                                                    + escapeHtml(String(err)) + "</p>";
                                            });
                                    });
                                });
                            })();
                        </script>';
                    } else {
                        echo '<div class="alert alert-warning">Keine packageID – DB-Einträge per packageID nicht analysierbar.</div>';
                    }

                    // ── Plugin-eigene Tabellen (DROP TABLE) ───────────────────
                    echo '<h2 style="margin:24px 0 10px">Plugin-eigene Tabellen (DROP TABLE)</h2>';
                    if (!empty($customTables)) {
                        echo '<table class="table">';
                        echo '<thead><tr><th style="width:36px">&#x2713;</th><th>Tabellenname</th><th style="text-align:right">Einträge</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($customTables as $table) {
                            $safeTable = \str_replace('`', '', (string)$table);
                            if (!recoveryValidateSqlTableName($safeTable)) {
                                continue;
                            }
                            $cnt = '?';
                            try {
                                $st = $db->prepareStatement('SELECT COUNT(*) AS c FROM `' . $safeTable . '`');
                                $st->execute();
                                $cnt = (int)($st->fetchArray()['c'] ?? 0);
                            } catch (\Throwable $ignored) {}
                            echo '<tr>';
                            echo '<td><input type="checkbox" name="drop_tables[]" value="' . \htmlspecialchars($safeTable) . '" checked></td>';
                            echo '<td><code>' . \htmlspecialchars($safeTable) . '</code></td>';
                            echo '<td style="text-align:right">' . $cnt . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    } else {
                        echo '<p style="color:#999"><em>Keine plugin-eigenen Tabellen gefunden.</em></p>';
                    }

                    // ── Dateisystem ───────────────────────────────────────────
                    echo '<h2 style="margin:24px 0 10px"><i class="fa-solid fa-folder-open"></i> Dateisystem</h2>';
                    if ($fsEval['deletable']) {
                        echo '<div class="alert alert-warning">';
                        echo '<label style="cursor:pointer"><input type="checkbox" name="delete_files" value="1"> ';
                        echo 'Plugin-Verzeichnis <code>' . \htmlspecialchars((string)$fsEval['relativePath']) . '/</code> auf dem Server löschen</label>';
                        echo '<br><small style="margin-top:6px;display:block">Sicherheitsprüfung: nur wenn Pfad innerhalb WCF_DIR und kein geschütztes Verzeichnis.</small>';
                        echo '</div>';
                    } else {
                        echo '<div class="alert alert-info"><strong>Dateisystem:</strong> ' . \htmlspecialchars($fsEval['reason']) . '</div>';
                    }

                    echo '<div style="margin-top:28px">';
                    echo '<button type="submit" class="btn-danger"><i class="fa-solid fa-play"></i> Weiter: Backup &amp; Ausführen</button>';
                    echo '</div>';
                    echo '</form>';

                // ── SCHRITT 2: BACKUP ─────────────────────────────────────────
                } elseif ($uninstallStep === '1') {
                    $isDryRun      = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
                    $selectedPips  = \is_array($_POST['pip_select'] ?? null)  ? (array)$_POST['pip_select']  : [];
                    $dropTables    = \is_array($_POST['drop_tables'] ?? null)  ? (array)$_POST['drop_tables']  : [];
                    $deleteFiles   = !empty($_POST['delete_files']) && $_POST['delete_files'] === '1';

                    // Eingaben validieren
                    $pipMap    = recoveryGetPipResourceMap();
                    $validPips = \array_values(\array_filter($selectedPips, fn($p) => isset($pipMap[$p]) && $pipMap[$p]['safe'] && $pipMap[$p]['table'] !== ''));
                    $validDropTables = [];
                    foreach ($dropTables as $t) {
                        $s = \str_replace('`', '', (string)$t);
                        if (recoveryValidateSqlTableName($s)) {
                            $validDropTables[] = $s;
                        }
                    }
?>
    <div class="wizardSteps">
        <div class="wizardStep completed">
            <div class="wizardStepNumber">1</div>
            <div class="wizardStepLabel">Analyse &amp; Auswahl</div>
        </div>
        <div class="wizardStep active">
            <div class="wizardStepNumber">2</div>
            <div class="wizardStepLabel">Backup</div>
        </div>
        <div class="wizardStep">
            <div class="wizardStepNumber">3</div>
            <div class="wizardStepLabel"><?= $isDryRun ? 'Dry-Run' : 'Ausführen' ?></div>
        </div>
    </div>
<?php
                    // SQL-Backup generieren
                    $backupSql = '';
                    if ($packageID && !empty($validPips)) {
                        $backupSql = recoveryGenerateSqlBackup($db, $wcfN, $packageID, $validPips);
                    }

                    if ($backupSql !== '') {
                        $backupB64 = \base64_encode($backupSql);
                        echo '<h2><i class="fa-solid fa-database"></i> SQL-Backup der betroffenen Zeilen</h2>';
                        echo '<div class="alert alert-info">';
                        echo '<strong>Backup für packageID = ' . $packageID . '</strong><br>';
                        echo '<small>Enthält alle Zeilen aus den ausgewählten Tabellen – bitte vor dem Ausführen herunterladen oder kopieren.</small>';
                        echo '<br><br>';
                        // Server-seitiger Download via POST
                        echo '<form method="POST" action="?action=download-sql&amp;t=' . \htmlspecialchars($authHash) . '" style="display:inline;margin-right:10px">';
                        echo '<input type="hidden" name="sql_b64" value="' . \htmlspecialchars($backupB64) . '">';
                        echo '<button type="submit" class="button"><i class="fa-solid fa-download"></i> SQL-Backup herunterladen (.sql)</button>';
                        echo '</form>';
                        // Client-seitiger JS-Download (Fallback)
                        echo '<button type="button" class="button" id="recoveryJsSqlDownload" style="margin-left:8px">';
                        echo '<i class="fa-solid fa-download"></i> JS-Download</button>';
                        echo '<script>(function(){var el=document.getElementById("recoveryJsSqlDownload");';
                        echo 'if(!el){return;}el.addEventListener("click",function(){';
                        echo 'var s=atob(' . \json_encode($backupB64) . ');';
                        echo 'var b=new Blob([s],{type:"text/plain;charset=utf-8"});';
                        echo 'var a=document.createElement("a");a.href=URL.createObjectURL(b);';
                        echo 'a.download="recovery-backup-' . \date('Y-m-d-His') . '.sql";document.body.appendChild(a);';
                        echo 'a.click();document.body.removeChild(a);URL.revokeObjectURL(a.href);});})();</script>';
                        echo '<br><br>';
                        echo '<details><summary style="cursor:pointer">SQL-Inhalt anzeigen (' . \number_format(\strlen($backupSql)) . ' Bytes)</summary>';
                        echo '<textarea style="width:100%;height:220px;margin-top:10px;font-size:12px;font-family:monospace;background:#2D2D2D;color:#c0c0c0;border:1px solid #444;padding:10px;border-radius:3px;box-sizing:border-box" readonly>';
                        echo \htmlspecialchars(\substr($backupSql, 0, 50000)) . (\strlen($backupSql) > 50000 ? "\n-- [gekürzt …]" : '');
                        echo '</textarea>';
                        echo '</details>';
                        echo '</div>';
                    } else {
                        echo '<div class="alert alert-info">';
                        echo '<strong>Kein SQL-Backup erforderlich</strong><br>';
                        if (!$packageID) {
                            echo '<small>Ohne packageID können keine Zeilen gesichert werden.</small>';
                        } else {
                            echo '<small>Keine Zeilen in den ausgewählten Tabellen gefunden.</small>';
                        }
                        echo '</div>';
                    }

                    // Zusammenfassung der geplanten Aktionen
                    echo '<h2 style="margin-top:24px"><i class="fa-solid fa-database"></i> Geplante Aktionen ' . ($isDryRun ? '<span style="color:#c93">(Dry-Run)</span>' : '') . '</h2>';
                    echo '<div class="alert ' . ($isDryRun ? 'alert-warning' : 'alert-error') . '">';
                    if ($isDryRun) {
                        echo '<strong>&#128065; Dry-Run – keine Änderungen werden vorgenommen</strong><br><br>';
                    }

                    if (!empty($validPips) && $packageID) {
                        echo '<strong>DB-Löschungen (WHERE packageID = ' . $packageID . '):</strong><br>';
                        foreach ($validPips as $pip) {
                            echo '&bull; <code>wcf' . $wcfN . '_' . \htmlspecialchars($pipMap[$pip]['table']) . '</code> – ' . \htmlspecialchars($pipMap[$pip]['label']) . '<br>';
                        }
                        echo '&bull; <code>wcf' . $wcfN . '_package</code> – Package-Eintrag (ID ' . $packageID . ')<br>';
                        echo '&bull; Package-Queue, Requirements, SQL-Log, File-Log<br><br>';
                    } elseif (empty($validPips)) {
                        echo '<em>Keine DB-Kategorien ausgewählt.</em><br><br>';
                    }

                    if (!empty($validDropTables)) {
                        echo '<strong>DROP TABLE:</strong><br>';
                        foreach ($validDropTables as $t) {
                            echo '&bull; <code>' . \htmlspecialchars($t) . '</code><br>';
                        }
                        echo '<br>';
                    }

                    if ($deleteFiles) {
                        $fsEval2 = recoveryEvaluatePluginDirectoryDeletion($packageData, $packageIdentifier, $db, $wcfN, $extractDir);
                        if ($fsEval2['deletable']) {
                            echo '<strong>Dateisystem:</strong> Verzeichnis <code>' . \htmlspecialchars((string)$fsEval2['relativePath']) . '/</code> wird gelöscht<br>';
                        } else {
                            echo '<strong>Dateisystem:</strong> ' . \htmlspecialchars($fsEval2['reason']) . ' (kein Löschen)<br>';
                        }
                    }
                    echo '</div>';

                    // Formular mit allen Selektionen als Hidden-Inputs → Step 3 (Execute)
                    echo '<form method="POST" enctype="multipart/form-data" action="' . \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash)) . '">';
                    echo '<input type="hidden" name="mode" value="' . RECOVERY_MODE_PLUGIN_UNINSTALL . '">';
                    echo '<input type="hidden" name="t" value="' . \htmlspecialchars($authHash, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<input type="hidden" name="package_identifier" value="' . \htmlspecialchars($packageIdentifier) . '">';
                    if ($extractDir) {
                        echo '<input type="hidden" name="extract_dir" value="' . \htmlspecialchars($extractDir) . '">';
                    }
                    echo '<input type="hidden" name="uninstall_step" value="2">';
                    if ($isDryRun) {
                        echo '<input type="hidden" name="dry_run" value="1">';
                    }
                    if ($deleteFiles) {
                        echo '<input type="hidden" name="delete_files" value="1">';
                    }
                    foreach ($validPips as $pip) {
                        echo '<input type="hidden" name="pip_select[]" value="' . \htmlspecialchars($pip) . '">';
                    }
                    foreach ($validDropTables as $t) {
                        echo '<input type="hidden" name="drop_tables[]" value="' . \htmlspecialchars($t) . '">';
                    }
                    $btnLabel = $isDryRun ? '<i class="fa-solid fa-play"></i> Dry-Run starten' : '<i class="fa-solid fa-trash-can"></i> Jetzt ausführen (nicht rückgängig!)';
                    $btnClass = $isDryRun ? 'button' : 'button btn-danger';
                    echo '<button type="submit" class="' . $btnClass . '">' . $btnLabel . '</button>';
                    echo '</form>';

                // ── SCHRITT 3: AUSFÜHREN ──────────────────────────────────────
                } elseif ($uninstallStep === '2') {
                    $isDryRun      = !empty($_POST['dry_run']) && $_POST['dry_run'] === '1';
                    $selectedPips  = \is_array($_POST['pip_select'] ?? null)  ? (array)$_POST['pip_select']  : [];
                    $dropTables    = \is_array($_POST['drop_tables'] ?? null)  ? (array)$_POST['drop_tables']  : [];
                    $deleteFiles   = !empty($_POST['delete_files']) && $_POST['delete_files'] === '1';

                    $pipMap    = recoveryGetPipResourceMap();
                    $validPips = \array_values(\array_filter($selectedPips, fn($p) => isset($pipMap[$p]) && $pipMap[$p]['safe'] && $pipMap[$p]['table'] !== ''));
                    $validDropTables = [];
                    foreach ($dropTables as $t) {
                        $s = \str_replace('`', '', (string)$t);
                        if (recoveryValidateSqlTableName($s)) {
                            $validDropTables[] = $s;
                        }
                    }
?>
    <div class="wizardSteps">
        <div class="wizardStep completed">
            <div class="wizardStepNumber">1</div>
            <div class="wizardStepLabel">Analyse &amp; Auswahl</div>
        </div>
        <div class="wizardStep completed">
            <div class="wizardStepNumber">2</div>
            <div class="wizardStepLabel">Backup</div>
        </div>
        <div class="wizardStep active">
            <div class="wizardStepNumber">3</div>
            <div class="wizardStepLabel"><?= $isDryRun ? 'Dry-Run' : 'Ausführen' ?></div>
        </div>
    </div>
<?php
                    $log = [];

                    try {
                        // ── DB-Bereinigung nach packageID ─────────────────────
                        if ($packageID && !empty($validPips)) {
                            foreach ($validPips as $pip) {
                                $info = $pipMap[$pip];
                                if ($isDryRun) {
                                    try {
                                        $st = $db->prepareStatement("SELECT COUNT(*) AS cnt FROM wcf{$wcfN}_{$info['table']} WHERE packageID = ?");
                                        $st->execute([$packageID]);
                                        $r = $st->fetchArray();
                                        $log[] = '[DRY-RUN] WÜRDE LÖSCHEN: wcf' . $wcfN . '_' . $info['table'] . ' – ' . (int)($r['cnt'] ?? 0) . ' Einträge';
                                    } catch (\Throwable $e) {
                                        $log[] = '[DRY-RUN] ' . $info['label'] . ': Tabelle nicht vorhanden';
                                    }
                                } else {
                                    recoveryTryDeleteByPackageId($db, $wcfN, $info['table'], $packageID, $info['label'], $log);
                                }
                            }
                        }

                        // ── Package-Infrastruktur ─────────────────────────────
                        if ($packageID) {
                            if ($isDryRun) {
                                $log[] = '[DRY-RUN] WÜRDE LÖSCHEN: Package-Queue, Nodes, Forms, Requirements, SQL-Log, Package-Eintrag';
                            } else {
                                recoveryCleanupPackageInstallationArtifacts($db, $wcfN, $packageID, $packageIdentifier, $log);
                                recoveryCleanupPackageUpdateEntries($db, $wcfN, $packageIdentifier, $log);
                                recoveryTryDeletePackageRequirements($db, $wcfN, $packageID, $log);
                                recoveryTryExecuteDelete(
                                    $db,
                                    "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE packageID = ?",
                                    [$packageID],
                                    'Package SQL-Log',
                                    $log
                                );
                                recoveryTryExecuteDelete(
                                    $db,
                                    "DELETE FROM wcf{$wcfN}_package WHERE packageID = ?",
                                    [$packageID],
                                    'Package-Eintrag',
                                    $log
                                );
                            }
                        }

                        // ── Plugin-eigene Tabellen droppen ────────────────────
                        foreach ($validDropTables as $table) {
                            if ($isDryRun) {
                                $log[] = '[DRY-RUN] WÜRDE DROP TABLE: ' . $table;
                            } else {
                                try {
                                    $stmt = $db->prepareStatement('DROP TABLE IF EXISTS `' . $table . '`');
                                    $stmt->execute();
                                    $log[] = 'Tabelle gelöscht: ' . $table;
                                } catch (\Throwable $e) {
                                    $log[] = 'DROP TABLE fehlgeschlagen (' . $table . '): ' . $e->getMessage();
                                }
                            }
                        }

                        // ── Dateisystem ───────────────────────────────────────
                        if ($deleteFiles) {
                            recoveryDeletePluginFilesOnDisk(
                                $packageData, $packageIdentifier, $log, !$isDryRun, $db, $wcfN, $extractDir
                            );
                        }

                        // ── options.inc.php + Cache ───────────────────────────
                        if (!$isDryRun) {
                            $optionConstants = recoveryCollectOptionConstantNames($db, $wcfN, $packageID);
                            if (recoveryRebuildOptionsIncPhp()) {
                                $log[] = 'options.inc.php neu erzeugt';
                            } elseif (!empty($optionConstants)) {
                                recoveryStripConstantsFromOptionsIncPhp($optionConstants);
                                $log[] = 'options.inc.php bereinigt (' . \count($optionConstants) . ' Konstanten entfernt)';
                            }
                            recoveryEnsureOptionConstantFallbacks($db, $wcfN, $log);
                            $deletedCacheFiles = clearCompiledTemplates();
                            $log[] = 'Cache gelöscht: ' . $deletedCacheFiles . ' Dateien';
                            recoveryCleanupUploadWorkspace();
                        }

                        // ── Ergebnis anzeigen ─────────────────────────────────
                        $resultClass = $isDryRun ? 'alert-warning' : 'alert-success';
                        echo '<div class="alert ' . $resultClass . '">';
                        echo '<strong>' . ($isDryRun ? '&#128065; Dry-Run abgeschlossen – keine Änderungen vorgenommen' : '&#10003; Plugin-Bereinigung abgeschlossen!') . '</strong><br><br>';
                        echo '<strong>Protokoll:</strong><br>';
                        foreach ($log as $entry) {
                            echo '&bull; ' . \htmlspecialchars($entry) . '<br>';
                        }

                        echo '</div>';

                        if (!$isDryRun) {
                            $cacheAgainUrl = \htmlspecialchars(
                                recoveryBuildModeUrl(RECOVERY_MODE_CACHE_CLEAR, $authHash),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            echo '<div class="alert alert-info">';
                            echo '<strong>ACP lädt nicht oder zeigt Fatal Error?</strong><br>';
                            echo 'Zusätzlich wurde <code>options.inc.php</code> mit einem markierten Fallback-Block ergänzt (<code>if (!defined(&#8230;)) define(&#8230;)</code>) für <strong>alle</strong> Optionen aus der DB plus Konstanten-Erkennung aus kompilierten Templates. ';
                            echo 'Nach Plugin-Problemen bleiben oft <em>kompilierte Templates</em> ohne passende globale Konstanten (Log: '
                                . '<code>Undefined constant &quot;&hellip;&quot;</code>). ';
                            echo 'Das Tool hat den Datei-Cache geleert; bei Bedarf <strong>Caches erneut leeren:</strong> Modus Cache Clear.';
                            echo '<br><br><a href="' . $cacheAgainUrl . '" class="button">';
                            echo '<i class="fa-solid fa-broom"></i> Cache Clear öffnen</a>';
                            echo '<br><br><small>Plugin-Fix: Konstanten immer mit <code>defined(\'CONST\')</code> oder Standardwert in Templates nutzen. '
                                . 'Nach fehlgeschlagener Installation hilft häufig manuell: <code>acp/templates/compiled/</code> leeren.';
                            echo '</small></div>';
                        }

                    } catch (\Throwable $e) {
                        echo '<div class="alert alert-error">';
                        echo '<strong>Fehler bei Deinstallation:</strong><br>';
                        echo \nl2br(\htmlspecialchars(recoveryFormatUserError($e)));
                        recoveryRenderExceptionDetails($e);
                        echo '</div>';
                    }
                } else {
                    echo '<div class="alert alert-error"><strong>Fehler:</strong> Unbekannter Wizard-Schritt (uninstall_step='
                        . \htmlspecialchars($uninstallStep) . ').</div>';
                }
            }
        }
        } catch (\Throwable $e) {
            recoveryRenderProcessingError($e);
        }
        echo '<script>var o=document.getElementById("recovery-loading-overlay");if(o){o.style.display="none";}</script>';
        echo '<p style="margin-top:24px"><a href="' . \htmlspecialchars($uninstallModeUrl) . '" class="back-link"><i class="fa-solid fa-arrow-left"></i> Neue Analyse starten</a></p>';
    }
}

// ============================================================================
// MODUS 3: USER MANAGEMENT
// ============================================================================

elseif ($mode === RECOVERY_MODE_USER_MANAGEMENT) {
    $umBaseUrl = '?mode=' . RECOVERY_MODE_USER_MANAGEMENT . '&t=' . \htmlspecialchars($authHash);
    $umUid     = isset($_GET['um_uid']) ? (int)$_GET['um_uid'] : 0;
    $umMessages = [];
    $umErrors   = [];

    // --- POST-Aktionen verarbeiten ---
    if ($umUid > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $umAction = $_POST['um_action'] ?? '';
        try {
            switch ($umAction) {
                case 'reset_password':
                    $newPwd = recoveryUserGenerateRandomPassword();
                    recoveryUserResetPassword($db, $umUid, $newPwd);
                    $umMessages[] = 'Passwort wurde auf <code>' . \htmlspecialchars($newPwd) . '</code> gesetzt. Bitte sofort notieren!';
                    break;

                case 'reset_password_custom':
                    $customPwd = \trim($_POST['custom_password'] ?? '');
                    if ($customPwd === '') {
                        $umErrors[] = 'Bitte ein Passwort eingeben.';
                    } elseif (\strlen($customPwd) < 8) {
                        $umErrors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
                    } else {
                        recoveryUserResetPassword($db, $umUid, $customPwd);
                        $umMessages[] = 'Passwort wurde erfolgreich gesetzt.';
                    }
                    break;

                case 'set_groups':
                    $groupIDs = isset($_POST['group_ids']) && \is_array($_POST['group_ids'])
                        ? \array_map('intval', $_POST['group_ids'])
                        : [];
                    recoveryUserSetGroups($db, $umUid, $groupIDs);
                    $umMessages[] = 'Gruppenmitgliedschaften wurden aktualisiert.';
                    break;

                case 'add_admin':
                    $currentGIDs = recoveryUserGetGroupIDs($db, $umUid);
                    if (!\in_array(4, $currentGIDs, true)) {
                        $currentGIDs[] = 4;
                        recoveryUserSetGroups($db, $umUid, $currentGIDs);
                        $umMessages[] = 'Benutzer wurde zur Administrator-Gruppe (ID&nbsp;4) hinzugefügt.';
                    } else {
                        $umMessages[] = 'Benutzer ist bereits in der Administrator-Gruppe.';
                    }
                    break;

                case 'change_email':
                    $newEmail = \trim($_POST['new_email'] ?? '');
                    if ($newEmail === '' || !\filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                        $umErrors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
                    } else {
                        recoveryUserChangeEmail($db, $umUid, $newEmail);
                        $umMessages[] = 'E-Mail-Adresse auf <code>' . \htmlspecialchars($newEmail) . '</code> geändert.';
                    }
                    break;

                case 'activate':
                    recoveryUserActivate($db, $umUid);
                    $umMessages[] = 'Benutzer wurde aktiviert und Sperre aufgehoben.';
                    break;

                case 'disable_2fa':
                    recoveryUserDisable2FA($db, $umUid);
                    $umMessages[] = 'Zwei-Faktor-Authentifizierung wurde deaktiviert und alle 2FA-Setups gelöscht.';
                    break;
            }
        } catch (\Throwable $e) {
            $umErrors[] = 'Fehler: ' . \htmlspecialchars(recoveryFormatUserError($e));
            recoveryRenderExceptionDetails($e);
        }
    }
?>
    <h1>User Management</h1>
    <p class="subtitle">Benutzersuche, Passwort-Reset, Gruppen, E-Mail &amp; Kontoverwaltung</p>

<?php if ($umUid > 0):
    $umUser = recoveryUserGetByID($db, $umUid);
    if ($umUser === null): ?>
    <div class="alert alert-error">Benutzer mit ID <code><?= $umUid ?></code> nicht gefunden.</div>
    <a href="<?= $umBaseUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Zurück zur Suche</a>
<?php else:
    $currentGroupIDs = recoveryUserGetGroupIDs($db, (int)$umUser['userID']);
?>
    <a href="<?= $umBaseUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Anderen Benutzer suchen</a>

    <h2>Benutzer: <code><?= \htmlspecialchars($umUser['username']) ?></code> <small style="font-size:16px; color:#9D9D9D;">(ID&nbsp;<?= (int)$umUser['userID'] ?>)</small></h2>

    <table style="margin-bottom: 24px;">
        <tbody>
            <tr><th style="width:160px">Benutzername</th><td><?= \htmlspecialchars($umUser['username']) ?></td></tr>
            <tr><th>E-Mail</th><td><?= \htmlspecialchars($umUser['email']) ?></td></tr>
            <tr><th>Status</th><td>
                <?php if ($umUser['banned']): ?>
                    <span style="color:#e74c3c">&#9632; Gesperrt</span>
                <?php elseif ($umUser['activationCode'] != 0): ?>
                    <span style="color:#f39c12">&#9632; Aktivierung ausstehend</span>
                <?php else: ?>
                    <span style="color:#00bc8c">&#9632; Aktiv</span>
                <?php endif; ?>
            </td></tr>
            <tr><th>2FA</th><td><?= $umUser['multifactorActive'] ? '<span style="color:#f39c12">Aktiv</span>' : '<span style="color:#9D9D9D">Inaktiv</span>' ?></td></tr>
            <tr><th>Gruppen</th><td><?= \implode(', ', $currentGroupIDs) ?></td></tr>
        </tbody>
    </table>

    <?php foreach ($umErrors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div>
    <?php endforeach; ?>
    <?php foreach ($umMessages as $msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
    <?php endforeach; ?>

    <!-- ── Passwort zurücksetzen ──────────────────────────────────────── -->
    <h2><i class="fa-solid fa-key"></i> Passwort zurücksetzen</h2>
    <p style="margin:0 0 16px; font-size:13px; color:#9D9D9D;">Wie im <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">offiziellen WoltLab Recovery Tool</a>: zufälliges Passwort bestätigen oder ein eigenes setzen.</p>
    <div class="recovery-option-cards">
        <div class="recovery-option-card recovery-card">
            <h3><i class="fa-solid fa-dice"></i> Zufälliges Passwort</h3>
            <p style="margin:0 0 16px; font-size:13px; color:#9D9D9D;">Wird nach dem Setzen <strong>einmalig</strong> angezeigt – bitte sofort notieren.</p>
            <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
                <input type="hidden" name="um_action" value="reset_password">
                <button type="submit" class="btn-danger"><i class="fa-solid fa-key"></i> Zufälliges Passwort setzen</button>
            </form>
        </div>
        <div class="recovery-option-card recovery-card">
            <h3><i class="fa-solid fa-pen"></i> Eigenes Passwort</h3>
            <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
                <input type="hidden" name="um_action" value="reset_password_custom">
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="um_custom_pwd">Neues Passwort (min. 8 Zeichen)</label>
                    <input type="password" id="um_custom_pwd" name="custom_password" autocomplete="new-password" placeholder="Passwort eingeben">
                </div>
                <button type="submit"><i class="fa-solid fa-key"></i> Passwort setzen</button>
            </form>
        </div>
    </div>

    <!-- ── E-Mail ändern ──────────────────────────────────────────────── -->
    <h2><i class="fa-solid fa-envelope"></i> E-Mail-Adresse ändern</h2>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="change_email">
        <div class="form-group">
            <label for="um_email">Neue E-Mail-Adresse</label>
            <input type="text" id="um_email" name="new_email" value="<?= \htmlspecialchars($umUser['email']) ?>" placeholder="neue@email.de">
        </div>
        <button type="submit"><i class="fa-solid fa-envelope"></i> E-Mail ändern</button>
    </form>

    <!-- ── Konto aktivieren / Sperre aufheben ────────────────────────── -->
    <?php if ($umUser['banned'] || $umUser['activationCode'] != 0): ?>
    <h2><i class="fa-solid fa-user"></i> Konto aktivieren &amp; Sperre aufheben</h2>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Setzt <code>activationCode&nbsp;=&nbsp;0</code>, <code>banned&nbsp;=&nbsp;0</code> und löscht den Sperr-Grund.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="activate">
        <button type="submit" class="btn-success"><i class="fa-solid fa-circle-check"></i> Benutzer aktivieren &amp; entsperren</button>
    </form>
    <?php endif; ?>

    <!-- ── 2FA deaktivieren ───────────────────────────────────────────── -->
    <?php if ($umUser['multifactorActive']): ?>
    <h2><i class="fa-solid fa-shield-halved"></i> Zwei-Faktor-Authentifizierung deaktivieren</h2>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Löscht alle 2FA-Setups (inkl. Backup-Codes) und setzt <code>multifactorActive&nbsp;=&nbsp;0</code>.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="disable_2fa">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-shield-halved"></i> 2FA deaktivieren</button>
    </form>
    <?php endif; ?>

    <!-- ── Schnell zur Administrator-Gruppe ──────────────────────────── -->
    <h2><i class="fa-solid fa-users-gear"></i> Administrator-Gruppe (ID&nbsp;4)</h2>
    <?php if (\in_array(4, $currentGroupIDs, true)): ?>
    <div class="alert alert-info">Benutzer ist bereits in der Administrator-Gruppe (ID&nbsp;4).</div>
    <?php else: ?>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Fügt den Benutzer direkt zur WoltLab-Standard-Administrator-Gruppe (groupID&nbsp;4) hinzu.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="add_admin">
        <button type="submit" class="btn-success"><i class="fa-solid fa-users-gear"></i> Zur Administrator-Gruppe hinzufügen</button>
    </form>
    <?php endif; ?>

    <!-- ── Alle Gruppen verwalten ─────────────────────────────────────── -->
    <h2><i class="fa-solid fa-sliders"></i> Alle Gruppen verwalten</h2>
    <?php $allGroups = recoveryUserGetAllGroups($db); ?>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="set_groups">
        <table>
            <thead>
                <tr>
                    <th style="width:1px"></th>
                    <th style="width:55px">ID</th>
                    <th>Gruppe</th>
                    <th style="width:80px">Typ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allGroups as $grp):
                $gid      = (int)$grp['groupID'];
                $isSystem = \in_array($gid, [1, 2], true);
                $isMember = \in_array($gid, $currentGroupIDs, true);
                $groupType = (int) $grp['groupType'];
                if ($groupType === 1) {
                    $gType = 'System';
                } elseif ($groupType === 4) {
                    $gType = 'Admin';
                } else {
                    $gType = 'Normal';
                }
            ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" name="group_ids[]" id="grp_<?= $gid ?>"
                            value="<?= $gid ?>"
                            <?= $isMember ? 'checked' : '' ?>
                            <?= $isSystem ? 'disabled' : '' ?>>
                        <?php if ($isSystem): ?>
                        <input type="hidden" name="group_ids[]" value="<?= $gid ?>">
                        <?php endif; ?>
                    </td>
                    <td><?= $gid ?></td>
                    <td><label for="grp_<?= $gid ?>"><?= recoveryFormatUserGroupLabel($gid, (string) $grp['groupName']) ?></label></td>
                    <td><small><?= $gType ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" style="margin-top:15px;"><i class="fa-solid fa-sliders"></i> Gruppen speichern</button>
    </form>

<?php endif; // $umUser !== null

else: // $umUid === 0 → Suchmaske ?>

    <?php foreach ($umErrors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div>
    <?php endforeach; ?>
    <?php foreach ($umMessages as $msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
    <?php endforeach; ?>

    <h2><i class="fa-solid fa-magnifying-glass"></i> Benutzer suchen</h2>
    <form method="POST" action="<?= $umBaseUrl ?>">
        <div class="form-group">
            <label for="um_search">Benutzername oder E-Mail (Präfix-Suche, max. 50 Treffer)</label>
            <input type="text" id="um_search" name="um_search"
                value="<?= \htmlspecialchars($_POST['um_search'] ?? '') ?>"
                placeholder="z.&thinsp;B. Admin oder admin@example.com" autofocus>
        </div>
        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Suchen</button>
    </form>

    <?php
    $umSearchQuery = \trim($_POST['um_search'] ?? '');
    if ($umSearchQuery !== ''):
        try {
            $umResults = recoveryUserSearch($db, $umSearchQuery);
        } catch (\Throwable $e) {
            $umResults = [];
            echo '<div class="alert alert-error">Suchfehler: ' . \htmlspecialchars($e->getMessage()) . '</div>';
        }
    ?>

    <?php if (empty($umResults)): ?>
    <div class="alert alert-info" style="margin-top:20px;">
        Keine Benutzer für <code><?= \htmlspecialchars($umSearchQuery) ?></code> gefunden.
    </div>
    <?php else: ?>
    <table style="margin-top:20px;">
        <thead>
            <tr>
                <th style="width:55px">ID</th>
                <th>Benutzername</th>
                <th>E-Mail</th>
                <th style="width:100px">Status</th>
                <th style="width:55px">2FA</th>
                <th style="width:1px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($umResults as $umRow): ?>
            <tr>
                <td><?= (int)$umRow['userID'] ?></td>
                <td><?= \htmlspecialchars($umRow['username']) ?></td>
                <td><?= \htmlspecialchars($umRow['email']) ?></td>
                <td>
                    <?php if ($umRow['banned']): ?>
                        <span style="color:#e74c3c">Gesperrt</span>
                    <?php elseif ($umRow['activationCode'] != 0): ?>
                        <span style="color:#f39c12">Inaktiv</span>
                    <?php else: ?>
                        <span style="color:#00bc8c">Aktiv</span>
                    <?php endif; ?>
                </td>
                <td><?= $umRow['multifactorActive'] ? '<span style="color:#f39c12">Ja</span>' : 'Nein' ?></td>
                <td>
                    <a href="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umRow['userID'] ?>" class="button" style="padding:5px 12px; font-size:13px;">Bearbeiten</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; // $umSearchQuery !== '' ?>

<?php endif; // $umUid > 0 ?>
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
        acp/templates/compiled/<br>
        sowie bei installierten Anwendungen z.&nbsp;B. <code>shrinkr/templates/compiled/</code> und <code>shrinkr/acp/templates/compiled/</code>.<br><br>
        <strong>Hinweis:</strong> Anschließend werden fehlende Option-<code>define()</code>s aus der Datenbank <strong>aller Plugins</strong> sowie aus kompilierten Templates ermittelt und sicher in <code>options.inc.php</code> nachgetragen (gegen Fatal Error „Undefined constant“ im ACP).
    </div>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-broom"></i> Cache jetzt löschen</button>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();
        $optionFbLog = [];
        recoveryEnsureOptionConstantFallbacks($db, WCF_N, $optionFbLog);

        echo '<div class="alert alert-success">';
        echo '<strong>Cache erfolgreich geleert.</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br>';
        foreach ($optionFbLog as $fbEntry) {
            echo \htmlspecialchars($fbEntry) . '<br>';
        }
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
        <code>package_requirement</code>, <code>package_exclusion</code>, File-Logs.
    </div>

    <details style="margin: 1rem 0;">
        <summary>SQL für phpMyAdmin / manuelle Ausführung (WCF_N=<?= (int) WCF_N ?>)</summary>
        <pre class="recoveryLog"><?= \htmlspecialchars($orphanSql) ?></pre>
    </details>

    <form method="POST">
        <input type="hidden" name="confirm_repair" value="1">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-list-check"></i> Verwaiste Einträge jetzt bereinigen</button>
    </form>
<?php
    } else {
        try {
            $result = recoveryRepairOrphanedPackageReferences($db, WCF_N);
            $deletedFiles = clearCompiledTemplates();
            $optionFbLog = [];
            recoveryEnsureOptionConstantFallbacks($db, WCF_N, $optionFbLog);

            echo '<div class="alert alert-success">';
            echo '<strong>Paketliste-Reparatur abgeschlossen.</strong><br><br>';
            foreach ($result['log'] as $entry) {
                echo '• ' . \htmlspecialchars($entry) . '<br>';
            }
            echo '<br>Cache-Dateien gelöscht: ' . (int) $deletedFiles . '<br>';
            foreach ($optionFbLog as $fbEntry) {
                echo \htmlspecialchars($fbEntry) . '<br>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars(recoveryFormatUserError($e)) . '</div>';
            recoveryRenderExceptionDetails($e);
        }
    }
}

// ============================================================================
// MODUS 6: PLUGIN-DATEIEN REPARIEREN
// ============================================================================

elseif ($mode === RECOVERY_MODE_PACKAGE_FILE_REPAIR) {
    $fileRepairUrl = recoveryBuildModeUrl(RECOVERY_MODE_PACKAGE_FILE_REPAIR, $authHash);
    $wcfDir = \rtrim(WCF_DIR, '/\\') . \DIRECTORY_SEPARATOR;
    $liveMissing = recoveryFindMissingBootstrapClasses($wcfDir);
?>
    <h1>Plugin-Dateien reparieren</h1>
    <p class="subtitle">Fehlende PHP-Klassen (Bootstrap-Registrierung) aus hochgeladenem Paket wiederherstellen</p>

<?php
    recoveryFormLoadingScript();
    if (recoveryWasPostTruncated()) {
        recoveryRenderPostTruncatedWarning();
    }

    if (isset($_POST['confirm_file_repair'])) {
        $repairLog = [];
        $extractDir = recoveryResolveTrustedExtractDir();
        if ($extractDir === null) {
            echo '<div class="alert alert-error"><strong>Kein gültiges Paket-Archiv in der Session.</strong> Bitte erneut hochladen.</div>';
        } else {
            $payload = recoveryExtractPackageInstructionTars($extractDir, $repairLog);
            if ($payload === null) {
                echo '<div class="alert alert-error"><strong>Paket konnte nicht ausgewertet werden.</strong><br>';
                foreach ($repairLog as $line) {
                    echo \htmlspecialchars($line) . '<br>';
                }
                echo '</div>';
            } else {
                $toRestore = $liveMissing;
                if (isset($_POST['repair_classes']) && \is_array($_POST['repair_classes'])) {
                    $toRestore = [];
                    foreach ($_POST['repair_classes'] as $cn) {
                        $cn = \trim((string) $cn);
                        if ($cn !== '') {
                            $toRestore[] = $cn;
                        }
                    }
                }
                $copied = recoveryRepairMissingPluginFilesFromPayload($wcfDir, $payload, $toRestore, $repairLog);
                $deletedFiles = clearCompiledTemplates();
                $optionFbLog = [];
                recoveryEnsureOptionConstantFallbacks($db, WCF_N, $optionFbLog);
                recoveryCleanupUploadWorkspace();

                echo '<div class="alert alert-success">';
                echo '<strong>Reparatur abgeschlossen.</strong><br>';
                echo 'Kopierte Dateien: ' . \count($copied) . '<br>';
                echo 'Cache-Dateien gelöscht: ' . (int) $deletedFiles . '<br><br>';
                foreach ($repairLog as $line) {
                    echo '• ' . \htmlspecialchars($line) . '<br>';
                }
                foreach ($optionFbLog as $fbEntry) {
                    echo \htmlspecialchars($fbEntry) . '<br>';
                }
                echo '<br><strong>Bitte ACP erneut testen.</strong>';
                echo '</div>';
            }
        }
    } elseif (isset($_POST['analyze_file_repair']) || recoveryHasUploadedPackageFile()) {
        $packageInput = recoveryResolvePackageInputFromRequest($authHash);
        if (isset($packageInput['error'])) {
            echo '<div class="alert alert-error">' . \htmlspecialchars($packageInput['error']) . '</div>';
        } elseif (empty($packageInput['extractDir'])) {
            echo '<div class="alert alert-error">Kein Entpack-Verzeichnis.</div>';
        } else {
            $analyzeLog = [];
            $payload = recoveryExtractPackageInstructionTars($packageInput['extractDir'], $analyzeLog);
            if ($payload === null) {
                echo '<div class="alert alert-error"><strong>Paket konnte nicht ausgewertet werden.</strong><br>';
                foreach ($analyzeLog as $line) {
                    echo \htmlspecialchars($line) . '<br>';
                }
                echo '</div>';
            } else {
            $missingNow = recoveryFindMissingBootstrapClasses($wcfDir);
?>
    <div class="alert alert-info">
        <strong>Analyse</strong> für Paket <code><?= \htmlspecialchars((string) ($payload['package'] ?? $packageInput['packageIdentifier'] ?? '')) ?></code>
        (App: <code><?= \htmlspecialchars((string) ($payload['applicationDirectory'] ?? '')) ?></code>)<br>
        <?php foreach ($analyzeLog as $line) {
            echo \htmlspecialchars($line) . '<br>';
        } ?>
    </div>

    <form method="POST" action="<?= \htmlspecialchars($fileRepairUrl) ?>">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_PACKAGE_FILE_REPAIR, $authHash); ?>
        <input type="hidden" name="extract_dir" value="<?= \htmlspecialchars((string) $packageInput['extractDir']) ?>">
        <input type="hidden" name="confirm_file_repair" value="1">

        <h2>Fehlende Klassen auf dem Server</h2>
        <?php if ($missingNow === []): ?>
        <p>Keine fehlenden Bootstrap-Klassen erkannt. Sie können trotzdem Bootstrap aus dem Paket synchronisieren.</p>
        <?php else: ?>
        <ul>
        <?php foreach ($missingNow as $cn): ?>
            <li><label><input type="checkbox" name="repair_classes[]" value="<?= \htmlspecialchars($cn) ?>" checked>
                <code><?= \htmlspecialchars($cn) ?></code></label></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <button type="submit" class="btn-danger"><i class="fa-solid fa-screwdriver-wrench"></i> Dateien jetzt wiederherstellen + Cache leeren</button>
    </form>
<?php
        }
        }
    } else {
?>
    <div class="alert alert-warning">
        <strong>Typisches Symptom:</strong> ACP zeigt <code>ClassNotFoundException</code> für eine Klasse, die im Bootstrap
        (<code>lib/bootstrap/de.*.php</code>) registriert ist, deren <code>.class.php</code> auf dem Server fehlt
        (z.&nbsp;B. nach partiellem Löschen von <code>shrinkr/lib/</code>).<br><br>
        Das Tool liest <code>lib/bootstrap/*.php</code>, findet fehlende Klassen und kopiert sie aus Ihrem
        <strong>Paket-Archiv</strong> (<code>files.tar</code> / <code>files_wcf.tar</code>), leert danach den Cache und räumt Uploads auf.
    </div>

    <?php if ($liveMissing !== []): ?>
    <div class="alert alert-error">
        <strong>Aktuell fehlend (Live-Scan):</strong>
        <ul style="margin:8px 0 0 20px">
        <?php foreach ($liveMissing as $cn): ?>
            <li><code><?= \htmlspecialchars($cn) ?></code></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-success">Live-Scan: keine fehlenden Bootstrap-Klassen gefunden (ACP-Fehler kann andere Ursache haben).</div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($fileRepairUrl) ?>">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_PACKAGE_FILE_REPAIR, $authHash); ?>
        <input type="hidden" name="analyze_file_repair" value="1">
        <label for="package_file">Paket-Archiv (.tar.gz) — z.&nbsp;B. de.sunnyc.wsc.shrinkr_v1.0.17.tar.gz</label>
        <input type="file" name="package_file" id="package_file" accept=".tar,.tar.gz,.tgz" required>
        <p style="margin-top:8px"><small>Optional Paket-ID:</small></p>
        <input type="text" name="package_identifier" placeholder="de.sunnyc.wsc.shrinkr" style="max-width:420px">
        <p style="margin-top:16px">
            <button type="submit" class="button"><i class="fa-solid fa-magnifying-glass"></i> Paket analysieren &amp; Vorschau</button>
        </p>
    </form>
<?php
    }
}

// ============================================================================
// MODUS 7: RECOVERY-WIZARD (halbautomatisch)
// ============================================================================

elseif ($mode === RECOVERY_MODE_RECOVERY_WIZARD) {
    $wizardUrl = recoveryBuildModeUrl(RECOVERY_MODE_RECOVERY_WIZARD, $authHash);
    $wcfDir = \rtrim(WCF_DIR, '/\\') . \DIRECTORY_SEPARATOR;
    $phase = (string) ($_POST['wizard_phase'] ?? $_GET['wizard_phase'] ?? 'package');
    $phaseIndex = match ($phase) {
        'diagnose' => 1,
        'plan' => 2,
        'run' => 3,
        'done' => 3,
        default => 0,
    };

    recoveryRenderWizardPhaseSteps($phaseIndex, ['Paket', 'Diagnose', 'Plan', 'Ausführung']);
?>
    <h1>Recovery-Wizard</h1>
    <p class="subtitle">Geführte Reparatur in sinnvoller Reihenfolge — Sie entscheiden pro Schritt, was ausgeführt wird</p>

<?php
    if ($phase === 'run' && isset($_POST['wizard_execute'])) {
        $plan = [
            'orphans' => !empty($_POST['do_orphans']),
            'files' => !empty($_POST['do_files']),
            'cache' => !empty($_POST['do_cache']),
            'extractDir' => recoveryResolveTrustedExtractDir(),
            'classes' => isset($_POST['repair_classes']) && \is_array($_POST['repair_classes'])
                ? \array_values(\array_filter(\array_map('strval', $_POST['repair_classes'])))
                : [],
        ];
        $execLog = [];
        $result = recoveryWizardExecutePlan($wcfDir, $db, WCF_N, $plan, $execLog);
        if ($plan['files']) {
            recoveryCleanupUploadWorkspace();
        }
        recoveryWizardSaveState($authHash, ['lastRun' => $result]);
?>
    <div class="alert alert-success">
        <strong>Ausführung abgeschlossen.</strong><br>
        Kopierte Dateien: <?= \count($result['copiedFiles']) ?><br>
        Cache-Dateien gelöscht: <?= (int) $result['cacheDeleted'] ?>
    </div>
    <pre class="recoveryLog" style="max-height:320px;overflow:auto;margin-top:12px"><?php
        foreach ($execLog as $line) {
            echo \htmlspecialchars($line) . "\n";
        }
    ?></pre>
    <p style="margin-top:16px">
        <a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=done') ?>" class="button">Weiter zur Zusammenfassung</a>
        <a href="<?= \htmlspecialchars($recoveryBaseUrl . 'acp/') ?>" class="button" style="margin-left:8px">ACP testen</a>
    </p>
<?php
    } elseif ($phase === 'done') {
        $state = recoveryWizardLoadState($authHash);
?>
    <div class="alert alert-info">
        <strong>Wizard abgeschlossen.</strong> Einzelne Modi (ACP Repair, Cache Clear, …) bleiben weiterhin manuell nutzbar.
    </div>
    <p><a href="<?= \htmlspecialchars($recoveryBaseUrl . 'acp/') ?>" class="button"><i class="fa-solid fa-gauge-high"></i> Zum ACP</a></p>
    <p style="margin-top:12px"><a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=package') ?>">Wizard von vorn beginnen</a></p>
<?php
    } elseif ($phase === 'plan' || isset($_POST['wizard_to_plan'])) {
        if (recoveryHasUploadedPackageFile()) {
            $upload = recoveryHandlePackageUpload($_FILES['package_file']);
            if ($upload['ok'] && !empty($upload['extractDir'])) {
                recoveryStorePackageContext($authHash, (string) $upload['packageIdentifier'], $upload['extractDir']);
            }
        }
        $state = recoveryWizardLoadState($authHash);
        $scopeApp = isset($state['scopeApplication']) ? (string) $state['scopeApplication'] : null;
        $scopeApp = $scopeApp !== '' ? $scopeApp : null;
        $diag = $state['diagnosis'] ?? recoveryBuildSystemDiagnosis($wcfDir, $db, WCF_N, $scopeApp);
        $suggest = $diag['suggestedActions'] ?? ['orphans' => false, 'files' => false, 'cache' => true];
        $missing = $diag['missingBootstrapClasses'] ?? recoveryFindMissingBootstrapClasses($wcfDir);
        if ($scopeApp !== null) {
            $missing = recoveryFilterFqcnByApplicationPrefix($missing, $scopeApp);
        }
        $extractDir = recoveryResolveTrustedExtractDir();
?>
    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($wizardUrl) ?>">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_RECOVERY_WIZARD, $authHash); ?>
        <input type="hidden" name="wizard_phase" value="run">
        <input type="hidden" name="wizard_execute" value="1">
        <?php if ($extractDir): ?>
        <input type="hidden" name="extract_dir" value="<?= \htmlspecialchars($extractDir) ?>">
        <?php endif; ?>

        <h2>Schritte auswählen (Reihenfolge bei Ausführung)</h2>
        <ol style="margin:0 0 16px 20px;color:#9D9D9D">
            <li>Paketliste bereinigen (verwaiste DB-Einträge)</li>
            <li>Fehlende Plugin-Dateien aus Archiv</li>
            <li>Cache leeren + Option-Konstanten-Fallback</li>
        </ol>

        <p><label><input type="checkbox" name="do_orphans" value="1" <?= !empty($suggest['orphans']) ? 'checked' : '' ?>>
            <strong>1. Paketliste reparieren</strong> (<?= (int) ($diag['orphanApplicationCount'] ?? 0) ?> verwaiste Applications)</label></p>

        <p><label><input type="checkbox" name="do_files" value="1" <?= !empty($suggest['files']) ? 'checked' : '' ?>>
            <strong>2. Plugin-Dateien wiederherstellen</strong> (<?= \count($missing) ?> fehlende Klassen)</label></p>

        <?php if ($missing !== []): ?>
        <ul style="margin:4px 0 12px 24px">
        <?php foreach ($missing as $cn): ?>
            <li><label><input type="checkbox" name="repair_classes[]" value="<?= \htmlspecialchars($cn) ?>" checked>
                <code><?= \htmlspecialchars($cn) ?></code></label></li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!$extractDir): ?>
        <div class="alert alert-warning" style="margin:12px 0">
            Für Schritt 2: <strong>Paket-Archiv hochladen</strong> (package.xml mit files.tar / files_wcf.tar — wie vom
            <a href="https://github.com/SoftCreatRMedia/wspackager" target="_blank" rel="noopener">wspackager</a> gebaut).
        </div>
        <label for="wizard_package_file">Paket (.tar.gz)</label>
        <input type="file" name="package_file" id="wizard_package_file" accept=".tar,.tar.gz,.tgz">
        <?php else: ?>
        <p class="alert alert-success" style="margin:12px 0">Paket in Session: <code><?= \htmlspecialchars($extractDir) ?></code></p>
        <?php endif; ?>

        <p><label><input type="checkbox" name="do_cache" value="1" checked>
            <strong>3. Cache leeren</strong> + options.inc.php-Fallback (empfohlen)</label></p>

        <p style="margin-top:20px">
            <button type="submit" class="btn-danger"><i class="fa-solid fa-play"></i> Ausgewählte Schritte jetzt ausführen</button>
            <a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=diagnose') ?>" class="back-link" style="margin-left:12px">Zurück zur Diagnose</a>
            <a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=package') ?>" class="back-link" style="margin-left:12px">Paket ändern</a>
        </p>
    </form>
<?php
    }

    $wizardUploadError = null;
    if (isset($_POST['wizard_to_diagnose'])) {
        $phase = 'diagnose';
        $scopeApp = null;
        $packageLabel = '';
        $fullServerScan = !empty($_POST['wizard_full_scan']);

        if (!$fullServerScan) {
            if (recoveryHasUploadedPackageFile()) {
                $upload = recoveryHandlePackageUpload($_FILES['package_file']);
                if (!$upload['ok']) {
                    $wizardUploadError = (string) ($upload['error'] ?? 'Upload fehlgeschlagen.');
                    $phase = 'package';
                } elseif (!empty($upload['extractDir'])) {
                    recoveryStorePackageContext($authHash, (string) $upload['packageIdentifier'], $upload['extractDir']);
                    $meta = recoveryParsePackageMetaFromExtractDir((string) $upload['extractDir']);
                    $packageLabel = (string) ($meta['package'] ?? $upload['packageIdentifier'] ?? '');
                    $scopeApp = (string) ($meta['applicationDirectory'] ?? '');
                }
            } elseif (!empty($_POST['package_identifier'])) {
                $packageLabel = \trim((string) $_POST['package_identifier']);
                if (\preg_match(RECOVERY_PACKAGE_ID_PATTERN, $packageLabel)) {
                    recoveryStorePackageContext($authHash, $packageLabel, null);
                    $scopeApp = recoveryGuessApplicationFromPackageIdentifier($packageLabel);
                } else {
                    $wizardUploadError = 'Ungültige Paket-ID.';
                    $phase = 'package';
                }
            } else {
                $wizardUploadError = 'Bitte Paket-Archiv hochladen, Paket-ID eingeben oder „gesamten Server prüfen“ wählen.';
                $phase = 'package';
            }
        }

        if ($phase === 'diagnose') {
            $diag = recoveryBuildSystemDiagnosis(
                $wcfDir,
                $db,
                WCF_N,
                $fullServerScan ? null : ($scopeApp !== '' && $scopeApp !== null ? $scopeApp : null)
            );
            recoveryWizardSaveState($authHash, [
                'diagnosis' => $diag,
                'scopeApplication' => $fullServerScan ? null : ($scopeApp !== '' ? $scopeApp : null),
                'packageLabel' => $packageLabel,
                'fullServerScan' => $fullServerScan,
            ]);
        }
    }

    if ($phase === 'package') {
?>
    <div class="alert alert-info">
        <strong>Schritt 1 — Paket festlegen (empfohlen)</strong><br>
        Laden Sie das <strong>Paket-Archiv</strong> des defekten Plugins hoch (z.&nbsp;B. <code>.tar.gz</code> mit
        <code>package.xml</code> und <code>files.tar</code>). Dann kann die Diagnose gezielt für dieses Plugin laufen
        und die Reparatur nutzt alle Paketdaten.<br>
        Alternativ nur die <strong>Paket-ID</strong> (z.&nbsp;B. <code>de.example.meinplugin</code>) — dann wird nach App-Name gefiltert,
        ohne Archiv-Inhalt.
    </div>

    <?php if ($wizardUploadError !== null): ?>
    <div class="alert alert-error"><?= \htmlspecialchars($wizardUploadError) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($wizardUrl) ?>">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_RECOVERY_WIZARD, $authHash); ?>
        <input type="hidden" name="wizard_phase" value="diagnose">
        <input type="hidden" name="wizard_to_diagnose" value="1">

        <label for="wizard_package_file"><strong>Paket-Archiv</strong> (.tar, .tar.gz, .tgz) — empfohlen</label>
        <input type="file" name="package_file" id="wizard_package_file" accept=".tar,.tar.gz,.tgz">

        <p style="margin-top:16px"><label for="wizard_package_identifier">oder nur Paket-ID</label></p>
        <input type="text" name="package_identifier" id="wizard_package_identifier" placeholder="de.vendor.meinplugin" style="max-width:420px">

        <p style="margin-top:20px">
            <button type="submit" class="button"><i class="fa-solid fa-arrow-right"></i> Weiter zur Diagnose</button>
        </p>
    </form>

    <form method="POST" action="<?= \htmlspecialchars($wizardUrl) ?>" style="margin-top:12px">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_RECOVERY_WIZARD, $authHash); ?>
        <input type="hidden" name="wizard_phase" value="diagnose">
        <input type="hidden" name="wizard_to_diagnose" value="1">
        <input type="hidden" name="wizard_full_scan" value="1">
        <button type="submit" class="back-link" style="background:none;border:none;color:#6EC2FF;cursor:pointer;padding:0">
            Ohne Paket — gesamten Server prüfen (alle Plugins)
        </button>
    </form>
<?php
    } elseif ($phase === 'diagnose') {
        $state = recoveryWizardLoadState($authHash);
        if (empty($state['diagnosis'])) {
?>
    <div class="alert alert-warning">Noch keine Diagnose. Bitte zuerst Schritt 1 (Paket) ausführen.</div>
    <p><a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=package') ?>" class="button">Zu Schritt 1 — Paket</a></p>
<?php
        } else {
        $diag = $state['diagnosis'];
        $fullServerScan = !empty($state['fullServerScan']);
        $packageLabel = (string) ($state['packageLabel'] ?? '');
        $scopeApp = (string) ($state['scopeApplication'] ?? '');
?>
    <div class="alert alert-info">
        <strong>Schritt 2 — Diagnose</strong><br>
        <?php if ($fullServerScan): ?>
        Es wird der <strong>gesamte Server</strong> geprüft (alle Bootstrap-Registrierungen auf diesem System).
        <?php elseif ($packageLabel !== ''): ?>
        Gefiltert für Paket <code><?= \htmlspecialchars($packageLabel) ?></code><?php if ($scopeApp !== ''): ?>
        — App <code><?= \htmlspecialchars($scopeApp) ?></code><?php endif; ?>.
        Das Tool zeigt nur fehlende Klassen, deren Namespace zu dieser App passt (nicht „erraten“, sondern gefiltert).
        <?php else: ?>
        Live-Scan: Bootstrap-Einträge vs. vorhandene <code>.class.php</code> auf dem Server.
        <?php endif; ?>
    </div>

    <h2>Ergebnis (aktueller Server-Stand)</h2>
    <table class="table" style="width:100%;margin-bottom:16px">
        <tr><th>Fehlende Bootstrap-Klassen auf dem Server</th><td><?= \count($diag['missingBootstrapClasses']) ?></td></tr>
        <tr><th>Verwaiste Applications (DB)</th><td><?= (int) $diag['orphanApplicationCount'] ?></td></tr>
        <tr><th>Log-Hinweise (letzte Datei)</th><td><?= \count($diag['logExcerpts']) ?> Treffer</td></tr>
    </table>

    <?php if ($diag['missingBootstrapClasses'] === []): ?>
    <div class="alert alert-success">
        Keine fehlenden Bootstrap-Klassen (im gewählten Umfang) gefunden. Sie können trotzdem fortfahren
        (z.&nbsp;B. Cache leeren oder Paketliste bereinigen).
    </div>
    <?php else: ?>
    <div class="alert alert-error">
        <strong>Auf dem Server fehlen Dateien für diese Klassen</strong> (in Bootstrap registriert, <code>.class.php</code> nicht gefunden):
        <?php foreach (recoveryGroupFqcnByApplicationPrefix($diag['missingBootstrapClasses']) as $app => $classes): ?>
        <p style="margin:12px 0 4px"><strong>App <code><?= \htmlspecialchars($app) ?></code></strong> (<?= \count($classes) ?> Klassen)</p>
        <ul style="margin:0 0 8px 20px"><?php foreach ($classes as $cn): ?>
            <li><code><?= \htmlspecialchars($cn) ?></code></li>
        <?php endforeach; ?></ul>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($diag['logExcerpts'] !== []): ?>
    <details><summary>Log-Auszug</summary>
    <pre class="recoveryLog"><?php foreach ($diag['logExcerpts'] as $line) {
        echo \htmlspecialchars($line) . "\n";
    } ?></pre></details>
    <?php endif; ?>

    <form method="POST" action="<?= \htmlspecialchars($wizardUrl) ?>">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_RECOVERY_WIZARD, $authHash); ?>
        <input type="hidden" name="wizard_phase" value="plan">
        <input type="hidden" name="wizard_to_plan" value="1">
        <button type="submit" class="button"><i class="fa-solid fa-arrow-right"></i> Weiter — Plan &amp; Auswahl</button>
    </form>
<?php
    }
}

}

?>
<?php
recoveryRenderPageEnd();
