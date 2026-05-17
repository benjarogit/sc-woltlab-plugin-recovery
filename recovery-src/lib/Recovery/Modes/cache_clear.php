<?php
/** Recovery mode: cache_clear — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_CACHE_CLEAR) {
?>
    <h1>Cache Clear</h1>
    <p class="subtitle">Löscht alle Caches und kompilierte Templates</p>

<?php
    if (!isset($_POST['confirm_clear'])) {
?>
    <div class="alert alert-warning">
        <strong>Folgende Verzeichnisse werden geleert:</strong><br>
        tmp/<br>
        cache/<br>
        templates/compiled/<br>
        acp/templates/compiled/<br>
        sowie bei installierten Anwendungen z.&nbsp;B. <code>shrinkr/templates/compiled/</code> und <code>shrinkr/acp/templates/compiled/</code>.<br><br>
        <strong>Hinweis:</strong> Anschließend werden fehlende Option-<code>define()</code>s aus der Datenbank <strong>aller Plugins</strong> sowie aus kompilierten Templates ermittelt und sicher in <code>options.inc.php</code> nachgetragen (gegen Fatal Error „Undefined constant“ im ACP).
    </div>

    <form method="POST">
        <input type="hidden" name="confirm_clear" value="1">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-broom"></i> Cache jetzt löschen</button>
    </form>
<?php
    } else {
        $deletedFiles = clearCompiledTemplates();
        $optionFbLog = [];
        recoveryEnsureOptionConstantFallbacks($db, WCF_N, $optionFbLog);

        echo '<div class="alert alert-success">';
        echo '<strong>Cache erfolgreich geleert.</strong><br>';
        echo 'Gelöschte Dateien: ' . $deletedFiles . '<br>';
        foreach ($optionFbLog as $fbEntry) {
            echo \htmlspecialchars($fbEntry) . '<br>';
        }
        echo '</div>';
    }
}

// ============================================================================
// MODUS 5: PAKETLISTE REPARIEREN
// ============================================================================

