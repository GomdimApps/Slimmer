<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Engines;

use GomdimApps\Slimmer\Exceptions\ZipException;
use GomdimApps\Slimmer\Support\CompressionLevelValidator;
use GomdimApps\Slimmer\Traits\Binary;
use GomdimApps\Slimmer\Traits\ProcessExecution;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executes zip/unzip commands as subprocesses using Symfony Process.
 *
 * Unlike TarEngine (single `tar` binary for create/extract/list), the create
 * path (`zip`) and read path (`unzip`) are genuinely separate binaries that
 * may not both be installed, so each is resolved lazily on first use.
 */
class ZipEngine
{
    use Binary;
    use ProcessExecution;

    /** Valid compression-level range: 0 (store) to 9 (max). */
    public const LEVEL_RANGE = [0, 9];

    private ?string $zipBin = null;
    private ?string $unzipBin = null;

    /** Execution timeout in seconds (0 = no timeout). */
    private float $timeout = 0;

    /** Compression level (0–9). */
    private int $compressionLevel = 6;

    /** Exclusion patterns forwarded to -x. Replaces on each call to withExclude(). */
    private array $excludePatterns = [];

    /** Custom CLI arguments injected before the input path. Replaces on each call to withCustomArgs(). */
    private array $customArgs = [];

    /**
     * @param string $zipBinaryName   Name or absolute path of the `zip` binary.
     * @param string $unzipBinaryName Name or absolute path of the `unzip` binary.
     */
    public function __construct(
        private readonly string $zipBinaryName = 'zip',
        private readonly string $unzipBinaryName = 'unzip',
    ) {
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
     * Set the compression level (0 = store, 9 = max). Replaces the previously configured value.
     *
     * @throws \InvalidArgumentException
     */
    public function withCompressionLevel(int $level): static
    {
        CompressionLevelValidator::assertInRange($level, self::LEVEL_RANGE);

        $this->compressionLevel = $level;

        return $this;
    }

    /**
     * Set exclusion patterns (replaces the current set).
     * Each pattern is forwarded as a `-x <pattern>` argument to zip.
     */
    public function withExclude(string ...$patterns): static
    {
        $this->excludePatterns = $patterns;

        return $this;
    }

    /**
     * Set additional custom CLI arguments (replaces the current set).
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
     * Return the version string reported by the zip binary.
     *
     * @throws ZipException
     */
    public function getVersion(): string
    {
        $process = new Process([$this->zipBinary(), '-v']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw ZipException::compressionFailed(
                $this->zipBinaryName . ' -v',
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }

        return trim($process->getOutput());
    }

    /**
     * Resolve the final output path.
     *
     * When $outputPath already ends with .zip it is returned as-is. Otherwise a
     * filename is generated as: <input-basename>_<YYYY-mm-dd_His>.zip and
     * appended to $outputPath (treated as a directory).
     */
    public function resolveOutputPath(string $inputPath, string $outputPath): string
    {
        if (str_ends_with($outputPath, '.zip')) {
            return $outputPath;
        }

        $baseName  = basename(rtrim($inputPath, '/\\'));
        $dateTime  = (new \DateTimeImmutable())->format('Y-m-d_His');
        $generated = "{$baseName}_{$dateTime}.zip";

        return rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . $generated;
    }

    /**
     * Compress a file or directory to the given output archive.
     *
     * @param string        $inputPath  Absolute path to the source file or directory.
     * @param string        $outputPath Absolute path to the archive (already resolved).
     * @param string[]      $extraArgs  Additional one-off arguments for this call only.
     * @param callable|null $onProgress Called with one filename per line of `adding:` output.
     *
     * @throws ZipException
     */
    public function compress(
        string $inputPath,
        string $outputPath,
        array $extraArgs = [],
        ?callable $onProgress = null
    ): void {
        $argv = $this->buildCompressArgv($inputPath, $outputPath, $extraArgs, $onProgress !== null);

        $this->execute($argv, dirname($inputPath), $onProgress, 'compressionFailed', $this->zipBinary());
    }

    /**
     * Build the argv array for compressing $inputPath into $outputPath.
     * The command is intended to run with cwd = dirname($inputPath) (zip has
     * no built-in chdir-then-add flag, unlike tar's -C).
     *
     * @param string[] $extraArgs
     *
     * @return string[]
     */
    public function buildCompressArgv(
        string $inputPath,
        string $outputPath,
        array $extraArgs = [],
        bool $verbose = false
    ): array {
        $argv = [$this->zipBinaryName];

        if (is_dir($inputPath)) {
            $argv[] = '-r';
        }

        if (!$verbose) {
            $argv[] = '-q';
        }

        $argv[] = '-' . $this->compressionLevel;
        $argv[] = $outputPath;
        $argv[] = basename(rtrim($inputPath, '/\\'));

        foreach ($this->customArgs as $arg) {
            $argv[] = $arg;
        }

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        foreach ($this->excludePatterns as $pattern) {
            $argv[] = '-x';
            $argv[] = $pattern;
        }

        return $argv;
    }

    /**
     * Extract an archive to $outputDir.
     *
     * @param string        $archivePath Absolute path to the archive to extract.
     * @param string        $outputDir   Absolute path to extract into (created if missing).
     * @param string[]      $extraArgs   Additional one-off arguments for this call only.
     * @param callable|null $onProgress  Called with one filename per line of verbose output.
     *
     * @throws ZipException
     */
    public function extract(
        string $archivePath,
        string $outputDir,
        array $extraArgs = [],
        ?callable $onProgress = null
    ): void {
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new ZipException("Could not create output directory \"{$outputDir}\".");
        }

        $argv = $this->buildExtractArgv($archivePath, $outputDir, $extraArgs, $onProgress !== null);

        $this->execute($argv, null, $onProgress, 'extractionFailed', $this->unzipBinary());
    }

    /**
     * Build the argv array for extracting an archive.
     *
     * @param string[] $extraArgs
     *
     * @return string[]
     */
    public function buildExtractArgv(
        string $archivePath,
        string $outputDir,
        array $extraArgs = [],
        bool $verbose = false
    ): array {
        $argv = [$this->unzipBinaryName, '-o'];

        if (!$verbose) {
            $argv[] = '-q';
        }

        $argv[] = $archivePath;

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        $argv[] = '-d';
        $argv[] = $outputDir;

        return $argv;
    }

    /**
     * List the member paths contained in an archive, without extracting it.
     *
     * @return string[]
     *
     * @throws ZipException
     */
    public function listContents(string $archivePath): array
    {
        $argv    = $this->buildListArgv($archivePath);
        $argv[0] = $this->unzipBinary();
        $process = new Process($argv);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ZipException('Zip process timed out after ' . $this->timeout . ' seconds.');
        }

        if (!$process->isSuccessful()) {
            throw ZipException::listingFailed(
                implode(' ', $argv),
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }

        $lines = preg_split('/\R/', trim($process->getOutput()));

        return $lines === [''] ? [] : $lines;
    }

    /**
     * Build the argv array for listing an archive's contents (bare paths via unzip -Z1).
     *
     * @return string[]
     */
    public function buildListArgv(string $archivePath): array
    {
        return [$this->unzipBinaryName, '-Z1', $archivePath];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function zipBinary(): string
    {
        return $this->zipBin ??= $this->resolveBinary($this->zipBinaryName);
    }

    private function unzipBinary(): string
    {
        return $this->unzipBin ??= $this->resolveBinary($this->unzipBinaryName);
    }

    /**
     * Execute a zip/unzip subprocess, streaming each output line to $onProgress when given.
     *
     * @param string[]      $argv
     * @param string|null   $cwd            Working directory for the subprocess, if any.
     * @param callable|null $onProgress     Called with one filename-bearing line per emitted line.
     * @param string        $failureFactory Name of the ZipException factory to use on failure.
     * @param string        $resolvedBinary Resolved binary path, substituted in place of the bare
     *                                      binary name (argv[0]) so the process actually runs the
     *                                      binary this engine already verified exists.
     *
     * @throws ZipException
     */
    private function execute(
        array $argv,
        ?string $cwd,
        ?callable $onProgress,
        string $failureFactory,
        string $resolvedBinary
    ): void {
        $argv[0] = $resolvedBinary;

        $this->runProcess(
            $argv,
            null,
            $cwd,
            $onProgress,
            fn (int $code, string $stderr) => throw ZipException::{$failureFactory}(implode(' ', $argv), $code, $stderr),
            fn () => throw new ZipException('Zip process timed out after ' . $this->timeout . ' seconds.'),
        );
    }
}
