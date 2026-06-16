<?php

/**
 * Bootstrap: load Composer dependencies (OVH SDK) and the module's own classes.
 * Safe to require multiple times.
 */

$ovhvpsLibDir = __DIR__;

$ovhvpsVendor = dirname($ovhvpsLibDir) . '/vendor/autoload.php';
if (is_file($ovhvpsVendor)) {
    require_once $ovhvpsVendor;
}

/**
 * Fallback PSR-4 autoloader for the OvhVps\ namespace so the module still loads
 * (and can report a clear error) even before "composer install" has been run.
 */
spl_autoload_register(static function (string $class) use ($ovhvpsLibDir): void {
    if (strncmp($class, 'OvhVps\\', 7) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 7));
    $file = $ovhvpsLibDir . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
