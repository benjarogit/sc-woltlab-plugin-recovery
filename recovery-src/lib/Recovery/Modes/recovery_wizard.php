<?php
/** Recovery mode: recovery_wizard — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_RECOVERY_WIZARD) {
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
    <p class="subtitle">Geführte Reparatur: Paket festlegen → Diagnose → Schritte wählen → ausführen. Sie behalten die Kontrolle.</p>

    <div class="alert alert-info" style="margin-bottom:20px">
        <strong>Ziel:</strong> ACP wieder erreichbar machen und defektes Plugin anschließend sauber entfernen.
        <a href="<?= \htmlspecialchars(recoveryHomeUrl($authHash)) ?>" style="color:#6EC2FF;margin-left:8px">← Andere Situation wählen</a>
    </div>

<?php
    $wizardEmergencyFixed = recoverySessionGetEmergencyFixed($authHash);
    if ($wizardEmergencyFixed !== null) {
        $suggestedPkg = '';
        foreach (recoveryExtractMissingClassesFromLog($wcfDir) as $cn) {
            if (\preg_match('/^([a-z0-9]+)\\\\/', (string) $cn, $m)) {
                $suggestedPkg = 'de.sunnyc.wsc.' . $m[1];
                break;
            }
        }
        $removeUrl = recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash, $suggestedPkg !== '' ? ['package_identifier' => $suggestedPkg] : []);
        ?>
    <div class="alert alert-warning" style="margin-bottom:20px">
        <strong>ACP-Notfall wurde bereits ausgeführt.</strong> Dateien nicht wiederherstellen — Plugin vollständig entfernen.
        <a href="<?= \htmlspecialchars($removeUrl) ?>" class="button" style="margin-left:12px;margin-top:8px;display:inline-block">
            <i class="fa-solid fa-trash-can"></i> Plugin entfernen
        </a>
    </div>
        <?php
    }

    if ($phase === 'run' && isset($_POST['wizard_execute'])) {
        if (recoveryHasUploadedPackageFile()) {
            $upload = recoveryHandlePackageUpload($_FILES['package_file']);
            if ($upload['ok'] && !empty($upload['extractDir'])) {
                recoveryStorePackageContext($authHash, (string) $upload['packageIdentifier'], $upload['extractDir']);
            }
        }
        $wizardState = recoveryWizardLoadState($authHash);
        $scopeForRun = isset($wizardState['scopeApplication']) ? (string) $wizardState['scopeApplication'] : '';
        $plan = [
            'orphans' => !empty($_POST['do_orphans']),
            'files' => !empty($_POST['do_files']) && $wizardEmergencyFixed === null,
            'neutralizeBootstrap' => !empty($_POST['do_neutralize_bootstrap']),
            'dbEventListeners' => !empty($_POST['do_db_event_listeners']),
            'cache' => !empty($_POST['do_cache']),
            'extractDir' => recoveryResolveWizardExtractDir($authHash),
            'scopeApplication' => $scopeForRun !== '' ? $scopeForRun : null,
            'dryRun' => !empty($_POST['wizard_dry_run']),
            'classes' => isset($_POST['repair_classes']) && \is_array($_POST['repair_classes'])
                ? \array_values(\array_filter(\array_map('strval', $_POST['repair_classes'])))
                : [],
        ];
        $execLog = [];
        $result = recoveryWizardExecutePlan($wcfDir, $db, WCF_N, $plan, $execLog);
        recoveryWizardSaveState($authHash, ['lastRun' => $result, 'lastPlan' => $plan]);
        $runInterp = recoveryBuildWizardRunInterpretation($result, $plan);
?>
    <div class="alert <?= !empty($result['dryRun']) ? 'alert-warning' : 'alert-success' ?>">
        <strong><?= !empty($result['dryRun']) ? 'Dry-Run abgeschlossen (keine Änderungen).' : 'Ausführung abgeschlossen.' ?></strong><br>
        Kopierte Dateien: <?= \count($result['copiedFiles'] ?? []) ?><br>
        Bootstrap angepasst (fehlende Listener auskommentiert): <?= \count($result['bootstrapNeutralized'] ?? []) ?> Datei(en)<br>
        DB Event-Listener entfernt: <?= (int) ($result['dbEventListenersDeleted'] ?? 0) ?><br>
        Cache-Dateien gelöscht: <?= (int) ($result['cacheDeleted'] ?? 0) ?>
    </div>
    <section class="recovery-rec-panel recovery-rec-panel--ok" style="margin-top:16px">
        <h2><i class="fa-solid fa-circle-check"></i> Was bedeutet das?</h2>
        <ul class="recovery-next-list">
        <?php foreach ($runInterp as $line): ?>
            <li><?= \htmlspecialchars($line) ?></li>
        <?php endforeach; ?>
        </ul>
        <p style="margin:14px 0 0;font-size:14px;color:#d0d0d0">
            <strong>Nächster Schritt:</strong> ACP testen. Wenn die Seite lädt, Plugin über
            <a href="<?= \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash)) ?>" style="color:#6EC2FF">Plugin Uninstall</a>
            vollständig entfernen.
        </p>
    </section>
    <p style="margin:12px 0 6px;font-weight:600;color:#fff">Technisches Protokoll</p>
    <button type="button" class="recovery-copy-btn" data-recovery-copy="wizard-exec-log" style="margin-bottom:8px">
        <i class="fa-solid fa-copy"></i> Protokoll kopieren
    </button>
    <pre class="recoveryLog" id="wizard-exec-log" style="max-height:320px;overflow:auto;margin-top:4px"><?php
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
        $lastRun = $state['lastRun'] ?? [];
        $lastPlan = $state['lastPlan'] ?? [];
?>
    <div class="alert alert-info">
        <strong>Wizard abgeschlossen.</strong> Prüfen Sie den ACP. Bei Erfolg das defekte Plugin deinstallieren und das Recovery Tool entfernen.
    </div>
    <?php if ($lastRun !== []): ?>
    <section class="recovery-info-panel">
        <h2>Letzte Ausführung — Kurzüberblick</h2>
        <table class="table" style="width:100%">
            <tr><th>Kopierte Dateien</th><td><?= \count($lastRun['copiedFiles'] ?? []) ?></td></tr>
            <tr><th>Bootstrap-Dateien angepasst</th><td><?= \count($lastRun['bootstrapNeutralized'] ?? []) ?></td></tr>
            <tr><th>DB Event-Listener entfernt</th><td><?= (int) ($lastRun['dbEventListenersDeleted'] ?? 0) ?></td></tr>
            <tr><th>Cache-Dateien gelöscht</th><td><?= (int) ($lastRun['cacheDeleted'] ?? 0) ?></td></tr>
        </table>
    </section>
    <?php endif; ?>
    <section class="recovery-rec-panel recovery-rec-panel--ok">
        <h2><i class="fa-solid fa-list-check"></i> Checkliste</h2>
        <ol class="recovery-next-list">
            <li><a href="<?= \htmlspecialchars($recoveryBaseUrl . 'acp/') ?>" style="color:#6EC2FF">ACP öffnen</a> — lädt das Dashboard ohne Fehler?</li>
            <li>Ja → <a href="<?= \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash)) ?>" style="color:#6EC2FF">Plugin Uninstall</a> für vollständige Entfernung</li>
            <li>Nein → Wizard <a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=package') ?>" style="color:#6EC2FF">von vorn</a> oder Experten-Modi auf der <a href="<?= \htmlspecialchars(recoveryHomeUrl($authHash)) ?>" style="color:#6EC2FF">Startseite</a></li>
            <li>Fertig → Recovery Tool vom Server löschen (Sicherheit)</li>
        </ol>
    </section>
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
        $diag = recoveryBuildSystemDiagnosis($wcfDir, $db, WCF_N, $scopeApp);
        recoveryWizardSaveState($authHash, \array_merge($state, ['diagnosis' => $diag]));
        $suggest = \array_merge([
            'orphans' => false,
            'files' => false,
            'neutralizeBootstrap' => false,
            'dbEventListeners' => false,
            'cache' => true,
        ], $diag['suggestedActions'] ?? []);
        $missing = $diag['missingBootstrapClasses'] ?? recoveryFindMissingBootstrapClasses($wcfDir);
        if ($scopeApp !== null) {
            $missing = recoveryFilterFqcnByApplicationPrefix($missing, $scopeApp);
        }
        $pkgCtx = recoveryLoadPackageContext($authHash);
        $extractDir = recoveryResolveWizardExtractDir($authHash);
        $sessionPackageId = (string) ($pkgCtx['packageIdentifier'] ?? $state['packageLabel'] ?? '');
        if ($extractDir !== null) {
            recoveryWizardSaveState($authHash, ['extractDir' => $extractDir]);
        }
        $wizardRec = recoveryBuildWizardRecommendations($diag, $sessionPackageId !== '' ? $sessionPackageId : null);
        $acpAlreadyFixed = recoverySessionGetEmergencyFixed($authHash) !== null;
        $recByKey = [];
        foreach ($wizardRec['steps'] as $rs) {
            if (isset($rs['key'])) {
                $recByKey[(string) $rs['key']] = $rs;
            }
        }
?>
    <?php if ($acpAlreadyFixed): ?>
    <div class="alert alert-warning" style="margin-bottom:16px">
        <strong>ACP läuft bereits?</strong> Dann <em>keine</em> fehlenden Plugin-Dateien wiederherstellen (Schritt 2 abwählen).
        Stattdessen <a href="<?= \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash)) ?>" style="color:#6EC2FF">Plugin entfernen</a>.
    </div>
    <?php endif; ?>

    <details class="recovery-info-panel" style="margin-bottom:16px">
        <summary style="cursor:pointer;font-weight:600;color:#fff">Empfehlungen aus der Diagnose</summary>
        <div style="margin-top:12px">
        <?php recoveryRenderWizardRecommendationsPanel($wizardRec); ?>
        </div>
    </details>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($wizardUrl) ?>"
        data-recovery-loading="Recovery-Schritte werden ausgeführt …"
        data-recovery-loading-steps="Reihenfolge: Paketliste → Dateien → Bootstrap → DB-Listener → Cache. Bitte nicht abbrechen.">
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
            <li>Bootstrap: fehlende PSR-14-<code>EventHandler::register</code>-Aufrufe auskommentieren (ACP-Notfall)</li>
            <li>DB: Event-Listener mit fehlender Klasse entfernen (ACP-Dashboard)</li>
            <li>Cache leeren + Option-Konstanten-Fallback</li>
        </ol>

        <p><label><input type="checkbox" name="do_orphans" value="1" <?= !empty($suggest['orphans']) ? 'checked' : '' ?>>
            <strong>1. Paketliste reparieren</strong> (<?= (int) ($diag['orphanApplicationCount'] ?? 0) ?> verwaiste Applications)</label></p>
        <?php if (isset($recByKey['orphans'])): ?>
        <div class="recovery-step-help"><?= $recByKey['orphans']['why'] ?? '' ?></div>
        <?php endif; ?>

        <p><label><input type="checkbox" name="do_files" value="1" <?= !empty($suggest['files']) && !$acpAlreadyFixed ? 'checked' : '' ?> <?= $acpAlreadyFixed ? 'disabled' : '' ?>>
            <strong>2. Plugin-Dateien wiederherstellen</strong> (<?= \count($missing) ?> fehlende Klassen)<?= $acpAlreadyFixed ? ' — nach ACP-Notfall deaktiviert' : '' ?></label></p>
        <?php if (isset($recByKey['files'])): ?>
        <div class="recovery-step-help"><?= $recByKey['files']['why'] ?? '' ?></div>
        <?php endif; ?>

        <?php
            $neutralCand = (int) ($diag['bootstrapNeutralizeCandidates'] ?? recoveryCountNeutralizableBootstrapRegisters($wcfDir));
        ?>
        <p><label><input type="checkbox" name="do_neutralize_bootstrap" value="1" <?= !empty($suggest['neutralizeBootstrap']) ? 'checked' : '' ?>>
            <strong>3. Bootstrap neutralisieren</strong> — betrifft <strong><?= $neutralCand ?></strong> PSR-14-<code>register()</code>-Aufruf(e)
            (nicht ladbar oder laut WoltLab-Log fehlend; zeilenweise auskommentiert; Backup <code>*.recovery-backup-*.php</code>).
            <em>Behebt typisch <code>ClassNotFoundException</code> beim ACP-Dashboard.</em></label></p>
        <?php if (isset($recByKey['neutralizeBootstrap'])): ?>
        <div class="recovery-step-help"><?= $recByKey['neutralizeBootstrap']['why'] ?? '' ?></div>
        <?php endif; ?>

        <?php
            $orphDb = $diag['orphanedDbEventListeners']
                ?? recoveryFindOrphanedDbEventListeners($wcfDir, $db, WCF_N, $scopeApp);
        ?>
        <p><label><input type="checkbox" name="do_db_event_listeners" value="1" <?= !empty($suggest['dbEventListeners']) ? 'checked' : '' ?>>
            <strong>4. DB Event-Listener bereinigen</strong> — <strong><?= \count($orphDb) ?></strong> Eintrag/Einträge in
            <code>wcf<?= (int) WCF_N ?>_event_listener</code> zeigen auf fehlende Klassen
            <em>(laut Log z.&nbsp;B. <code>BoxCollectingShrinkrDashboardListener</code> — das behebt den ACP-Dashboard-Fehler).</em></label></p>
        <?php if (isset($recByKey['dbEventListeners'])): ?>
        <div class="recovery-step-help"><?= $recByKey['dbEventListeners']['why'] ?? '' ?></div>
        <?php endif; ?>

        <?php if ($missing !== []): ?>
        <details style="margin:8px 0 12px 24px">
            <summary style="cursor:pointer;color:#9D9D9D">Klassen für Schritt 2 auswählen (<?= \count($missing) ?>)</summary>
            <ul style="margin:8px 0 0 8px">
            <?php foreach ($missing as $cn): ?>
                <li><label><input type="checkbox" name="repair_classes[]" value="<?= \htmlspecialchars($cn) ?>" checked>
                    <code><?= \htmlspecialchars($cn) ?></code></label></li>
            <?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>

        <?php if ($extractDir): ?>
        <p class="alert alert-success" style="margin:12px 0">
            <strong>Paket-Archiv aus Schritt 1 aktiv</strong><?php if ($sessionPackageId !== ''): ?>
            — <code><?= \htmlspecialchars($sessionPackageId) ?></code><?php endif; ?>
            (Upload muss nicht erneut gewählt werden.)
        </p>
        <?php elseif ($sessionPackageId !== ''): ?>
        <p class="alert alert-info" style="margin:12px 0">
            Paket-ID gespeichert: <code><?= \htmlspecialchars($sessionPackageId) ?></code>.
            Für <strong>Schritt 2 (Dateien)</strong> bitte das Archiv unten nachreichen.
        </p>
        <label for="wizard_package_file">Paket-Archiv (.tar.gz)</label>
        <input type="file" name="package_file" id="wizard_package_file" accept=".tar,.tar.gz,.tgz">
        <?php else: ?>
        <div class="alert alert-warning" style="margin:12px 0">
            Für Schritt 2: <strong>Paket-Archiv erneut hochladen</strong> — Standard-WoltLab-<code>.tar.gz</code>
            mit <code>package.xml</code> und <code>files.tar</code> (egal ob WS-Packager, Simple Plugin Manager oder manuell).
        </div>
        <label for="wizard_package_file">Paket (.tar.gz)</label>
        <input type="file" name="package_file" id="wizard_package_file" accept=".tar,.tar.gz,.tgz">
        <?php endif; ?>

        <p><label><input type="checkbox" name="do_cache" value="1" checked>
            <strong>5. Cache leeren</strong> + options.inc.php-Fallback (empfohlen)</label></p>
        <?php if (isset($recByKey['cache'])): ?>
        <div class="recovery-step-help"><?= $recByKey['cache']['why'] ?? '' ?></div>
        <?php endif; ?>

        <p style="margin:14px 0;padding:12px;background:rgba(0,0,0,.2);border-radius:6px;border:1px solid #444">
            <label style="cursor:pointer">
                <input type="checkbox" name="wizard_dry_run" value="1">
                <strong>Dry-Run:</strong> Zeigt im Protokoll, was passieren würde — ohne Änderungen am Server.
            </label>
        </p>

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
                    recoveryWizardSaveState($authHash, [
                        'extractDir' => (string) $upload['extractDir'],
                        'packageLabel' => $packageLabel,
                    ]);
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
        <strong>Schritt 1 — Paket festlegen</strong><br>
        Damit Diagnose und Dateiwiederherstellung gezielt für Ihr Plugin laufen.
    </div>
    <section class="recovery-info-panel">
        <h2>Was passiert danach?</h2>
        <ol class="recovery-next-list">
            <li><strong>Diagnose</strong> — Bootstrap, Datenbank und Log auf fehlende Klassen prüfen.</li>
            <li><strong>Plan</strong> — empfohlene Schritte mit Erklärung; Sie setzen die Häkchen.</li>
            <li><strong>Ausführung</strong> — nur gewählte Aktionen, mit Fortschrittsanzeige.</li>
        </ol>
        <p style="margin:12px 0 0;font-size:13px;color:#9D9D9D">
            <strong>.tar.gz-Archiv</strong> mit <code>package.xml</code> + <code>files.tar</code> (Standard-WoltLab-Paket, beliebiger Packager).
            <strong>Nur Paket-ID</strong> filtert die Diagnose, reicht aber nicht zum Kopieren von Dateien.
        </p>
    </section>

    <?php if ($wizardUploadError !== null): ?>
    <div class="alert alert-error"><?= \htmlspecialchars($wizardUploadError) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" action="<?= \htmlspecialchars($wizardUrl) ?>" data-recovery-loading="Paket wird hochgeladen und Diagnose vorbereitet …">
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

    <form method="POST" action="<?= \htmlspecialchars($wizardUrl) ?>" style="margin-top:12px" data-recovery-loading="Live-Diagnose läuft (Bootstrap-Scan kann einige Sekunden dauern) …">
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
        if (empty($state['diagnosis'])):
?>
    <div class="alert alert-warning">Noch keine Diagnose. Bitte zuerst Schritt 1 (Paket) ausführen.</div>
    <p><a href="<?= \htmlspecialchars($wizardUrl . '&wizard_phase=package') ?>" class="button">Zu Schritt 1 — Paket</a></p>
<?php
        else:
        $scopeForDiag = (string) ($state['scopeApplication'] ?? '');
        $diag = recoveryBuildSystemDiagnosis(
            $wcfDir,
            $db,
            WCF_N,
            $scopeForDiag !== '' ? $scopeForDiag : null
        );
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
        <tr><th>PSR-14 Bootstrap-Register (fehlende Listener)</th><td><?= (int) ($diag['bootstrapNeutralizeCandidates'] ?? recoveryCountNeutralizableBootstrapRegisters($wcfDir)) ?> betroffen</td></tr>
        <tr><th>DB Event-Listener (fehlende Klasse)</th><td><?= \count($diag['orphanedDbEventListeners'] ?? recoveryFindOrphanedDbEventListeners($wcfDir, $db, WCF_N, $scopeApp !== '' ? $scopeApp : null)) ?> betroffen</td></tr>
    </table>

    <?php
        $diagRec = recoveryBuildWizardRecommendations($diag, $packageLabel !== '' ? $packageLabel : null);
    ?>
    <details class="recovery-info-panel" style="margin-bottom:16px">
        <summary style="cursor:pointer;font-weight:600;color:#fff">Empfehlungen &amp; Hinweise</summary>
        <div style="margin-top:12px">
        <?php recoveryRenderWizardRecommendationsPanel($diagRec); ?>
        </div>
    </details>
    <?php recoveryRenderLogExcerptsPanel($diag['logExcerpts'] ?? [], 'wizard-diag-log'); ?>

    <?php if ($diag['missingBootstrapClasses'] === []): ?>
    <div class="alert alert-success">
        Keine fehlenden Bootstrap-Klassen (im gewählten Umfang) gefunden. Sie können trotzdem fortfahren
        (z.&nbsp;B. Cache leeren oder Paketliste bereinigen).
    </div>
    <?php else: ?>
    <div class="alert alert-error" style="margin-bottom:12px">
        <strong><?= \count($diag['missingBootstrapClasses']) ?> fehlende Klassen</strong> im gewählten Umfang
        (Bootstrap-Registrierung, aber keine <code>.class.php</code> auf dem Server).
    </div>
    <?php recoveryRenderWizardMissingClassesDetails($diag['missingBootstrapClasses']); ?>
    <?php endif; ?>

    <?php
        $diagExtractDir = recoveryResolveWizardExtractDir($authHash);
    ?>
    <form method="POST" action="<?= \htmlspecialchars($wizardUrl) ?>" data-recovery-loading="Plan &amp; Auswahl wird geladen …">
        <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_RECOVERY_WIZARD, $authHash); ?>
        <input type="hidden" name="wizard_phase" value="plan">
        <input type="hidden" name="wizard_to_plan" value="1">
        <?php if ($diagExtractDir): ?>
        <input type="hidden" name="extract_dir" value="<?= \htmlspecialchars($diagExtractDir) ?>">
        <?php endif; ?>
        <button type="submit" class="button"><i class="fa-solid fa-arrow-right"></i> Weiter — Plan &amp; Auswahl</button>
    </form>
<?php
        endif;
    }
}

// ============================================================================
// MODUS 8: SYSTEM-CHECK
// ============================================================================

