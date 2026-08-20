<?php

/*
 * The package vendor autoloader is resolved from two possible locations:
 *  1. The package's own vendor/ directory (standalone install via `composer install` inside the package).
 *  2. The host application's vendor/ directory (monorepo / path-repository install).
 */

$packageVendor = dirname(__DIR__).'/vendor/autoload.php';
$hostVendor    = dirname(__DIR__, 4).'/vendor/autoload.php';

if (file_exists($packageVendor)) {
    require $packageVendor;
} elseif (file_exists($hostVendor)) {
    require $hostVendor;
} else {
    fwrite(STDERR, "Composer autoloader not found. Run `composer install` inside the package or the host app.\n");
    exit(1);
}
