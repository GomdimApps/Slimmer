<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\ZipException;
use GomdimApps\Slimmer\Support\CompressionLevelValidator;
use GomdimApps\Slimmer\Traits\CompressionRetention;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;
use GomdimApps\Slimmer\Traits\SourceFiles;

/**
 * Compresses files and directories into .zip archives.
 *
 * Mirrors CompressTar's shape (fluent config, Optimizer contract, retention
 * strategy) over the separate zip/unzip binaries wrapped by ZipEngine.
 */
class CompressZip implements Optimizer
{
    use CompressionRetention;
    use InteractsWithTemporaryInput;
    use SourceFiles;

    /** Compression level (0–9). */
    private int $compressionLevel = 6;

    /** Exclusion patterns forwarded to ZipEngine::withExclude(). */
    private array $excludePatterns = [];

    /** Custom raw CLI arguments forwarded to ZipEngine::withCustomArgs(). */
    private array $customArgs = [];

    /** @var callable|null Called with one filename per line of `adding:` output. */
    private $onProgress = null;

    public function __construct(private readonly ZipEngine $engine = new ZipEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the compression level (0 = store, 9 = max).
     *
     * @throws \InvalidArgumentException
     */
    public function withCompressionLevel(int $level): static
    {
        CompressionLevelValidator::assertInRange($level, ZipEngine::LEVEL_RANGE);

        $this->compressionLevel = $level;

        return $this;
    }

    /**
     * Append exclusion patterns (zip glob syntax).
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = array_merge($this->excludePatterns, $patterns);

        return $this;
    }

    /**
     * Append custom raw CLI arguments for zip.
     */
    public function withCustomArgs(string ...$args): static
    {
        $this->customArgs = array_merge($this->customArgs, $args);

        return $this;
    }

    /**
     * Receive one filename per line of `adding:` output as files are added to the archive.
     */
    public function withProgress(callable $callback): static
    {
        $this->onProgress = $callback;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Optimizer contract
    // -------------------------------------------------------------------------

    /**
     * @throws SlimmerException
     * @throws ZipException
     */
    public function optimize(?string $inputPath, string $outputPath): float
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputPath($resolvedInputPath);
        $this->validateOutputDirectory($outputPath);

        [, $ratio] = $this->compressAndMeasure($resolvedInputPath, $outputPath);

        return $ratio;
    }

    /**
     * Return the exact command string that would be executed, without running it.
     */
    public function dryRun(?string $inputPath, string $outputPath): string
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->configureEngine();

        $resolvedOutputPath = $this->engine->resolveOutputPath($resolvedInputPath, $outputPath);

        $argv = $this->engine->buildCompressArgv($resolvedInputPath, $resolvedOutputPath, $this->buildExtraArgs());

        return implode(' ', $argv);
    }

    // -------------------------------------------------------------------------
    // Retention strategy
    // -------------------------------------------------------------------------

    /**
     * Compress the input, delete the source, then keep only the $limit most-recent
     * archives (ordered by mtime) in the output directory, deleting the older ones.
     *
     * @throws SlimmerException
     * @throws ZipException
     */
    public function compressAndRetain(string $inputPath, string $outputPath, int $limit): float
    {
        $this->validateInputPath($inputPath);
        $this->validateOutputDirectory($outputPath);

        [$resolvedOutputPath, $ratio] = $this->compressAndMeasure($inputPath, $outputPath);

        $this->deleteSource($inputPath);

        $outputDir = is_dir($outputPath) ? $outputPath : dirname($resolvedOutputPath);
        $this->cleanDirectory($outputDir, $limit);

        return $ratio;
    }

    // cleanDirectory(string $directory, int $limit): void — keeps only the $limit most-recent
    // .zip archives (ordered by mtime) — is provided by the CompressionRetention trait, driven
    // by retentionGlobPatterns() and throwRetentionCleanupFailedException() below.

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Configure the injected ZipEngine with the current CompressZip settings.
     * Uses SET semantics on the engine so repeated calls are idempotent.
     */
    private function configureEngine(): void
    {
        $this->engine
            ->withCompressionLevel($this->compressionLevel)
            ->withExclude(...$this->excludePatterns)
            ->withCustomArgs(...$this->customArgs);
    }

    /**
     * @return string[]
     */
    private function buildExtraArgs(): array
    {
        return [];
    }

    /** @throws SlimmerException */
    private function validateInputPath(string $path): void
    {
        if (!is_readable($path) || (!is_file($path) && !is_dir($path))) {
            throw SlimmerException::inputFileNotFound($path);
        }
    }

    /** @throws SlimmerException */
    private function validateOutputDirectory(string $outputPath): void
    {
        $directory = str_ends_with($outputPath, '.zip')
            ? dirname($outputPath)
            : rtrim($outputPath, '/\\');

        if (!is_dir($directory) || !is_writable($directory)) {
            throw SlimmerException::outputDirectoryNotWritable($directory);
        }
    }

    // -------------------------------------------------------------------------
    // CompressionRetention / SourceFiles hooks
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected function retentionGlobPatterns(): array
    {
        return ['*.zip'];
    }

    protected function resolveArchivePath(string $inputPath, string $outputPath): string
    {
        return $this->engine->resolveOutputPath($inputPath, $outputPath);
    }

    protected function runCompression(string $inputPath, string $resolvedOutputPath): void
    {
        $this->engine->compress($inputPath, $resolvedOutputPath, $this->buildExtraArgs(), $this->onProgress);
    }

    protected function throwMissingOutputException(string $resolvedOutputPath): never
    {
        throw new ZipException("Zip did not produce an output file at \"{$resolvedOutputPath}\".");
    }

    protected function throwRetentionCleanupFailedException(string $directory, string $reason): never
    {
        throw ZipException::retentionCleanupFailed($directory, $reason);
    }

    protected function throwDeletionFailedException(string $path, string $reason): never
    {
        throw ZipException::deletionFailed($path, $reason);
    }
}
