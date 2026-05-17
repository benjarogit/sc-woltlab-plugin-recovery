<?php
/** Recovery mode: selection — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_SELECTION) {
    recoveryRenderFlashSnackbarFromQuery();
    $wizardStartUrl = recoveryBuildModeUrl(RECOVERY_MODE_RECOVERY_WIZARD, $authHash);
    $adminUrl = recoveryBuildModeUrl(RECOVERY_MODE_USER_MANAGEMENT, $authHash);
    $uninstallUrl = recoveryBuildModeUrl(RECOVERY_MODE_PLUGIN_UNINSTALL, $authHash);
    $expertOpen = !empty($_GET['expert']);
    $sysinfoOpen = !empty($_GET['sysinfo']);
    $acpUrl = $recoveryBaseUrl . 'acp/';
    $showEmergencyFix = recoveryShouldOfferEmergencyClassNotFoundFix($wcfDirMain)
        && $emergencyFixedSession === null;
?>
    <?php if (isset($_GET['auth_ok'])): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Anmeldung erfolgreich.</strong> Wählen Sie unten, was auf Ihrer Installation zutrifft.</div>
    <?php endif; ?>

    <header class="recovery-intake-hero">
        <h1>WoltLab Recovery Tool</h1>
        <p class="subtitle" style="max-width:720px">
            Geführte Hilfe bei Notfällen — zuerst die Situation wählen. Für defekte Plugins und ein nicht erreichbares ACP
            ist der <strong>Recovery-Wizard</strong> der empfohlene Weg (Diagnose → Plan → Ausführung).
        </p>
    </header>

    <?php recoveryRenderCompactStatusBar($authHash, $recoveryBaseUrl); ?>
    <?php recoveryRenderRuntimeInfoPanel($authHash, $recoveryBaseUrl, $sysinfoOpen); ?>

    <?php if ($emergencyAcpResult !== null && !empty($emergencyAcpResult['error'])): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <strong>Notfall-Reparatur fehlgeschlagen:</strong> <?= \htmlspecialchars((string) $emergencyAcpResult['error']) ?>
    </div>
    <?php elseif ($emergencyFixedSession !== null || ($emergencyAcpResult !== null && empty($emergencyAcpResult['error']))): ?>
    <?php
        $guidanceResult = \is_array($emergencyAcpResult) ? $emergencyAcpResult : ($emergencyFixedSession['result'] ?? []);
        recoveryRenderAcpRecoveredGuidance($guidanceResult, $acpUrl, $uninstallUrl);
    ?>
    <?php endif; ?>

    <?php if ($showEmergencyFix): ?>
    <section class="recovery-scenario-card" style="margin-bottom:24px;border-color:#c60;background:rgba(204,102,0,0.08)">
        <h2 style="margin:0 0 8px;font-size:17px"><i class="fa-solid fa-bolt"></i> Sofort: ACP zeigt ClassNotFound</h2>
        <p style="margin:0 0 10px;color:#ccc;font-size:14px;line-height:1.5">
            <strong>Automatisch erkannt</strong> — im WoltLab-Log steht eine <code>ClassNotFoundException</code>
            (siehe Kurzstatus oben). Ein Klick deaktiviert die betroffenen Bootstrap-<code>register()</code>-Aufrufe,
            bereinigt DB-Listener und leert den Cache.
        </p>
        <form method="POST" action="<?= \htmlspecialchars(recoveryBuildHomeUrl($authHash)) ?>"
            data-recovery-loading="Notfall-Reparatur läuft (Bootstrap, DB, Cache) …"
            data-recovery-confirm="Bootstrap-Register werden auskommentiert (mit Backup), DB-Listener gelöscht, Cache geleert. Fortfahren?"
            data-recovery-confirm-title="ACP ClassNotFound beheben"
            data-recovery-confirm-ok="Jetzt beheben">
            <?php recoveryRenderFormModeHiddenFields(RECOVERY_MODE_SELECTION, $authHash); ?>
            <input type="hidden" name="emergency_acp_fix" value="1">
            <button type="submit" class="btn-danger"><i class="fa-solid fa-bolt"></i> ACP ClassNotFound jetzt beheben</button>
        </form>
    </section>
    <?php endif; ?>

    <h2 style="margin:0 0 16px;font-size:18px;color:#fff">Was ist passiert?</h2>
    <div class="recovery-scenario-grid">
        <a href="<?= \htmlspecialchars($wizardStartUrl) ?>" class="recovery-scenario-card recovery-scenario-card--primary">
            <i class="fa-solid fa-route recovery-scenario-icon" aria-hidden="true"></i>
            <h2>Plugin defekt / ACP nicht erreichbar</h2>
            <p>
                Wenn <code>/acp/</code> nicht lädt: Schritt für Schritt Diagnose, Reparatur, optional Dateien aus dem
                Paket-Archiv. <strong>ACP läuft bereits?</strong> Dann nicht den Wizard für „Dateien wiederherstellen“ —
                stattdessen Plugin entfernen (rechte Karte).
            </p>
            <span class="recovery-scenario-cta">Recovery-Wizard starten →</span>
        </a>

        <a href="<?= \htmlspecialchars($adminUrl) ?>" class="recovery-scenario-card">
            <i class="fa-solid fa-user-shield recovery-scenario-icon" aria-hidden="true"></i>
            <h2>Admin-Zugang wiederherstellen</h2>
            <p>
                Passwort vergessen, kein Zugang zum ACP, Administrator-Rechte nötig — Benutzerkonto und Berechtigungen
                direkt in der Datenbank anpassen.
            </p>
            <span class="recovery-scenario-cta">User Management →</span>
        </a>

        <a href="<?= \htmlspecialchars($uninstallUrl) ?>" class="recovery-scenario-card">
            <i class="fa-solid fa-trash-can recovery-scenario-icon" aria-hidden="true"></i>
            <h2>Plugin gezielt entfernen</h2>
            <p>
                Wie Deinstallieren im ACP, aber direkt über DB + optional Dateien — wenn das ACP wieder geht oder das
                Paket in der Paketliste fehlt, Reste aber noch da sind (<code>de.sunnyc.wsc.shrinkr</code> usw.).
            </p>
            <span class="recovery-scenario-cta">Plugin Uninstall →</span>
        </a>
    </div>

    <details class="recovery-expert-panel" id="recovery-expert-panel"<?= $expertOpen ? ' open' : '' ?>>
        <summary>Experten: Einzelmodi manuell (optional)</summary>
        <div class="recovery-expert-body">
            <p style="margin:0 0 16px;color:#9D9D9D;font-size:14px;line-height:1.55">
                Nur nutzen, wenn Sie genau wissen, welcher Schritt nötig ist. Für die meisten Fälle reicht der
                <a href="<?= \htmlspecialchars($wizardStartUrl) ?>" style="color:#6EC2FF">Recovery-Wizard</a>.
            </p>
            <?php recoveryRenderExpertModesGrid($authHash); ?>
        </div>
    </details>

    <div class="alert alert-info" style="margin-top:24px">
        <i class="fa-solid fa-circle-info"></i>
        <strong>Hinweis:</strong> Das Tool arbeitet direkt auf dem Server (Datenbank &amp; Dateien). Nur im Notfall verwenden.
        Nach erfolgreicher Recovery alle Recovery-Dateien vom Webspace entfernen.
    </div>

    <div class="alert alert-warning" style="margin-top: 20px;">
        <i class="fa-solid fa-triangle-exclamation"></i> <strong>Fertig mit Recovery?</strong><br>
        Wenn alles wieder funktioniert, Recovery Tool und Auth-Datei löschen.<br><br>
        <a href="?action=cleanup&amp;t=<?= \htmlspecialchars($authHash) ?>" class="button btn-danger" onclick="return confirm('ACHTUNG: Das Recovery Tool wird entfernt (Auth-Datei, Uploads, diese PHP-Datei) und Sie werden ins ACP weitergeleitet. Fortfahren?')">
            <i class="fa-solid fa-xmark"></i> Recovery Tool vollständig entfernen
        </a>
    </div>

<?php
}

