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

/**
 * Create a fresh source+output temp-directory pair for archive tests: a
 * writable output directory, and a source directory containing a 'subdir/'
 * populated per $files (and, optionally, an empty 'empty/' subdirectory).
 *
 * @param array<string,string> $files Map of path-relative-to-sourceDir => contents.
 */
function makeArchiveFixture(string $prefix, array $files, bool $withEmptyDir = false): object
{
    $outputDir = sys_get_temp_dir() . '/' . $prefix . '_out_' . uniqid();
    mkdir($outputDir);

    $sourceDir = sys_get_temp_dir() . '/' . $prefix . '_src_' . uniqid();
    mkdir($sourceDir);
    mkdir($sourceDir . '/subdir');
    if ($withEmptyDir) {
        mkdir($sourceDir . '/empty');
    }
    foreach ($files as $relativePath => $contents) {
        file_put_contents($sourceDir . '/' . $relativePath, $contents);
    }

    return (object) ['sourceDir' => $sourceDir, 'outputDir' => $outputDir];
}

/**
 * Remove both directories of a fixture built by makeArchiveFixture().
 * Safe to call even if either directory was already removed by the test itself.
 */
function removeArchiveFixture(object $fixture): void
{
    removeDir($fixture->outputDir);
    removeDir($fixture->sourceDir);
}
