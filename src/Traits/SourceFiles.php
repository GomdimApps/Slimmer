<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

use GomdimApps\Slimmer\Exceptions\SlimmerException;

/**
 * File and directory operations for source-consuming optimizers.
 *
 * Provides recursive size calculation and safe deletion of source files /
 * directories used by CompressTar's and CompressZip's retention strategies.
 * Deletion failures are reported via the consuming class's own exception type
 * (see throwDeletionFailedException()), since this trait is shared by both.
 */
trait SourceFiles
{
    /**
     * Return the total byte size of a file, or the recursive sum for a directory.
     */
    private function getInputSize(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }

        $size     = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    /**
     * Delete a source file or directory (recursive).
     *
     * @throws SlimmerException
     */
    private function deleteSource(string $path): void
    {
        if (is_file($path)) {
            if (!@unlink($path)) {
                $this->throwDeletionFailedException($path, error_get_last()['message'] ?? '');
            }

            return;
        }

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }
    }

    /**
     * Recursively delete a directory and all its contents (children first).
     *
     * @throws SlimmerException
     */
    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                if (!@rmdir($item->getPathname())) {
                    $this->throwDeletionFailedException($item->getPathname(), error_get_last()['message'] ?? '');
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    $this->throwDeletionFailedException($item->getPathname(), error_get_last()['message'] ?? '');
                }
            }
        }

        if (!@rmdir($directory)) {
            $this->throwDeletionFailedException($directory, error_get_last()['message'] ?? '');
        }
    }

    /**
     * Throw the consuming class's own "deletion failed" exception (e.g.
     * TarException::deletionFailed() for CompressTar, ZipException::deletionFailed()
     * for CompressZip), so a caller catching that specific subtype sees this failure.
     */
    abstract protected function throwDeletionFailedException(string $path, string $reason): never;
}
