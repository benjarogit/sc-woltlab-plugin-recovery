<?php

declare(strict_types=1);

function recoveryStubGetSiteBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/plugin-recovery-tool.php';
    $base = \rtrim(\str_replace('\\', '/', \dirname($script)), '/');

    return $scheme . '://' . $host . ($base === '' || $base === '.' ? '' : $base) . '/';
}

/**
 * @return array{WCFSetup.css: string, woltlabSuite.png: string, fontAwesomeCss: string, fontAwesomeLocal: bool}
 */
function recoveryStubGetSetupAssets(): array
{
    $root = recoveryStubWcfRoot();
    $assets = [
        'WCFSetup.css' => '',
        'woltlabSuite.png' => '',
        'fontAwesomeCss' => '',
        'fontAwesomeLocal' => false,
    ];
    if (\is_readable($root . 'acp/style/setup/WCFSetup.css')) {
        $assets['WCFSetup.css'] = 'acp/style/setup/WCFSetup.css';
    }
    if (\is_readable($root . 'acp/images/woltlabSuite.png')) {
        $assets['woltlabSuite.png'] = 'acp/images/woltlabSuite.png';
    }
    if (\is_readable($root . 'icon/font-awesome/v7/css/all.min.css')) {
        $assets['fontAwesomeCss'] = 'icon/font-awesome/v7/css/all.min.css';
        $assets['fontAwesomeLocal'] = true;
    }

    return $assets;
}

function recoveryStubAssetHref(string $relative): string
{
    if ($relative === '') {
        return '';
    }

    return recoveryStubGetSiteBaseUrl() . \ltrim($relative, '/');
}

function recoveryStubWizardCss(): string
{
    return <<<'RECOVERY_STUB_WIZARD_CSS'
/* Recovery-Tool: nur Ergänzungen — Basis ist WCFSetup.css (ACP) */

.content {
    margin: 0 auto;
    max-width: 980px;
}

/* Auth-Wizard */
.wizardSteps { display: flex; align-items: flex-start; margin: 0 0 30px; padding: 0; }
.wizardStep { display: flex; flex-direction: column; align-items: center; position: relative; flex: 1; }
.wizardStep::after { content: ''; position: absolute; top: 20px; left: 50%; width: 100%; height: 2px; background: var(--wcfContentBorderInner, #ddd); z-index: 0; }
.wizardStep:last-child::after { display: none; }
.wizardStepNumber { width: 40px; height: 40px; border-radius: 50%; background: var(--wcfTabularBoxBackgroundActive, #e0e0e0); color: var(--wcfContentDimmedText, #888); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; position: relative; z-index: 1; border: 2px solid var(--wcfContentBorderInner, #ddd); }
.wizardStep.active .wizardStepNumber { background: var(--wcfStatusInfoBackground, #369); color: #fff; border-color: var(--wcfStatusInfoBorder, #369); }
.wizardStep.completed .wizardStepNumber { background: var(--wcfStatusSuccessBackground, #3a3); color: transparent; border-color: var(--wcfStatusSuccessBorder, #3a3); }
.wizardStep.completed .wizardStepNumber::after { content: '✓'; color: #fff; font-size: 16px; font-weight: 700; position: absolute; }
.wizardStepLabel { margin-top: 8px; font-size: 12px; color: var(--wcfContentDimmedText, #888); text-align: center; line-height: 1.3; }
.wizardStep.active .wizardStepLabel { color: var(--wcfContentText, #333); font-weight: 600; }
.wizardPanel { display: none; }
.wizardPanel.active { display: block; }


RECOVERY_STUB_WIZARD_CSS;
}


function recoveryStubRenderPageStart(string $title, string $subtitle = ''): void
{
    $assets = recoveryStubGetSetupAssets();
    $cssHref = recoveryStubAssetHref($assets['WCFSetup.css']);
    $logoHref = recoveryStubAssetHref($assets['woltlabSuite.png']);
    $faHref = $assets['fontAwesomeCss'] !== ''
        ? recoveryStubAssetHref($assets['fontAwesomeCss'])
        : 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css';
    $faExtra = $assets['fontAwesomeLocal'] ? '' : ' crossorigin="anonymous" referrerpolicy="no-referrer"';

    \header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($title) ?></title>
    <?php if ($cssHref !== ''): ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($cssHref) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($faHref) ?>"<?= $faExtra ?>>
    <style><?= recoveryStubWizardCss() ?></style>
</head>
<body class="wcfAcp">
<div id="pageContainer" class="pageContainer acpPageHiddenMenu">
    <div class="pageHeaderContainer">
        <header id="pageHeaderFacade" class="pageHeaderFacade">
            <div class="layoutBoundary">
                <div id="pageHeaderLogo" class="pageHeaderLogo">
                    <?php if ($logoHref !== ''): ?>
                    <img src="<?= \htmlspecialchars($logoHref) ?>" alt="" style="width:281px;height:40px;display:inline!important;">
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
                        <h1 class="contentTitle"><?= \htmlspecialchars($title) ?></h1>
                        <?php if ($subtitle !== ''): ?>
                        <p class="contentHeaderDescription"><?= \htmlspecialchars($subtitle) ?></p>
                        <?php endif; ?>
                    </header>
    <?php
}

function recoveryStubRenderPageEnd(): void
{
    ?>
                </div>
            </div>
        </section>
    </div>
</div>
<p class="recovery-footer-meta" style="text-align:right;font-size:13px;margin:16px;">
    <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank" rel="noopener">Plugin Recovery Tool</a>
    | <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">WoltLab Recovery</a>
</p>
</body>
</html>
    <?php
}

function recoveryStubRenderAuthWizard(string $authHash): void
{
    $authFile = RECOVERY_AUTH_FILENAME;
    recoveryStubRenderPageStart('Plugin Recovery Tool', 'Authentifizierung erforderlich');
    ?>
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
        <p class="info"><strong><i class="fa-solid fa-file-arrow-down"></i> Schritt 1: Auth-Datei herunterladen</strong><br>
        Laden Sie die Authentifizierungsdatei herunter. Sie enthält ein einmaliges Token.</p>
        <div class="formSubmit">
            <a href="?action=download-auth-file&amp;t=<?= \htmlspecialchars($authHash) ?>" class="button" id="downloadBtn">
                <i class="fa-solid fa-file-arrow-down"></i> <?= \htmlspecialchars($authFile) ?> herunterladen
            </a>
        </div>
    </div>

    <div class="wizardPanel" id="wp2">
        <p class="info"><strong><i class="fa-solid fa-file-arrow-up"></i> Schritt 2: Datei hochladen</strong><br>
        Laden Sie <code><?= \htmlspecialchars($authFile) ?></code> ins WoltLab-Hauptverzeichnis (neben <code>plugin-recovery-tool.php</code>).<br>
        <small>Nutzen Sie FTP, SFTP oder den Dateimanager Ihres Hosters.</small></p>
        <div class="formSubmit">
            <button type="button" class="button" id="uploadedBtn">
                <i class="fa-solid fa-circle-check"></i> Ich habe die Datei hochgeladen
            </button>
        </div>
        <span id="pollStatus" style="margin-left:14px;font-size:13px;color:var(--wcfContentDimmedText,#888);"></span>
    </div>

    <div class="wizardPanel" id="wp3">
        <p class="success"><strong><i class="fa-solid fa-circle-check"></i> Authentifizierung erfolgreich!</strong><br>
        Die Auth-Datei wurde erkannt.</p>
        <div class="formSubmit">
            <a href="?t=<?= \htmlspecialchars($authHash) ?>&amp;auth_ok=1" class="button">
                <i class="fa-solid fa-rocket"></i> Recovery Tool starten
            </a>
        </div>
    </div>

    <p class="warning" style="margin-top:24px;"><i class="fa-solid fa-shield-halved"></i> <strong>Sicherheitshinweis:</strong>
    Löschen Sie <code>plugin-recovery-tool.php</code> und <code><?= \htmlspecialchars($authFile) ?></code> nach der Verwendung.</p>

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
            if (pollInterval) { clearInterval(pollInterval); }
            pollInterval = setInterval(function () {
                fetch('?action=auth-status&t=' + encodeURIComponent(authToken))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) { clearInterval(pollInterval); goToStep(3); }
                        else { document.getElementById('pollStatus').textContent = 'Datei noch nicht gefunden \u2013 prüfe erneut\u2026'; }
                    })
                    .catch(function () {});
            }, 2000);
        });
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd();
}

function recoveryStubRenderPackageInstallPage(string $authHash, string $bodyHtml): void
{
    recoveryStubRenderPageStart('Recovery-Paket installieren', 'Paket wird für die volle Oberfläche benötigt');
    echo $bodyHtml;
    recoveryStubRenderPageEnd();
}

/**
 * WoltLab Plugin Recovery Tool — Stub (v2.0)
 *
 * Upload ins WoltLab-Hauptverzeichnis. Auth bleibt separat (plugin-recovery-auth.php).
 * Nach Auth wird recovery-{VERSION}.tar.gz von GitHub geladen und nach recovery-tool/ entpackt.
 *
 * @version 2.0.0
 */

define('RECOVERY_STUB_VERSION', '2.0.2');
define('RECOVERY_PACKAGE_VERSION', '2.0.2');
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
    recoveryStubRenderPackageInstallPage($authHash, '<div class="alert alert-error">' . $result['error'] . '</div>');
    exit;
}

if (!$isAuthenticated) {
    recoveryStubRenderAuthWizard($authHash);
    exit;
}

if (!recoveryStubPackageReady()) {
    $installUrl = '?action=install-package&amp;t=' . \htmlspecialchars($authHash);
    $ghUrl = \htmlspecialchars(recoveryStubReleaseDownloadUrl(RECOVERY_PACKAGE_VERSION));
    $body = '<div class="alert alert-info">'
        . '<strong><i class="fa-solid fa-box-archive"></i> Recovery-Paket erforderlich</strong><br>'
        . 'Nach der Anmeldung wird das Paket <strong>v' . \htmlspecialchars(RECOVERY_PACKAGE_VERSION) . '</strong> benötigt.</div>'
        . '<p><a class="button" href="' . $installUrl . '"><i class="fa-solid fa-download"></i> Paket automatisch installieren</a></p>'
        . '<p>Oder <a href="' . $ghUrl . '">recovery-' . RECOVERY_PACKAGE_VERSION . '.tar.gz</a> manuell nach <code>'
        . RECOVERY_PACKAGE_DIR_NAME . '/</code> entpacken.</p>';
    recoveryStubRenderPackageInstallPage($authHash, $body);
    exit;
}

// --- Paket laden ---
\define('RECOVERY_WCF_ROOT', recoveryStubWcfRoot());
\define('RECOVERY_PACKAGE_DIR', recoveryStubPackageDir());
$recoveryAuthHash = $authHash;
$recoveryIsAuthenticated = true;
require recoveryStubPackageDir() . 'bootstrap.php';
