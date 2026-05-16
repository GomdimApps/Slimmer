<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Global Configuration
|--------------------------------------------------------------------------
|
| This file is evaluated before any test and is the right place to define
| custom expectations, helpers, and dataset factories shared across all
| test files.
|
*/

/**
 * Recursively remove a directory and all of its contents.
 * Safe to call when the directory does not exist.
 */
function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}
