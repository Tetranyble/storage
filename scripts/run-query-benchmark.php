<?php

$phpunit = dirname(__DIR__).'/vendor/bin/phpunit';

if (! is_file($phpunit)) {
    fwrite(STDERR, "Install Composer dependencies before running query benchmarks.\n");
    exit(1);
}

putenv('STORAGE_RUN_QUERY_BENCHMARKS=1');
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($phpunit).' '.escapeshellarg('tests/Benchmark');
passthru($command, $exitCode);
exit($exitCode);
