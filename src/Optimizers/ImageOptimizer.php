<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;
use GomdimApps\Slimmer\Traits\OptimizationIO;

/**
 * Optimizes Image files (JPG, JPEG, PNG) to PDF using Ghostscript.
 */
class ImageOptimizer implements Optimizer
{
    use InteractsWithTemporaryInput;
    use OptimizationIO;

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

    /**
     * Explicitly set dimensions (e.g. 800, 600).
     */
    public function withDimensions(int $width, int $height): static
    {
        $this->dimensions = "{$width}x{$height}";

        return $this;
    }

    // -------------------------------------------------------------------------
    // Optimizer contract
    // -------------------------------------------------------------------------

    /**
     * Explicitly set string dimensions e.g. "800x600"
     */
    private ?string $dimensions = null;

    /**
     * @throws SlimmerException
     */
    public function optimize(?string $inputPath, string $outputPath): float
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputFile($resolvedInputPath);
        $this->validateOutputDirectory($outputPath);

        $originalSize = filesize($resolvedInputPath);
        
        $dimensions = $this->dimensions;
        if ($dimensions === null) {
            $imageSize = @getimagesize($resolvedInputPath);
            if ($imageSize !== false) {
                $dimensions = $imageSize[0] . 'x' . $imageSize[1];
            }
        }

        $device = $this->determineDevice($outputPath);

        $this->engine->compressImage(
            $resolvedInputPath,
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

    /**
     * Return the exact string of the command that would be executed, without starting the process.
     */
    public function dryRun(?string $inputPath, string $outputPath): string
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        
        $dimensions = $this->dimensions;
        if ($dimensions === null) {
            $imageSize = @getimagesize($resolvedInputPath);
            if ($imageSize !== false) {
                $dimensions = $imageSize[0] . 'x' . $imageSize[1];
            }
        }

        $device = $this->determineDevice($outputPath);

        $argv = $this->engine->buildImageArgv(
            $resolvedInputPath,
            $outputPath,
            $device,
            $this->quality,
            $dimensions,
            $this->extraArgs
        );

        return implode(' ', $argv);
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

}
