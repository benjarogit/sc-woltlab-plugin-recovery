<?php
/** Recovery mode: package_list_repair — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_PACKAGE_LIST_REPAIR) {
?>
    <h1>Paketliste reparieren</h1>
    <p class="subtitle">Entfernt verwaiste Datenbankeinträge, die die ACP-Paketliste oder Deinstallation blockieren</p>

<?php
    $orphanSql = recoveryGenerateOrphanRepairSql(WCF_N);

    if (!isset($_POST['confirm_repair'])) {
?>
    <div class="alert alert-warning">
        <strong>Typische Symptome:</strong><br>
        • ACP-Paketliste: <code>Attempt to read property "packageID" on null</code><br>
        • Deinstallation: <code>assert($package !== null)</code> bei hängender Queue<br><br>
        <strong>Bereinigt (generisch, alle Plugins):</strong> verwaiste <code>application</code>-Zeilen,
        <code>package_installation_queue</code> / <code>node</code> / <code>form</code>,
        <code>package_requirement</code>, <code>package_exclusion</code>, File-Logs.
    </div>

    <details style="margin: 1rem 0;">
        <summary>SQL für phpMyAdmin / manuelle Ausführung (WCF_N=<?= (int) WCF_N ?>)</summary>
        <pre class="recoveryLog"><?= \htmlspecialchars($orphanSql) ?></pre>
    </details>

    <form method="POST">
        <input type="hidden" name="confirm_repair" value="1">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-list-check"></i> Verwaiste Einträge jetzt bereinigen</button>
    </form>
<?php
    } else {
        try {
            $result = recoveryRepairOrphanedPackageReferences($db, WCF_N);
            $deletedFiles = clearCompiledTemplates();
            $optionFbLog = [];
            recoveryEnsureOptionConstantFallbacks($db, WCF_N, $optionFbLog);

            echo '<div class="alert alert-success">';
            echo '<strong>Paketliste-Reparatur abgeschlossen.</strong><br><br>';
            foreach ($result['log'] as $entry) {
                echo '• ' . \htmlspecialchars($entry) . '<br>';
            }
            echo '<br>Cache-Dateien gelöscht: ' . (int) $deletedFiles . '<br>';
            foreach ($optionFbLog as $fbEntry) {
                echo \htmlspecialchars($fbEntry) . '<br>';
            }
            echo '</div>';
        } catch (\Throwable $e) {
            echo '<div class="alert alert-error"><strong>Fehler:</strong> '
                . \htmlspecialchars(recoveryFormatUserError($e)) . '</div>';
            recoveryRenderExceptionDetails($e);
        }
    }
}

// ============================================================================
// MODUS 6: PLUGIN-DATEIEN REPARIEREN
// ============================================================================

