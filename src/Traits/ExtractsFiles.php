<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

/**
 * Shared post-extraction file collection for archive-reading optimizers
 * (ExtractTar, ExtractZip).
 */
trait ExtractsFiles
{
    /**
     * Walk $outputDir after a successful extraction and return the absolute
     * paths of every extracted file. Robust regardless of whether verbose
     * output / a progress callback was used.
     *
     * @return string[]
     */
    private function collectExtractedFiles(string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
