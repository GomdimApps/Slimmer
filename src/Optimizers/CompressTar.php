<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\Utils\Tar\TarArgsBuilder;
use GomdimApps\Slimmer\Support\CompressionLevelValidator;
use GomdimApps\Slimmer\Traits\CompressionRetention;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;
use GomdimApps\Slimmer\Traits\SourceFiles;

/**
 * Compresses files and directories into tar archives (.tar.gz or .tar.zst).
 *
 * Provides a fluent API for format, compression level, threads, exclusion
 * filters and convenience flags, plus retention strategies that compress,
 * optionally delete the source and keep only the N most-recent archives in a
 * target directory.
 */
class CompressTar implements Optimizer
{
    use CompressionRetention;
    use InteractsWithTemporaryInput;
    use SourceFiles;

    /** Archive format: 'gz' (.tar.gz) or 'zst' (.tar.zst). */
    private string $format = 'gz';

    /** Compression level (1–9 for gz, 1–19 for zst). */
    private int $compressionLevel = 6;

    /** CPU threads for parallel compression (effective for zst only). */
    private int $threads = 1;

    /** Exclusion patterns forwarded to TarEngine::withExclude(). */
    private array $excludePatterns = [];

    /** Custom raw CLI arguments forwarded to TarEngine::withCustomArgs(). */
    private array $customArgs = [];

    /** Whether to preserve file permissions (-p flag). */
    private bool $preservePermissions = false;

    /** Whether to skip empty directories when building the archive. */
    private bool $ignoreEmptyDirs = false;

    /** @var callable|null Called with one filename per line of `-v` output as files are added. */
    private $onProgress = null;

    public function __construct(private readonly TarEngine $engine = new TarEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the archive format.
     *
     * @param string $format 'gz' (.tar.gz), 'zst' (.tar.zst) or 'bz2' (.tar.bz2)
     * @throws \InvalidArgumentException
     */
    public function withFormat(string $format): static
    {
        if (!in_array($format, TarEngine::SUPPORTED_FORMATS, true)) {
            throw new \InvalidArgumentException(
                "Unknown format \"{$format}\". Allowed: " . implode(', ', TarEngine::SUPPORTED_FORMATS) . '.'
            );
        }

        $this->assertLevelInRange($this->compressionLevel, $format);

        $this->format = $format;

        return $this;
    }

    /**
     * Set the compression level. Validated against the currently configured format's range.
     *
     * @throws \InvalidArgumentException
     */
    public function withCompressionLevel(int $level): static
    {
        $this->assertLevelInRange($level, $this->format);

        $this->compressionLevel = $level;

        return $this;
    }

    /**
     * Set the maximum number of CPU threads (effective for zst only).
     */
    public function withThreads(int $threads): static
    {
        $this->threads = max(1, $threads);

        return $this;
    }

    /**
     * Append exclusion patterns (extensions, file names or directory names).
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = array_merge($this->excludePatterns, $patterns);

        return $this;
    }

    /**
     * Append custom raw CLI arguments for tar.
     */
    public function withCustomArgs(string ...$args): static
    {
        $this->customArgs = array_merge($this->customArgs, $args);

        return $this;
    }

    /**
     * Enable the -p flag to preserve file permissions in the archive.
     */
    public function preservePermissions(): static
    {
        $this->preservePermissions = true;

        return $this;
    }

    /**
     * Omit empty directories from the archive.
     */
    public function ignoreEmptyDirectories(): static
    {
        $this->ignoreEmptyDirs = true;

        return $this;
    }

    /**
     * Receive one filename per line of `-v` output as files are added to the archive.
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
     * Compress the input and write the archive to $outputPath.
     *
     * When $outputPath does not end with the correct extension, a timestamped
     * filename is auto-generated inside that directory.
     *
     * @param  string|null $inputPath  Absolute path to the source, or null when
     *                                 using fromStream() / fromString().
     * @param  string      $outputPath Absolute path to the archive or target directory.
     *
     * @return float Compression ratio achieved (bytes saved / original size), 0.0–1.0.
     *
     * @throws SlimmerException
     * @throws TarException
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
        $resolvedInputPath  = $this->resolveInputPath($inputPath);
        $this->configureEngine();

        $resolvedOutputPath = $this->engine->resolveOutputPath($resolvedInputPath, $outputPath, $this->format);

        $argv = $this->engine->buildArgv(
            $resolvedInputPath,
            $resolvedOutputPath,
            $this->format,
            $this->buildExtraArgs()
        );

        return implode(' ', $argv);
    }

    // -------------------------------------------------------------------------
    // Retention strategies
    // -------------------------------------------------------------------------

    /**
     * Compress the input, delete the source, then keep only the $limit most-recent
     * archives (ordered by mtime) in the output directory, deleting the older ones.
     *
     * @param string $inputPath  Absolute path to the source file or directory.
     * @param string $outputPath Absolute path to the archive or target directory.
     * @param int    $limit      Number of recent archives to retain.
     *
     * @return float Compression ratio achieved.
     *
     * @throws SlimmerException
     * @throws TarException
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
    // archives (.tar.gz/.tar.zst/.tar.bz2, ordered by mtime) — is provided by the
    // CompressionRetention trait, driven by retentionGlobPatterns() and
    // throwRetentionCleanupFailedException() below.

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Configure the injected TarEngine with the current CompressTar settings.
     * Uses SET semantics on the engine so repeated calls are idempotent.
     */
    private function configureEngine(): void
    {
        $this->engine
            ->withCompressionLevel($this->compressionLevel)
            ->withThreads($this->threads)
            ->withIgnoreEmptyDirectories($this->ignoreEmptyDirs)
            ->withExclude(...$this->excludePatterns)
            ->withCustomArgs(...$this->customArgs);
    }

    /**
     * Build the per-call extra arguments derived from active flags.
     * Delegates to TarArgsBuilder so the translation logic lives outside this class.
     *
     * @return string[]
     */
    private function buildExtraArgs(): array
    {
        return TarArgsBuilder::build($this->preservePermissions);
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
        $extension = TarEngine::EXTENSIONS[$this->format] ?? '.tar.gz';
        $directory = str_ends_with($outputPath, $extension)
            ? dirname($outputPath)
            : rtrim($outputPath, '/\\');

        if (!is_dir($directory) || !is_writable($directory)) {
            throw SlimmerException::outputDirectoryNotWritable($directory);
        }
    }

    /** @throws \InvalidArgumentException */
    private function assertLevelInRange(int $level, string $format): void
    {
        $range = TarEngine::LEVEL_RANGES[$format] ?? null;

        if ($range === null) {
            return;
        }

        CompressionLevelValidator::assertInRange($level, $range, $format);
    }

    // -------------------------------------------------------------------------
    // CompressionRetention / SourceFiles hooks
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected function retentionGlobPatterns(): array
    {
        return ['*.tar.gz', '*.tar.zst', '*.tar.bz2'];
    }

    protected function resolveArchivePath(string $inputPath, string $outputPath): string
    {
        return $this->engine->resolveOutputPath($inputPath, $outputPath, $this->format);
    }

    protected function runCompression(string $inputPath, string $resolvedOutputPath): void
    {
        $this->engine->compress(
            $inputPath,
            $resolvedOutputPath,
            $this->format,
            $this->buildExtraArgs(),
            $this->onProgress
        );
    }

    protected function throwMissingOutputException(string $resolvedOutputPath): never
    {
        throw new TarException("Tar did not produce an output file at \"{$resolvedOutputPath}\".");
    }

    protected function throwRetentionCleanupFailedException(string $directory, string $reason): never
    {
        throw TarException::retentionCleanupFailed($directory, $reason);
    }

    protected function throwDeletionFailedException(string $path, string $reason): never
    {
        throw TarException::deletionFailed($path, $reason);
    }
}
