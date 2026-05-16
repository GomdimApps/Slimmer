<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Engines;

use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Traits\Binary;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executes tar commands as a subprocess using Symfony Process.
 *
 * Supported output formats: .tar.gz (gzip) and .tar.zst (zstd).
 */
class TarEngine
{
    use Binary;

    private const SUPPORTED_FORMATS = ['gz', 'zst'];

    /** Resolved absolute path to the tar binary. */
    private string $binary;

    /** Execution timeout in seconds (0 = no timeout). */
    private float $timeout = 0;

    /** Compression level (1–9 for gz, 1–19 for zst). */
    private int $compressionLevel = 6;

    /** CPU threads for parallel compression (effective for zst only). */
    private int $threads = 1;

    /** Exclusion patterns forwarded to --exclude. Replaces on each call to withExclude(). */
    private array $excludePatterns = [];

    /** Whether to skip empty directories when building the archive. */
    private bool $ignoreEmptyDirectories = false;

    /** Custom CLI arguments injected before the input path. Replaces on each call to withCustomArgs(). */
    private array $customArgs = [];

    /**
     * @param string $binary Binary name or absolute path.
     * @throws TarException If the binary is not found or not executable.
     */
    public function __construct(string $binary = 'tar')
    {
        $this->binary = $this->resolveBinary($binary);
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set the execution timeout in seconds (0 = no timeout).
     */
    public function setTimeout(float $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the compression level.
     * Replaces the previously configured value.
     */
    public function withCompressionLevel(int $level): static
    {
        $this->compressionLevel = $level;

        return $this;
    }

    /**
     * Set the number of CPU threads for parallel compression (zst only).
     * Replaces the previously configured value.
     */
    public function withThreads(int $threads): static
    {
        $this->threads = max(1, $threads);

        return $this;
    }

    /**
     * Set exclusion patterns (replaces the current set).
     * Each pattern is forwarded as a --exclude=<pattern> argument to tar.
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = $patterns;

        return $this;
    }

    /**
     * Set whether empty directories should be omitted from the archive.
     */
    public function withIgnoreEmptyDirectories(bool $ignore = true): static
    {
        $this->ignoreEmptyDirectories = $ignore;

        return $this;
    }

    /**
     * Set additional custom CLI arguments (replaces the current set).
     * They are injected after any exclusion patterns, before the input path.
     */
    public function withCustomArgs(string ...$args): static
    {
        $this->customArgs = $args;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the version string reported by the tar binary.
     *
     * @throws TarException
     */
    public function getVersion(): string
    {
        $process = new Process([$this->binary, '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw TarException::compressionFailed(
                $this->binary . ' --version',
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }

        return trim($process->getOutput());
    }

    /**
     * Compress a file or directory to the given output archive.
     *
     * When $outputPath does not end with the format's extension (.tar.gz or
     * .tar.zst), a timestamped filename is auto-generated inside that path.
     *
     * @param string   $inputPath  Absolute path to the source file or directory.
     * @param string   $outputPath Absolute path to the archive or target directory.
     * @param string   $format     'gz' (.tar.gz) or 'zst' (.tar.zst).
     * @param string[] $extraArgs  Additional one-off arguments for this call only.
     *
     * @throws TarException
     */
    public function compress(
        string $inputPath,
        string $outputPath,
        string $format = 'gz',
        array $extraArgs = []
    ): void {
        $this->validateFormat($format);

        $resolvedOutputPath = $this->resolveOutputPath($inputPath, $outputPath, $format);

        $fileList = null;
        if ($this->ignoreEmptyDirectories && is_dir($inputPath)) {
            $fileList = $this->buildNonEmptyFileList($inputPath);
        }

        $argv = $this->buildArgv($inputPath, $resolvedOutputPath, $format, $extraArgs, $fileList);

        $this->execute($argv, $fileList);
    }

    /**
     * Resolve the final output path.
     *
     * When $outputPath already ends with the correct extension it is returned as-is.
     * Otherwise a filename is generated as: <input-basename>_<YYYY-mm-dd_His><ext>
     * and appended to $outputPath (treated as a directory).
     *
     * @param string $inputPath  Source path used to derive the base name.
     * @param string $outputPath Desired output path or target directory.
     * @param string $format     'gz' or 'zst'.
     */
    public function resolveOutputPath(string $inputPath, string $outputPath, string $format): string
    {
        $extension = $format === 'zst' ? '.tar.zst' : '.tar.gz';

        if (str_ends_with($outputPath, $extension)) {
            return $outputPath;
        }

        $baseName  = basename(rtrim($inputPath, '/\\'));
        $dateTime  = (new \DateTimeImmutable())->format('Y-m-d_His');
        $generated = "{$baseName}_{$dateTime}{$extension}";

        return rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . $generated;
    }

    /**
     * Build the argv array for the tar subprocess.
     *
     * @param string        $inputPath   Source file or directory.
     * @param string        $outputPath  Already-resolved archive path.
     * @param string        $format      'gz' or 'zst'.
     * @param string[]      $extraArgs   Per-call extra arguments.
     * @param string[]|null $fileList    When not null, file paths are piped to stdin (-T -).
     *
     * @return string[]
     */
    public function buildArgv(
        string $inputPath,
        string $outputPath,
        string $format,
        array $extraArgs = [],
        ?array $fileList = null
    ): array {
        $argv = [
            $this->binary,
            '--use-compress-program=' . $this->buildCompressProgram($format),
            '-cf',
            $outputPath,
        ];

        foreach ($this->excludePatterns as $pattern) {
            $argv[] = '--exclude=' . $pattern;
        }

        foreach ($this->customArgs as $arg) {
            $argv[] = $arg;
        }

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        // -C changes the working directory so paths inside the archive are clean.
        $argv[] = '-C';
        $argv[] = dirname($inputPath);

        if ($fileList !== null) {
            // File paths (relative to dirname($inputPath)) are piped via stdin.
            $argv[] = '-T';
            $argv[] = '-';
        } else {
            $argv[] = basename($inputPath);
        }

        return $argv;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the --use-compress-program value for the given format.
     *
     * @throws TarException
     */
    private function buildCompressProgram(string $format): string
    {
        return match ($format) {
            'gz'  => "gzip -{$this->compressionLevel}",
            'zst' => "zstd -{$this->compressionLevel} -T{$this->threads}",
            default => throw TarException::unsupportedFormat($format),
        };
    }

    /**
     * Build a list of file paths (relative to dirname($inputPath)) that belong
     * to the input directory, skipping any empty subdirectories.
     *
     * @return string[]
     */
    private function buildNonEmptyFileList(string $inputPath): array
    {
        $inputPath = rtrim($inputPath, '/\\');
        $baseName  = basename($inputPath);
        $files     = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($inputPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $relative = $baseName . '/' . substr($item->getPathname(), strlen($inputPath) + 1);
                $files[]  = $relative;
            }
        }

        return $files;
    }

    /**
     * Execute the tar subprocess, optionally piping $fileList to stdin.
     *
     * @param string[]      $argv
     * @param string[]|null $fileList
     *
     * @throws TarException
     */
    private function execute(array $argv, ?array $fileList = null): void
    {
        $process = new Process($argv);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        if ($fileList !== null) {
            $process->setInput(implode("\n", $fileList));
        }

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new TarException('Tar process timed out after ' . $this->timeout . ' seconds.');
        }

        if (!$process->isSuccessful()) {
            throw TarException::compressionFailed(
                implode(' ', $argv),
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }
    }

    /**
     * @throws TarException
     */
    private function validateFormat(string $format): void
    {
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw TarException::unsupportedFormat($format);
        }
    }
}
