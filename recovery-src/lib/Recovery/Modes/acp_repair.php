<?php
/** Recovery mode: acp_repair — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_ACP_REPAIR) {
?>
    <h1>ACP Repair</h1>
    <p class="subtitle">Repariert defekte ACP-Menüeinträge eines Plugins</p>

<?php
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

