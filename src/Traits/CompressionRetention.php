<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

/**
 * Shared "compress, measure, retain" flow for CompressTar/CompressZip: both
 * classes compress a source, compute the size-reduction ratio, and (for
 * compressAndRetain) delete the source and prune older archives from the
 * output directory. Format/engine-call/exception specifics are supplied by
 * the consuming class via the abstract hooks below.
 */
trait CompressionRetention
{
    /**
     * Compress $inputPath into a resolved archive path under $outputPath.
     *
     * @return array{0: string, 1: float} [resolvedOutputPath, ratio]
     */
    private function compressAndMeasure(string $inputPath, string $outputPath): array
    {
        $originalSize = $this->getInputSize($inputPath);

        $this->configureEngine();

        $resolvedOutputPath = $this->resolveArchivePath($inputPath, $outputPath);
        $this->runCompression($inputPath, $resolvedOutputPath);

        if (!is_file($resolvedOutputPath)) {
            $this->throwMissingOutputException($resolvedOutputPath);
        }

        $optimizedSize = (int) filesize($resolvedOutputPath);

        $ratio = $originalSize > 0
            ? max(0.0, round(($originalSize - $optimizedSize) / $originalSize, 4))
            : 0.0;

        return [$resolvedOutputPath, $ratio];
    }

    /**
     * Keep only the $limit most-recent archives in $directory, deleting the rest.
     * Archives are identified via retentionGlobPatterns() and ordered by their
     * last-modification time (newest first).
     *
     * @param string $directory Absolute path to the target directory.
     * @param int    $limit     Number of files to retain (must be >= 0).
     */
    public function cleanDirectory(string $directory, int $limit): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = [];
        foreach ($this->retentionGlobPatterns() as $pattern) {
            $files = array_merge($files, glob($directory . '/' . $pattern) ?: []);
        }

        if (count($files) <= $limit) {
            return;
        }

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $limit) as $file) {
            if (!@unlink($file)) {
                $this->throwRetentionCleanupFailedException(
                    $directory,
                    sprintf('Could not delete "%s": %s', $file, error_get_last()['message'] ?? 'unknown error')
                );
            }
        }
    }

    /** @return string[] Glob patterns (relative to a directory) identifying this format's archives. */
    abstract protected function retentionGlobPatterns(): array;

    /** Resolve the final archive path for $inputPath/$outputPath (format-specific). */
    abstract protected function resolveArchivePath(string $inputPath, string $outputPath): string;

    /** Run the actual compression into $resolvedOutputPath (format-specific engine call). */
    abstract protected function runCompression(string $inputPath, string $resolvedOutputPath): void;

    /** Throw the consuming class's own "engine did not produce an output file" exception. */
    abstract protected function throwMissingOutputException(string $resolvedOutputPath): never;

    /** Throw the consuming class's own "retention cleanup failed" exception. */
    abstract protected function throwRetentionCleanupFailedException(string $directory, string $reason): never;
}
