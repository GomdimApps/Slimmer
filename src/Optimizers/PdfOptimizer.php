<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;

/**
 * Optimizes PDF files using Ghostscript.
 */
class PdfOptimizer implements Optimizer
{
    /** Ghostscript PDFSETTINGS preset */
    private string $quality = 'ebook';

    /** PDF compatibility level (-dCompatibilityLevel) */
    private string $compatibilityLevel = '1.4';

    /** Additional raw Ghostscript arguments */
    private array $extraArgs = [];

    public function __construct(private readonly GhostscriptEngine $engine = new GhostscriptEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set quality preset: screen, ebook, printer, prepress, default.
     * @throws \InvalidArgumentException
     */
    public function withQuality(string $preset): static
    {
        $allowed = ['screen', 'ebook', 'printer', 'prepress', 'default'];

        if (!in_array($preset, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Unknown quality preset \"{$preset}\". Allowed: " . implode(', ', $allowed) . '.'
            );
        }

        $this->quality = $preset;

        return $this;
    }

    /** Set PDF compatibility level (e.g. '1.4') */
    public function withCompatibilityLevel(string $level): static
    {
        $this->compatibilityLevel = $level;

        return $this;
    }

    /** Append extra Ghostscript arguments */
    public function withExtraArgs(string ...$args): static
    {
        $this->extraArgs = array_merge($this->extraArgs, $args);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Optimizer contract
    // -------------------------------------------------------------------------

    /**
     * @throws SlimmerException
     */
    public function optimize(string $inputPath, string $outputPath): float
    {
        $this->validateInputFile($inputPath);
        $this->validateOutputDirectory($outputPath);

        $originalSize = filesize($inputPath);

        $this->engine->compress(
            $inputPath,
            $outputPath,
            $this->buildPdfSettings(),
            $this->compatibilityLevel,
            $this->extraArgs
        );

        if (!is_file($outputPath)) {
            throw new SlimmerException(
                "Ghostscript did not produce an output file at \"{$outputPath}\"."
            );
        }

        $optimizedSize = filesize($outputPath);

        if ($originalSize === 0 || $originalSize === false || $optimizedSize === false) {
            return 0.0;
        }

        $ratio = ($originalSize - $optimizedSize) / $originalSize;

        return max(0.0, round($ratio, 4));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Build full PDFSETTINGS with leading slash */
    private function buildPdfSettings(): string
    {
        return '/' . $this->quality;
    }

    /** @throws SlimmerException */
    private function validateInputFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw SlimmerException::inputFileNotFound($path);
        }
    }

    /** @throws SlimmerException */
    private function validateOutputDirectory(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw SlimmerException::outputDirectoryNotWritable($directory);
        }
    }
}