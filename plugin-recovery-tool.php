<?php

declare(strict_types=1);

/**
 * WoltLab Plugin Recovery Tool — Stub (v2.0)
 *
 * Upload ins WoltLab-Hauptverzeichnis. Auth bleibt separat (plugin-recovery-auth.php).
 * Nach Auth wird recovery-{VERSION}.tar.gz von GitHub geladen und nach recovery-tool/ entpackt.
 *
 * @version 2.0.0
 */

define('RECOVERY_STUB_VERSION', '2.0.0');
define('RECOVERY_PACKAGE_VERSION', '2.0.0');
define('RECOVERY_MIN_PHP_VERSION', '8.1.0');
define('RECOVERY_GITHUB_REPO', 'benjarogit/sc-woltlab-plugin-recovery');
define('RECOVERY_AUTH_FILENAME', 'plugin-recovery-auth.php');
define('RECOVERY_PACKAGE_DIR_NAME', 'recovery-tool');

if (\PHP_VERSION_ID < 80100) {
    \header('Content-Type: text/html; charset=utf-8');
    \http_response_code(500);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Recovery Tool</title></head><body>';
    echo '<h1>PHP-Version zu alt</h1><p>Mindestens <strong>PHP 8.1</strong> erforderlich. Aktuell: <code>'
        . \htmlspecialchars(\PHP_VERSION) . '</code></p></body></html>';
    exit;
}

function recoveryStubWcfRoot(): string
{
    foreach ([__DIR__, \dirname(__DIR__), \dirname(__DIR__, 2)] as $dir) {
        if (\is_file($dir . '/global.php') && \is_file($dir . '/config.inc.php')) {
            return \rtrim($dir, '/\\') . '/';
        }
    }

    return \rtrim(__DIR__, '/\\') . '/';
}

function recoveryStubPackageDir(): string
{
    return recoveryStubWcfRoot() . RECOVERY_PACKAGE_DIR_NAME . '/';
}

function recoveryStubReleaseDownloadUrl(string $version): string
{
    return 'https://github.com/' . RECOVERY_GITHUB_REPO
        . '/releases/download/v' . $version . '/recovery-' . $version . '.tar.gz';
}

function recoveryStubReadInstalledVersion(): ?string
{
    $manifest = recoveryStubPackageDir() . 'manifest.json';
    if (!\is_file($manifest)) {
        $versionPhp = recoveryStubPackageDir() . 'version.php';
        if (\is_file($versionPhp)) {
            require $versionPhp;
            if (\defined('RECOVERY_PACKAGE_VERSION')) {
                return (string) RECOVERY_PACKAGE_VERSION;
            }
        }

        return null;
    }
    $json = \json_decode((string) \file_get_contents($manifest), true);
    if (!\is_array($json) || empty($json['version'])) {
        return null;
    }

    return (string) $json['version'];
}

function recoveryStubPackageReady(): bool
{
    $bootstrap = recoveryStubPackageDir() . 'bootstrap.php';
    if (!\is_file($bootstrap)) {
        return false;
    }
    $installed = recoveryStubReadInstalledVersion();
    if ($installed === null) {
        return false;
    }

    return \version_compare($installed, RECOVERY_PACKAGE_VERSION, '>=');
}

function recoveryStubIsUnsafeArchivePath(string $path): bool
{
    $path = \str_replace('\\', '/', $path);
    if ($path === '' || $path[0] === '/' || \str_contains($path, '..')) {
        return true;
    }

    return false;
}

function recoveryStubRemoveDirectory(string $dir): void
{
    if (!\is_dir($dir)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @\rmdir($f->getPathname()) : @\unlink($f->getPathname());
    }
    @\rmdir($dir);
}

/**
 * @return array{ok: bool, error?: string}
 */
function recoveryStubExtractTarGz(string $archive, string $destination): array
{
    if (!\is_dir($destination) && !@\mkdir($destination, 0755, true)) {
        return ['ok' => false, 'error' => 'Zielverzeichnis konnte nicht angelegt werden.'];
    }

    if (\class_exists(\PharData::class, false)) {
        try {
            $phar = new \PharData($archive);
            $phar->extractTo($destination, null, true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'PharData: ' . $e->getMessage()];
        }
    } else {
        $tar = \trim((string) \shell_exec('command -v tar 2>/dev/null'));
        if ($tar === '') {
            return ['ok' => false, 'error' => 'Weder Phar noch tar verfügbar.'];
        }
        $cmd = \escapeshellarg($tar) . ' -xzf ' . \escapeshellarg($archive)
            . ' -C ' . \escapeshellarg($destination) . ' 2>&1';
        \exec($cmd, $out, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'tar exit ' . $code . ': ' . \implode("\n", $out)];
        }
    }

    // Archiv enthält recovery-tool/ als Top-Level
    $nested = $destination . '/' . RECOVERY_PACKAGE_DIR_NAME;
    if (\is_dir($nested) && \is_file($nested . '/bootstrap.php')) {
        foreach (new \DirectoryIterator($nested) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $src = $item->getPathname();
            $dst = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                if (\is_dir($dst)) {
                    recoveryStubRemoveDirectory($dst);
                }
                @\rename($src, $dst);
            } else {
                @\rename($src, $dst);
            }
        }
        recoveryStubRemoveDirectory($nested);
    }

    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($destination, \RecursiveDirectoryIterator::SKIP_DOTS)
    ) as $file) {
        if (recoveryStubIsUnsafeArchivePath(\str_replace($destination . '/', '', $file->getPathname()))) {
            @\unlink($file->getPathname());
        }
    }

    if (!\is_file($destination . '/bootstrap.php')) {
        return ['ok' => false, 'error' => 'Paket unvollständig (bootstrap.php fehlt).'];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function recoveryStubInstallPackage(string $version): array
{
    $url = recoveryStubReleaseDownloadUrl($version);
    $dest = recoveryStubPackageDir();
    $cacheDir = $dest . '.cache/';
    if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0755, true)) {
        $cacheDir = \sys_get_temp_dir() . '/';
    }
    $archive = $cacheDir . 'recovery-' . $version . '.tar.gz';

    $data = @\file_get_contents($url);
    if ($data === false) {
        return [
            'ok' => false,
            'error' => 'Download fehlgeschlagen. Bitte '
                . '<a href="' . \htmlspecialchars($url) . '">recovery-' . $version . '.tar.gz</a> '
                . 'manuell nach <code>' . RECOVERY_PACKAGE_DIR_NAME . '/</code> entpacken.',
        ];
    }
    if (@\file_put_contents($archive, $data) === false) {
        return ['ok' => false, 'error' => 'Archiv konnte nicht gespeichert werden.'];
    }

    if (\is_dir($dest)) {
        recoveryStubRemoveDirectory($dest);
    }
    if (!@\mkdir($dest, 0755, true)) {
        return ['ok' => false, 'error' => 'Verzeichnis ' . RECOVERY_PACKAGE_DIR_NAME . '/ konnte nicht erstellt werden.'];
    }

    $extract = recoveryStubExtractTarGz($archive, $dest);
    @\unlink($archive);

    return $extract;
}

function recoveryStubRenderMinimalPage(string $title, string $bodyHtml): void
{
    \header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . \htmlspecialchars($title) . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;line-height:1.5}';
    echo 'code{background:#f0f0f0;padding:2px 6px;border-radius:4px}.alert{padding:1rem;border-radius:8px;margin:1rem 0}';
    echo '.alert-info{background:#e8f4fc}.alert-error{background:#fde8e8}.button{display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;border:0;cursor:pointer}</style>';
    echo '</head><body><h1>' . \htmlspecialchars($title) . '</h1>' . $bodyHtml . '</body></html>';
}

function recoveryStubCleanupAuxiliary(): void
{
    $root = recoveryStubWcfRoot();
    $paths = [
        $root . RECOVERY_AUTH_FILENAME,
        $root . 'uploads',
        recoveryStubPackageDir(),
    ];
    foreach ($paths as $path) {
        if (\is_file($path)) {
            @\unlink($path);
        } elseif (\is_dir($path)) {
            recoveryStubRemoveDirectory($path);
        }
    }
    foreach (\glob($root . 'log/recovery-tool-*.ndjson') ?: [] as $log) {
        @\unlink($log);
    }
    foreach (\glob($root . 'log/plugin-recovery-*.ndjson') ?: [] as $log) {
        @\unlink($log);
    }
}

// --- Token ---
if (empty($_REQUEST['t']) || !\preg_match('~^[a-f0-9]{40}$~', (string) $_REQUEST['t'])) {
    $authHash = \bin2hex(\random_bytes(20));
    \header('Location: plugin-recovery-tool.php?t=' . $authHash);
    exit;
}
$authHash = (string) $_REQUEST['t'];
$action = (!empty($_GET['action'])) ? (string) $_GET['action'] : '';

if ($action === 'download-auth-file') {
    $expiresTimestamp = \time() + 86400;
    $content = "<?php exit; /* --- NICHT BEARBEITEN --- */ ?>\n{$expiresTimestamp}\n{$authHash}";
    \header('Content-type: application/octet-stream');
    \header('Content-Disposition: attachment; filename="' . RECOVERY_AUTH_FILENAME . '"');
    \header('Content-Length: ' . \strlen($content));
    echo $content;
    exit;
}

$isAuthenticated = false;
$authFilePath = recoveryStubWcfRoot() . RECOVERY_AUTH_FILENAME;
if (\is_file($authFilePath) && \is_readable($authFilePath)) {
    $lines = \explode("\n", (string) \file_get_contents($authFilePath));
    if (\count($lines) >= 3) {
        $expiresTimestamp = (int) $lines[1];
        $storedHash = \trim($lines[2]);
        if ($expiresTimestamp > \time() && \hash_equals($storedHash, $authHash)) {
            $isAuthenticated = true;
        }
    }
}

if ($action === 'auth-status') {
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode(['ok' => $isAuthenticated]);
    exit;
}

if ($action === 'cleanup') {
    recoveryStubCleanupAuxiliary();
    \register_shutdown_function(static function (): void {
        @\unlink(__DIR__ . '/plugin-recovery-tool.php');
    });
    \header('Location: ' . recoveryStubWcfRoot() . 'acp/');
    exit;
}

if ($action === 'install-package' && $isAuthenticated) {
    $result = recoveryStubInstallPackage(RECOVERY_PACKAGE_VERSION);
    if ($result['ok']) {
        \header('Location: plugin-recovery-tool.php?t=' . \urlencode($authHash) . '&package_ok=1');
        exit;
    }
    recoveryStubRenderMinimalPage('Paket-Installation', '<div class="alert alert-error">' . $result['error'] . '</div>');
    exit;
}

// --- Nicht authentifiziert: Auth-UI im Stub ---
if (!$isAuthenticated) {
    recoveryStubRenderMinimalPage('Plugin Recovery Tool', '
    <p>Authentifizierung erforderlich (Stub v' . RECOVERY_STUB_VERSION . ').</p>
    <p><strong>1.</strong> <a class="button" href="?action=download-auth-file&amp;t=' . \htmlspecialchars($authHash) . '">'
        . RECOVERY_AUTH_FILENAME . ' herunterladen</a></p>
    <p><strong>2.</strong> Datei per FTP ins WoltLab-Hauptverzeichnis hochladen (neben diese Datei).</p>
    <p><strong>3.</strong> <a class="button" href="?t=' . \htmlspecialchars($authHash) . '">Seite neu laden</a></p>
    <script>
    setInterval(function(){
      fetch("?action=auth-status&t=' . \rawurlencode($authHash) . '").then(function(r){return r.json();}).then(function(d){
        if(d.ok){ location.href="?t=' . \rawurlencode($authHash) . '"; }
      });
    }, 2500);
    </script>');
    exit;
}

// --- Paket fehlt / veraltet ---
if (!recoveryStubPackageReady()) {
  $installUrl = '?action=install-package&amp;t=' . \htmlspecialchars($authHash);
  $ghUrl = \htmlspecialchars(recoveryStubReleaseDownloadUrl(RECOVERY_PACKAGE_VERSION));
  recoveryStubRenderMinimalPage('Recovery-Paket installieren', '
    <div class="alert alert-info">Nach der Auth wird das Recovery-Paket <strong>v' . RECOVERY_PACKAGE_VERSION . '</strong> benötigt.</div>
    <p><a class="button" href="' . $installUrl . '">Paket automatisch installieren</a></p>
    <p>Oder <a href="' . $ghUrl . '">recovery-' . RECOVERY_PACKAGE_VERSION . '.tar.gz</a> manuell nach <code>'
    . RECOVERY_PACKAGE_DIR_NAME . '/</code> entpacken.</p>');
    exit;
}

// --- Paket laden ---
\define('RECOVERY_WCF_ROOT', recoveryStubWcfRoot());
\define('RECOVERY_PACKAGE_DIR', recoveryStubPackageDir());
$recoveryAuthHash = $authHash;
$recoveryIsAuthenticated = true;
require recoveryStubPackageDir() . 'bootstrap.php';
