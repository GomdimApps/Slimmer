<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Archiver;
use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\ZipException;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;

/**
 * Extracts and lists .zip archives.
 *
 * Mirrors ExtractTar's shape over the separate zip/unzip binaries wrapped by
 * ZipEngine. No format parameter is needed — zip is a single format.
 */
class ExtractZip implements Archiver
{
    use InteractsWithTemporaryInput;

    /** Exclusion patterns forwarded to ZipEngine::withExclude(). */
    private array $excludePatterns = [];

    /** @var callable|null */
    private $onProgress = null;

    public function __construct(private readonly ZipEngine $engine = new ZipEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Append exclusion patterns forwarded as `-x <pattern>` to unzip/zip.
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = array_merge($this->excludePatterns, $patterns);

        return $this;
    }

    /**
     * Receive one filename per line of verbose output as files are extracted.
     */
    public function withProgress(callable $callback): static
    {
        $this->onProgress = $callback;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Archiver contract
    // -------------------------------------------------------------------------

    /**
     * @throws SlimmerException
     * @throws ZipException
     */
    public function extract(?string $inputPath, string $outputDir): array
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputPath($resolvedInputPath);

        $this->engine->extract($resolvedInputPath, $outputDir, $this->buildExtraArgs(), $this->onProgress);

        return $this->collectExtractedFiles($outputDir);
    }

    /**
     * List the member paths contained in the archive, without extracting it.
     *
     * @return string[]
     *
     * @throws SlimmerException
     * @throws ZipException
     */
    public function listContents(?string $inputPath): array
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputPath($resolvedInputPath);

        return $this->engine->listContents($resolvedInputPath);
    }

    /**
     * Return the exact string of the command that would be executed, without starting the process.
     * Performs no filesystem writes.
     */
    public function dryRun(?string $inputPath, string $outputDir): string
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);

        $argv = $this->engine->buildExtractArgv($resolvedInputPath, $outputDir, $this->buildExtraArgs());

        return implode(' ', $argv);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function buildExtraArgs(): array
    {
        $args = [];

        foreach ($this->excludePatterns as $pattern) {
            $args[] = '-x';
            $args[] = $pattern;
        }

        return $args;
    }

    /**
     * Walk $outputDir after a successful extraction and return the absolute
     * paths of every extracted file.
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

    /** @throws SlimmerException */
    private function validateInputPath(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw SlimmerException::inputFileNotFound($path);
        }
    }
}
