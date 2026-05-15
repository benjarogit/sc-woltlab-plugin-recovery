<?php
/**
 * Minimaler PHP-Test – nur diese Datei hochladen, wenn das Recovery Tool 500 liefert.
 * Aufruf: https://ihre-domain.de/recovery-ping.php
 * Erwartung: Textzeile mit OK und PHP-Version. Danach wieder löschen.
 */
header('Content-Type: text/plain; charset=utf-8');
echo 'RECOVERY_PING_OK PHP=' . PHP_VERSION . "\n";
