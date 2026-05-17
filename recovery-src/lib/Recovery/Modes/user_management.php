<?php
/** Recovery mode: user_management — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_USER_MANAGEMENT) {
    $umBaseUrl = '?mode=' . RECOVERY_MODE_USER_MANAGEMENT . '&t=' . \htmlspecialchars($authHash);
    $umUid     = isset($_GET['um_uid']) ? (int)$_GET['um_uid'] : 0;
    $umMessages = [];
    $umErrors   = [];

    // --- POST-Aktionen verarbeiten ---
    if ($umUid > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $umAction = $_POST['um_action'] ?? '';
        try {
            switch ($umAction) {
                case 'reset_password':
                    $newPwd = recoveryUserGenerateRandomPassword();
                    recoveryUserResetPassword($db, $umUid, $newPwd);
                    $umMessages[] = 'Passwort wurde auf <code>' . \htmlspecialchars($newPwd) . '</code> gesetzt. Bitte sofort notieren!';
                    break;

                case 'reset_password_custom':
                    $customPwd = \trim($_POST['custom_password'] ?? '');
                    if ($customPwd === '') {
                        $umErrors[] = 'Bitte ein Passwort eingeben.';
                    } elseif (\strlen($customPwd) < 8) {
                        $umErrors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
                    } else {
                        recoveryUserResetPassword($db, $umUid, $customPwd);
                        $umMessages[] = 'Passwort wurde erfolgreich gesetzt.';
                    }
                    break;

                case 'set_groups':
                    $groupIDs = isset($_POST['group_ids']) && \is_array($_POST['group_ids'])
                        ? \array_map('intval', $_POST['group_ids'])
                        : [];
                    recoveryUserSetGroups($db, $umUid, $groupIDs);
                    $umMessages[] = 'Gruppenmitgliedschaften wurden aktualisiert.';
                    break;

                case 'add_admin':
                    $currentGIDs = recoveryUserGetGroupIDs($db, $umUid);
                    if (!\in_array(4, $currentGIDs, true)) {
                        $currentGIDs[] = 4;
                        recoveryUserSetGroups($db, $umUid, $currentGIDs);
                        $umMessages[] = 'Benutzer wurde zur Administrator-Gruppe (ID&nbsp;4) hinzugefügt.';
                    } else {
                        $umMessages[] = 'Benutzer ist bereits in der Administrator-Gruppe.';
                    }
                    break;

                case 'change_email':
                    $newEmail = \trim($_POST['new_email'] ?? '');
                    if ($newEmail === '' || !\filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                        $umErrors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
                    } else {
                        recoveryUserChangeEmail($db, $umUid, $newEmail);
                        $umMessages[] = 'E-Mail-Adresse auf <code>' . \htmlspecialchars($newEmail) . '</code> geändert.';
                    }
                    break;

                case 'activate':
                    recoveryUserActivate($db, $umUid);
                    $umMessages[] = 'Benutzer wurde aktiviert und Sperre aufgehoben.';
                    break;

                case 'disable_2fa':
                    recoveryUserDisable2FA($db, $umUid);
                    $umMessages[] = 'Zwei-Faktor-Authentifizierung wurde deaktiviert und alle 2FA-Setups gelöscht.';
                    break;
            }
        } catch (\Throwable $e) {
            $umErrors[] = 'Fehler: ' . \htmlspecialchars(recoveryFormatUserError($e));
            recoveryRenderExceptionDetails($e);
        }
    }
?>
    <h1>User Management</h1>
    <p class="subtitle">Benutzersuche, Passwort-Reset, Gruppen, E-Mail &amp; Kontoverwaltung</p>

<?php if ($umUid > 0):
    $umUser = recoveryUserGetByID($db, $umUid);
    if ($umUser === null): ?>
    <div class="alert alert-error">Benutzer mit ID <code><?= $umUid ?></code> nicht gefunden.</div>
    <a href="<?= $umBaseUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Zurück zur Suche</a>
<?php else:
    $currentGroupIDs = recoveryUserGetGroupIDs($db, (int)$umUser['userID']);
?>
    <a href="<?= $umBaseUrl ?>" class="back-link"><i class="fa-solid fa-arrow-left"></i> Anderen Benutzer suchen</a>

    <h2>Benutzer: <code><?= \htmlspecialchars($umUser['username']) ?></code> <small style="font-size:16px; color:#9D9D9D;">(ID&nbsp;<?= (int)$umUser['userID'] ?>)</small></h2>

    <table style="margin-bottom: 24px;">
        <tbody>
            <tr><th style="width:160px">Benutzername</th><td><?= \htmlspecialchars($umUser['username']) ?></td></tr>
            <tr><th>E-Mail</th><td><?= \htmlspecialchars($umUser['email']) ?></td></tr>
            <tr><th>Status</th><td>
                <?php if ($umUser['banned']): ?>
                    <span style="color:#e74c3c">&#9632; Gesperrt</span>
                <?php elseif ($umUser['activationCode'] != 0): ?>
                    <span style="color:#f39c12">&#9632; Aktivierung ausstehend</span>
                <?php else: ?>
                    <span style="color:#00bc8c">&#9632; Aktiv</span>
                <?php endif; ?>
            </td></tr>
            <tr><th>2FA</th><td><?= $umUser['multifactorActive'] ? '<span style="color:#f39c12">Aktiv</span>' : '<span style="color:#9D9D9D">Inaktiv</span>' ?></td></tr>
            <tr><th>Gruppen</th><td><?= \implode(', ', $currentGroupIDs) ?></td></tr>
        </tbody>
    </table>

    <?php foreach ($umErrors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div>
    <?php endforeach; ?>
    <?php foreach ($umMessages as $msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
    <?php endforeach; ?>

    <!-- ── Passwort zurücksetzen ──────────────────────────────────────── -->
    <h2><i class="fa-solid fa-key"></i> Passwort zurücksetzen</h2>
    <p style="margin:0 0 16px; font-size:13px; color:#9D9D9D;">Wie im <a href="https://manual.woltlab.com/de/recovery-tool/" target="_blank" rel="noopener">offiziellen WoltLab Recovery Tool</a>: zufälliges Passwort bestätigen oder ein eigenes setzen.</p>
    <div class="recovery-option-cards">
        <div class="recovery-option-card recovery-card">
            <h3><i class="fa-solid fa-dice"></i> Zufälliges Passwort</h3>
            <p style="margin:0 0 16px; font-size:13px; color:#9D9D9D;">Wird nach dem Setzen <strong>einmalig</strong> angezeigt – bitte sofort notieren.</p>
            <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
                <input type="hidden" name="um_action" value="reset_password">
                <button type="submit" class="btn-danger"><i class="fa-solid fa-key"></i> Zufälliges Passwort setzen</button>
            </form>
        </div>
        <div class="recovery-option-card recovery-card">
            <h3><i class="fa-solid fa-pen"></i> Eigenes Passwort</h3>
            <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
                <input type="hidden" name="um_action" value="reset_password_custom">
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="um_custom_pwd">Neues Passwort (min. 8 Zeichen)</label>
                    <input type="password" id="um_custom_pwd" name="custom_password" autocomplete="new-password" placeholder="Passwort eingeben">
                </div>
                <button type="submit"><i class="fa-solid fa-key"></i> Passwort setzen</button>
            </form>
        </div>
    </div>

    <!-- ── E-Mail ändern ──────────────────────────────────────────────── -->
    <h2><i class="fa-solid fa-envelope"></i> E-Mail-Adresse ändern</h2>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="change_email">
        <div class="form-group">
            <label for="um_email">Neue E-Mail-Adresse</label>
            <input type="text" id="um_email" name="new_email" value="<?= \htmlspecialchars($umUser['email']) ?>" placeholder="neue@email.de">
        </div>
        <button type="submit"><i class="fa-solid fa-envelope"></i> E-Mail ändern</button>
    </form>

    <!-- ── Konto aktivieren / Sperre aufheben ────────────────────────── -->
    <?php if ($umUser['banned'] || $umUser['activationCode'] != 0): ?>
    <h2><i class="fa-solid fa-user"></i> Konto aktivieren &amp; Sperre aufheben</h2>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Setzt <code>activationCode&nbsp;=&nbsp;0</code>, <code>banned&nbsp;=&nbsp;0</code> und löscht den Sperr-Grund.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="activate">
        <button type="submit" class="btn-success"><i class="fa-solid fa-circle-check"></i> Benutzer aktivieren &amp; entsperren</button>
    </form>
    <?php endif; ?>

    <!-- ── 2FA deaktivieren ───────────────────────────────────────────── -->
    <?php if ($umUser['multifactorActive']): ?>
    <h2><i class="fa-solid fa-shield-halved"></i> Zwei-Faktor-Authentifizierung deaktivieren</h2>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Löscht alle 2FA-Setups (inkl. Backup-Codes) und setzt <code>multifactorActive&nbsp;=&nbsp;0</code>.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="disable_2fa">
        <button type="submit" class="btn-danger"><i class="fa-solid fa-shield-halved"></i> 2FA deaktivieren</button>
    </form>
    <?php endif; ?>

    <!-- ── Schnell zur Administrator-Gruppe ──────────────────────────── -->
    <h2><i class="fa-solid fa-users-gear"></i> Administrator-Gruppe (ID&nbsp;4)</h2>
    <?php if (\in_array(4, $currentGroupIDs, true)): ?>
    <div class="alert alert-info">Benutzer ist bereits in der Administrator-Gruppe (ID&nbsp;4).</div>
    <?php else: ?>
    <p style="margin-bottom:12px; font-size:13px; color:#9D9D9D;">
        Fügt den Benutzer direkt zur WoltLab-Standard-Administrator-Gruppe (groupID&nbsp;4) hinzu.
    </p>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="add_admin">
        <button type="submit" class="btn-success"><i class="fa-solid fa-users-gear"></i> Zur Administrator-Gruppe hinzufügen</button>
    </form>
    <?php endif; ?>

    <!-- ── Alle Gruppen verwalten ─────────────────────────────────────── -->
    <h2><i class="fa-solid fa-sliders"></i> Alle Gruppen verwalten</h2>
    <?php $allGroups = recoveryUserGetAllGroups($db); ?>
    <form method="POST" action="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umUser['userID'] ?>">
        <input type="hidden" name="um_action" value="set_groups">
        <table>
            <thead>
                <tr>
                    <th style="width:1px"></th>
                    <th style="width:55px">ID</th>
                    <th>Gruppe</th>
                    <th style="width:80px">Typ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allGroups as $grp):
                $gid      = (int)$grp['groupID'];
                $isSystem = \in_array($gid, [1, 2], true);
                $isMember = \in_array($gid, $currentGroupIDs, true);
                $groupType = (int) $grp['groupType'];
                if ($groupType === 1) {
                    $gType = 'System';
                } elseif ($groupType === 4) {
                    $gType = 'Admin';
                } else {
                    $gType = 'Normal';
                }
            ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" name="group_ids[]" id="grp_<?= $gid ?>"
                            value="<?= $gid ?>"
                            <?= $isMember ? 'checked' : '' ?>
                            <?= $isSystem ? 'disabled' : '' ?>>
                        <?php if ($isSystem): ?>
                        <input type="hidden" name="group_ids[]" value="<?= $gid ?>">
                        <?php endif; ?>
                    </td>
                    <td><?= $gid ?></td>
                    <td><label for="grp_<?= $gid ?>"><?= recoveryFormatUserGroupLabel($gid, (string) $grp['groupName']) ?></label></td>
                    <td><small><?= $gType ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" style="margin-top:15px;"><i class="fa-solid fa-sliders"></i> Gruppen speichern</button>
    </form>

<?php endif; // $umUser !== null

else: // $umUid === 0 → Suchmaske ?>

    <?php foreach ($umErrors as $err): ?>
    <div class="alert alert-error"><?= $err ?></div>
    <?php endforeach; ?>
    <?php foreach ($umMessages as $msg): ?>
    <div class="alert alert-success"><?= $msg ?></div>
    <?php endforeach; ?>

    <h2><i class="fa-solid fa-magnifying-glass"></i> Benutzer suchen</h2>
    <form method="POST" action="<?= $umBaseUrl ?>">
        <div class="form-group">
            <label for="um_search">Benutzername oder E-Mail (Präfix-Suche, max. 50 Treffer)</label>
            <input type="text" id="um_search" name="um_search"
                value="<?= \htmlspecialchars($_POST['um_search'] ?? '') ?>"
                placeholder="z.&thinsp;B. Admin oder admin@example.com" autofocus>
        </div>
        <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Suchen</button>
    </form>

    <?php
    $umSearchQuery = \trim($_POST['um_search'] ?? '');
    if ($umSearchQuery !== ''):
        try {
            $umResults = recoveryUserSearch($db, $umSearchQuery);
        } catch (\Throwable $e) {
            $umResults = [];
            echo '<div class="alert alert-error">Suchfehler: ' . \htmlspecialchars($e->getMessage()) . '</div>';
        }
    ?>

    <?php if (empty($umResults)): ?>
    <div class="alert alert-info" style="margin-top:20px;">
        Keine Benutzer für <code><?= \htmlspecialchars($umSearchQuery) ?></code> gefunden.
    </div>
    <?php else: ?>
    <table style="margin-top:20px;">
        <thead>
            <tr>
                <th style="width:55px">ID</th>
                <th>Benutzername</th>
                <th>E-Mail</th>
                <th style="width:100px">Status</th>
                <th style="width:55px">2FA</th>
                <th style="width:1px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($umResults as $umRow): ?>
            <tr>
                <td><?= (int)$umRow['userID'] ?></td>
                <td><?= \htmlspecialchars($umRow['username']) ?></td>
                <td><?= \htmlspecialchars($umRow['email']) ?></td>
                <td>
                    <?php if ($umRow['banned']): ?>
                        <span style="color:#e74c3c">Gesperrt</span>
                    <?php elseif ($umRow['activationCode'] != 0): ?>
                        <span style="color:#f39c12">Inaktiv</span>
                    <?php else: ?>
                        <span style="color:#00bc8c">Aktiv</span>
                    <?php endif; ?>
                </td>
                <td><?= $umRow['multifactorActive'] ? '<span style="color:#f39c12">Ja</span>' : 'Nein' ?></td>
                <td>
                    <a href="<?= $umBaseUrl ?>&amp;um_uid=<?= (int)$umRow['userID'] ?>" class="button" style="padding:5px 12px; font-size:13px;">Bearbeiten</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endif; // $umSearchQuery !== '' ?>

<?php endif; // $umUid > 0 ?>
<?php
}

// ============================================================================
// MODUS 4: CACHE CLEAR
// ============================================================================

