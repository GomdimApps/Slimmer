<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers;

use GomdimApps\Slimmer\Contracts\Optimizer;
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\Utils\Pdf\PdfAdvancedArgsBuilder;
use GomdimApps\Slimmer\Traits\InteractsWithTemporaryInput;
use GomdimApps\Slimmer\Traits\OptimizationIO;

/**
 * Optimizes PDF files using Ghostscript.
 */
class PdfOptimizer implements Optimizer
{
    use InteractsWithTemporaryInput;
    use OptimizationIO;

    /** Allowed values for withQuality() */
    private const QUALITY_PRESETS = ['screen', 'ebook', 'printer', 'prepress', 'default'];

    /** Allowed values for withImageDownsampling()'s $type */
    private const DOWNSAMPLE_TYPES = ['Subsample', 'Average', 'Bicubic'];

    /** Allowed values for withAutoRotatePages()'s $mode */
    private const AUTO_ROTATE_MODES = ['None', 'All', 'PageByPage'];

    /** Ghostscript PDFSETTINGS preset */
    private string $quality = 'ebook';

    /** PDF compatibility level (-dCompatibilityLevel) */
    private string $compatibilityLevel = '1.4';

    /** Additional raw Ghostscript arguments */
    private array $extraArgs = [];

    /** -dDetectDuplicateImages, null = leave Ghostscript's own default */
    private ?bool $detectDuplicateImages = null;

    /** Target DPI for Color/Gray image downsampling, null = disabled */
    private ?int $imageDownsampleDpi = null;

    /** -d{Color,Gray}ImageDownsampleType */
    private string $imageDownsampleType = 'Bicubic';

    /** -d{Color,Gray}ImageDownsampleThreshold */
    private float $imageDownsampleThreshold = 1.5;

    /** -dWriteObjStms / -dWriteXRefStm */
    private bool $objectStreamCompression = false;

    /** -dStreamEffort, null = leave Ghostscript's own default (5) */
    private ?int $streamEffort = null;

    /** -dFastWebView */
    private bool $fastWebView = false;

    /** -dAutoRotatePages, null = leave Ghostscript's own default */
    private ?string $autoRotatePages = null;

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
        if (!in_array($preset, self::QUALITY_PRESETS, true)) {
            throw new \InvalidArgumentException(
                "Unknown quality preset \"{$preset}\". Allowed: " . implode(', ', self::QUALITY_PRESETS) . '.'
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

    /**
     * Deduplicate identical embedded images (e.g. repeated logos/letterheads) with no quality loss.
     */
    public function withDetectDuplicateImages(bool $enabled = true): static
    {
        $this->detectDuplicateImages = $enabled;

        return $this;
    }

    /**
     * Downsample Color and Gray images to a target DPI. Mono images are left untouched
     * (usually scanned text/line-art, where DPI cuts hurt legibility).
     *
     * @throws \InvalidArgumentException
     */
    public function withImageDownsampling(int $dpi, string $type = 'Bicubic', float $threshold = 1.5): static
    {
        if ($dpi < 1) {
            throw new \InvalidArgumentException("Image downsample DPI must be >= 1, got {$dpi}.");
        }

        if (!in_array($type, self::DOWNSAMPLE_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown downsample type \"{$type}\". Allowed: " . implode(', ', self::DOWNSAMPLE_TYPES) . '.'
            );
        }

        if ($threshold < 1.0) {
            throw new \InvalidArgumentException("Image downsample threshold must be >= 1.0, got {$threshold}.");
        }

        $this->imageDownsampleDpi       = $dpi;
        $this->imageDownsampleType      = $type;
        $this->imageDownsampleThreshold = $threshold;

        return $this;
    }

    /**
     * Enable compressed object/cross-reference streams (-dWriteObjStms / -dWriteXRefStm).
     * Requires a PDF compatibility level of 1.5 or higher.
     *
     * @throws \InvalidArgumentException If the current compatibility level is below 1.5.
     */
    public function withObjectStreamCompression(bool $enabled = true): static
    {
        if ($enabled && (float) $this->compatibilityLevel < 1.5) {
            throw new \InvalidArgumentException(
                'Object/cross-reference stream compression requires a PDF compatibility level of 1.5 or higher '
                . "(current: \"{$this->compatibilityLevel}\"). Call withCompatibilityLevel('1.5') before "
                . 'withObjectStreamCompression().'
            );
        }

        $this->objectStreamCompression = $enabled;

        return $this;
    }

    /**
     * Set the stream compression effort (-dStreamEffort). Lower values (1-3) trade size for speed;
     * higher values (7-9) trade speed for size. Ghostscript's own default is 5.
     *
     * @throws \InvalidArgumentException
     */
    public function withStreamEffort(int $effort): static
    {
        if ($effort < 1 || $effort > 9) {
            throw new \InvalidArgumentException("Stream effort must be between 1 and 9, got {$effort}.");
        }

        $this->streamEffort = $effort;

        return $this;
    }

    /**
     * Enable Fast Web View (linearization) for progressive rendering while downloading.
     * This optimizes for network delivery, not file size — it can slightly increase output size.
     */
    public function withFastWebView(bool $enabled = true): static
    {
        $this->fastWebView = $enabled;

        return $this;
    }

    /**
     * Set page auto-rotation behavior (-dAutoRotatePages). Defaults to 'None' when called,
     * guarding against Ghostscript's heuristic rotating image-only pages sideways/upside-down.
     *
     * @throws \InvalidArgumentException
     */
    public function withAutoRotatePages(string $mode = 'None'): static
    {
        if (!in_array($mode, self::AUTO_ROTATE_MODES, true)) {
            throw new \InvalidArgumentException(
                "Unknown auto-rotate mode \"{$mode}\". Allowed: " . implode(', ', self::AUTO_ROTATE_MODES) . '.'
            );
        }

        $this->autoRotatePages = $mode;

        return $this;
    }

    /**
     * Convenience bundle of lossless/structural compression improvements: object stream
     * compression (bumping compatibility level to 1.5 if needed), duplicate image detection,
     * maximum stream effort, and safe page auto-rotation. Does NOT touch quality/DPI — combine
     * with withQuality('screen') or withImageDownsampling() for lossy size reduction.
     */
    public function withAggressiveCompression(): static
    {
        if ((float) $this->compatibilityLevel < 1.5) {
            $this->compatibilityLevel = '1.5';
        }

        $this->withObjectStreamCompression();
        $this->withDetectDuplicateImages(true);
        $this->withStreamEffort(9);
        $this->withAutoRotatePages('None');

        return $this;
    }

    // -------------------------------------------------------------------------
    // Optimizer contract
    // -------------------------------------------------------------------------

    /**
     * @throws SlimmerException
     */
    public function optimize(?string $inputPath, string $outputPath): float
    {
        $resolvedInputPath = $this->resolveInputPath($inputPath);
        $this->validateInputFile($resolvedInputPath);
        $this->validateOutputDirectory($outputPath);

        $originalSize = filesize($resolvedInputPath);

        $this->engine->compress(
            $resolvedInputPath,
            $outputPath,
            $this->buildPdfSettings(),
            $this->compatibilityLevel,
            [...$this->buildAdvancedArgs(), ...$this->extraArgs]
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

        $argv = $this->engine->buildArgv(
            $resolvedInputPath,
            $outputPath,
            $this->buildPdfSettings(),
            $this->compatibilityLevel,
            [...$this->buildAdvancedArgs(), ...$this->extraArgs]
        );

        return implode(' ', $argv);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Build full PDFSETTINGS with leading slash */
    private function buildPdfSettings(): string
    {
        return '/' . $this->quality;
    }

    /**
     * Translate the advanced-compression fluent state into raw Ghostscript flags.
     * Delegates to PdfAdvancedArgsBuilder so the translation logic lives outside this class.
     */
    private function buildAdvancedArgs(): array
    {
        return PdfAdvancedArgsBuilder::build(
            $this->autoRotatePages,
            $this->detectDuplicateImages,
            $this->imageDownsampleDpi,
            $this->imageDownsampleType,
            $this->imageDownsampleThreshold,
            $this->objectStreamCompression,
            $this->streamEffort,
            $this->fastWebView
        );
    }

}