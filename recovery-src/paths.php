<?php

declare(strict_types=1);

/**
 * WoltLab-Hauptverzeichnis (Parent von recovery-tool/).
 */
function recoveryWcfRoot(): string
{
    if (\defined('RECOVERY_WCF_ROOT')) {
        return \rtrim((string) \constant('RECOVERY_WCF_ROOT'), '/\\') . '/';
    }

    return \rtrim(\dirname(\defined('RECOVERY_PACKAGE_DIR') ? RECOVERY_PACKAGE_DIR : __DIR__), '/\\') . '/';
}

function recoveryWcfPath(string $relative = ''): string
{
    $root = recoveryWcfRoot();
    $relative = \ltrim(\str_replace('\\', '/', $relative), '/');

    return $relative === '' ? $root : $root . $relative;
}
