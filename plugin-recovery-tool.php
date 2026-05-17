<?php

declare(strict_types=1);

declare(strict_types=1);

define('RECOVERY_STUB_BEER_CSS', 'https://cdn.jsdelivr.net/npm/beercss@4.0.21/dist/cdn/beer.min.css');
define('RECOVERY_STUB_BEER_JS', 'https://cdn.jsdelivr.net/npm/beercss@4.0.21/dist/cdn/beer.min.js');
define('RECOVERY_STUB_BEER_COLORS_JS', 'https://cdn.jsdelivr.net/npm/material-dynamic-colors@1.1.4/dist/cdn/material-dynamic-colors.min.js');
define('RECOVERY_STUB_FA_CSS', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css');

function recoveryStubShellCss(): string
{
    return <<<'RECOVERY_STUB_CSS'
    <style>
        :root, html[data-recovery-theme="dark"] {
            --recovery-bg: #2D2D2D;
            --recovery-text: #c0c0c0;
            --recovery-card: #3D3D3D;
            --recovery-border: #444444;
            --recovery-heading: #fff;
            --recovery-muted: #9D9D9D;
            --recovery-link: #6EC2FF;
            --recovery-input-bg: #2D2D2D;
        }
        html[data-recovery-theme="light"] {
            --recovery-bg: #f0f0f0;
            --recovery-text: #333;
            --recovery-card: #fff;
            --recovery-border: #ddd;
            --recovery-heading: #333;
            --recovery-muted: #666;
            --recovery-link: #369;
            --recovery-input-bg: #fff;
        }
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
            line-height: 1.5;
        }
        .recovery-shell {
            max-width: 1024px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        .recovery-beer-header { margin-bottom: 24px; }
        .recovery-beer-header h1 { margin: 0 0 8px; font-weight: 400; }
        .recovery-beer-header p { margin: 0; opacity: 0.85; }
        .recovery-code-block {
            display: block; overflow-x: auto; padding: 12px 14px; margin: 12px 0;
            font-size: 13px; border-radius: 8px; white-space: pre-wrap; word-break: break-all;
        }
        .margin-bottom-medium { margin-bottom: 20px; }
        .margin-top-large { margin-top: 28px; }
        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 0;
        }
        .recovery-theme-bar {
            display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
            margin-bottom: 16px; font-size: 12px; color: var(--recovery-muted);
        }
        .recovery-theme-btn {
            background: transparent; border: 1px solid var(--recovery-border);
            color: var(--recovery-text); border-radius: 4px; padding: 4px 10px;
            cursor: pointer; font-size: 12px;
        }
        .recovery-theme-btn.is-active { background: rgba(51,102,153,.35); border-color: #369; }
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
        h1 { color: var(--recovery-heading); margin-bottom: 10px; font-size: 32px; font-weight: 300; }
        h2 { color: var(--recovery-heading); margin: 40px 0 10px 0; font-size: 24px; font-weight: 300; }
        .subtitle { color: var(--recovery-muted); margin-bottom: 30px; font-size: 14px; }
        code { color: var(--recovery-heading); font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; word-break: break-word; }
        html[data-recovery-theme="light"] code { color: #369; background: #f0f4ff; padding: 1px 5px; border-radius: 2px; }
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
        label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--recovery-heading); }
        input[type="text"], input[type="password"], textarea, select {
            width: 100%; padding: 10px; border: 1px solid var(--recovery-border); border-radius: 3px;
            font-size: 14px; background: var(--recovery-input-bg); color: var(--recovery-text);
        }
        input[type="file"] {
            width: 100%; padding: 10px; border: 1px dashed var(--recovery-border); border-radius: 3px;
            background: var(--recovery-input-bg); color: var(--recovery-text);
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
        .recovery-loading-msg { display: block; font-size: 15px; color: #e8e8e8; margin-bottom: 6px; }
        .recovery-loading-track {
            height: 6px;
            background: rgba(0, 0, 0, .35);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 14px;
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
        }
        .recovery-loading-fill {
            height: 100%;
            width: 42%;
            background: linear-gradient(90deg, #369, #6EC2FF);
            border-radius: 3px;
            animation: recovery-indeterminate 1.35s ease-in-out infinite;
        }
        @keyframes recovery-indeterminate {
            0% { transform: translateX(-110%); }
            100% { transform: translateX(320%); }
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
        .recovery-breadcrumb {
            font-size: 13px; color: #9D9D9D; margin: 0 0 20px; line-height: 1.6;
        }
        .recovery-breadcrumb a { color: #6EC2FF; text-decoration: none; }
        .recovery-breadcrumb a:hover { text-decoration: underline; }
        .recovery-breadcrumb strong { color: #e8e8e8; }
        .recovery-intake-hero { margin-bottom: 28px; }
        .recovery-intake-hero h1 { margin-bottom: 8px; }
        .recovery-scenario-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 28px;
        }
        @media (min-width: 720px) {
            .recovery-scenario-grid { grid-template-columns: repeat(2, 1fr); }
            .recovery-scenario-card--primary { grid-column: 1 / -1; }
        }
        .recovery-scenario-card {
            display: block;
            padding: 22px 24px;
            background: rgba(0, 0, 0, .2);
            border: 2px solid #444;
            border-radius: 6px;
            text-decoration: none;
            color: inherit;
            transition: border-color .2s, background .2s, transform .15s;
        }
        .recovery-scenario-card:hover {
            border-color: #666;
            background: rgba(0, 0, 0, .3);
            transform: translateY(-1px);
        }
        .recovery-scenario-card--primary {
            border-color: #369;
            background: rgba(51, 102, 153, .15);
        }
        .recovery-scenario-card--primary:hover { border-color: #6EC2FF; }
        .recovery-scenario-icon {
            font-size: 28px; color: #6EC2FF; margin-bottom: 12px; display: block;
        }
        .recovery-scenario-card--primary .recovery-scenario-icon { color: #fff; }
        .recovery-scenario-card h2 {
            margin: 0 0 10px; font-size: 20px; color: #fff; font-weight: 700;
        }
        .recovery-scenario-card p {
            margin: 0 0 14px; font-size: 14px; line-height: 1.55; color: #b8b8b8;
        }
        .recovery-scenario-cta {
            font-size: 13px; font-weight: 700; color: #6EC2FF; text-transform: uppercase;
            letter-spacing: .03em;
        }
        .recovery-scenario-card--primary .recovery-scenario-cta { color: #fff; }
        .recovery-info-panel {
            background: rgba(0, 0, 0, .15);
            border: 1px solid #444;
            border-radius: 6px;
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .recovery-info-panel h2 {
            margin: 0 0 6px; font-size: 16px; color: #fff;
        }
        .recovery-info-panel .recovery-info-hint {
            margin: 0 0 16px; font-size: 13px; color: #9D9D9D; line-height: 1.5;
        }
        .recovery-info-panel--drawer summary {
            cursor: pointer; font-size: 15px; font-weight: 600; color: #fff;
            list-style: none; margin: 0 0 8px;
        }
        .recovery-info-panel--drawer summary::-webkit-details-marker { display: none; }
        .recovery-status-bar {
            margin: 0 0 20px; font-size: 13px; color: #9D9D9D; line-height: 1.5;
        }
        .recovery-status-sep { margin: 0 6px; color: #555; }
        .recovery-status-warn { color: #fc6; }
        .recovery-status-link { color: #6EC2FF; text-decoration: none; }
        .recovery-status-link:hover { text-decoration: underline; }
        .recovery-info-grid { display: grid; gap: 10px; }
        .recovery-copy-row {
            display: grid;
            grid-template-columns: minmax(120px, 28%) 1fr auto;
            gap: 10px 12px;
            align-items: center;
            padding: 10px 12px;
            background: rgba(0, 0, 0, .2);
            border-radius: 4px;
            border: 1px solid #3a3a3a;
        }
        @media (max-width: 640px) {
            .recovery-copy-row { grid-template-columns: 1fr; }
        }
        .recovery-copy-label { font-size: 12px; color: #9D9D9D; font-weight: 600; }
        .recovery-copy-value {
            font-size: 13px; color: #e0e0e0; word-break: break-all;
            margin: 0;
        }
        .recovery-copy-btn {
            background: #444; color: #fff; border: none; border-radius: 4px;
            padding: 8px 12px; font-size: 12px; font-weight: 600; cursor: pointer;
            white-space: nowrap;
        }
        .recovery-copy-btn:hover { background: #555; }
        .recovery-copy-btn.copied { background: #3a3; }
        .recovery-expert-panel {
            margin-top: 8px; border: 1px solid #444; border-radius: 6px;
            background: rgba(0, 0, 0, .08);
        }
        .recovery-expert-panel > summary {
            cursor: pointer; padding: 16px 20px; font-weight: 700; color: #c0c0c0;
            list-style: none; user-select: none;
        }
        .recovery-expert-panel > summary::-webkit-details-marker { display: none; }
        .recovery-expert-panel > summary::before {
            content: '▸ '; color: #6EC2FF;
        }
        .recovery-expert-panel[open] > summary::before { content: '▾ '; }
        .recovery-expert-panel[open] > summary { color: #fff; border-bottom: 1px solid #444; }
        .recovery-expert-body { padding: 20px; }
        .recovery-expert-body .mode-grid { margin-bottom: 0; }
        .recovery-loading-steps {
            font-size: 13px; color: #9D9D9D; margin-top: 10px; max-width: 520px;
            margin-left: auto; margin-right: auto; text-align: left;
        }
        .recovery-loading-pct {
            font-size: 12px; color: #6EC2FF; margin-top: 8px; font-variant-numeric: tabular-nums;
        }
        .recovery-rec-panel {
            border-radius: 6px; padding: 18px 20px; margin-bottom: 20px;
            border: 1px solid #444; background: rgba(0, 0, 0, .18);
        }
        .recovery-rec-panel--critical { border-color: #c33; background: rgba(204, 51, 51, .12); }
        .recovery-rec-panel--warning { border-color: #c93; background: rgba(204, 153, 51, .1); }
        .recovery-rec-panel--ok { border-color: #3a3; background: rgba(51, 153, 51, .1); }
        .recovery-rec-panel h2 { margin: 0 0 10px; font-size: 17px; color: #fff; }
        .recovery-rec-panel .recovery-rec-summary {
            margin: 0 0 16px; font-size: 14px; line-height: 1.6; color: #d0d0d0;
        }
        .recovery-rec-steps { list-style: none; margin: 0; padding: 0; }
        .recovery-rec-step {
            padding: 12px 14px; margin-bottom: 10px; border-radius: 4px;
            background: rgba(0, 0, 0, .22); border-left: 4px solid #555;
        }
        .recovery-rec-step--required { border-left-color: #fc6; }
        .recovery-rec-step--optional { border-left-color: #369; }
        .recovery-rec-step strong { color: #fff; display: block; margin-bottom: 4px; }
        .recovery-rec-step p { margin: 0; font-size: 13px; line-height: 1.55; color: #b0b0b0; }
        .recovery-rec-badge {
            display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; padding: 2px 8px; border-radius: 3px; margin-right: 8px;
        }
        .recovery-rec-badge--required { background: #c93; color: #1a1a1a; }
        .recovery-rec-badge--recommended { background: #369; color: #fff; }
        .recovery-rec-badge--optional { background: #555; color: #eee; }
        .recovery-next-list { margin: 12px 0 0 20px; color: #c0c0c0; line-height: 1.6; }
        .recovery-step-help {
            margin: 6px 0 0 28px; padding: 10px 12px; font-size: 13px; line-height: 1.5;
            color: #9D9D9D; background: rgba(0,0,0,.15); border-radius: 4px; border-left: 3px solid #369;
        }

        /* Font Awesome Icon-Abstände */
        .fa-solid, .fas { margin-right: 6px; }
        .alert .fa-solid, p.info .fa-solid, p.error .fa-solid, p.success .fa-solid, p.warning .fa-solid { flex-shrink: 0; }
        .mode-button .fa-solid, .recoveryModeCard .fa-solid { display: block; font-size: 28px; margin: 0 auto 10px; }
        button .fa-solid, .button .fa-solid { margin-right: 6px; }

        /* WoltLab Snackbar + Dialog (ACP-ähnlich, standalone) */
        :root {
            --wcfContentBackground: var(--recovery-card);
            --wcfContentBorderInner: var(--recovery-border);
            --wcfContentText: var(--recovery-text);
            --wcfBoxShadow: 0 2px 8px rgba(0, 0, 0, 0.35);
            --wcfStatusSuccessBackground: rgba(51, 153, 51, 0.2);
            --wcfStatusSuccessBorder: #3a3;
            --wcfStatusSuccessText: #e8ffe8;
            --wcfStatusInfoBackground: rgba(51, 102, 153, 0.25);
            --wcfStatusInfoBorder: #369;
            --wcfStatusInfoText: #e8f4ff;
            --wcfBorderRadiusContainer: 4px;
        }
        .snackbarContainer {
            align-items: start;
            bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            left: 20px;
            position: fixed;
            z-index: 10050;
            pointer-events: none;
        }
        .snackbarContainer .snackbar { pointer-events: auto; }
        @keyframes recoverySnackbarIn {
            0% { opacity: 0; transform: translateX(-100%); }
            50% { opacity: 1; transform: translateX(-50%); }
            100% { opacity: 1; transform: translateX(0); }
        }
        @keyframes recoverySnackbarOut {
            0% { opacity: 1; transform: translateX(0); }
            100% { opacity: 0; transform: translateX(-100%); }
        }
        .snackbar {
            animation: recoverySnackbarIn 0.12s ease-in-out;
            background-color: var(--wcfContentBackground);
            border: 1px solid var(--wcfContentBorderInner);
            border-radius: 4px;
            box-shadow: var(--wcfBoxShadow);
            color: var(--wcfContentText);
            display: flex;
            min-width: 220px;
            overflow: hidden;
            padding: 0 5px;
            user-select: none;
        }
        .snackbar--closing { animation: recoverySnackbarOut 0.24s ease-in-out forwards; }
        .snackbar--success {
            background-color: var(--wcfStatusSuccessBackground);
            border-color: var(--wcfStatusSuccessBorder);
            color: var(--wcfStatusSuccessText);
        }
        .snackbar--progress {
            background-color: var(--wcfStatusInfoBackground);
            border-color: var(--wcfStatusInfoBorder);
            color: var(--wcfStatusInfoText);
        }
        .snackbar__icon {
            align-items: center;
            display: flex;
            justify-content: center;
            width: 36px;
        }
        .snackbar__message { flex: 1 0 auto; padding: 10px 5px 10px 0; }
        .recovery-wfl-dialog.dialog {
            background-color: var(--recovery-card);
            border: 1px solid var(--recovery-border);
            color: var(--recovery-text);
            max-width: min(500px, 92vw);
            min-width: 0;
            padding: 0;
        }
        .recovery-wfl-dialog .dialog__document { padding: 20px; }
        .recovery-wfl-dialog .dialog__title {
            color: var(--recovery-heading);
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        .recovery-wfl-dialog .dialog__content {
            line-height: 1.55;
            margin-top: 12px;
        }
        .recovery-wfl-dialog .dialog__control {
            column-gap: 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .recovery-wfl-dialog .button--primary { background: #369; }
        .recovery-wfl-dialog::backdrop { background: rgba(0, 0, 0, 0.55); }

RECOVERY_STUB_CSS;
}


function recoveryStubRenderPageStart(string $documentTitle, string $subtitle = ''): void
{
    \header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de" data-recovery-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= \htmlspecialchars($documentTitle) ?></title>
    <link rel="stylesheet" href="<?= \htmlspecialchars(RECOVERY_STUB_BEER_CSS) ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&amp;display=swap">
    <script>
    (function () {
        var k = 'recoveryTheme', t = localStorage.getItem(k) || 'dark';
        document.documentElement.setAttribute('data-recovery-theme', t);
        document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
    })();
    </script>
    <link rel="stylesheet" href="<?= \htmlspecialchars(RECOVERY_STUB_FA_CSS) ?>" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style><?= recoveryStubShellCss() ?></style>
</head>
<body>
<main class="responsive recovery-shell">
<div class="recovery-theme-bar row middle-align small-space" role="group" aria-label="Darstellung">
    <span class="small">Theme</span>
    <button type="button" class="chip tiny" data-recovery-set-theme="light">Hell</button>
    <button type="button" class="chip tiny" data-recovery-set-theme="dark">Dunkel</button>
    <button type="button" class="chip tiny" data-recovery-set-theme="system">System</button>
</div>
<div class="container">
<script>
(function () {
    var key = 'recoveryTheme';
    function apply(theme) {
        var resolved = theme === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : (theme === 'light' ? 'light' : 'dark');
        document.documentElement.setAttribute('data-recovery-theme', theme);
        document.documentElement.setAttribute('data-theme', resolved === 'light' ? 'light' : 'dark');
        document.querySelectorAll('[data-recovery-set-theme]').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-recovery-set-theme') === theme);
        });
    }
    var stored = localStorage.getItem(key) || 'dark';
    apply(stored);
    document.querySelectorAll('[data-recovery-set-theme]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = btn.getAttribute('data-recovery-set-theme') || 'dark';
            localStorage.setItem(key, t);
            apply(t);
        });
    });
    if (stored === 'system') {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (localStorage.getItem(key) === 'system') { apply('system'); }
        });
    }
})();
</script>
    <h1><?= \htmlspecialchars($documentTitle) ?></h1>
    <?php if ($subtitle !== ''): ?>
    <p class="subtitle"><?= \htmlspecialchars($subtitle) ?></p>
    <?php endif; ?>
    <?php
}

function recoveryStubRenderPageEnd(): void
{
    ?>
</div>
<footer class="center-align small-margin">
    <a href="https://github.com/benjarogit/sc-woltlab-plugin-recovery" target="_blank" rel="noopener"><i class="fa-solid fa-screwdriver-wrench"></i> Plugin Recovery Tool</a>
    &copy; <?= \date('Y') ?> Sunny C.
    | <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">WoltLab Recovery</a>
    | <a href="https://www.beercss.com/" target="_blank" rel="noopener">Beer CSS</a>
</footer>
</main>
<script type="module" src="<?= \htmlspecialchars(RECOVERY_STUB_BEER_JS) ?>"></script>
<script type="module" src="<?= \htmlspecialchars(RECOVERY_STUB_BEER_COLORS_JS) ?>"></script>
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
        <div class="alert alert-info">
            <strong><i class="fa-solid fa-file-arrow-down"></i> Schritt 1: Auth-Datei herunterladen</strong><br>
            Laden Sie die Authentifizierungsdatei herunter. Sie enthält ein einmaliges Token
            und wird benötigt, um Ihren Zugriff auf dieses Tool zu verifizieren.
        </div>
        <a href="?action=download-auth-file&amp;t=<?= \htmlspecialchars($authHash) ?>" class="button" id="downloadBtn">
            <i class="fa-solid fa-file-arrow-down"></i> <?= \htmlspecialchars($authFile) ?> herunterladen
        </a>
    </div>

    <div class="wizardPanel" id="wp2">
        <div class="alert alert-info">
            <strong><i class="fa-solid fa-file-arrow-up"></i> Schritt 2: Datei hochladen</strong><br>
            Laden Sie die heruntergeladene Datei <code><?= \htmlspecialchars($authFile) ?></code> in dasselbe
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
        <a href="?t=<?= \htmlspecialchars($authHash) ?>&amp;auth_ok=1" class="button btn-success" style="font-size:16px;padding:16px 32px;">
            <i class="fa-solid fa-rocket"></i> Recovery Tool starten
        </a>
    </div>

    <div class="alert alert-error" style="margin-top: 30px;">
        <i class="fa-solid fa-shield-halved"></i> <strong>Sicherheitshinweis:</strong><br>
        Löschen Sie beide Dateien (<code>plugin-recovery-tool.php</code> und <code><?= \htmlspecialchars($authFile) ?></code>)
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
    recoveryStubRenderPageEnd();
}

function recoveryStubRenderPackageInstallPage(string $authHash, string $bodyHtml): void
{
    recoveryStubRenderPageStart('Recovery-Paket installieren', 'Paket wird für die volle Recovery-Oberfläche benötigt');
    echo $bodyHtml;
    recoveryStubRenderPageEnd();
}

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
