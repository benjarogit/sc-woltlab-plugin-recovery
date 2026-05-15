<?php

/**
 * CLI-Harness für Debug-Session: simuliert GET mit gültigem ?t= (kein Redirect),
 * damit plugin-recovery-tool.php bis zur HTML-Ausgabe läuft und NDJSON-Logs schreibt.
 *
 * Logs: standardmäßig `plugin-recovery-agent-debug.ndjson` neben `plugin-recovery-tool.php`,
 * oder Ziel per Umgebung `RECOVERY_AGENT_LOG_PATH=/pfad/zur/datei.ndjson`.
 *
 * Ausführung: php debug_invoke_recovery.php (im gleichen Verzeichnis wie das Tool)
 */
if (!\getenv('RECOVERY_AGENT_LOG_PATH')) {
    $workspaceCursor = \dirname(__DIR__, 2) . '/.cursor/debug-54d5f7.log';
    if (\is_dir(\dirname($workspaceCursor))) {
        \putenv('RECOVERY_AGENT_LOG_PATH=' . $workspaceCursor);
    }
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/plugin-recovery-tool.php';
$_SERVER['RECOVERY_DEBUG_RUN'] = 'cli-harness';

$t = \str_repeat('a', 40);
$_GET['t'] = $t;
$_REQUEST['t'] = $t;

require __DIR__ . '/plugin-recovery-tool.php';
