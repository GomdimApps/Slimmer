<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Archiver;
use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\Utils\Tar\TarFormatDetector;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;

/**
 * Extracts and lists .tar.gz / .tar.zst / .tar.bz2 archives.
 *
 * Kept separate from CompressTar: extraction has no size-reduction ratio to
 * report, so it does not fit the Optimizer contract and instead implements
 * the lighter Archiver contract.
 */
class ExtractTar implements Archiver
{
    use InteractsWithTemporaryInput;

    /** Explicit format, or null to auto-detect via TarFormatDetector. */
    private ?string $format = null;

    /** Number of leading path components to strip on extraction. */
    private int $stripComponents = 0;

    /** Exclusion patterns forwarded to TarEngine::withExclude(). */
    private array $excludePatterns = [];

    /** @var callable|null */
    private $onProgress = null;

    public function __construct(private readonly TarEngine $engine = new TarEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the archive format explicitly, skipping auto-detection.
     *
     * @throws \InvalidArgumentException
     */
    public function withFormat(string $format): static
    {
        if (!in_array($format, TarEngine::SUPPORTED_FORMATS, true)) {
            throw new \InvalidArgumentException(
                "Unknown format \"{$format}\". Allowed: " . implode(', ', TarEngine::SUPPORTED_FORMATS) . '.'
            );
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Strip this many leading path components from extracted paths
     * (mirrors GNU tar's --strip-components).
     *
     * @throws \InvalidArgumentException
     */
    public function withStripComponents(int $count): static
    {
        if ($count < 0) {
            throw new \InvalidArgumentException("Strip components count must be >= 0, got {$count}.");
        }

        $this->stripComponents = $count;

        return $this;
    }

    /**
     * Append exclusion patterns forwarded as --exclude=<pattern> to tar.
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = array_merge($this->excludePatterns, $patterns);

        return $this;
    }

    /**
     * Receive one filename per line of `-v` output as files are extracted.
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
     * @throws TarException
     */
    public function extract(?string $inputPath, string $outputDir): array
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputPath($resolvedInputPath);

        $format = $this->resolveFormat($resolvedInputPath);
        $this->configureEngine();

        $this->engine->extract(
            $resolvedInputPath,
            $outputDir,
            $format,
            $this->stripComponents,
            [],
            $this->onProgress
        );

        return $this->collectExtractedFiles($outputDir);
    }

    /**
     * List the member paths contained in the archive, without extracting it.
     *
     * @return string[]
     *
     * @throws SlimmerException
     * @throws TarException
     */
    public function listContents(?string $inputPath): array
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputPath($resolvedInputPath);

        $format = $this->resolveFormat($resolvedInputPath);

        return $this->engine->listContents($resolvedInputPath, $format);
    }

    /**
     * Return the exact string of the command that would be executed, without starting the process.
     * Performs no filesystem writes.
     */
    public function dryRun(?string $inputPath, string $outputDir): string
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $format = $this->resolveFormat($resolvedInputPath);
        $this->configureEngine();

        $argv = $this->engine->buildExtractArgv($resolvedInputPath, $outputDir, $format, $this->stripComponents);

        return implode(' ', $argv);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the format explicitly set via withFormat(), or auto-detect it.
     *
     * @throws TarException
     */
    private function resolveFormat(string $path): string
    {
        if ($this->format !== null) {
            return $this->format;
        }

        $detected = TarFormatDetector::detect($path);

        if ($detected === null) {
            throw TarException::formatDetectionFailed($path);
        }

        return $detected;
    }

    /**
     * Configure the injected TarEngine with the current ExtractTar settings.
     * Uses SET semantics on the engine so repeated calls are idempotent.
     */
    private function configureEngine(): void
    {
        $this->engine->withExclude(...$this->excludePatterns);
    }

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

    /** @throws SlimmerException */
    private function validateInputPath(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw SlimmerException::inputFileNotFound($path);
        }
    }
}
