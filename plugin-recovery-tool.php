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
        .recovery-log-panel { margin-top: 24px; }
        .recovery-log-panel > summary {
            cursor: pointer;
            list-style: none;
            font-weight: 600;
        }
        .recovery-log-panel > summary::-webkit-details-marker { display: none; }
        .recovery-log-panel > summary::before {
            content: "\f078";
            font-family: "Font Awesome 6 Free", "Font Awesome 7 Free";
            font-weight: 900;
            display: inline-block;
            margin-right: 8px;
            transition: transform 0.15s ease;
        }
        .recovery-log-panel[open] > summary::before { transform: rotate(-180deg); }
        .recovery-log-files { margin: 12px 0 0; }
        .recovery-log-files dt { font-weight: 600; margin-top: 8px; }
        .recovery-log-files dd { margin: 2px 0 0; color: var(--wcfContentDimmedText, #888); }
        .recovery-log-files code { font-size: 12px; }
        .recovery-log-pre {
            margin: 12px 0 0;
            padding: 12px 14px;
            max-height: 220px;
            overflow: auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.45;
            border-radius: 4px;
            background: var(--wcfSidebarBackground, rgba(0,0,0,.06));
            border: 1px solid var(--wcfContainerBorder, rgba(0,0,0,.12));
            white-space: pre-wrap;
            word-break: break-word;
        }
        .recovery-log-empty { margin: 8px 0 0; color: var(--wcfContentDimmedText, #888); font-size: 13px; }
        @keyframes recovery-spin { to { transform: rotate(360deg); } }
        .recovery-spinner {
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            display: inline-block;
            width: 1.1em;
            height: 1.1em;
            vertical-align: -0.15em;
            animation: recovery-spin 0.7s linear infinite;
        }
        .recovery-loading-inline { display: inline-flex; align-items: center; gap: 8px; }
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

function recoveryStubLogFileBadge(array $entry): string
{
    $isErrorLog = \str_contains($entry['file'], 'stub-errors-');
    $hasContent = $entry['exists'] && $entry['size'] > 0;

    if ($hasContent) {
        return '<span class="badge green small">' . \htmlspecialchars(recoveryStubFormatLogSize($entry['size'])) . '</span>';
    }
    if ($isErrorLog) {
        return '<span class="badge green small">keine Fehler</span>';
    }

    return '<span class="badge small">noch keine Einträge</span>';
}

function recoveryStubRenderLogPanel(bool $expanded = false): void
{
    $catalog = recoveryStubLogFileCatalog();
    $excerpt = recoveryStubRecentLogExcerpt();
    $open = $expanded || $excerpt !== [];
    ?>
    <section class="section recovery-log-section">
        <details class="recovery-log-panel"<?= $open ? ' open' : '' ?>>
            <summary>Protokoll &amp; Diagnose</summary>
            <p class="sectionDescription" style="margin-top:12px">
                Verzeichnis: <code title="<?= \htmlspecialchars(recoveryStubLogDir()) ?>"><?= \htmlspecialchars(recoveryStubLogDisplayPath()) ?></code>
            </p>
            <p class="info" style="margin-top:10px"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                Die Log-Dateien sind Protokolle, kein Fehlerstatus der Oberfläche.
                Ein leeres <strong>Fehlerprotokoll</strong> bedeutet: es ist nichts schiefgelaufen.</p>
            <dl class="recovery-log-files">
                <?php foreach ($catalog as $entry): ?>
                <dt>
                    <?= \htmlspecialchars($entry['label']) ?>
                    <?= recoveryStubLogFileBadge($entry) ?>
                </dt>
                <dd>
                    <?= \htmlspecialchars($entry['description']) ?>
                    — <code><?= \htmlspecialchars($entry['file']) ?></code>
                </dd>
                <?php endforeach; ?>
            </dl>
            <?php if ($excerpt !== []): ?>
            <p class="sectionDescription" style="margin-top:14px">Letzte Einträge</p>
            <pre class="recovery-log-pre" role="log"><?= \htmlspecialchars(\implode("\n", $excerpt)) ?></pre>
            <?php else: ?>
            <p class="recovery-log-empty">Nach Aktionen (Download, Auth, Installation) erscheinen hier die letzten Zeilen.</p>
            <?php endif; ?>
        </details>
    </section>
    <?php
}

function recoveryStubFormatLogSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return \round($bytes / 1024, 1) . ' KB';
    }

    return \round($bytes / 1048576, 1) . ' MB';
}

/**
 * @param array{showLogPanel?: bool, logPanelExpanded?: bool} $options
 */
function recoveryStubRenderPageEnd(array $options = []): void
{
    if ($options['showLogPanel'] ?? true) {
        recoveryStubRenderLogPanel((bool) ($options['logPanelExpanded'] ?? false));
    }
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

function recoveryStubRenderIntegrityError(string $message, ?string $logDir = null): void
{
    recoveryStubLogExposeHeaders();
    recoveryStubRenderPageStart('Plugin Recovery Tool', 'Integritätsprüfung fehlgeschlagen');
    ?>
    <p class="error"><strong><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Ungültige Recovery-Datei</strong><br>
    <?= \htmlspecialchars($message) ?></p>
    <p class="info">Laden Sie <code>plugin-recovery-tool.php</code> ausschließlich vom
        <a href="https://github.com/<?= \htmlspecialchars(RECOVERY_GITHUB_REPO) ?>/releases" rel="noopener noreferrer">offiziellen GitHub-Release</a> herunter.</p>
    <?php
    recoveryStubRenderPageEnd(['logPanelExpanded' => true]);
}

function recoveryStubRenderAuthWizard(string $authHash, ?string $errorMessage = null): void
{
    $authFile = RECOVERY_AUTH_FILENAME;
    recoveryStubRenderPageStart('Plugin Recovery Tool', '', [
        'value' => 1,
        'max' => 3,
        'label' => 'Schritt 1 von 3',
    ]);
    ?>
    <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
    <p class="error"><strong><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Authentifizierung</strong><br><?= \htmlspecialchars($errorMessage) ?></p>
    <?php endif; ?>
    <section class="section recovery-auth-step" id="auth-step-1">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Auth-Datei laden</h2>
            <p class="sectionDescription">Laden Sie die Auth-Datei herunter. Sie ist kryptografisch an diese Sitzung und diese Tool-Version gebunden.</p>
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
            <a href="?t=<?= \htmlspecialchars($authHash) ?>" class="button buttonPrimary">
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
                            var msg = data.message || 'Datei noch nicht gefunden \u2013 prüfe erneut\u2026';
                            document.getElementById('pollStatus').textContent = msg;
                        }
                    })
                    .catch(function () {});
            }, 2000);
        });
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd(['logPanelExpanded' => $errorMessage !== null && $errorMessage !== '']);
}

function recoveryStubRenderPackageInstallPage(string $authHash, ?string $errorMessage = null): void
{
    $version = RECOVERY_PACKAGE_VERSION;
    $ghDownloadUrl = \htmlspecialchars(recoveryStubReleaseDownloadUrl($version));
    $ghReleaseUrl = \htmlspecialchars(recoveryStubReleasePageUrl($version));
    $dirName = RECOVERY_PACKAGE_DIR_NAME;

    recoveryStubRenderPageStart('Recovery-Paket installieren', 'Paket wird für die volle Oberfläche benötigt');
    ?>
    <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
    <p class="error"><strong><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Fehler</strong><br><?= $errorMessage ?></p>
    <?php endif; ?>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Paket automatisch installieren</h2>
            <p class="sectionDescription">Lädt <a href="<?= $ghDownloadUrl ?>"><code>recovery-<?= \htmlspecialchars($version) ?>.tar.gz</code></a> vom <a href="<?= $ghReleaseUrl ?>" rel="noopener noreferrer">GitHub-Release v<?= \htmlspecialchars($version) ?></a> und entpackt es nach <code><?= \htmlspecialchars($dirName) ?>/</code>.</p>
        </header>
        <p class="info"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Version <strong>v<?= \htmlspecialchars($version) ?></strong> enthält alle Recovery-Modi und die ACP-Oberfläche.</p>
        <form method="post" action="plugin-recovery-tool.php" id="installPackageForm">
            <input type="hidden" name="action" value="install-package">
            <input type="hidden" name="t" value="<?= \htmlspecialchars($authHash) ?>">
            <p class="info recovery-loading-inline" id="installPackageStatus" hidden>
                <span class="recovery-spinner" aria-hidden="true"></span>
                Paket wird heruntergeladen und entpackt — bitte warten …
            </p>
            <div class="formSubmit">
                <input type="submit" id="installPackageBtn" class="button buttonPrimary" value="Paket automatisch installieren" accesskey="s">
            </div>
        </form>
    </section>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Manuelle Installation</h2>
            <p class="sectionDescription">Wenn der automatische Download auf Ihrem Server blockiert ist.</p>
        </header>
        <dl>
            <dt><label>Archiv</label></dt>
            <dd><a href="<?= $ghDownloadUrl ?>">recovery-<?= \htmlspecialchars($version) ?>.tar.gz</a> (<a href="<?= $ghReleaseUrl ?>" rel="noopener noreferrer">Release</a>)</dd>
            <dt><label>Zielverzeichnis</label></dt>
            <dd><code><?= \htmlspecialchars($dirName) ?>/</code> im WoltLab-Hauptverzeichnis (neben <code>plugin-recovery-tool.php</code>)</dd>
        </dl>
    </section>

    <script>
    (function () {
        var form = document.getElementById('installPackageForm');
        if (!form) { return; }
        form.addEventListener('submit', function () {
            var status = document.getElementById('installPackageStatus');
            var btn = document.getElementById('installPackageBtn');
            if (status) {
                status.hidden = false;
            }
            if (btn) {
                btn.disabled = true;
                btn.value = 'Installation läuft …';
            }
        });
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd(['logPanelExpanded' => $errorMessage !== null && $errorMessage !== '']);
}

\define('RECOVERY_STUB_LOG_SUBDIR', 'log/recovery');

function recoveryStubLogDir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $dir = \rtrim(recoveryStubWcfRoot(), '/\\') . '/' . RECOVERY_STUB_LOG_SUBDIR . '/';
    if (\is_file($dir)) {
        @\unlink($dir);
    }
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0775, true);
    }
    if (\is_dir($dir)) {
        if (!\is_writable($dir)) {
            @\chmod($dir, 0775);
        }
        if (\is_writable($dir)) {
            return $resolved = $dir;
        }
    }

    $fallback = \rtrim(\sys_get_temp_dir(), '/\\') . '/woltlab-recovery-stub/';
    if (!\is_dir($fallback)) {
        @\mkdir($fallback, 0775, true);
    }

    return $resolved = $fallback;
}

function recoveryStubLogPath(string $basename): string
{
    return recoveryStubLogDir() . $basename;
}

/**
 * @return array<string, mixed>
 */
function recoveryStubRequestContext(): array
{
    $token = isset($_REQUEST['t']) ? (string) $_REQUEST['t'] : '';

    return [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'action' => isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : null,
        'tokenPrefix' => $token !== '' ? \substr($token, 0, 8) : null,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'remoteAddr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'stubVersion' => \defined('RECOVERY_STUB_VERSION') ? RECOVERY_STUB_VERSION : null,
    ];
}

function recoveryStubWriteLogLine(string $basename, string $line): void
{
    $path = recoveryStubLogPath($basename);
    if (@\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX) === false) {
        @\error_log('[recovery-stub] log_write_failed path=' . $path . ' line=' . $line);
    }
}

/**
 * @param array<string, mixed> $context
 */
function recoveryStubLog(string $level, string $message, array $context = []): void
{
    $line = \sprintf("[%s] [%s] %s", \date('Y-m-d H:i:s'), \strtoupper($level), $message);
    if ($context !== []) {
        $json = \json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }
    recoveryStubWriteLogLine('stub-' . \date('Y-m-d') . '.log', $line);
}

/**
 * @param array<string, mixed> $context
 */
function recoveryStubLogAction(string $action, array $context = []): void
{
    $payload = ['action' => $action] + recoveryStubRequestContext() + $context;
    recoveryStubLog('info', 'ACTION ' . $action, $payload);
    recoveryStubWriteLogLine(
        'stub-actions-' . \date('Y-m-d') . '.log',
        \sprintf("[%s] %s %s", \date('Y-m-d H:i:s'), $action, \json_encode($payload, \JSON_UNESCAPED_UNICODE) ?: '{}')
    );
}

/**
 * @param array<string, mixed> $context
 */
function recoveryStubLogError(string $message, array $context = []): void
{
    $payload = recoveryStubRequestContext() + $context;
    recoveryStubLog('error', $message, $payload);
    recoveryStubWriteLogLine(
        'stub-errors-' . \date('Y-m-d') . '.log',
        \sprintf("[%s] %s %s", \date('Y-m-d H:i:s'), $message, \json_encode($payload, \JSON_UNESCAPED_UNICODE) ?: '{}')
    );
}

/** @param array<string, mixed> $data */
function recoveryStubLogDebug(string $location, string $message, array $data = []): void
{
    recoveryStubLog('debug', $message, ['location' => $location] + $data);
    $payload = [
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) \round(\microtime(true) * 1000),
        'stubVersion' => \defined('RECOVERY_STUB_VERSION') ? RECOVERY_STUB_VERSION : null,
    ] + recoveryStubRequestContext();
    $line = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line !== false) {
        recoveryStubWriteLogLine('stub-debug-' . \date('Y-m-d') . '.ndjson', $line);
    }
}

function recoveryStubLogRequestStarted(): void
{
    recoveryStubLogAction('request', [
        'logDir' => recoveryStubLogDir(),
        'wcfRoot' => recoveryStubWcfRoot(),
    ]);
}

function recoveryStubLogDisplayPath(): string
{
    $root = \rtrim(recoveryStubWcfRoot(), '/\\') . '/';
    $dir = recoveryStubLogDir();
    if (\str_starts_with($dir, $root)) {
        return \rtrim(\substr($dir, \strlen($root)), '/') . '/';
    }

    return $dir;
}

/**
 * @return list<array{file: string, label: string, description: string, exists: bool, size: int}>
 */
function recoveryStubLogFileCatalog(): array
{
    $date = \date('Y-m-d');
    $entries = [
        ['file' => 'stub-' . $date . '.log', 'label' => 'Gesamtprotokoll', 'description' => 'Alle Meldungen des Stub'],
        ['file' => 'stub-actions-' . $date . '.log', 'label' => 'Aktionen', 'description' => 'Requests, Auth-Schritte, Installation'],
        ['file' => 'stub-errors-' . $date . '.log', 'label' => 'Fehlerprotokoll', 'description' => 'Nur bei echten Fehlern (Integrität, Auth, Download)'],
        ['file' => 'stub-debug-' . $date . '.ndjson', 'label' => 'Debug (NDJSON)', 'description' => 'Strukturierte Diagnose'],
    ];
    foreach ($entries as &$entry) {
        $path = recoveryStubLogPath($entry['file']);
        $entry['exists'] = \is_file($path) && \is_readable($path);
        $entry['size'] = $entry['exists'] ? (int) \filesize($path) : 0;
    }
    unset($entry);

    return $entries;
}

function recoveryStubReadLogTail(string $basename, int $maxLines = 15): string
{
    if (!\preg_match('~^stub-(?:actions-|errors-|debug-)?\d{4}-\d{2}-\d{2}\.(?:log|ndjson)$~', $basename)) {
        return '';
    }
    $path = recoveryStubLogPath($basename);
    if (!\is_file($path) || !\is_readable($path)) {
        return '';
    }
    $lines = @\file($path, \FILE_IGNORE_NEW_LINES);
    if (!\is_array($lines) || $lines === []) {
        return '';
    }

    return \implode("\n", \array_slice($lines, -\max(1, $maxLines)));
}

/**
 * @return list<string>
 */
function recoveryStubRecentLogExcerpt(int $maxLines = 18): array
{
    $date = \date('Y-m-d');
    $chunks = [];
    foreach (['stub-errors-' . $date . '.log', 'stub-actions-' . $date . '.log', 'stub-' . $date . '.log'] as $file) {
        $tail = recoveryStubReadLogTail($file, 8);
        if ($tail !== '') {
            $chunks[] = '--- ' . $file . " ---\n" . $tail;
        }
    }

    if ($chunks === []) {
        return [];
    }

    $merged = \explode("\n", \implode("\n", $chunks));

    return \array_slice($merged, -\max(1, $maxLines));
}

function recoveryStubLogExposeHeaders(): void
{
    static $sent = false;
    if ($sent || \headers_sent()) {
        return;
    }
    $sent = true;
    \header('X-WFL-Recovery-Stub-Log-Dir-B64: ' . \base64_encode(recoveryStubLogDir()));
    \header('X-WFL-Recovery-Stub-Log-B64: ' . \base64_encode(recoveryStubLogPath('stub-' . \date('Y-m-d') . '.log')));
}

function recoveryStubCleanupAllRecoveryArtifacts(): void
{
    $root = recoveryStubWcfRoot();

    $logDir = \rtrim($root, '/\\') . '/' . RECOVERY_STUB_LOG_SUBDIR;
    if (\is_dir($logDir)) {
        recoveryStubRemoveDirectory($logDir);
    }

    foreach (
        [
            $root . 'log/recovery-tool-*.ndjson',
            $root . 'log/plugin-recovery-*.ndjson',
            $root . 'log/debug-*.ndjson',
        ] as $pattern
    ) {
        foreach (\glob($pattern) ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    foreach (
        [
            $root . RECOVERY_AUTH_FILENAME,
            $root . 'uploads/.recovery-cache',
            recoveryStubPackageDir(),
        ] as $path
    ) {
        if (\is_file($path)) {
            @\unlink($path);
        } elseif (\is_dir($path)) {
            recoveryStubRemoveDirectory($path);
        }
    }

    $tmpDir = \rtrim($root, '/\\') . '/tmp/';
    if (\is_dir($tmpDir)) {
        foreach (\glob($tmpDir . 'recovery-*.tar.gz') ?: [] as $archive) {
            if (\is_file($archive)) {
                @\unlink($archive);
            }
        }
    }
}

\define('RECOVERY_AUTH_FORMAT', 2);
\define('RECOVERY_AUTH_PENDING_DIR', 'log/recovery/.auth-pending');
\define('RECOVERY_AUTH_BOUND_DIR', 'log/recovery/.auth-bound');

function recoveryStubAuthStateDir(string $subDir): string
{
    $dir = \rtrim(recoveryStubWcfRoot(), '/\\') . '/' . $subDir . '/';
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0700, true);
    }
    if (\is_dir($dir)) {
        @\chmod($dir, 0700);
    }

    return $dir;
}

function recoveryStubPendingAuthPath(string $token): string
{
    return recoveryStubAuthStateDir(RECOVERY_AUTH_PENDING_DIR) . $token . '.json';
}

function recoveryStubBoundAuthPath(string $token): string
{
    return recoveryStubAuthStateDir(RECOVERY_AUTH_BOUND_DIR) . $token . '.json';
}

function recoveryStubBuildId(): string
{
    $hash = \defined('RECOVERY_STUB_INTEGRITY_HASH') ? (string) RECOVERY_STUB_INTEGRITY_HASH : '';

    return RECOVERY_STUB_VERSION . '-' . \substr($hash, 0, 16);
}

/**
 * @return array{ok: bool, message?: string, expectedPrefix?: string, actualPrefix?: string, logDir?: string}
 */
function recoveryStubVerifyIntegrityDetailed(): array
{
    $logDir = recoveryStubLogDir();
    if (!\defined('RECOVERY_STUB_INTEGRITY_HASH') || RECOVERY_STUB_INTEGRITY_HASH === '') {
        return [
            'ok' => false,
            'message' => 'Stub-Integritätsprüfung fehlt (kein offizielles Release?).',
            'logDir' => $logDir,
        ];
    }
    $content = (string) @\file_get_contents(__FILE__);
    if ($content === '') {
        return [
            'ok' => false,
            'message' => 'Stub-Datei konnte nicht gelesen werden.',
            'logDir' => $logDir,
        ];
    }
    $placeholder = \str_repeat('0', 64);
    $canonical = \preg_replace(
        "/^define\\('RECOVERY_STUB_INTEGRITY_HASH',\\s*'[^']*'\\);/m",
        "define('RECOVERY_STUB_INTEGRITY_HASH', '" . $placeholder . "');",
        $content,
        1
    );
    if ($canonical === null) {
        return [
            'ok' => false,
            'message' => 'Stub-Integritätsprüfung fehlgeschlagen.',
            'logDir' => $logDir,
        ];
    }
    $expected = (string) RECOVERY_STUB_INTEGRITY_HASH;
    $actual = \hash('sha256', $canonical);
    if (!\hash_equals($expected, $actual)) {
        return [
            'ok' => false,
            'message' => 'Die Datei plugin-recovery-tool.php wurde verändert oder ist kein offizielles Release von GitHub.',
            'expectedPrefix' => \substr($expected, 0, 16),
            'actualPrefix' => \substr($actual, 0, 16),
            'logDir' => $logDir,
        ];
    }

    return ['ok' => true, 'logDir' => $logDir];
}

function recoveryStubVerifyIntegrity(): ?string
{
    $result = recoveryStubVerifyIntegrityDetailed();

    return $result['ok'] ? null : (string) ($result['message'] ?? 'Integritätsprüfung fehlgeschlagen.');
}

function recoveryStubAssertValidToken(string $token): bool
{
    return \preg_match('~^[a-f0-9]{40}$~', $token) === 1;
}

/**
 * @return array<string, mixed>|null
 */
function recoveryStubLoadAuthState(string $path): ?array
{
    if (!\is_file($path) || !\is_readable($path)) {
        return null;
    }
    $json = \json_decode((string) \file_get_contents($path), true);

    return \is_array($json) ? $json : null;
}

function recoveryStubSaveAuthState(string $path, array $state): bool
{
    $dir = \dirname($path);
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0700, true);
    }
    $payload = \json_encode($state, \JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }

    return @\file_put_contents($path, $payload, \LOCK_EX) !== false && @\chmod($path, 0600);
}

function recoveryStubCreatePendingSession(string $token): void
{
    if (!recoveryStubAssertValidToken($token)) {
        return;
    }
    $now = \time();
    recoveryStubSaveAuthState(recoveryStubPendingAuthPath($token), [
        'token' => $token,
        'secret' => \bin2hex(\random_bytes(32)),
        'stubBuildId' => recoveryStubBuildId(),
        'stubIntegrity' => \defined('RECOVERY_STUB_INTEGRITY_HASH') ? RECOVERY_STUB_INTEGRITY_HASH : '',
        'createdAt' => $now,
        'expiresAt' => $now + 86400,
        'authIssuedAt' => null,
    ]);
    recoveryStubLogDebug('auth', 'pending_session_created', ['tokenPrefix' => \substr($token, 0, 8)]);
}

function recoveryStubAuthSignature(string $secret, int $expires, string $token, string $stubBuildId): string
{
    $payload = $expires . "\n" . $token . "\n" . $stubBuildId;

    return \hash_hmac('sha256', $payload, $secret);
}

/**
 * @return array{ok: true, content: string}|array{ok: false, error: string}
 */
function recoveryStubGenerateAuthFileContent(string $token): array
{
    if (!recoveryStubAssertValidToken($token)) {
        return ['ok' => false, 'error' => 'Ungültige Sitzung.'];
    }

    $pending = recoveryStubLoadAuthState(recoveryStubPendingAuthPath($token));
    if ($pending === null) {
        recoveryStubLog('warning', 'Auth-Download ohne Pending-Session', ['tokenPrefix' => \substr($token, 0, 8)]);

        return ['ok' => false, 'error' => 'Keine gültige Recovery-Sitzung. Bitte plugin-recovery-tool.php erneut aufrufen.'];
    }

    if (($pending['stubBuildId'] ?? '') !== recoveryStubBuildId()) {
        return ['ok' => false, 'error' => 'Auth-Datei passt nicht zu dieser plugin-recovery-tool.php (Version/Build).'];
    }

    if (($pending['stubIntegrity'] ?? '') !== '' && \defined('RECOVERY_STUB_INTEGRITY_HASH')
        && !\hash_equals((string) $pending['stubIntegrity'], (string) RECOVERY_STUB_INTEGRITY_HASH)) {
        return ['ok' => false, 'error' => 'Stub wurde seit Sitzungsstart verändert. Neu starten.'];
    }

    $expires = (int) ($pending['expiresAt'] ?? 0);
    if ($expires <= \time()) {
        return ['ok' => false, 'error' => 'Sitzung abgelaufen. Tool neu aufrufen.'];
    }

    $secret = (string) ($pending['secret'] ?? '');
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Sitzungsgeheimnis fehlt.'];
    }

    $stubBuildId = recoveryStubBuildId();
    $signature = recoveryStubAuthSignature($secret, $expires, $token, $stubBuildId);

    $pending['authIssuedAt'] = \time();
    recoveryStubSaveAuthState(recoveryStubPendingAuthPath($token), $pending);

    $content = "<?php exit; /* WoltLab Plugin Recovery Auth v" . RECOVERY_AUTH_FORMAT . " — NICHT BEARBEITEN */ ?>\n"
        . $expires . "\n"
        . $token . "\n"
        . $stubBuildId . "\n"
        . $signature;

    recoveryStubLog('info', 'Auth-Datei ausgestellt', ['tokenPrefix' => \substr($token, 0, 8)]);

    return ['ok' => true, 'content' => $content];
}

/**
 * @return array{ok: bool, reason?: string}
 */
function recoveryStubValidateAuthFile(string $urlToken): array
{
    if (!recoveryStubAssertValidToken($urlToken)) {
        return ['ok' => false, 'reason' => 'invalid_token'];
    }

    $authPath = recoveryStubWcfRoot() . RECOVERY_AUTH_FILENAME;
    if (!\is_file($authPath) || !\is_readable($authPath)) {
        return ['ok' => false, 'reason' => 'missing_file'];
    }

    $lines = \preg_split("/\r\n|\n|\r/", (string) \file_get_contents($authPath)) ?: [];
    if (\count($lines) < 4) {
        return ['ok' => false, 'reason' => 'legacy_or_invalid_format'];
    }

    if (!\str_contains((string) ($lines[0] ?? ''), 'Auth v' . RECOVERY_AUTH_FORMAT)) {
        return ['ok' => false, 'reason' => 'wrong_format_version'];
    }

    $expires = (int) ($lines[1] ?? 0);
    $fileToken = \trim((string) ($lines[2] ?? ''));
    $fileStubBuildId = \trim((string) ($lines[3] ?? ''));
    $fileSignature = \trim((string) ($lines[4] ?? ''));

    if ($expires <= \time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }

    if (!\hash_equals($urlToken, $fileToken)) {
        recoveryStubLog('warning', 'Auth-Token-Mismatch URL vs. Datei', []);

        return ['ok' => false, 'reason' => 'token_mismatch'];
    }

    if (!\hash_equals(recoveryStubBuildId(), $fileStubBuildId)) {
        recoveryStubLog('warning', 'Auth-Stub-Build-Mismatch', [
            'expected' => recoveryStubBuildId(),
            'got' => $fileStubBuildId,
        ]);

        return ['ok' => false, 'reason' => 'stub_mismatch'];
    }

    $state = recoveryStubLoadAuthState(recoveryStubPendingAuthPath($urlToken))
        ?? recoveryStubLoadAuthState(recoveryStubBoundAuthPath($urlToken));
    if ($state === null) {
        return ['ok' => false, 'reason' => 'no_server_session'];
    }

    if (($state['stubBuildId'] ?? '') !== recoveryStubBuildId()) {
        return ['ok' => false, 'reason' => 'server_stub_mismatch'];
    }

    $secret = (string) ($state['secret'] ?? '');
    if ($secret === '') {
        return ['ok' => false, 'reason' => 'no_secret'];
    }

    $expected = recoveryStubAuthSignature($secret, $expires, $fileToken, $fileStubBuildId);
    if ($fileSignature === '' || !\hash_equals($expected, $fileSignature)) {
        recoveryStubLog('warning', 'Auth-Signatur ungültig', []);

        return ['ok' => false, 'reason' => 'bad_signature'];
    }

    recoveryStubSaveAuthState(recoveryStubBoundAuthPath($urlToken), $state + [
        'boundAt' => \time(),
        'lastValidatedAt' => \time(),
    ]);
    @\unlink(recoveryStubPendingAuthPath($urlToken));

    recoveryStubLog('info', 'Auth erfolgreich validiert', ['tokenPrefix' => \substr($urlToken, 0, 8)]);

    return ['ok' => true];
}

function recoveryStubIsAuthenticated(string $urlToken): bool
{
    static $cache = [];

    if (isset($cache[$urlToken])) {
        return $cache[$urlToken];
    }

    $bound = recoveryStubLoadAuthState(recoveryStubBoundAuthPath($urlToken));
    if ($bound !== null && (int) ($bound['expiresAt'] ?? 0) > \time()) {
        if (($bound['stubBuildId'] ?? '') === recoveryStubBuildId()) {
            return $cache[$urlToken] = true;
        }
    }

    $result = recoveryStubValidateAuthFile($urlToken);

    return $cache[$urlToken] = $result['ok'];
}

function recoveryStubAuthFailureMessage(string $reason): string
{
    return match ($reason) {
        'token_mismatch' => 'Die Auth-Datei gehört nicht zu dieser Sitzung (URL-Token stimmt nicht überein).',
        'stub_mismatch', 'server_stub_mismatch' => 'Die Auth-Datei passt nicht zu dieser plugin-recovery-tool.php-Version.',
        'bad_signature' => 'Die Auth-Datei wurde manipuliert oder stammt von einer anderen Sitzung.',
        'expired' => 'Die Auth-Datei ist abgelaufen. Bitte neu herunterladen.',
        'no_server_session' => 'Keine gültige Recovery-Sitzung auf dem Server. Tool-URL erneut aufrufen.',
        'legacy_or_invalid_format', 'wrong_format_version' => 'Ungültige oder veraltete Auth-Datei. Bitte neu herunterladen.',
        'missing_file' => 'Auth-Datei wurde noch nicht hochgeladen.',
        default => 'Authentifizierung fehlgeschlagen.',
    };
}

function recoveryStubCleanupAuthState(): void
{
    foreach ([RECOVERY_AUTH_PENDING_DIR, RECOVERY_AUTH_BOUND_DIR] as $sub) {
        $dir = \rtrim(recoveryStubWcfRoot(), '/\\') . '/' . $sub;
        if (\is_dir($dir)) {
            recoveryStubRemoveDirectory($dir);
        }
    }
}

/**
 * WoltLab Plugin Recovery Tool — Stub (v2.0)
 *
 * Upload ins WoltLab-Hauptverzeichnis. Auth bleibt separat (plugin-recovery-auth.php).
 * Nach Auth wird recovery-{VERSION}.tar.gz von GitHub geladen und nach recovery-tool/ entpackt.
 *
 * @version 2.1.5
 */

define('RECOVERY_STUB_VERSION', '2.1.5');
define('RECOVERY_PACKAGE_VERSION', '2.1.5');
define('RECOVERY_STUB_INTEGRITY_HASH', 'fd5798dea86db08fc15aa33c10334064518b4257003c61fad746f88b8e6b43b0');
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

function recoveryStubReleasePageUrl(string $version): string
{
    return 'https://github.com/' . RECOVERY_GITHUB_REPO . '/releases/tag/v' . $version;
}

/**
 * WoltLab-Temp wie FileUtil::getTempFolder() — WCF_DIR/tmp/
 */
function recoveryStubDownloadCacheDir(): string
{
    $dir = recoveryStubWcfRoot() . 'tmp/';
    if (\is_file($dir)) {
        @\unlink($dir);
    }
    if (!\is_dir($dir) && !@\mkdir($dir, 0777, true)) {
        return \rtrim(\sys_get_temp_dir(), '/\\') . '/';
    }
    if (\is_dir($dir) && !\is_writable($dir)) {
        @\chmod($dir, 0777);
    }

    return \rtrim($dir, '/\\') . '/';
}

/**
 * @return array{ok: true, data: string}|array{ok: false, error: string}
 */
function recoveryStubHttpDownload(string $url): array
{
    if (\function_exists('curl_init')) {
        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'cURL konnte nicht initialisiert werden.'];
        }
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 10,
            \CURLOPT_CONNECTTIMEOUT => 30,
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_USERAGENT => 'WoltLab-Plugin-Recovery/' . RECOVERY_STUB_VERSION,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = \curl_exec($ch);
        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);
        \curl_close($ch);
        if ($data === false) {
            return ['ok' => false, 'error' => 'cURL: ' . ($curlError !== '' ? $curlError : 'Unbekannter Fehler')];
        }
        if ($httpCode !== 200) {
            return ['ok' => false, 'error' => 'HTTP-Status ' . $httpCode . ' beim Download.'];
        }

        return ['ok' => true, 'data' => $data];
    }

    if (!\ini_get('allow_url_fopen')) {
        return [
            'ok' => false,
            'error' => 'allow_url_fopen ist deaktiviert und cURL nicht verfügbar. Bitte manuell installieren.',
        ];
    }

    $context = \stream_context_create([
        'http' => [
            'method' => 'GET',
            'follow_location' => 1,
            'max_redirects' => 10,
            'timeout' => 300,
            'header' => 'User-Agent: WoltLab-Plugin-Recovery/' . RECOVERY_STUB_VERSION . "\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @\file_get_contents($url, false, $context);
    if ($data === false) {
        $last = \error_get_last();

        return [
            'ok' => false,
            'error' => 'Download fehlgeschlagen'
                . ($last['message'] ?? '' ? ' (' . $last['message'] . ')' : '') . '.',
        ];
    }

    return ['ok' => true, 'data' => $data];
}

function recoveryStubIsGzipArchive(string $data): bool
{
    return \strlen($data) >= 2 && $data[0] === "\x1f" && $data[1] === "\x8b";
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

function recoveryStubCopyDirectory(string $src, string $dst): void
{
    if (!\is_dir($dst) && !@\mkdir($dst, 0755, true)) {
        return;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $target = $dst . \substr($item->getPathname(), \strlen($src));
        if ($item->isDir()) {
            if (!\is_dir($target)) {
                @\mkdir($target, 0755, true);
            }
        } else {
            @\copy($item->getPathname(), $target);
        }
    }
}

function recoveryStubMoveItem(string $src, string $dst): void
{
    if (\is_dir($src)) {
        if (\is_dir($dst)) {
            recoveryStubRemoveDirectory($dst);
        }
        if (!@\rename($src, $dst)) {
            recoveryStubCopyDirectory($src, $dst);
            recoveryStubRemoveDirectory($src);
        }

        return;
    }

    if (!@\rename($src, $dst)) {
        @\copy($src, $dst);
        @\unlink($src);
    }
}

function recoveryStubPharRelativePath(string $archive, \SplFileInfo $entry): string
{
    $path = \str_replace('\\', '/', $entry->getPathname());
    $prefix = 'phar://' . $archive . '/';
    if (\str_starts_with($path, $prefix)) {
        return \substr($path, \strlen($prefix));
    }

    return \ltrim($path, './');
}

function recoveryStubArchiveUsesPackagePrefix(string $archive): bool
{
    if (!\class_exists(\PharData::class, false)) {
        return true;
    }

    try {
        $phar = new \PharData($archive);
        foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $path = recoveryStubPharRelativePath($archive, $entry);

            return \str_starts_with($path, RECOVERY_PACKAGE_DIR_NAME . '/');
        }
    } catch (\Throwable $ignored) {
    }

    return false;
}

function recoveryStubValidateArchive(string $archive): ?string
{
    if (!\class_exists(\PharData::class, false)) {
        return null;
    }

    try {
        $phar = new \PharData($archive);
        foreach (new \RecursiveIteratorIterator($phar) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $path = recoveryStubPharRelativePath($archive, $entry);
            if ($path === 'bootstrap.php' || \str_ends_with($path, '/bootstrap.php')) {
                return null;
            }
        }
    } catch (\Throwable $e) {
        return 'Archiv ungültig: ' . $e->getMessage();
    }

    return 'Archiv enthält keine bootstrap.php.';
}

function recoveryStubFlattenNestedPackageDir(string $destination): void
{
    $nested = \rtrim($destination, '/\\') . '/' . RECOVERY_PACKAGE_DIR_NAME;
    if (!\is_dir($nested) || !\is_file($nested . '/bootstrap.php')) {
        return;
    }

    foreach (new \DirectoryIterator($nested) as $item) {
        if ($item->isDot()) {
            continue;
        }
        recoveryStubMoveItem($item->getPathname(), \rtrim($destination, '/\\') . '/' . $item->getFilename());
    }
    recoveryStubRemoveDirectory($nested);
}

/**
 * @return array{ok: bool, error?: string}
 */
function recoveryStubExtractTarGz(string $archive, string $destination): array
{
    $destination = \rtrim($destination, '/\\') . '/';
    if (!\is_dir($destination) && !@\mkdir($destination, 0755, true)) {
        return ['ok' => false, 'error' => 'Zielverzeichnis konnte nicht angelegt werden.'];
    }

    $archiveError = recoveryStubValidateArchive($archive);
    if ($archiveError !== null) {
        return ['ok' => false, 'error' => $archiveError];
    }

    $stripPrefix = recoveryStubArchiveUsesPackagePrefix($archive);

    if (\class_exists(\PharData::class, false)) {
        try {
            $phar = new \PharData($archive);
            $files = [];
            foreach (new \RecursiveIteratorIterator($phar) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $relative = recoveryStubPharRelativePath($archive, $entry);
                if ($relative === '' || \str_contains($relative, '..')) {
                    continue;
                }
                $files[] = $relative;
            }
            if ($files === []) {
                return ['ok' => false, 'error' => 'Archiv enthält keine Dateien.'];
            }
            $phar->extractTo($destination, $files, true);
            if ($stripPrefix) {
                recoveryStubFlattenNestedPackageDir($destination);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'PharData: ' . $e->getMessage()];
        }
    } else {
        $tar = \trim((string) \shell_exec('command -v tar 2>/dev/null'));
        if ($tar === '') {
            return ['ok' => false, 'error' => 'Weder Phar noch tar verfügbar.'];
        }
        $cmd = \escapeshellarg($tar) . ' -xzf ' . \escapeshellarg($archive)
            . ($stripPrefix ? ' --strip-components=1' : '')
            . ' -C ' . \escapeshellarg($destination) . ' 2>&1';
        \exec($cmd, $out, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'tar exit ' . $code . ': ' . \implode("\n", $out)];
        }
    }

    if (!\is_file($destination . 'bootstrap.php')) {
        $found = [];
        if (\is_dir($destination)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($destination, \RecursiveDirectoryIterator::SKIP_DOTS)
            ) as $file) {
                if ($file->isFile() && $file->getFilename() === 'bootstrap.php') {
                    $found[] = \str_replace($destination, '', $file->getPathname());
                }
            }
        }

        $hint = $found !== []
            ? ' Gefunden unter: ' . \implode(', ', $found) . ' (Verschachtelung konnte nicht aufgelöst werden).'
            : ' Bitte <code>recovery-tool/</code> leeren und erneut versuchen oder manuell entpacken.';

        return ['ok' => false, 'error' => 'Paket unvollständig (bootstrap.php fehlt).' . $hint];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function recoveryStubInstallPackage(string $version): array
{
    @\set_time_limit(300);
    recoveryStubLog('info', 'Paket-Installation gestartet', ['version' => $version]);

    $url = recoveryStubReleaseDownloadUrl($version);
    $dest = recoveryStubPackageDir();
    $archive = recoveryStubDownloadCacheDir() . 'recovery-' . $version . '.tar.gz';

    $download = recoveryStubHttpDownload($url);
    if (!$download['ok']) {
        recoveryStubLog('error', 'Download fehlgeschlagen', ['error' => $download['error'], 'url' => $url]);

        return [
            'ok' => false,
            'error' => $download['error'] . ' Bitte '
                . '<a href="' . \htmlspecialchars($url) . '">recovery-' . $version . '.tar.gz</a> '
                . 'manuell nach <code>' . RECOVERY_PACKAGE_DIR_NAME . '/</code> entpacken.',
        ];
    }

    $data = $download['data'];
    if (!recoveryStubIsGzipArchive($data)) {
        return [
            'ok' => false,
            'error' => 'Ungültige Antwort vom Server (kein gzip-Archiv). Bitte manuell installieren.',
        ];
    }

    if (@\file_put_contents($archive, $data) === false) {
        return ['ok' => false, 'error' => 'Archiv konnte nicht gespeichert werden: ' . \htmlspecialchars($archive)];
    }

    if (\is_dir($dest)) {
        recoveryStubRemoveDirectory($dest);
    }
    if (!@\mkdir($dest, 0755, true)) {
        @\unlink($archive);

        return ['ok' => false, 'error' => 'Verzeichnis ' . RECOVERY_PACKAGE_DIR_NAME . '/ konnte nicht erstellt werden.'];
    }

    $extract = recoveryStubExtractTarGz($archive, $dest);
    @\unlink($archive);

    if ($extract['ok']) {
        recoveryStubLog('info', 'Paket erfolgreich installiert', ['dest' => $dest]);
    } else {
        recoveryStubLog('error', 'Paket-Entpacken fehlgeschlagen', ['error' => $extract['error'] ?? '']);
    }

    return $extract;
}

function recoveryStubCleanupAuxiliary(): void
{
    recoveryStubLog('info', 'Stub-Cleanup gestartet');
    recoveryStubCleanupAllRecoveryArtifacts();
    recoveryStubLog('info', 'Stub-Cleanup abgeschlossen');
}

recoveryStubLogRequestStarted();
recoveryStubLogExposeHeaders();

$integrityResult = recoveryStubVerifyIntegrityDetailed();
if (!$integrityResult['ok']) {
    recoveryStubLogError('Integritätsprüfung fehlgeschlagen', $integrityResult);
    recoveryStubLogAction('integrity_denied', $integrityResult);
    recoveryStubRenderIntegrityError(
        (string) ($integrityResult['message'] ?? 'Integritätsprüfung fehlgeschlagen.'),
        (string) ($integrityResult['logDir'] ?? recoveryStubLogDir())
    );
    exit;
}
recoveryStubLogDebug('bootstrap', 'integrity_ok', ['buildId' => recoveryStubBuildId()]);

// --- Token / Sitzung ---
if (empty($_REQUEST['t']) || !recoveryStubAssertValidToken((string) $_REQUEST['t'])) {
    $authHash = \bin2hex(\random_bytes(20));
    recoveryStubCreatePendingSession($authHash);
    recoveryStubLogAction('session_start', ['tokenPrefix' => \substr($authHash, 0, 8)]);
    \header('Location: plugin-recovery-tool.php?t=' . $authHash);
    exit;
}
$authHash = (string) $_REQUEST['t'];
$action = (!empty($_REQUEST['action'])) ? (string) $_REQUEST['action'] : '';

if ($action === 'download-auth-file') {
    recoveryStubLogAction('auth_download');
    $generated = recoveryStubGenerateAuthFileContent($authHash);
    if (!$generated['ok']) {
        recoveryStubLogError('Auth-Datei konnte nicht erstellt werden', ['error' => $generated['error'] ?? '']);
        recoveryStubRenderAuthWizard($authHash, (string) ($generated['error'] ?? ''));
        exit;
    }
    $content = $generated['content'];
    \header('Content-type: application/octet-stream');
    \header('Content-Disposition: attachment; filename="' . RECOVERY_AUTH_FILENAME . '"');
    \header('Content-Length: ' . (string) \strlen($content));
    echo $content;
    exit;
}

$isAuthenticated = recoveryStubIsAuthenticated($authHash);

if ($action === 'auth-status') {
    $reason = null;
    if (!$isAuthenticated) {
        $check = recoveryStubValidateAuthFile($authHash);
        $reason = $check['reason'] ?? 'unknown';
        recoveryStubLogAction('auth_status_pending', ['reason' => $reason]);
    } else {
        recoveryStubLogAction('auth_status_ok');
    }
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode([
        'ok' => $isAuthenticated,
        'reason' => $isAuthenticated ? null : $reason,
        'message' => $isAuthenticated ? null : recoveryStubAuthFailureMessage((string) $reason),
    ]);
    exit;
}

if ($action === 'cleanup') {
    recoveryStubLogAction('cleanup');
    recoveryStubCleanupAuxiliary();
    \register_shutdown_function(static function (): void {
        @\unlink(__DIR__ . '/plugin-recovery-tool.php');
    });
    \header('Location: ' . recoveryStubWcfRoot() . 'acp/');
    exit;
}

if ($action === 'install-package' && $isAuthenticated) {
    recoveryStubLogAction('install_package', ['version' => RECOVERY_PACKAGE_VERSION]);
    $result = recoveryStubInstallPackage(RECOVERY_PACKAGE_VERSION);
    if ($result['ok']) {
        \header('Location: plugin-recovery-tool.php?t=' . \urlencode($authHash) . '&package_ok=1');
        exit;
    }
    recoveryStubRenderPackageInstallPage($authHash, (string) ($result['error'] ?? ''));
    exit;
}

if (!$isAuthenticated) {
    if (recoveryStubLoadAuthState(recoveryStubPendingAuthPath($authHash)) === null
        && recoveryStubLoadAuthState(recoveryStubBoundAuthPath($authHash)) === null) {
        recoveryStubCreatePendingSession($authHash);
    }
    $authHint = null;
    if (\is_file(recoveryStubWcfRoot() . RECOVERY_AUTH_FILENAME)) {
        $check = recoveryStubValidateAuthFile($authHash);
        if (!$check['ok'] && isset($check['reason'])) {
            $authHint = recoveryStubAuthFailureMessage((string) $check['reason']);
        }
    }
    recoveryStubLogAction('auth_wizard', ['hint' => $authHint]);
    recoveryStubRenderAuthWizard($authHash, $authHint);
    exit;
}

if (!recoveryStubPackageReady()) {
    recoveryStubLogAction('package_install_page');
    recoveryStubRenderPackageInstallPage($authHash);
    exit;
}

recoveryStubLogAction('package_bootstrap');
// --- Paket laden ---
\define('RECOVERY_WCF_ROOT', recoveryStubWcfRoot());
\define('RECOVERY_PACKAGE_DIR', recoveryStubPackageDir());
$recoveryAuthHash = $authHash;
$recoveryIsAuthenticated = true;
require recoveryStubPackageDir() . 'bootstrap.php';
