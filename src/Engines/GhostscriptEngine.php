<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Engines;

use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Traits\Binary;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executes Ghostscript commands as a subprocess using proc_open.
 */
class GhostscriptEngine
{
    use Binary;

    /** Resolved path to Ghostscript binary */
    private string $binary;

    /**
     * @param string $binary Binary name or absolute path.
     * @throws SlimmerException If binary not found.
     */
    public function __construct(string $binary = 'gs')
    {
        $this->binary = $this->resolveBinary($binary);
    }

    /** Timeout for the engine execution in seconds (0 = no timeout) */
    private float $timeout = 0;

    /**
     * Set a timeout for Ghostscript execution.
     */
    public function setTimeout(float $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Get Ghostscript version.
     */
    public function getVersion(): string
    {
        $process = new Process([$this->binary, '--version']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw SlimmerException::engineCommandFailed($this->binary . ' --version', $process->getExitCode() ?? 1);
        }

        return trim($process->getOutput());
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Compress PDF using PDFSETTINGS quality preset.
     * Presets: /screen, /ebook, /printer, /prepress, /default.
     *
     * @throws SlimmerException On command failure.
     */
    public function compress(
        string $inputPath,
        string $outputPath,
        string $pdfSettings = '/ebook',
        string $compatLevel = '1.4',
        array $extraArgs = []
    ): void {
        $argv = $this->buildArgv($inputPath, $outputPath, $pdfSettings, $compatLevel, $extraArgs);

        $this->execute($argv);
    }

    /**
     * Compress Image using Ghostscript.
     *
     * @throws SlimmerException On command failure.
     */
    public function compressImage(
        string $inputPath,
        string $outputPath,
        string $device = 'jpeg',
        int $quality = 75,
        ?string $dimensions = null,
        array $extraArgs = []
    ): void {
        $argv = $this->buildImageArgv($inputPath, $outputPath, $device, $quality, $dimensions, $extraArgs);

        $this->execute($argv);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build argv array for proc_open.
     */
    public function buildArgv(
        string $inputPath,
        string $outputPath,
        string $pdfSettings,
        string $compatLevel,
        array $extraArgs
    ): array {
        $argv = [
            $this->binary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=' . $compatLevel,
            '-dPDFSETTINGS=' . $pdfSettings,
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-sOutputFile=' . $outputPath,
        ];

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        $argv[] = $inputPath;

        return $argv;
    }

    /**
     * Build argv array for proc_open for images.
     */
    public function buildImageArgv(
        string $inputPath,
        string $outputPath,
        string $device,
        int $quality,
        ?string $dimensions,
        array $extraArgs
    ): array {
        $argv = [
            $this->binary,
            '-sDEVICE=' . $device,
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
        ];

        if ($dimensions !== null) {
            $argv[] = '-g' . $dimensions;
        }

        $argv[] = '--permit-file-read=' . $inputPath;
        $argv[] = '-sOutputFile=' . $outputPath;

        if ($device === 'jpeg') {
            $argv[] = '-dJPEGQ=' . $quality;
        }

        foreach ($extraArgs as $arg) {
            $argv[] = $arg;
        }

        $argv[] = 'viewjpeg.ps';
        $argv[] = '-c';
        $argv[] = "({$inputPath}) viewJPEG showpage";

        return $argv;
    }

    /**
     * Execute command via proc_open and capture stderr.
     * @throws SlimmerException
     */
    private function execute(array $argv): void
    {
        $process = new Process($argv);
        
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new SlimmerException('Ghostscript process timed out after ' . $this->timeout . ' seconds.');
        }

        if (!$process->isSuccessful()) {
            throw SlimmerException::engineCommandFailed(
                implode(' ', $argv),
                $process->getExitCode() ?? 1,
                trim($process->getErrorOutput())
            );
        }
    }

}