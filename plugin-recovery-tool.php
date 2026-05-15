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
 * @version 1.5.1
 * @requires PHP >= 8.3
 *
 * Eine Datei: ins WoltLab-Hauptverzeichnis legen (neben global.php).
 * Kein global.php – funktioniert auch wenn das ACP durch ein Plugin kaputt ist.
 */

// ============================================================================
// KONFIGURATION
// ============================================================================

define('RECOVERY_VERSION', '1.5.1');
define('RECOVERY_MIN_PHP_VERSION', '8.3.0');

if (\PHP_VERSION_ID < 80300) {
    \header('Content-Type: text/html; charset=utf-8');
    \http_response_code(500);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Recovery Tool</title></head><body>';
    echo '<h1>PHP-Version zu alt</h1>';
    echo '<p>Dieses Recovery Tool benötigt <strong>PHP 8.3</strong> oder neuer.</p>';
    echo '<p>Aktuell: <code>' . \htmlspecialchars(\PHP_VERSION) . '</code></p>';
    echo '<p>Bitte PHP beim Hoster aktualisieren (WoltLab Suite 6.2 empfiehlt PHP 8.1+).</p>';
    echo '</body></html>';
    exit;
}
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
 * @return array{WCFSetup.css: string, woltlabSuite.png: string}
 */
function recoveryGetSetupAssets(): array
{
    if (!\defined('WCF_DIR')) {
        try {
            \define('WCF_DIR', recoveryResolveWcfDir());
        } catch (\Throwable $ignored) {
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
                        $db->commitTransaction();
                        recoveryCleanupUploadWorkspace();

                        echo '<div class="alert alert-success">';
                        echo '<strong>✓ ACP-Repair erfolgreich!</strong><br>';
                        echo 'Gelöschte Menüeinträge: ' . $deletedCount . '<br>';
                        echo 'Cache wurde geleert.<br>';
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

                    // PIP-Counts aus DB
                    $pipMap    = recoveryGetPipResourceMap();
                    $pipCounts = $packageID ? recoveryGetPipDbCounts($db, $wcfN, $packageID) : [];

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
                    echo '<label style="cursor:pointer"><input type="checkbox" name="dry_run" value="1" style="margin-right:6px">';
                    echo '<strong>Dry-Run-Modus:</strong> Zeigt was gelöscht WÜRDE, ohne tatsächliche Änderungen vorzunehmen</label>';
                    echo '</div>';

                    // ── DB-Einträge nach packageID ────────────────────────────
                    if ($packageID) {
                        $hasSafeRows = false;
                        foreach ($pipCounts as $cnt) {
                            if ($cnt > 0) { $hasSafeRows = true; break; }
                        }

                        echo '<h2 style="margin-bottom:10px">DB-Einträge nach packageID</h2>';
                        echo '<p style="margin-bottom:12px"><small>Nur Einträge mit <code>packageID = ' . $packageID . '</code> werden gelöscht – keine Massenlöschungen.</small></p>';
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
                                echo '<td style="text-align:right">' . ($count > 0 ? '<strong>' . $count . '</strong>' : '0') . '</td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table>';
                        echo '<script>
                            var counts = ' . \json_encode($pipCounts) . ';
                            var allChecked = Object.values(counts).some(function(v){ return v > 0; });
                            var chkAllPip = document.getElementById("chkAllPip");
                            if (chkAllPip) {
                                chkAllPip.checked = allChecked;
                                chkAllPip.addEventListener("change", function () {
                                    document.querySelectorAll("input[name=\\"pip_select[]\\"]:not(:disabled)").forEach(function (c) {
                                        c.checked = chkAllPip.checked;
                                    });
                                });
                            }
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
                        echo '<button type="button" class="button" style="margin-left:8px" onclick="(function(){';
                        echo 'var s=atob(' . \json_encode($backupB64) . ');';
                        echo 'var b=new Blob([s],{type:\'text/plain\'});';
                        echo 'var a=document.createElement(\'a\');a.href=URL.createObjectURL(b);';
                        echo 'a.download=\'recovery-backup-' . \date('Y-m-d-His') . '.sql\';a.click();';
                        echo '})()"><i class=\"fa-solid fa-download\"></i> JS-Download</button>';
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
    <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
        <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>" style="flex:1; min-width:200px;">
            <input type="hidden" name="um_action" value="reset_password">
            <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">Generiert ein zufälliges sicheres Passwort und zeigt es einmalig an.</p>
            <button type="submit" class="btn-danger"><i class="fa-solid fa-key"></i> Zufälliges Passwort setzen</button>
        </form>
        <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>" style="flex:2; min-width:280px;">
            <input type="hidden" name="um_action" value="reset_password_custom">
            <div class="form-group">
                <label for="um_custom_pwd">Eigenes Passwort setzen (min. 8 Zeichen)</label>
                <input type="password" id="um_custom_pwd" name="custom_password" autocomplete="new-password" placeholder="Neues Passwort eingeben">
            </div>
            <button type="submit"><i class="fa-solid fa-key"></i> Passwort setzen</button>
        </form>
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
                    <td><label for="grp_<?= $gid ?>"><?= \htmlspecialchars($grp['groupName']) ?></label></td>
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
        acp/templates/compiled/
    </div>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-broom"></i> Cache jetzt löschen</button>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();

        echo '<div class="alert alert-success">';
        echo '<strong>Cache erfolgreich geleert.</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br>';
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
        <button type="submit" class="btn-danger"><i class="fa-solid fa-list-check"></i> Verwaiste Einträge jetzt bereinigen</button>
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
