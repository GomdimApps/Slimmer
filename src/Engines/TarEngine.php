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

    public const SUPPORTED_FORMATS = ['gz', 'zst', 'bz2'];

    /** Valid compression-level range per format: [min, max]. */
    public const LEVEL_RANGES = [
        'gz'  => [1, 9],
        'zst' => [1, 19],
        'bz2' => [1, 9],
    ];

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
     * @param string        $inputPath   Absolute path to the source file or directory.
     * @param string        $outputPath  Absolute path to the archive or target directory.
     * @param string        $format      'gz' (.tar.gz), 'zst' (.tar.zst) or 'bz2' (.tar.bz2).
     * @param string[]      $extraArgs   Additional one-off arguments for this call only.
     * @param callable|null $onProgress  Called with one filename per line of `-v` output as
     *                                   files are added, when given. Leaving this null keeps
     *                                   the command byte-identical to a call without it.
     *
     * @throws TarException
     */
    public function compress(
        string $inputPath,
        string $outputPath,
        string $format = 'gz',
        array $extraArgs = [],
        ?callable $onProgress = null
    ): void {
        $this->validateFormat($format);

        $resolvedOutputPath = $this->resolveOutputPath($inputPath, $outputPath, $format);

        $fileList = null;
        if ($this->ignoreEmptyDirectories && is_dir($inputPath)) {
            $fileList = $this->buildNonEmptyFileList($inputPath);
        }

        $argv = $this->buildArgv($inputPath, $resolvedOutputPath, $format, $extraArgs, $fileList, $onProgress !== null);

        $this->execute($argv, $fileList, $onProgress);
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
        $extension = match ($format) {
            'zst' => '.tar.zst',
            'bz2' => '.tar.bz2',
            default => '.tar.gz',
        };

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
     * @param string        $format      'gz', 'zst' or 'bz2'.
     * @param string[]      $extraArgs   Per-call extra arguments.
     * @param string[]|null $fileList    When not null, file paths are piped to stdin (-T -).
     * @param bool          $verbose     Append -v (one filename per line of output). Defaults to
     *                                   false so existing 4-arg call sites produce identical argv.
     *
     * @return string[]
     */
    public function buildArgv(
        string $inputPath,
        string $outputPath,
        string $format,
        array $extraArgs = [],
        ?array $fileList = null,
        bool $verbose = false
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

        if ($verbose) {
            $argv[] = '-v';
        }

        return $argv;
    }

    /**
     * Extract an archive to $outputDir.
     *
     * @param string        $archivePath     Absolute path to the archive to extract.
     * @param string        $outputDir       Absolute path to extract into (created if missing).
     * @param string        $format          'gz', 'zst' or 'bz2'.
     * @param int           $stripComponents Number of leading path components to strip, like
     *                                       GNU tar's --strip-components (0 = keep full paths).
     * @param string[]      $extraArgs       Additional one-off arguments for this call only.
     * @param callable|null $onProgress      Called with one filename per line of `-v` output.
     *
     * @throws TarException
     */
    public function extract(
        string $archivePath,
        string $outputDir,
        string $format,
        int $stripComponents = 0,
        array $extraArgs = [],
        ?callable $onProgress = null
    ): void {
        $this->validateFormat($format);

        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new TarException("Could not create output directory \"{$outputDir}\".");
        }

        $argv = $this->buildExtractArgv(
            $archivePath,
            $outputDir,
            $format,
            $stripComponents,
            $extraArgs,
            $onProgress !== null
        );

        $this->execute($argv, null, $onProgress, 'extractionFailed');
    }

    /**
     * Build the argv array for extracting an archive.
     *
     * @param string[] $extraArgs Per-call extra arguments.
     *
     * @return string[]
     */
    public function buildExtractArgv(
        string $archivePath,
        string $outputDir,
        string $format,
        int $stripComponents = 0,
        array $extraArgs = [],
        bool $verbose = false
    ): array {
        $argv = [
            $this->binary,
            '--use-compress-program=' . $this->buildDecompressProgram($format),
            '-xf',
            $archivePath,
            '-C',
            $outputDir,
        ];

        if ($stripComponents > 0) {
            $argv[] = "--strip-components={$stripComponents}";
        }

        foreach ($this->excludePatterns as $pattern) {
            $argv[] = '--exclude=' . $pattern;
        }

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        if ($verbose) {
            $argv[] = '-v';
        }

        return $argv;
    }

    /**
     * List the member paths contained in an archive, without extracting it.
     *
     * @return string[] Member paths, one per archive entry, in archive order.
     *
     * @throws TarException
     */
    public function listContents(string $archivePath, string $format): array
    {
        $this->validateFormat($format);

        $argv    = $this->buildListArgv($archivePath, $format);
        $process = new Process($argv);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new TarException('Tar process timed out after ' . $this->timeout . ' seconds.');
        }

        if (!$process->isSuccessful()) {
            throw TarException::listingFailed(
                implode(' ', $argv),
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }

        $lines = preg_split('/\R/', trim($process->getOutput()));

        return $lines === [''] ? [] : $lines;
    }

    /**
     * Build the argv array for listing an archive's contents.
     *
     * @return string[]
     */
    public function buildListArgv(string $archivePath, string $format): array
    {
        return [
            $this->binary,
            '--use-compress-program=' . $this->buildDecompressProgram($format),
            '-tf',
            $archivePath,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the --use-compress-program value for the given format.
     *
     * @throws TarException
     * @throws \InvalidArgumentException If the configured compression level is out of range for $format.
     */
    private function buildCompressProgram(string $format): string
    {
        $this->validateFormat($format);

        [$min, $max] = self::LEVEL_RANGES[$format];
        if ($this->compressionLevel < $min || $this->compressionLevel > $max) {
            throw new \InvalidArgumentException(
                "Compression level {$this->compressionLevel} is out of range for format \"{$format}\" "
                . "(allowed: {$min}-{$max})."
            );
        }

        return match ($format) {
            'gz'  => "gzip -{$this->compressionLevel}",
            'zst' => "zstd -{$this->compressionLevel} -T{$this->threads}",
            'bz2' => "bzip2 -{$this->compressionLevel}",
        };
    }

    /**
     * Build the --use-compress-program value used to decompress (extract/list) the given format.
     * Deliberately separate from buildCompressProgram(): the compression level/thread count are
     * meaningless on the read path, and GNU tar appends -d to this value itself.
     *
     * @throws TarException
     */
    private function buildDecompressProgram(string $format): string
    {
        $this->validateFormat($format);

        return match ($format) {
            'gz'  => 'gzip',
            'zst' => 'zstd',
            'bz2' => 'bzip2',
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
     * Execute the tar subprocess, optionally piping $fileList to stdin and/or
     * streaming each output line to $onProgress.
     *
     * @param string[]      $argv
     * @param string[]|null $fileList
     * @param callable|null $onProgress     Called with one filename per emitted line.
     * @param string        $failureFactory Name of the TarException factory to use on failure
     *                                      ('compressionFailed', 'extractionFailed', ...).
     *
     * @throws TarException
     */
    private function execute(
        array $argv,
        ?array $fileList = null,
        ?callable $onProgress = null,
        string $failureFactory = 'compressionFailed'
    ): void {
        $process = new Process($argv);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        if ($fileList !== null) {
            $process->setInput(implode("\n", $fileList));
        }

        try {
            if ($onProgress !== null) {
                $buffer = '';
                $process->run(function (string $type, string $chunk) use ($onProgress, &$buffer): void {
                    $buffer .= $chunk;
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);
                    foreach ($lines as $line) {
                        if ($line !== '') {
                            $onProgress($line);
                        }
                    }
                });
                if ($buffer !== '') {
                    $onProgress($buffer);
                }
            } else {
                $process->run();
            }
        } catch (ProcessTimedOutException) {
            throw new TarException('Tar process timed out after ' . $this->timeout . ' seconds.');
        }

        if (!$process->isSuccessful()) {
            throw TarException::{$failureFactory}(
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
