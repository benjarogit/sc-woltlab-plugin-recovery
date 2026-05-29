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
 * @return array{stylesheets: list<string>, WCFSetup.css: string, woltlabSuite.png: string, fontAwesomeCss: string, fontAwesomeLocal: bool, usesCompiledAcpStyle: bool, webComponentsJs: string}
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
        'webComponentsJs' => '',
    ];
    if (\is_readable($root . 'acp/images/woltlabSuite.png')) {
        $assets['woltlabSuite.png'] = 'acp/images/woltlabSuite.png';
    }
    $wcPath = 'js/WoltLabSuite/WebComponent.min.js';
    if (\is_readable($root . $wcPath)) {
        $assets['webComponentsJs'] = $wcPath;
    }
    if (\is_readable($root . 'icon/font-awesome/v7/css/all.min.css')) {
        $assets['fontAwesomeCss'] = 'icon/font-awesome/v7/css/all.min.css';
        $assets['fontAwesomeLocal'] = true;
    }

    return $assets;
}

function recoveryStubFaIcon(int $size, string $name, bool $solid = true): string
{
    $size = \in_array($size, [16, 24, 32, 48, 64, 96, 128, 144], true) ? $size : 16;
    $name = \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8');
    if ($solid) {
        return \sprintf('<fa-icon size="%d" name="%s" solid></fa-icon>', $size, $name);
    }

    return \sprintf('<fa-icon size="%d" name="%s"></fa-icon>', $size, $name);
}

function recoveryStubLoadingIndicator(int $size = 24): string
{
    if (!\in_array($size, [24, 48, 96], true)) {
        $size = 24;
    }

    return '<woltlab-core-loading-indicator size="' . $size . '" hide-text></woltlab-core-loading-indicator>';
}

function recoveryStubRenderWoltLabHeadGlobals(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $timeNow = \time();
    ?>
    <script data-eager="true">
    window.TIME_NOW = window.TIME_NOW || <?= (int) $timeNow ?>;
    window.LAST_UPDATE_TIME = window.LAST_UPDATE_TIME || <?= (int) $timeNow ?>;
    window.COMPILER_TARGET_DEFAULT = true;
    </script>
    <?php
}

function recoveryStubRenderFaIconStyles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $root = recoveryStubWcfRoot();
    $solidFont = '';
    $regularFont = '';
    foreach ([
        'acp/style/font/fa-solid-900.woff2',
        'icon/font-awesome/v7/webfonts/fa-solid-900.woff2',
    ] as $rel) {
        if (\is_readable($root . $rel)) {
            $solidFont = recoveryStubAssetHref($rel);
            break;
        }
    }
    foreach ([
        'acp/style/font/fa-regular-400.woff2',
        'icon/font-awesome/v7/webfonts/fa-regular-400.woff2',
    ] as $rel) {
        if (\is_readable($root . $rel)) {
            $regularFont = recoveryStubAssetHref($rel);
            break;
        }
    }
    ?>
    <style>
    <?php if ($regularFont !== ''): ?>
    @font-face {
        font-family: "Font Awesome 7 Free";
        font-style: normal;
        font-weight: 400;
        font-display: block;
        src: url("<?= \htmlspecialchars($regularFont) ?>") format("woff2");
    }
    <?php endif; ?>
    <?php if ($solidFont !== ''): ?>
    @font-face {
        font-family: "Font Awesome 7 Free";
        font-style: normal;
        font-weight: 900;
        font-display: block;
        src: url("<?= \htmlspecialchars($solidFont) ?>") format("woff2");
    }
    <?php endif; ?>
    fa-brand, fa-icon {
        align-items: center;
        display: inline-flex;
        height: var(--icon-size);
        justify-content: center;
        pointer-events: none;
        width: calc(var(--icon-size) * 1.25);
    }
    fa-icon[size="16"] { --font-size: 14px; --icon-size: 16px; }
    fa-icon[size="24"] { --font-size: 18px; --icon-size: 24px; }
    fa-icon[size="32"] { --font-size: 28px; --icon-size: 32px; }
    fa-icon {
        font-family: var(--fa-font-family, "Font Awesome 7 Free") !important;
        font-size: var(--font-size) !important;
        font-weight: var(--fa-font-weight, 400) !important;
        line-height: 1 !important;
        visibility: visible !important;
    }
    fa-icon[solid] { font-weight: 900 !important; }
    #tplRecoveryAuth fa-icon { visibility: visible !important; }
    </style>
    <?php
}

function recoveryStubRenderWebComponentsScript(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $assets = recoveryStubGetSetupAssets();
    if (($assets['webComponentsJs'] ?? '') === '') {
        return;
    }
    $done = true;
    $href = recoveryStubAssetHref($assets['webComponentsJs']);
    $mtime = @\filemtime(recoveryStubWcfRoot() . $assets['webComponentsJs']);
    if ($mtime !== false) {
        $href .= (\str_contains($href, '?') ? '&' : '?') . 'v=' . $mtime;
    }
    recoveryStubRenderWoltLabHeadGlobals();
    ?>
    <script data-eager="true" src="<?= \htmlspecialchars($href) ?>"></script>
    <script data-eager="true">
    (function () {
        function bootIcon(el) {
            if (!el || typeof el.setIcon !== "function") { return; }
            var name = el.getAttribute("name");
            if (!name) { return; }
            try { el.setIcon(name, el.hasAttribute("solid")); } catch (e) {}
        }
        function bootAll() {
            document.querySelectorAll("fa-icon[name]").forEach(bootIcon);
        }
        if (customElements.get("fa-icon")) { bootAll(); }
        else { customElements.whenDefined("fa-icon").then(bootAll); }
        document.addEventListener("DOMContentLoaded", bootAll);
    }());
    </script>
    <?php
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
/* Recovery Tool — Layout & Banner (Stub + eingebettet in plugin-recovery-tool.php) */

/* Volle Breite im Content — kein WCFSetup-Sidebar-Float */
#tplRecoveryAuth .content .info,
#tplRecoveryAuth .content .warning,
#tplRecoveryAuth .content .error,
#tplRecoveryAuth .content .success,
#tplRecoveryAuth .content .recovery-content-banner,
#tplRecoveryAuth .content .recovery-panel,
#tplRecoveryAuth .content .recovery-info-panel,
#tplRecoveryAuth .content .recovery-log-hint,
#tplRecoveryAuth .content .recovery-auth-alert,
#tplRecoveryAuth .content .section,
#tplRecoveryAuth .content .recovery-log-panel {
    clear: both;
    display: block;
    float: none !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.recovery-content-banner {
    margin: 0 0 20px;
    padding: 14px 16px;
    border-radius: 4px;
    border-left: 4px solid var(--wcfStatusInfoBorder, #2196f3);
    background: var(--wcfStatusInfoBackground, rgba(33, 150, 243, 0.08));
}

.recovery-info-panel {
    width: 100%;
    margin: 0 0 20px;
    padding: 18px 20px;
    border: 1px solid var(--wcfContentBorderInner, rgba(0, 0, 0, 0.12));
    border-radius: 6px;
    background: var(--wcfSidebarBackground, rgba(0, 0, 0, 0.04));
    box-sizing: border-box;
}

.recovery-info-panel h2 {
    margin: 0 0 10px;
    font-size: 16px;
    font-weight: 600;
}

.recovery-info-panel .recovery-info-hint {
    margin: 0 0 16px;
    font-size: 13px;
    line-height: 1.5;
    color: var(--wcfContentDimmedText, #888);
}

.recovery-info-panel--drawer summary {
    cursor: pointer;
    font-weight: 600;
    list-style: none;
}

.recovery-info-panel--drawer summary::-webkit-details-marker {
    display: none;
}

/* Kommandozeilen — lesbar im Dark Mode */
.recovery-cmd-block,
pre.recoveryLog,
.recovery-log-pre {
    display: block !important;
    overflow-x: auto;
    margin: 12px 0;
    padding: 12px 14px !important;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
    white-space: pre-wrap;
    word-break: break-word;
    color: #e8ecf0 !important;
    background: #1e2832 !important;
    border: 1px solid #3d4f5f !important;
    border-radius: 4px;
    box-shadow: none !important;
}

html[data-color-scheme="light"] .recovery-cmd-block,
html[data-color-scheme="light"] pre.recoveryLog,
html[data-color-scheme="light"] .recovery-log-pre {
    color: #1a1a1a !important;
    background: #f4f4f4 !important;
    border-color: #ccc !important;
}

/* Auth wizard alerts */
.recovery-auth-alert {
    margin: 0 0 20px;
    padding: 14px 16px;
    border-radius: 4px;
    border-left: 4px solid var(--wcfStatusInfoBorder, #2196f3);
    background: var(--wcfStatusInfoBackground, rgba(33, 150, 243, 0.08));
}
.recovery-auth-alert--error {
    border-left-color: var(--wcfStatusErrorBorder, #f44336);
    background: var(--wcfStatusErrorBackground, rgba(244, 67, 54, 0.08));
}
.recovery-auth-alert--warning {
    border-left-color: var(--wcfStatusWarningBorder, #ff9800);
    background: var(--wcfStatusWarningBackground, rgba(255, 152, 0, 0.1));
}
.recovery-auth-alert--info {
    border-left-color: var(--wcfStatusInfoBorder, #2196f3);
    background: var(--wcfStatusInfoBackground, rgba(33, 150, 243, 0.08));
}
.recovery-auth-alert[hidden] {
    display: none !important;
}
.recovery-auth-alert-title {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 0 0 8px;
}
.recovery-auth-alert-title strong {
    font-weight: 600;
}
.recovery-auth-alert p {
    margin: 0 0 8px;
}
.recovery-auth-alert-steps {
    margin: 8px 0 0 1.25em;
    padding: 0;
}
.recovery-auth-alert-steps li {
    margin: 6px 0;
}
.recovery-auth-alert-steps code {
    font-size: 0.92em;
}

.recovery-auth-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
.recovery-auth-actions .button[data-state="done"],
.recovery-auth-actions .button[data-state="success"] {
    pointer-events: none;
}
.recovery-auth-btn-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.recovery-auth-actions .button .recovery-auth-btn-label {
    display: none;
}
.recovery-auth-actions .button[data-state="idle"] .recovery-auth-btn-label[data-state="idle"],
.recovery-auth-actions .button[data-state="loading"] .recovery-auth-btn-label[data-state="loading"],
.recovery-auth-actions .button[data-state="done"] .recovery-auth-btn-label[data-state="done"],
.recovery-auth-actions .button[data-state="success"] .recovery-auth-btn-label[data-state="success"] {
    display: inline-flex;
}

.recovery-auth-status-line {
    margin: 12px 0 0;
    font-size: 13px;
    color: var(--wcfContentDimmedText, #888);
    min-height: 1.25em;
}
.recovery-auth-status-line:empty {
    display: none;
}

/* Wizard-Fortschritt */
.recovery-wizard-progress {
    margin: 0 0 24px;
}
.recovery-wizard-progress progress {
    display: block;
    width: 100%;
    max-width: 480px;
    margin-bottom: 8px;
}
.recovery-wizard-steps {
    list-style: decimal;
    margin: 0 0 24px 24px;
    padding: 0;
    line-height: 1.6;
}

/* Auth-Wizard: Karten & Sektionen (an ACP-Recovery-Tool angeglichen) */
#tplRecoveryAuth .recovery-auth-step {
    margin-bottom: 24px;
    padding: 18px 20px;
    border: 1px solid var(--wcfContentBorderInner, rgba(0, 0, 0, 0.12));
    border-radius: 6px;
    background: var(--wcfSidebarBackground, rgba(0, 0, 0, 0.04));
    box-sizing: border-box;
}

#tplRecoveryAuth .recovery-auth-step .sectionHeader {
    margin-bottom: 12px;
}

#tplRecoveryAuth .recovery-content-banner {
    margin-bottom: 16px;
}

#tplRecoveryAuth .recovery-auth-actions {
    margin-top: 4px;
}

#tplRecoveryAuth .formSubmit.recovery-auth-actions .button {
    margin: 0;
}

.recovery-panel {
    width: 100%;
    margin: 0 0 20px;
    padding: 0;
    border: 1px solid var(--wcfContentBorderInner, #ddd);
    border-radius: 6px;
    background: var(--wcfSidebarBackground, rgba(0, 0, 0, 0.04));
    box-sizing: border-box;
}

.recovery-panel summary {
    cursor: pointer;
    padding: 14px 18px;
    font-weight: 600;
    list-style: none;
}

.recovery-panel summary::-webkit-details-marker {
    display: none;
}

.recovery-panel__body {
    padding: 0 18px 16px;
    line-height: 1.55;
}

.recovery-package-contents-list {
    margin: 8px 0 0;
    padding-left: 22px;
}

.recovery-package-contents-list li {
    margin: 0 0 6px;
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
        : '';
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
    <?php if ($faHref !== ''): ?>
    <link rel="stylesheet" href="<?= \htmlspecialchars($faHref) ?>"<?= $faExtra ?>>
    <?php endif; ?>
    <?php recoveryStubRenderColorSchemeHeadScript(); ?>
    <?php recoveryStubRenderWoltLabHeadGlobals(); ?>
    <?php recoveryStubRenderFaIconStyles(); ?>
    <?php recoveryStubRenderWebComponentsScript(); ?>
    <style>
        #pageHeaderContainer { height: 100px; }
        #pageHeader { padding: 30px 20px; }
        .content { margin: 0 auto; max-width: 1120px; }
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
        .recovery-loading-inline { display: inline-flex; align-items: center; gap: 10px; }
    </style>
    <?php if ($wizardCss !== ''): ?>
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
    $open = $expanded;
    ?>
    <section class="section recovery-log-section">
        <details class="recovery-log-panel"<?= $open ? ' open' : '' ?>>
            <summary>Protokoll &amp; Diagnose</summary>
            <p class="sectionDescription" style="margin-top:12px">
                Verzeichnis: <code title="<?= \htmlspecialchars(recoveryStubLogDir()) ?>"><?= \htmlspecialchars(recoveryStubLogDisplayPath()) ?></code>
            </p>
            <p class="info" style="margin-top:10px"><fa-icon size="16" name="circle-info" solid></fa-icon>
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
    <p class="error"><strong><fa-icon size="16" name="circle-xmark" solid></fa-icon> Ungültige Recovery-Datei</strong><br>
    <?= \htmlspecialchars($message) ?></p>
    <p class="info">Laden Sie <code>plugin-recovery-tool.php</code> ausschließlich vom
        <a href="https://github.com/<?= \htmlspecialchars(RECOVERY_GITHUB_REPO) ?>/releases" rel="noopener noreferrer">offiziellen GitHub-Release</a> herunter.</p>
    <?php
    recoveryStubRenderPageEnd(['logPanelExpanded' => true]);
}

/**
 * @param list<string> $steps
 */
function recoveryStubRenderAuthAlertSteps(array $steps): void
{
    if ($steps === []) {
        return;
    }
    echo '<ol class="recovery-auth-alert-steps">';
    foreach ($steps as $step) {
        echo '<li>' . $step . '</li>';
    }
    echo '</ol>';
}

function recoveryStubRenderAuthAlertBox(?string $reason): void
{
    if ($reason === null || $reason === '') {
        return;
    }
    $d = recoveryStubAuthFailureDetails($reason);
    $class = 'recovery-auth-alert recovery-auth-alert--' . \htmlspecialchars($d['severity']);
    ?>
    <div id="authAlert" class="<?= $class ?>" role="alert">
        <p class="recovery-auth-alert-title">
            <?= recoveryStubFaIcon(16, $d['severity'] === 'info' ? 'circle-info' : 'triangle-exclamation', true) ?>
            <strong><?= \htmlspecialchars($d['title']) ?></strong>
        </p>
        <p><?= \htmlspecialchars($d['message']) ?></p>
        <?php recoveryStubRenderAuthAlertSteps($d['steps']); ?>
    </div>
    <?php
}

function recoveryStubRenderAuthWizard(string $authHash, ?string $errorMessage = null, ?string $errorReason = null): void
{
    $authFile = RECOVERY_AUTH_FILENAME;
    $initialStep = recoveryStubGetAuthWizardStep($authHash);
    $stepLabels = ['Schritt 1 von 3', 'Schritt 2 von 3', 'Schritt 3 von 3'];
    recoveryStubRenderPageStart('Plugin Recovery Tool', '', [
        'value' => $initialStep,
        'max' => 3,
        'label' => $stepLabels[$initialStep - 1],
    ]);
    ?>
    <?php if ($errorReason === null): ?>
    <div id="authAlert" class="recovery-auth-alert" hidden role="alert"></div>
    <?php else: ?>
    <?php recoveryStubRenderAuthAlertBox($errorReason); ?>
    <?php endif; ?>
    <section class="section recovery-auth-step" id="auth-step-1"<?= $initialStep !== 1 ? ' hidden' : '' ?>>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Schritt 1 — Auth-Datei laden</h2>
            <p class="sectionDescription">Nur für <strong>diese</strong> Sitzung gültig. Bei neuem Tool-Link oder Lesezeichen: Auth-Datei neu herunterladen und alte auf dem Server überschreiben.</p>
        </header>
        <p class="info recovery-content-banner"><fa-icon size="16" name="file-arrow-down" solid></fa-icon>
            Dateiname: <code><?= \htmlspecialchars($authFile) ?></code></p>
        <div class="formSubmit recovery-auth-actions">
            <button type="button" class="button buttonPrimary" id="downloadBtn" data-state="<?= $initialStep >= 2 ? 'done' : 'idle' ?>"<?= $initialStep >= 2 ? ' disabled' : '' ?>>
                <span class="recovery-auth-btn-label" data-state="idle">
                    <fa-icon size="16" name="file-arrow-down" solid></fa-icon> <?= \htmlspecialchars($authFile) ?> herunterladen
                </span>
                <span class="recovery-auth-btn-label" data-state="loading">
                    <fa-icon size="16" name="spinner" solid></fa-icon> Wird erstellt …
                </span>
                <span class="recovery-auth-btn-label" data-state="done">
                    <fa-icon size="16" name="circle-check" solid></fa-icon> Heruntergeladen — weiter zu Schritt 2
                </span>
            </button>
        </div>
    </section>

    <section class="section recovery-auth-step" id="auth-step-2"<?= $initialStep !== 2 ? ' hidden' : '' ?>>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Schritt 2 — Hochladen &amp; prüfen</h2>
            <p class="sectionDescription">Datei ins WoltLab-Hauptverzeichnis legen (neben <code>plugin-recovery-tool.php</code>). Vorhandene Datei <strong>überschreiben</strong>.</p>
        </header>
        <p class="info"><fa-icon size="16" name="file-arrow-up" solid></fa-icon> FTP/SFTP: Binärmodus, exakter Dateiname <code><?= \htmlspecialchars($authFile) ?></code></p>
        <div class="formSubmit recovery-auth-actions">
            <button type="button" class="button buttonPrimary" id="validateBtn" data-state="idle">
                <span class="recovery-auth-btn-label" data-state="idle">
                    <fa-icon size="16" name="shield-halved" solid></fa-icon> Auth-Datei prüfen
                </span>
                <span class="recovery-auth-btn-label" data-state="loading">
                    <fa-icon size="16" name="spinner" solid></fa-icon> Prüfe …
                </span>
                <span class="recovery-auth-btn-label" data-state="success">
                    <fa-icon size="16" name="circle-check" solid></fa-icon> Gültig
                </span>
            </button>
            <button type="button" class="button" id="redownloadBtn">
                <fa-icon size="16" name="rotate" solid></fa-icon> Neu herunterladen
            </button>
        </div>
        <p class="recovery-auth-status-line" id="validateStatus" aria-live="polite"></p>
    </section>

    <section class="section recovery-auth-step" id="auth-step-3" hidden>
        <header class="sectionHeader">
            <h2 class="sectionTitle">Recovery Tool starten</h2>
        </header>
        <p class="success"><strong><fa-icon size="16" name="circle-check" solid></fa-icon> Authentifizierung erfolgreich!</strong> Die Auth-Datei wurde erkannt.</p>
        <div class="formSubmit">
            <a href="?t=<?= \htmlspecialchars($authHash) ?>" class="button buttonPrimary">
                <fa-icon size="16" name="rocket" solid></fa-icon> Recovery Tool starten
            </a>
        </div>
    </section>

    <p class="warning"><fa-icon size="16" name="shield-halved" solid></fa-icon> <strong>Sicherheitshinweis:</strong>
    Löschen Sie <code>plugin-recovery-tool.php</code> und <code><?= \htmlspecialchars($authFile) ?></code> nach der Verwendung.</p>

    <script>
    (function () {
        var authToken = <?= \json_encode($authHash) ?>;
        var authFileName = <?= \json_encode($authFile) ?>;
        var pollInterval = null;
        var pollingActive = false;
        var progress = document.getElementById('authWizardProgress');
        var steps = [
            document.getElementById('auth-step-1'),
            document.getElementById('auth-step-2'),
            document.getElementById('auth-step-3')
        ];
        var stepLabels = ['Schritt 1 von 3', 'Schritt 2 von 3', 'Schritt 3 von 3'];
        var downloadBtn = document.getElementById('downloadBtn');
        var validateBtn = document.getElementById('validateBtn');
        var redownloadBtn = document.getElementById('redownloadBtn');
        var authAlert = document.getElementById('authAlert');
        var validateStatus = document.getElementById('validateStatus');

        function setButtonState(btn, state) {
            if (!btn) { return; }
            btn.setAttribute('data-state', state);
        }

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
            if (n !== 2 && pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
                pollingActive = false;
            }
        }

        function severityIcon(severity) {
            return severity === 'info' ? 'circle-info' : 'triangle-exclamation';
        }

        function renderAlertFromDetails(details) {
            if (!authAlert || !details) {
                return;
            }
            var severity = details.severity || 'error';
            authAlert.className = 'recovery-auth-alert recovery-auth-alert--' + severity;
            authAlert.hidden = false;
            var stepsHtml = '';
            if (details.steps && details.steps.length) {
                stepsHtml = '<ol class="recovery-auth-alert-steps">';
                details.steps.forEach(function (step) {
                    stepsHtml += '<li>' + step + '</li>';
                });
                stepsHtml += '</ol>';
            }
            authAlert.innerHTML =
                '<p class="recovery-auth-alert-title">' +
                '<fa-icon size="16" name="' + severityIcon(severity) + '" solid></fa-icon>' +
                '<strong>' + escapeHtml(details.title || 'Hinweis') + '</strong></p>' +
                '<p>' + escapeHtml(details.message || '') + '</p>' +
                stepsHtml;
            bootFaIcons(authAlert);
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function bootFaIcons(root) {
            var scope = root || document;
            scope.querySelectorAll('fa-icon').forEach(function (el) {
                if (typeof el.setIcon === 'function') {
                    el.setIcon(el.getAttribute('name'), el.hasAttribute('solid'));
                }
            });
        }

        function hideAlert() {
            if (!authAlert) { return; }
            authAlert.hidden = true;
            authAlert.innerHTML = '';
        }

        function triggerBlobDownload(filename, base64Content) {
            var binary = atob(base64Content);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            var blob = new Blob([bytes], { type: 'application/octet-stream' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        }

        function downloadAuthFile(options) {
            options = options || {};
            var triggerBtn = options.triggerBtn || downloadBtn;
            if (!triggerBtn) {
                return Promise.reject(new Error('Download nicht verfügbar.'));
            }
            var isRedownload = triggerBtn === redownloadBtn;
            setButtonState(triggerBtn, 'loading');
            triggerBtn.disabled = true;
            hideAlert();
            if (validateStatus && options.clearStatus) {
                validateStatus.textContent = '';
            }
            return fetch('?action=download-auth-file&ajax=1&t=' + encodeURIComponent(authToken))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        throw new Error(data.message || 'Download fehlgeschlagen.');
                    }
                    triggerBlobDownload(data.filename || authFileName, data.content);
                    if (isRedownload) {
                        setButtonState(triggerBtn, 'idle');
                        triggerBtn.disabled = false;
                        if (validateStatus) {
                            validateStatus.textContent = 'Neue Auth-Datei heruntergeladen — bitte auf den Server hochladen und prüfen.';
                        }
                    } else if (downloadBtn) {
                        setButtonState(downloadBtn, 'done');
                        downloadBtn.disabled = true;
                        if (options.goToStep2 !== false) {
                            goToStep(2);
                        }
                    }
                    return data;
                })
                .catch(function (err) {
                    setButtonState(triggerBtn, 'idle');
                    triggerBtn.disabled = false;
                    if (authAlert) {
                        authAlert.className = 'recovery-auth-alert recovery-auth-alert--error';
                        authAlert.hidden = false;
                        authAlert.innerHTML =
                            '<p class="recovery-auth-alert-title">' +
                            '<fa-icon size="16" name="triangle-exclamation" solid></fa-icon>' +
                            '<strong>Download fehlgeschlagen</strong></p>' +
                            '<p>' + escapeHtml(err.message || 'Unbekannter Fehler') + '</p>';
                        bootFaIcons(authAlert);
                    }
                    throw err;
                });
        }

        function handleAuthStatus(data, fromPoll) {
            if (data.ok) {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                pollingActive = false;
                hideAlert();
                setButtonState(validateBtn, 'success');
                if (validateBtn) { validateBtn.disabled = true; }
                if (validateStatus) {
                    validateStatus.textContent = 'Authentifizierung erfolgreich.';
                }
                goToStep(3);
                return;
            }
            if (data.details) {
                renderAlertFromDetails(data.details);
            } else if (authAlert) {
                authAlert.className = 'recovery-auth-alert recovery-auth-alert--error';
                authAlert.hidden = false;
                authAlert.innerHTML =
                    '<p class="recovery-auth-alert-title">' +
                    '<fa-icon size="16" name="triangle-exclamation" solid></fa-icon>' +
                    '<strong>Prüfung fehlgeschlagen</strong></p>' +
                    '<p>' + escapeHtml(data.message || 'Auth-Datei noch nicht gültig.') + '</p>';
                bootFaIcons(authAlert);
            }
            setButtonState(validateBtn, 'idle');
            if (validateBtn) { validateBtn.disabled = false; }
            if (validateStatus) {
                validateStatus.textContent = fromPoll
                    ? (data.message || 'Warte auf gültige Auth-Datei …')
                    : (data.message || 'Bitte Datei hochladen und erneut prüfen.');
            }
        }

        function checkAuthStatus(fromPoll) {
            return fetch('?action=auth-status&t=' + encodeURIComponent(authToken))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    handleAuthStatus(data, fromPoll);
                    return data;
                })
                .catch(function () {
                    if (validateStatus && fromPoll) {
                        validateStatus.textContent = 'Verbindungsfehler — erneuter Versuch …';
                    }
                });
        }

        function startPolling() {
            if (pollingActive) { return; }
            pollingActive = true;
            if (pollInterval) { clearInterval(pollInterval); }
            pollInterval = setInterval(function () {
                checkAuthStatus(true);
            }, 3000);
        }

        goToStep(<?= (int) $initialStep ?>);

        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
                if (downloadBtn.getAttribute('data-state') === 'done') {
                    goToStep(2);
                    return;
                }
                downloadAuthFile({ goToStep2: true, clearStatus: true });
            });
        }

        if (redownloadBtn) {
            redownloadBtn.addEventListener('click', function () {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
                pollingActive = false;
                setButtonState(validateBtn, 'idle');
                if (validateBtn) { validateBtn.disabled = false; }
                downloadAuthFile({ goToStep2: false, clearStatus: true, triggerBtn: redownloadBtn });
            });
        }

        if (validateBtn) {
            validateBtn.addEventListener('click', function () {
                setButtonState(validateBtn, 'loading');
                validateBtn.disabled = true;
                if (validateStatus) {
                    validateStatus.textContent = 'Prüfe Auth-Datei auf dem Server …';
                }
                checkAuthStatus(false).finally(function () {
                    if (!pollingActive && validateBtn.getAttribute('data-state') !== 'success') {
                        startPolling();
                    }
                });
            });
        }
    }());
    </script>
    <?php
    recoveryStubRenderPageEnd(['logPanelExpanded' => false]);
}

/**
 * @return list<string>
 */
function recoveryStubGetPackageContentsList(): array
{
    $manifestPath = \defined('RECOVERY_PACKAGE_DIR')
        ? RECOVERY_PACKAGE_DIR . '/manifest.json'
        : '';
    if ($manifestPath !== '' && \is_file($manifestPath)) {
        $json = \json_decode((string) \file_get_contents($manifestPath), true);
        if (\is_array($json['contents'] ?? null)) {
            $items = [];
            foreach ($json['contents'] as $entry) {
                if (\is_string($entry) && $entry !== '') {
                    $items[] = $entry;
                }
            }
            if ($items !== []) {
                return $items;
            }
        }
    }

    return [
        'app.php — Router, Recovery-Logik und ACP-Oberfläche',
        'bootstrap.php, paths.php, version.php — Bootstrap und Version',
        'lib/Recovery/Modes/ — PHP-Modi (Start, Wizard, Plugin entfernen, System-Check, Datensicherung, …)',
        'lib/Recovery/Ui/ — Layout, RecoveryUi, recovery-acp-extensions.css, recovery-wcfsetup-dark.css',
        'lib/Recovery/Bootstrap/ — Datenbank-Anbindung',
        'lib/Recovery/Log/ — Recovery-Logger',
    ];
}

function recoveryStubRenderPackageInstallPage(string $authHash, ?string $errorMessage = null): void
{
    $version = RECOVERY_PACKAGE_VERSION;
    $ghDownloadUrl = \htmlspecialchars(recoveryStubReleaseDownloadUrl($version));
    $ghReleaseUrl = \htmlspecialchars(recoveryStubReleasePageUrl($version));
    $dirName = RECOVERY_PACKAGE_DIR_NAME;
    $packageContents = recoveryStubGetPackageContentsList();

    recoveryStubRenderPageStart('Recovery-Paket installieren', 'Paket wird für die volle Oberfläche benötigt');
    ?>
    <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
    <p class="error"><strong><fa-icon size="16" name="circle-xmark" solid></fa-icon> Fehler</strong><br><?= $errorMessage ?></p>
    <?php endif; ?>

    <p class="info recovery-content-banner">
        <fa-icon size="16" name="box-archive" solid></fa-icon>
        Das Archiv <strong><code>recovery-<?= \htmlspecialchars($version) ?>.tar.gz</code></strong> enthält das vollständige
        Recovery-Tool: alle PHP-Modi (Wizard, Plugin entfernen, System-Check, Datensicherung, …), die ACP-Oberfläche sowie
        die Stylesheets <code>recovery-acp-extensions.css</code> und <code>recovery-wcfsetup-dark.css</code>.
    </p>

    <details class="recovery-panel recovery-panel--package-contents">
        <summary><span class="recovery-license-panel__summary-title"><?= recoveryStubFaIcon(16, 'folder-open') ?> Was enthält das Paket?</span></summary>
        <div class="recovery-panel__body">
            <p class="sectionDescription">Inhalt von <code>recovery-<?= \htmlspecialchars($version) ?>.tar.gz</code> nach dem Entpacken nach <code><?= \htmlspecialchars($dirName) ?>/</code>:</p>
            <ul class="recovery-package-contents-list">
                <?php foreach ($packageContents as $line): ?>
                <li><?= \htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>

    <section class="section">
        <header class="sectionHeader">
            <h2 class="sectionTitle">Paket automatisch installieren</h2>
            <p class="sectionDescription">Lädt <a href="<?= $ghDownloadUrl ?>"><code>recovery-<?= \htmlspecialchars($version) ?>.tar.gz</code></a> vom <a href="<?= $ghReleaseUrl ?>" rel="noopener noreferrer">GitHub-Release v<?= \htmlspecialchars($version) ?></a> und entpackt es nach <code><?= \htmlspecialchars($dirName) ?>/</code>.</p>
        </header>
        <form method="post" action="plugin-recovery-tool.php" id="installPackageForm">
            <input type="hidden" name="action" value="install-package">
            <input type="hidden" name="t" value="<?= \htmlspecialchars($authHash) ?>">
            <p class="info recovery-loading-inline" id="installPackageStatus" hidden>
                <?= recoveryStubLoadingIndicator(24) ?>
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
    recoveryStubRenderPageEnd(['logPanelExpanded' => false]);
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

/**
 * Pending-Sitzung an aktuelle Stub-Version anpassen (z. B. nach Tool-Update ohne neuen Link).
 */
function recoveryStubSyncPendingStubBuild(string $token): bool
{
    if (!recoveryStubAssertValidToken($token)) {
        return false;
    }

    $path = recoveryStubPendingAuthPath($token);
    $state = recoveryStubLoadAuthState($path);
    if ($state === null) {
        return false;
    }

    $currentBuild = recoveryStubBuildId();
    if (($state['stubBuildId'] ?? '') === $currentBuild) {
        return true;
    }

    $state['stubBuildId'] = $currentBuild;
    if (\defined('RECOVERY_STUB_INTEGRITY_HASH')) {
        $state['stubIntegrity'] = (string) RECOVERY_STUB_INTEGRITY_HASH;
    }
    recoveryStubSaveAuthState($path, $state);

    $boundPath = recoveryStubBoundAuthPath($token);
    $bound = recoveryStubLoadAuthState($boundPath);
    if ($bound !== null) {
        $bound['stubBuildId'] = $currentBuild;
        if (\defined('RECOVERY_STUB_INTEGRITY_HASH')) {
            $bound['stubIntegrity'] = (string) RECOVERY_STUB_INTEGRITY_HASH;
        }
        recoveryStubSaveAuthState($boundPath, $bound);
    }

    recoveryStubLog('info', 'Pending-Sitzung an Stub-Build angepasst', [
        'tokenPrefix' => \substr($token, 0, 8),
        'buildId' => $currentBuild,
    ]);

    return true;
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

    $pendingPath = recoveryStubPendingAuthPath($token);
    $pending = recoveryStubLoadAuthState($pendingPath);
    if ($pending === null) {
        recoveryStubLog('warning', 'Auth-Download ohne Pending-Session', ['tokenPrefix' => \substr($token, 0, 8)]);

        return ['ok' => false, 'error' => 'Keine gültige Recovery-Sitzung. Bitte plugin-recovery-tool.php erneut aufrufen.'];
    }

    recoveryStubSyncPendingStubBuild($token);
    $pending = recoveryStubLoadAuthState($pendingPath);
    if ($pending === null) {
        return ['ok' => false, 'error' => 'Keine gültige Recovery-Sitzung. Bitte plugin-recovery-tool.php erneut aufrufen.'];
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
        recoveryStubSyncPendingStubBuild($urlToken);
        $state = recoveryStubLoadAuthState(recoveryStubPendingAuthPath($urlToken))
            ?? recoveryStubLoadAuthState(recoveryStubBoundAuthPath($urlToken));
        if ($state === null || ($state['stubBuildId'] ?? '') !== recoveryStubBuildId()) {
            return ['ok' => false, 'reason' => 'server_stub_mismatch'];
        }
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

function recoveryStubInvalidateBoundSession(string $urlToken): void
{
    if (!recoveryStubAssertValidToken($urlToken)) {
        return;
    }
    @\unlink(recoveryStubBoundAuthPath($urlToken));
}

function recoveryStubGetAuthWizardStep(string $urlToken): int
{
    if (!recoveryStubAssertValidToken($urlToken)) {
        return 1;
    }

    if (!empty($_GET['wizard']) && (int) $_GET['wizard'] === 2) {
        return 2;
    }

    $pending = recoveryStubLoadAuthState(recoveryStubPendingAuthPath($urlToken));
    if ($pending !== null && ($pending['authIssuedAt'] ?? null) !== null) {
        return 2;
    }

    return 1;
}

function recoveryStubEnsurePhpSession(): void
{
    if (\session_status() === PHP_SESSION_NONE) {
        \session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function recoveryStubBindSessionAuthToken(string $token): void
{
    if (!recoveryStubAssertValidToken($token)) {
        return;
    }
    recoveryStubEnsurePhpSession();
    $_SESSION['recovery_auth_token'] = $token;
    $_SESSION['recovery_auth_bound_at'] = \time();
}

function recoveryStubGetSessionAuthToken(): ?string
{
    recoveryStubEnsurePhpSession();
    $token = $_SESSION['recovery_auth_token'] ?? null;
    if (!\is_string($token) || !recoveryStubAssertValidToken($token)) {
        return null;
    }
    $boundAt = (int) ($_SESSION['recovery_auth_bound_at'] ?? 0);
    if ($boundAt > 0 && \time() - $boundAt > 86400) {
        unset($_SESSION['recovery_auth_token'], $_SESSION['recovery_auth_bound_at']);

        return null;
    }

    return $token;
}

function recoveryStubIsAuthenticated(string $urlToken): bool
{
    static $cache = [];

    if (isset($cache[$urlToken])) {
        return $cache[$urlToken];
    }

    $result = recoveryStubValidateAuthFile($urlToken);
    if (!$result['ok']) {
        if (\is_file(recoveryStubBoundAuthPath($urlToken))) {
            recoveryStubInvalidateBoundSession($urlToken);
        }

        return $cache[$urlToken] = false;
    }

    recoveryStubBindSessionAuthToken($urlToken);

    return $cache[$urlToken] = true;
}

/**
 * @return array{
 *     reason: string,
 *     title: string,
 *     message: string,
 *     steps: list<string>,
 *     severity: string,
 *     suggestRedownload: bool,
 *     suggestNewSession: bool
 * }
 */
function recoveryStubAuthFailureDetails(string $reason): array
{
    $authFile = RECOVERY_AUTH_FILENAME;

    return match ($reason) {
        'token_mismatch' => [
            'reason' => $reason,
            'title' => 'Falsche Auth-Datei für diese Sitzung',
            'message' => 'Die Datei auf dem Server stammt von einem anderen Tool-Aufruf (anderer Link / anderes Browser-Tab).',
            'steps' => [
                'Alte Datei auf dem Server löschen oder überschreiben: <code>' . $authFile . '</code> im WoltLab-Hauptverzeichnis.',
                'Unten „Auth-Datei neu herunterladen“ — nur die Datei von dieser Seite ist gültig.',
                'Neue Datei per FTP/SFTP hochladen (Binärmodus).',
                '„Auth-Datei prüfen“ klicken — die Prüfung läuft automatisch.',
            ],
            'severity' => 'error',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
        'stub_mismatch', 'server_stub_mismatch' => [
            'reason' => $reason,
            'title' => 'Auth-Datei passt nicht zur Tool-Version',
            'message' => 'Die hochgeladene Datei wurde mit einer anderen plugin-recovery-tool.php erzeugt.',
            'steps' => [
                'Aktuelle <code>plugin-recovery-tool.php</code> vom GitHub-Release verwenden.',
                'Auth-Datei neu herunterladen (Button unten) und alte Datei überschreiben.',
                'Erneut „Auth-Datei prüfen“.',
            ],
            'severity' => 'error',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
        'bad_signature' => [
            'reason' => $reason,
            'title' => 'Auth-Datei ungültig oder verändert',
            'message' => 'Die Signatur stimmt nicht — Datei wurde bearbeitet oder stammt von woanders.',
            'steps' => [
                'Datei nicht in einem Editor öffnen oder anpassen.',
                'Neu herunterladen und die alte Datei vollständig ersetzen.',
                '„Auth-Datei prüfen“ erneut ausführen.',
            ],
            'severity' => 'error',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
        'expired' => [
            'reason' => $reason,
            'title' => 'Auth-Datei abgelaufen',
            'message' => 'Aus Sicherheitsgründen sind Auth-Dateien nur kurz gültig.',
            'steps' => [
                'Neue Auth-Datei herunterladen (Button unten).',
                'Alte Datei auf dem Server überschreiben.',
                '„Auth-Datei prüfen“.',
            ],
            'severity' => 'warning',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
        'no_server_session' => [
            'reason' => $reason,
            'title' => 'Recovery-Sitzung abgelaufen',
            'message' => 'Auf dem Server gibt es keine passende Sitzung mehr zu diesem Link.',
            'steps' => [
                'Diese Seite komplett neu laden — Sie erhalten einen neuen Sicherheits-Link.',
                'Auth-Datei neu herunterladen und hochladen.',
                'Lesezeichen mit altem <code>?t=…</code>-Link nicht weiterverwenden.',
            ],
            'severity' => 'warning',
            'suggestRedownload' => true,
            'suggestNewSession' => true,
        ],
        'legacy_or_invalid_format', 'wrong_format_version' => [
            'reason' => $reason,
            'title' => 'Veraltetes Auth-Datei-Format',
            'message' => 'Die Datei ist zu alt oder keine gültige Recovery-Auth-Datei.',
            'steps' => [
                'Nur die Datei aus diesem Tool verwenden (Download-Button).',
                'Alte <code>' . $authFile . '</code> löschen und die neue hochladen.',
                '„Auth-Datei prüfen“.',
            ],
            'severity' => 'error',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
        'missing_file' => [
            'reason' => $reason,
            'title' => 'Auth-Datei fehlt noch',
            'message' => 'Auf dem Server wurde <code>' . $authFile . '</code> noch nicht gefunden.',
            'steps' => [
                'Zuerst Auth-Datei herunterladen (Schritt 1).',
                'Datei ins WoltLab-Hauptverzeichnis legen — neben <code>plugin-recovery-tool.php</code>.',
                '„Auth-Datei prüfen“ — die Prüfung erkennt die Datei automatisch.',
            ],
            'severity' => 'info',
            'suggestRedownload' => false,
            'suggestNewSession' => false,
        ],
        default => [
            'reason' => $reason,
            'title' => 'Authentifizierung fehlgeschlagen',
            'message' => recoveryStubAuthFailureMessageShort($reason),
            'steps' => [
                'Auth-Datei neu herunterladen und hochladen.',
                '„Auth-Datei prüfen“ erneut ausführen.',
            ],
            'severity' => 'error',
            'suggestRedownload' => true,
            'suggestNewSession' => false,
        ],
    };
}

function recoveryStubAuthFailureMessageShort(string $reason): string
{
    return match ($reason) {
        'token_mismatch' => 'Die Auth-Datei gehört nicht zu dieser Sitzung (URL-Token stimmt nicht überein).',
        'stub_mismatch', 'server_stub_mismatch' => 'Die Auth-Datei passt nicht zu dieser plugin-recovery-tool.php-Version.',
        'bad_signature' => 'Die Auth-Datei wurde manipuliert oder stammt von einer anderen Sitzung.',
        'expired' => 'Die Auth-Datei ist abgelaufen.',
        'no_server_session' => 'Keine gültige Recovery-Sitzung auf dem Server.',
        'legacy_or_invalid_format', 'wrong_format_version' => 'Ungültige oder veraltete Auth-Datei.',
        'missing_file' => 'Auth-Datei wurde noch nicht hochgeladen.',
        default => 'Authentifizierung fehlgeschlagen.',
    };
}

function recoveryStubAuthFailureMessage(string $reason): string
{
    return recoveryStubAuthFailureMessageShort($reason);
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
 * @version 2.2.1
 */

define('RECOVERY_STUB_VERSION', '2.7.22');
define('RECOVERY_PACKAGE_VERSION', '2.7.22');
define('RECOVERY_STUB_INTEGRITY_HASH', '4a21f4779b399b3818d59eb554136a719b097d041b3d481e85026c1733cbe06b');
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
recoveryStubEnsurePhpSession();
$authHash = null;
if (!empty($_REQUEST['t']) && recoveryStubAssertValidToken((string) $_REQUEST['t'])) {
    $authHash = (string) $_REQUEST['t'];
} else {
    $sessionToken = recoveryStubGetSessionAuthToken();
    if ($sessionToken !== null && recoveryStubIsAuthenticated($sessionToken)) {
        $authHash = $sessionToken;
    }
}
if ($authHash === null) {
    $authHash = \bin2hex(\random_bytes(20));
    recoveryStubCreatePendingSession($authHash);
    recoveryStubLogAction('session_start', ['tokenPrefix' => \substr($authHash, 0, 8)]);
    \header('Location: plugin-recovery-tool.php?t=' . $authHash);
    exit;
}
$action = (!empty($_REQUEST['action'])) ? (string) $_REQUEST['action'] : '';

if ($action === 'download-auth-file') {
    recoveryStubLogAction('auth_download');
    $ajax = !empty($_REQUEST['ajax']);
    $generated = recoveryStubGenerateAuthFileContent($authHash);
    if (!$generated['ok']) {
        recoveryStubLogError('Auth-Datei konnte nicht erstellt werden', ['error' => $generated['error'] ?? '']);
        if ($ajax) {
            \header('Content-Type: application/json; charset=utf-8');
            echo \json_encode([
                'ok' => false,
                'message' => (string) ($generated['error'] ?? 'Auth-Datei konnte nicht erstellt werden.'),
            ], \JSON_UNESCAPED_UNICODE);
            exit;
        }
        recoveryStubRenderAuthWizard($authHash, (string) ($generated['error'] ?? ''));
        exit;
    }
    $content = $generated['content'];
    if ($ajax) {
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode([
            'ok' => true,
            'filename' => RECOVERY_AUTH_FILENAME,
            'content' => \base64_encode($content),
            'step' => 2,
        ], \JSON_UNESCAPED_UNICODE);
        exit;
    }
    \header('Content-type: application/octet-stream');
    \header('Content-Disposition: attachment; filename="' . RECOVERY_AUTH_FILENAME . '"');
    \header('Content-Length: ' . (string) \strlen($content));
    echo $content;
    exit;
}

$isAuthenticated = recoveryStubIsAuthenticated($authHash);

if ($action === 'auth-status') {
    $reason = null;
    $details = null;
    if (!$isAuthenticated) {
        $check = recoveryStubValidateAuthFile($authHash);
        $reason = (string) ($check['reason'] ?? 'unknown');
        recoveryStubLogAction('auth_status_pending', ['reason' => $reason]);
        $details = recoveryStubAuthFailureDetails($reason);
    } else {
        recoveryStubLogAction('auth_status_ok');
    }
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode([
        'ok' => $isAuthenticated,
        'reason' => $isAuthenticated ? null : $reason,
        'message' => $isAuthenticated ? null : recoveryStubAuthFailureMessage($reason),
        'details' => $details,
    ], \JSON_UNESCAPED_UNICODE);
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
    $authReason = null;
    if (\is_file(recoveryStubWcfRoot() . RECOVERY_AUTH_FILENAME)) {
        $check = recoveryStubValidateAuthFile($authHash);
        if (!$check['ok'] && isset($check['reason'])) {
            $authReason = (string) $check['reason'];
            $authHint = recoveryStubAuthFailureMessage($authReason);
        }
    }
    recoveryStubLogAction('auth_wizard', ['hint' => $authHint, 'reason' => $authReason]);
    recoveryStubRenderAuthWizard($authHash, $authHint, $authReason);
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
