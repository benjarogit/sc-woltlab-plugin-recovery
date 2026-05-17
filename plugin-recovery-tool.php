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
 * @return list<string>
 */
function recoveryStubResolveAcpStylesheets(): array
{
    $root = recoveryStubWcfRoot();
    foreach (['acp/style/style.css', 'acp/style/setup/WCFSetup.css'] as $relative) {
        if (\is_readable($root . $relative)) {
            return [$relative];
        }
    }

    return [];
}

function recoveryStubUsesCompiledAcpStyle(): bool
{
    return recoveryStubResolveAcpStylesheets() !== []
        && recoveryStubResolveAcpStylesheets()[0] === 'acp/style/style.css';
}

/**
 * @return array{stylesheets: list<string>, WCFSetup.css: string, woltlabSuite.png: string, fontAwesomeCss: string, fontAwesomeLocal: bool, usesCompiledAcpStyle: bool}
 */
function recoveryStubGetSetupAssets(): array
{
    $root = recoveryStubWcfRoot();
    $stylesheets = recoveryStubResolveAcpStylesheets();
    $assets = [
        'stylesheets' => $stylesheets,
        'WCFSetup.css' => $stylesheets[0] ?? '',
        'woltlabSuite.png' => '',
        'fontAwesomeCss' => '',
        'fontAwesomeLocal' => false,
        'usesCompiledAcpStyle' => recoveryStubUsesCompiledAcpStyle(),
    ];
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
/* Stub: Dark-Mode wenn nur WCFSetup.css (kein kompiliertes acp/style/style.css). */
@media (prefers-color-scheme: dark) {
    html[data-color-scheme="dark"] body.wcfAcp,
    html[data-color-scheme="system"] body.wcfAcp {
        background-color: #1a1a1a;
        color: #c8c8c8;
    }
    html[data-color-scheme="dark"] .contentHeader .contentTitle,
    html[data-color-scheme="dark"] .sectionTitle,
    html[data-color-scheme="system"] .contentHeader .contentTitle,
    html[data-color-scheme="system"] .sectionTitle {
        color: #e8e8e8;
    }
    html[data-color-scheme="dark"] .info,
    html[data-color-scheme="system"] .info {
        background-color: rgba(33, 150, 243, 0.12);
        border-left-color: #2196f3;
        color: #b8d4f0;
    }
    html[data-color-scheme="dark"] .warning,
    html[data-color-scheme="system"] .warning {
        background-color: rgba(255, 193, 7, 0.12);
        border-left-color: #ffc107;
        color: #e8d9a8;
    }
    html[data-color-scheme="dark"] .success,
    html[data-color-scheme="system"] .success {
        background-color: rgba(76, 175, 80, 0.12);
        border-left-color: #4caf50;
        color: #c8e6c9;
    }
}

RECOVERY_STUB_WIZARD_CSS;
}


function recoveryStubRenderColorSchemeHeadScript(): void
{
    ?>
    <script data-eager="true">
    (function () {
        var root = document.documentElement;
        var mq = window.matchMedia("(prefers-color-scheme: dark)");
        function apply() {
            root.dataset.colorScheme = mq.matches ? "dark" : "light";
        }
        apply();
        if (typeof mq.addEventListener === "function") {
            mq.addEventListener("change", apply);
        } else if (typeof mq.addListener === "function") {
            mq.addListener(apply);
        }
    }());
    </script>
    <?php
}

/**
 * @param array{value?: int, max?: int, label?: string}|null $wizardProgress
 */
function recoveryStubRenderPageStart(string $title, string $subtitle = '', ?array $wizardProgress = null): void
{
    $assets = recoveryStubGetSetupAssets();
    $logoHref = recoveryStubAssetHref($assets['woltlabSuite.png']);
    $faHref = $assets['fontAwesomeCss'] !== ''
        ? recoveryStubAssetHref($assets['fontAwesomeCss'])
        : 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css';
    $faExtra = $assets['fontAwesomeLocal'] ? '' : ' crossorigin="anonymous" referrerpolicy="no-referrer"';
    $wizardCss = recoveryStubWizardCss();

    \header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de" data-color-scheme="system">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($title) ?></title>
    <?php foreach ($assets['stylesheets'] as $stylesheet): ?>
    <link rel="stylesheet" type="text/css" media="screen" href="<?= \htmlspecialchars(recoveryStubAssetHref($stylesheet)) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($faHref) ?>"<?= $faExtra ?>>
    <?php recoveryStubRenderColorSchemeHeadScript(); ?>
    <style>
        #pageHeaderContainer { height: 100px; }
        #pageHeader { padding: 30px 20px; }
        .content { margin: 0 auto; max-width: 800px; }
        .recovery-auth-step[hidden] { display: none !important; }
    </style>
    <?php if ($wizardCss !== '' && !$assets['usesCompiledAcpStyle']): ?>
    <style><?= $wizardCss ?></style>
    <?php endif; ?>
</head>
<body id="tplRecoveryAuth" class="wcfAcp">
<a id="top"></a>
<div id="pageContainer" class="pageContainer acpPageHiddenMenu">
    <div id="pageHeaderContainer" class="pageHeaderContainer">
        <header id="pageHeader" class="pageHeader">
            <div id="pageHeaderFacade" class="pageHeaderFacade">
                <div class="layoutBoundary">
                    <div id="pageHeaderLogo" class="pageHeaderLogo">
                        <?php if ($logoHref !== ''): ?>
                        <img src="<?= \htmlspecialchars($logoHref) ?>" alt="" style="height:40px;width:281px;">
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>
    </div>
    <div id="acpPageContentContainer" class="acpPageContentContainer">
        <section id="main" class="main" role="main">
            <div class="layoutBoundary">
                <div id="content" class="content">
                    <header class="contentHeader">
                        <div class="contentHeaderTitle">
                            <h1 class="contentTitle"><?= \htmlspecialchars($title) ?></h1>
                            <?php if ($wizardProgress !== null): ?>
                            <p class="contentHeaderDescription">
                                <progress id="authWizardProgress" value="<?= (int) ($wizardProgress['value'] ?? 1) ?>" max="<?= (int) ($wizardProgress['max'] ?? 3) ?>" style="width:300px"><?= (int) ($wizardProgress['value'] ?? 1) ?>%</progress>
                                <?php if (($wizardProgress['label'] ?? '') !== ''): ?>
                                <?= \htmlspecialchars((string) $wizardProgress['label']) ?>
                                <?php endif; ?>
                            </p>
                            <?php elseif ($subtitle !== ''): ?>
                            <p class="contentHeaderDescription"><?= \htmlspecialchars($subtitle) ?></p>
                            <?php endif; ?>
                        </div>
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
<p class="recovery-footer-meta" style="text-align:right;font-size:13px;margin:16px;color:var(--wcfContentDimmedText,#888);">
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
    recoveryStubRenderPageStart('Plugin Recovery Tool', '', [
        'value' => 1,
        'max' => 3,
        'label' => 'Schritt 1 von 3',
    ]);
    ?>
    <section class="section recovery-auth-step" id="auth-step-1">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Auth-Datei laden</h2>
            <p class="sectionDescription">Laden Sie die Authentifizierungsdatei herunter. Sie enthält ein einmaliges Token.</p>
        </header>
        <p class="info"><i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Die Datei <code><?= \htmlspecialchars($authFile) ?></code> wird für Schritt 2 benötigt.</p>
        <div class="formSubmit">
            <a href="?action=download-auth-file&amp;t=<?= \htmlspecialchars($authHash) ?>" class="button buttonPrimary" id="downloadBtn">
                <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> <?= \htmlspecialchars($authFile) ?> herunterladen
            </a>
        </div>
    </section>

    <section class="section recovery-auth-step" id="auth-step-2" hidden>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Datei hochladen</h2>
            <p class="sectionDescription">Legen Sie die Datei ins WoltLab-Hauptverzeichnis (neben <code>plugin-recovery-tool.php</code>).</p>
        </header>
        <p class="info"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i> FTP, SFTP oder Dateimanager Ihres Hosters.</p>
        <div class="formSubmit">
            <button type="button" class="button buttonPrimary" id="uploadedBtn">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Ich habe die Datei hochgeladen
            </button>
        </div>
        <p><small id="pollStatus"></small></p>
    </section>

    <section class="section recovery-auth-step" id="auth-step-3" hidden>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Recovery Tool starten</h2>
        </header>
        <p class="success"><strong><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Authentifizierung erfolgreich!</strong> Die Auth-Datei wurde erkannt.</p>
        <div class="formSubmit">
            <a href="?t=<?= \htmlspecialchars($authHash) ?>&amp;auth_ok=1" class="button buttonPrimary">
                <i class="fa-solid fa-rocket" aria-hidden="true"></i> Recovery Tool starten
            </a>
        </div>
    </section>

    <p class="warning"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> <strong>Sicherheitshinweis:</strong>
    Löschen Sie <code>plugin-recovery-tool.php</code> und <code><?= \htmlspecialchars($authFile) ?></code> nach der Verwendung.</p>

    <script>
    (function () {
        var authToken = <?= \json_encode($authHash) ?>;
        var pollInterval = null;
        var progress = document.getElementById('authWizardProgress');
        var steps = [
            document.getElementById('auth-step-1'),
            document.getElementById('auth-step-2'),
            document.getElementById('auth-step-3')
        ];
        var stepLabels = ['Schritt 1 von 3', 'Schritt 2 von 3', 'Schritt 3 von 3'];

        function updateProgressLabel(n) {
            var desc = document.querySelector('.contentHeaderDescription');
            if (!desc) { return; }
            var nodes = desc.childNodes;
            for (var i = nodes.length - 1; i >= 0; i--) {
                if (nodes[i].nodeType === Node.TEXT_NODE) {
                    nodes[i].textContent = ' ' + stepLabels[n - 1];
                    return;
                }
            }
            desc.appendChild(document.createTextNode(' ' + stepLabels[n - 1]));
        }

        function goToStep(n) {
            steps.forEach(function (el, i) {
                if (!el) { return; }
                el.hidden = i + 1 !== n;
            });
            if (progress) {
                progress.value = String(n);
                progress.title = Math.round((n / 3) * 100) + '%';
            }
            updateProgressLabel(n);
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
                        if (data.ok) {
                            clearInterval(pollInterval);
                            goToStep(3);
                        } else {
                            document.getElementById('pollStatus').textContent = 'Datei noch nicht gefunden \u2013 prüfe erneut\u2026';
                        }
                    })
                    .catch(function () {});
            }, 2000);
        });
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd();
}

function recoveryStubRenderPackageInstallPage(string $authHash, ?string $errorMessage = null): void
{
    $version = RECOVERY_PACKAGE_VERSION;
    $installUrl = '?action=install-package&amp;t=' . \htmlspecialchars($authHash);
    $ghUrl = \htmlspecialchars(recoveryStubReleaseDownloadUrl($version));
    $dirName = RECOVERY_PACKAGE_DIR_NAME;

    recoveryStubRenderPageStart('Recovery-Paket installieren', 'Paket wird für die volle Oberfläche benötigt');
    ?>
    <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
    <p class="error"><strong><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Fehler</strong><br><?= $errorMessage ?></p>
    <?php endif; ?>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Paket automatisch installieren</h2>
            <p class="sectionDescription">Lädt <code>recovery-<?= \htmlspecialchars($version) ?>.tar.gz</code> von GitHub und entpackt es nach <code><?= \htmlspecialchars($dirName) ?>/</code>.</p>
        </header>
        <p class="info"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Version <strong>v<?= \htmlspecialchars($version) ?></strong> enthält alle Recovery-Modi und die ACP-Oberfläche.</p>
        <div class="formSubmit">
            <a href="<?= $installUrl ?>" class="button buttonPrimary" id="installPackageBtn">
                <i class="fa-solid fa-download" aria-hidden="true"></i> Paket automatisch installieren
            </a>
        </div>
    </section>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Manuelle Installation</h2>
            <p class="sectionDescription">Wenn der automatische Download auf Ihrem Server blockiert ist.</p>
        </header>
        <dl>
            <dt><label>Archiv</label></dt>
            <dd><a href="<?= $ghUrl ?>">recovery-<?= \htmlspecialchars($version) ?>.tar.gz</a></dd>
            <dt><label>Zielverzeichnis</label></dt>
            <dd><code><?= \htmlspecialchars($dirName) ?>/</code> im WoltLab-Hauptverzeichnis (neben <code>plugin-recovery-tool.php</code>)</dd>
        </dl>
    </section>

    <script>
    (function () {
        var btn = document.getElementById('installPackageBtn');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            btn.classList.add('disabled');
            btn.setAttribute('aria-busy', 'true');
            var icon = btn.querySelector('.fa-download');
            if (icon) {
                icon.classList.remove('fa-download');
                icon.classList.add('fa-spinner', 'fa-spin');
            }
        });
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd();
}

/**
 * WoltLab Plugin Recovery Tool — Stub (v2.0)
 *
 * Upload ins WoltLab-Hauptverzeichnis. Auth bleibt separat (plugin-recovery-auth.php).
 * Nach Auth wird recovery-{VERSION}.tar.gz von GitHub geladen und nach recovery-tool/ entpackt.
 *
 * @version 2.0.5
 */

define('RECOVERY_STUB_VERSION', '2.0.5');
define('RECOVERY_PACKAGE_VERSION', '2.0.5');
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
    recoveryStubRenderPackageInstallPage($authHash, (string) ($result['error'] ?? ''));
    exit;
}

if (!$isAuthenticated) {
    recoveryStubRenderAuthWizard($authHash);
    exit;
}

if (!recoveryStubPackageReady()) {
    recoveryStubRenderPackageInstallPage($authHash);
    exit;
}

// --- Paket laden ---
\define('RECOVERY_WCF_ROOT', recoveryStubWcfRoot());
\define('RECOVERY_PACKAGE_DIR', recoveryStubPackageDir());
$recoveryAuthHash = $authHash;
$recoveryIsAuthenticated = true;
require recoveryStubPackageDir() . 'bootstrap.php';
