<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;

/**
 * Optimizes Image files (JPG, JPEG, PNG) to PDF using Ghostscript.
 */
class ImageOptimizer implements Optimizer
{
    /** JPEG Quality (0-100) */
    private int $quality = 75;

    /** Additional raw Ghostscript arguments */
    private array $extraArgs = [];

    public function __construct(private readonly GhostscriptEngine $engine = new GhostscriptEngine())
    {
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Set image quality (0-100).
     * @throws \InvalidArgumentException
     */
    public function withQuality(int $quality): static
    {
        if ($quality < 0 || $quality > 100) {
            throw new \InvalidArgumentException("Quality must be between 0 and 100.");
        }

        $this->quality = $quality;

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
        
        $dimensions = null;
        $imageSize = @getimagesize($inputPath);
        if ($imageSize !== false) {
            $dimensions = $imageSize[0] . 'x' . $imageSize[1];
        }

        $device = $this->determineDevice($outputPath);

        $this->engine->compressImage(
            $inputPath,
            $outputPath,
            $device,
            $this->quality,
            $dimensions,
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

    private function determineDevice(string $outputPath): string
    {
        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        
        return match ($ext) {
            'png' => 'png16m',
            default => 'jpeg',
        };
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
