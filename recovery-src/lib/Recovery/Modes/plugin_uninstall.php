<?php
/** Recovery mode: plugin_uninstall — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_PLUGIN_UNINSTALL) {
?>
    <h1>Plugin Uninstall</h1>
    <p class="subtitle">Deinstalliert Plugin komplett – per-Ressource-Auswahl, SQL-Backup &amp; Dry-Run</p>

<?php
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

                    // PIP-Counts aus DB (+ weitere Tabellen mit packageID-Spalte)
                    $pipMap    = recoveryGetPipResourceMap();
                    $pipCounts = $packageID ? recoveryGetPipDbCounts($db, $wcfN, $packageID) : [];
                    if ($packageID) {
                        recoveryMergeDiscoveredPipTables($pipMap, $pipCounts, $db, $wcfN, $packageID);
                    }

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
                    echo '<article class="border round medium-padding margin-bottom-medium" style="border-color:var(--error,#c62828)">';
                    echo '<p style="margin:0"><strong><i class="fa-solid fa-database"></i> Vor dem Entfernen:</strong> ';
                    echo '<a href="' . \htmlspecialchars(recoveryBuildModeUrl(RECOVERY_MODE_BACKUP_GUIDE, $authHash)) . '">Datensicherung</a> ';
                    echo 'anlegen (DB + Dateien) — siehe <a href="https://manual.woltlab.com/de/backup/" target="_blank" rel="noopener">WoltLab-Handbuch</a>.</p>';
                    echo '</article>';

                    echo '<div class="alert alert-warning" style="margin-bottom:20px">';
                    echo '<label style="cursor:pointer"><input type="checkbox" name="dry_run" id="recoveryDryRunToggle" value="1" style="margin-right:6px">';
                    echo '<strong>Dry-Run-Modus:</strong> Zeigt was gelöscht WÜRDE, ohne tatsächliche Änderungen vorzunehmen</label>';
                    echo '<div id="recoveryDryRunQuick" class="recovery-dryrun-quick">';
                    echo '<button type="submit" class="button" style="margin-top:12px"><i class="fa-solid fa-play"></i> Dry-Run jetzt starten</button>';
                    echo '<br><small>Startet direkt mit Dry-Run – ohne nach unten zu scrollen.</small>';
                    echo '</div>';
                    echo '</div>';

                    // ── DB-Einträge nach packageID ────────────────────────────
                    if ($packageID) {
                        $hasSafeRows = false;
                        foreach ($pipCounts as $cnt) {
                            if ($cnt > 0) { $hasSafeRows = true; break; }
                        }

                        echo '<h2 style="margin-bottom:10px">DB-Einträge nach packageID</h2>';
                        echo '<p style="margin-bottom:12px"><small>Nur Einträge mit <code>packageID = ' . $packageID . '</code> werden gelöscht – keine Massenlöschungen. '
                            . 'Klicken Sie auf die <strong>Zahl</strong> in „Einträge“, um die betroffenen Zeilen zu sehen.</small></p>';
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
                                echo '<td style="text-align:right">'
                                    . recoveryRenderPipCountCell($count, $info['table'], $packageID) . '</td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table>';
                        echo '<div id="recoveryPipPreviewModal" hidden>';
                        echo '<div class="recovery-pip-preview-dialog" role="dialog" aria-modal="true">';
                        echo '<h3 id="recoveryPipPreviewTitle">Einträge</h3>';
                        echo '<div id="recoveryPipPreviewBody"></div>';
                        echo '<p style="margin-top:16px"><button type="button" class="button" id="recoveryPipPreviewClose">Schließen</button></p>';
                        echo '</div></div>';
                        echo '<script>
                            (function () {
                                var authToken = ' . \json_encode($authHash) . ';
                                var dryToggle = document.getElementById("recoveryDryRunToggle");
                                var dryQuick = document.getElementById("recoveryDryRunQuick");
                                if (dryToggle && dryQuick) {
                                    dryToggle.addEventListener("change", function () {
                                        dryQuick.style.display = dryToggle.checked ? "block" : "none";
                                    });
                                }
                                var counts = ' . \json_encode($pipCounts) . ';
                                var allChecked = Object.values(counts).some(function (v) { return v > 0; });
                                var chkAllPip = document.getElementById("chkAllPip");
                                if (chkAllPip) {
                                    chkAllPip.checked = allChecked;
                                    chkAllPip.addEventListener("change", function () {
                                        document.querySelectorAll("input[name=\\"pip_select[]\\"]:not(:disabled)").forEach(function (c) {
                                            c.checked = chkAllPip.checked;
                                        });
                                    });
                                }
                                var modal = document.getElementById("recoveryPipPreviewModal");
                                var modalBody = document.getElementById("recoveryPipPreviewBody");
                                var modalTitle = document.getElementById("recoveryPipPreviewTitle");
                                var modalClose = document.getElementById("recoveryPipPreviewClose");
                                function escapeHtml(s) {
                                    var d = document.createElement("div");
                                    d.textContent = s;
                                    return d.innerHTML;
                                }
                                function closeModal() { if (modal) { modal.hidden = true; } }
                                if (modalClose) { modalClose.addEventListener("click", closeModal); }
                                if (modal) {
                                    modal.addEventListener("click", function (e) {
                                        if (e.target === modal) { closeModal(); }
                                    });
                                }
                                document.querySelectorAll(".recovery-pip-count-btn").forEach(function (btn) {
                                    btn.addEventListener("click", function () {
                                        var table = btn.getAttribute("data-table");
                                        var packageId = btn.getAttribute("data-package-id");
                                        if (!table || !packageId) { return; }
                                        modalTitle.textContent = "Lade …";
                                        modalBody.innerHTML = "<p>Bitte warten …</p>";
                                        modal.hidden = false;
                                        var previewUrl = new URL(window.location.href);
                                        previewUrl.search = "";
                                        previewUrl.searchParams.set("action", "pip-preview");
                                        previewUrl.searchParams.set("t", authToken);
                                        previewUrl.searchParams.set("table", table);
                                        previewUrl.searchParams.set("package_id", packageId);
                                        fetch(previewUrl.toString(), { credentials: "same-origin" })
                                            .then(function (r) {
                                                return r.text().then(function (text) {
                                                    if (!text) {
                                                        throw new Error("Leere Server-Antwort (HTTP " + r.status + ")");
                                                    }
                                                    try {
                                                        return JSON.parse(text);
                                                    } catch (parseErr) {
                                                        throw new Error("Keine gültige JSON-Antwort: " + text.substring(0, 200));
                                                    }
                                                });
                                            })
                                            .then(function (data) {
                                                if (!data.ok) {
                                                    modalBody.innerHTML = "<p class=\\"alert alert-error\\">"
                                                        + escapeHtml(data.error || "Fehler") + "</p>";
                                                    return;
                                                }
                                                modalTitle.textContent = data.table + " (" + data.total + " Einträge)";
                                                if (!data.rows || data.rows.length === 0) {
                                                    modalBody.innerHTML = "<p><em>Keine Zeilen gefunden.</em></p>";
                                                    return;
                                                }
                                                var html = "<p><small>Vorschau (max. " + data.rows.length
                                                    + " von " + data.total + "):</small></p>";
                                                html += "<table class=\\"table\\"><thead><tr>";
                                                (data.columns || []).forEach(function (c) {
                                                    html += "<th>" + escapeHtml(c) + "</th>";
                                                });
                                                html += "</tr></thead><tbody>";
                                                data.rows.forEach(function (row) {
                                                    html += "<tr>";
                                                    (data.columns || []).forEach(function (c) {
                                                        var val = row[c];
                                                        if (val === null || val === undefined) { val = "—"; }
                                                        else if (String(val).length > 120) {
                                                            val = String(val).substring(0, 117) + "…";
                                                        }
                                                        html += "<td><code>" + escapeHtml(String(val)) + "</code></td>";
                                                    });
                                                    html += "</tr>";
                                                });
                                                html += "</tbody></table>";
                                                modalBody.innerHTML = html;
                                            })
                                            .catch(function (err) {
                                                modalBody.innerHTML = "<p class=\\"alert alert-error\\">"
                                                    + escapeHtml(String(err)) + "</p>";
                                            });
                                    });
                                });
                            })();
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

                    $fileLogCount = $packageID ? \count(recoveryLoadPackageFileLogPaths($db, $wcfN, $packageID)) : 0;
                    $sqlPreviewStep = $packageID ? recoveryPreviewSqlRollback($db, $wcfN, $packageID) : ['actions' => []];

                    if ($packageID) {
                        echo '<h2 style="margin:24px 0 10px"><i class="fa-solid fa-gears"></i> Erweiterte Schritte</h2>';
                        echo '<div class="alert alert-info" style="margin-bottom:12px">';
                        echo '<label style="cursor:pointer;display:block;margin-bottom:8px">';
                        echo '<input type="checkbox" name="rebuild_bootstrap" value="1" checked> ';
                        echo '<strong>lib/bootstrap.php neu erzeugen</strong> (empfohlen)</label>';
                        if ($fileLogCount > 0) {
                            echo '<label style="cursor:pointer;display:block;margin-bottom:8px">';
                            echo '<input type="checkbox" name="delete_files_log" value="1" checked> ';
                            echo '<strong>Dateien aus file_log löschen</strong> (' . $fileLogCount . ')</label>';
                        }
                        if ($sqlPreviewStep['actions'] !== []) {
                            echo '<label style="cursor:pointer;display:block">';
                            echo '<input type="checkbox" name="sql_rollback" value="1"> ';
                            echo '<strong>SQL-Schema zurücksetzen</strong> (' . \count($sqlPreviewStep['actions']) . ' Aktionen — optional)</label>';
                            echo '<br><small style="margin-top:6px;display:block">Destruktiv — nur mit DB-Backup.</small>';
                        }
                        echo '</div>';
                    }

                    // ── Dateisystem ───────────────────────────────────────────
                    echo '<h2 style="margin:24px 0 10px"><i class="fa-solid fa-folder-open"></i> Dateisystem (Verzeichnis-Fallback)</h2>';
                    if ($fsEval['deletable']) {
                        echo '<div class="alert alert-warning">';
                        echo '<label style="cursor:pointer"><input type="checkbox" name="delete_files_dir" value="1"';
                        if ($fileLogCount === 0) { echo ' checked'; }
                        echo '> ';
                        echo 'Plugin-Verzeichnis <code>' . \htmlspecialchars((string)$fsEval['relativePath']) . '/</code> auf dem Server löschen</label>';
                        echo '<br><small style="margin-top:6px;display:block">Zusätzlich zu file_log oder als Fallback.</small>';
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
                    $deleteFilesLog = !empty($_POST['delete_files_log']) && $_POST['delete_files_log'] === '1';
                    $deleteFilesDir = !empty($_POST['delete_files_dir']) && $_POST['delete_files_dir'] === '1';
                    $deleteFilesLegacy = !empty($_POST['delete_files']) && $_POST['delete_files'] === '1';
                    $deleteFiles   = $deleteFilesLog || $deleteFilesDir || $deleteFilesLegacy;
                    $sqlRollback   = !empty($_POST['sql_rollback']) && $_POST['sql_rollback'] === '1';
                    $rebuildBootstrap = !isset($_POST['rebuild_bootstrap']) || $_POST['rebuild_bootstrap'] === '1';

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
                        echo '<button type="button" class="button" id="recoveryJsSqlDownload" style="margin-left:8px">';
                        echo '<i class="fa-solid fa-download"></i> JS-Download</button>';
                        echo '<script>(function(){var el=document.getElementById("recoveryJsSqlDownload");';
                        echo 'if(!el){return;}el.addEventListener("click",function(){';
                        echo 'var s=atob(' . \json_encode($backupB64) . ');';
                        echo 'var b=new Blob([s],{type:"text/plain;charset=utf-8"});';
                        echo 'var a=document.createElement("a");a.href=URL.createObjectURL(b);';
                        echo 'a.download="recovery-backup-' . \date('Y-m-d-His') . '.sql";document.body.appendChild(a);';
                        echo 'a.click();document.body.removeChild(a);URL.revokeObjectURL(a.href);});})();</script>';
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

                    if ($packageID) {
                        echo '<strong>Uninstall-Script:</strong> acp/uninstall/' . \htmlspecialchars($packageIdentifier) . '.php '
                            . (\is_file(\rtrim(WCF_DIR, '/\\') . '/acp/uninstall/' . $packageIdentifier . '.php') ? '(vorhanden)' : '(nicht vorhanden)') . '<br>';
                        if ($sqlRollback) {
                            $sp = recoveryPreviewSqlRollback($db, $wcfN, $packageID);
                            echo '<strong>SQL-Rollback:</strong> ' . \count($sp['actions']) . ' Aktion(en)<br>';
                        }
                        if ($rebuildBootstrap) {
                            echo '<strong>Bootstrap:</strong> lib/bootstrap.php wird neu erzeugt<br>';
                        }
                    }
                    if ($deleteFilesLog) {
                        echo '<strong>File-Log:</strong> ' . \count(recoveryLoadPackageFileLogPaths($db, $wcfN, (int)$packageID)) . ' Datei(en)<br>';
                    }
                    if ($deleteFilesDir) {
                        $fsEval2 = recoveryEvaluatePluginDirectoryDeletion($packageData, $packageIdentifier, $db, $wcfN, $extractDir);
                        if ($fsEval2['deletable']) {
                            echo '<strong>Verzeichnis:</strong> <code>' . \htmlspecialchars((string)$fsEval2['relativePath']) . '/</code><br>';
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
                    if ($deleteFilesLog) {
                        echo '<input type="hidden" name="delete_files_log" value="1">';
                    }
                    if ($deleteFilesDir) {
                        echo '<input type="hidden" name="delete_files_dir" value="1">';
                    }
                    if ($sqlRollback) {
                        echo '<input type="hidden" name="sql_rollback" value="1">';
                    }
                    if ($rebuildBootstrap) {
                        echo '<input type="hidden" name="rebuild_bootstrap" value="1">';
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
                    $deleteFilesLog = !empty($_POST['delete_files_log']) && $_POST['delete_files_log'] === '1';
                    $deleteFilesDir = !empty($_POST['delete_files_dir']) && $_POST['delete_files_dir'] === '1';
                    $deleteFilesLegacy = !empty($_POST['delete_files']) && $_POST['delete_files'] === '1';
                    $deleteFiles   = $deleteFilesLog || $deleteFilesDir || $deleteFilesLegacy;
                    $sqlRollback   = !empty($_POST['sql_rollback']) && $_POST['sql_rollback'] === '1';
                    $rebuildBootstrap = !isset($_POST['rebuild_bootstrap']) || $_POST['rebuild_bootstrap'] === '1';

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
                    $removalOpts = [
                        'dryRun' => $isDryRun,
                        'sqlRollback' => $sqlRollback,
                        'deleteFilesLog' => $deleteFilesLog || ($deleteFilesLegacy && !$deleteFilesDir),
                        'deleteFilesDir' => $deleteFilesDir || $deleteFilesLegacy,
                        'rebuildBootstrap' => $rebuildBootstrap,
                        'runUninstallScript' => true,
                    ];

                    try {
                        recoveryRunPreDbRemovalSteps(
                            $db,
                            $wcfN,
                            $packageIdentifier,
                            $packageID ?: null,
                            $removalOpts,
                            $log
                        );

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
                                recoveryTryDeletePackageRequirements($db, $wcfN, $packageID, $log);
                                if (!$sqlRollback) {
                                    recoveryTryExecuteDelete(
                                        $db,
                                        "DELETE FROM wcf{$wcfN}_package_installation_sql_log WHERE packageID = ?",
                                        [$packageID],
                                        'Package SQL-Log',
                                        $log
                                    );
                                }
                                recoveryTryExecuteDelete(
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

                        recoveryRunPostDbRemovalSteps(
                            $db,
                            $wcfN,
                            $packageIdentifier,
                            $packageData,
                            $packageID ?: null,
                            $removalOpts,
                            $log,
                            $extractDir
                        );

                        // ── options.inc.php + Cache ───────────────────────────
                        if (!$isDryRun) {
                            $optionConstants = recoveryCollectOptionConstantNames($db, $wcfN, $packageID);
                            if (recoveryRebuildOptionsIncPhp()) {
                                $log[] = 'options.inc.php neu erzeugt';
                            } elseif (!empty($optionConstants)) {
                                recoveryStripConstantsFromOptionsIncPhp($optionConstants);
                                $log[] = 'options.inc.php bereinigt (' . \count($optionConstants) . ' Konstanten entfernt)';
                            }
                            recoveryEnsureOptionConstantFallbacks($db, $wcfN, $log);
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

                        if (!$isDryRun) {
                            $cacheAgainUrl = \htmlspecialchars(
                                recoveryBuildModeUrl(RECOVERY_MODE_CACHE_CLEAR, $authHash),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            echo '<div class="alert alert-info">';
                            echo '<strong>ACP lädt nicht oder zeigt Fatal Error?</strong><br>';
                            echo 'Zusätzlich wurde <code>options.inc.php</code> mit einem markierten Fallback-Block ergänzt (<code>if (!defined(&#8230;)) define(&#8230;)</code>) für <strong>alle</strong> Optionen aus der DB plus Konstanten-Erkennung aus kompilierten Templates. ';
                            echo 'Nach Plugin-Problemen bleiben oft <em>kompilierte Templates</em> ohne passende globale Konstanten (Log: '
                                . '<code>Undefined constant &quot;&hellip;&quot;</code>). ';
                            echo 'Das Tool hat den Datei-Cache geleert; bei Bedarf <strong>Caches erneut leeren:</strong> Modus Cache Clear.';
                            echo '<br><br><a href="' . $cacheAgainUrl . '" class="button">';
                            echo '<i class="fa-solid fa-broom"></i> Cache Clear öffnen</a>';
                            echo '<br><br><small>Plugin-Fix: Konstanten immer mit <code>defined(\'CONST\')</code> oder Standardwert in Templates nutzen. '
                                . 'Nach fehlgeschlagener Installation hilft häufig manuell: <code>acp/templates/compiled/</code> leeren.';
                            echo '</small></div>';
                        }

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

