<?php
/** Recovery mode: directory_structure — included by lib/Recovery/router.php */
declare(strict_types=1);

if ($mode === RECOVERY_MODE_DIRECTORY_STRUCTURE) {
    recoveryRenderDirectoryStructurePage(
        $authHash,
        \rtrim(WCF_DIR, '/\\') . \DIRECTORY_SEPARATOR,
        $db,
        WCF_N
    );
}
