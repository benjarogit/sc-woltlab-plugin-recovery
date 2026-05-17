<?php
/** Recovery mode: package_file_repair — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_PACKAGE_FILE_REPAIR) {
    $fileRepairUrl = recoveryBuildModeUrl(RECOVERY_MODE_PACKAGE_FILE_REPAIR, $authHash);
    $wcfDir = \rtrim(WCF_DIR, '/\\') . \DIRECTORY_SEPARATOR;
    $liveMissing = recoveryFindMissingBootstrapClasses($wcfDir);
?>
    <h1>Plugin-Dateien reparieren</h1>
    <p class="subtitle">Fehlende PHP-Klassen (Bootstrap-Registrierung) aus hochgeladenem Paket wiederherstellen</p>

<?php
    if (recoveryWasPostTruncated()) {
        recoveryRenderPostTruncatedWarning();
    }

    if (isset($_POST['confirm_file_repair'])) {
        $repairLog = [];
        $extractDir = recoveryResolveTrustedExtractDir($authHash);
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

