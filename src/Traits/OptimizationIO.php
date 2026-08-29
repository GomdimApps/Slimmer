<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

use GomdimApps\Slimmer\Exceptions\SlimmerException;

/**
 * Shared I/O validation for file-based optimizers/archivers (PDF, image,
 * tar/zip extraction).
 *
 * Requires the input to be a regular file and the output parent directory
 * to exist and be writable.
 */
trait OptimizationIO
{
    /** @throws SlimmerException */
    private function validateInputFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw SlimmerException::inputFileNotFound($path);
        }
    }

    /** @throws SlimmerException */
    private function validateOutputDirectory(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw SlimmerException::outputDirectoryNotWritable($directory);
        }
    }
}
