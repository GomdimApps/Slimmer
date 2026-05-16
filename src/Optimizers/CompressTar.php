<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
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

    public function __construct(private readonly TarEngine $engine = new TarEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the archive format.
     *
     * @param string $format 'gz' (.tar.gz) or 'zst' (.tar.zst)
     * @throws \InvalidArgumentException
     */
    public function withFormat(string $format): static
    {
        if (!in_array($format, ['gz', 'zst'], true)) {
            throw new \InvalidArgumentException(
                "Unknown format \"{$format}\". Allowed: gz, zst."
            );
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Set the compression level.
     */
    public function withCompressionLevel(int $level): static
    {
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

        $originalSize = $this->getInputSize($resolvedInputPath);

        $this->configureEngine();

        $resolvedOutputPath = $this->engine->resolveOutputPath($resolvedInputPath, $outputPath, $this->format);

        $this->engine->compress($resolvedInputPath, $resolvedOutputPath, $this->format, $this->buildExtraArgs());

        if (!is_file($resolvedOutputPath)) {
            throw new TarException(
                "Tar did not produce an output file at \"{$resolvedOutputPath}\"."
            );
        }

        $optimizedSize = (int) filesize($resolvedOutputPath);

        if ($originalSize === 0) {
            return 0.0;
        }

        return max(0.0, round(($originalSize - $optimizedSize) / $originalSize, 4));
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

        $originalSize = $this->getInputSize($inputPath);

        $this->configureEngine();

        $resolvedOutputPath = $this->engine->resolveOutputPath($inputPath, $outputPath, $this->format);

        $this->engine->compress($inputPath, $resolvedOutputPath, $this->format, $this->buildExtraArgs());

        if (!is_file($resolvedOutputPath)) {
            throw new TarException(
                "Tar did not produce an output file at \"{$resolvedOutputPath}\"."
            );
        }

        $optimizedSize = (int) filesize($resolvedOutputPath);

        $ratio = $originalSize > 0
            ? max(0.0, round(($originalSize - $optimizedSize) / $originalSize, 4))
            : 0.0;

        $this->deleteSource($inputPath);

        $outputDir = is_dir($outputPath) ? $outputPath : dirname($resolvedOutputPath);
        $this->cleanDirectory($outputDir, $limit);

        return $ratio;
    }

    /**
     * Keep only the $limit most-recent archives in $directory, deleting the rest.
     *
     * Archives are identified by the extensions .tar.gz and .tar.zst and are
     * ordered by their last-modification time (newest first).
     *
     * @param string $directory Absolute path to the target directory.
     * @param int    $limit     Number of files to retain (must be >= 0).
     *
     * @throws TarException On deletion failure.
     */
    public function cleanDirectory(string $directory, int $limit): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_merge(
            glob($directory . '/*.tar.gz')  ?: [],
            glob($directory . '/*.tar.zst') ?: []
        );

        if (count($files) <= $limit) {
            return;
        }

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $limit) as $file) {
            if (!@unlink($file)) {
                throw TarException::retentionCleanupFailed(
                    $directory,
                    sprintf('Could not delete "%s": %s', $file, error_get_last()['message'] ?? 'unknown error')
                );
            }
        }
    }

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
     *
     * @return string[]
     */
    private function buildExtraArgs(): array
    {
        $args = [];

        if ($this->preservePermissions) {
            $args[] = '-p';
        }

        return $args;
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
        $extension = $this->format === 'zst' ? '.tar.zst' : '.tar.gz';
        $directory = str_ends_with($outputPath, $extension)
            ? dirname($outputPath)
            : rtrim($outputPath, '/\\');

        if (!is_dir($directory) || !is_writable($directory)) {
            throw SlimmerException::outputDirectoryNotWritable($directory);
        }
    }
}
