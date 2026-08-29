<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers\Utils\Pdf;

/**
 * Translates PdfOptimizer's advanced-compression fluent state into raw Ghostscript flags.
 */
class PdfAdvancedArgsBuilder
{
    /**
     * Build the advanced Ghostscript argv fragment from PdfOptimizer's configured state.
     * Returns [] when nothing advanced has been configured, keeping the default command
     * identical to the pre-advanced-options behavior.
     */
    public static function build(
        ?string $autoRotatePages,
        ?bool $detectDuplicateImages,
        ?int $imageDownsampleDpi,
        string $imageDownsampleType,
        float $imageDownsampleThreshold,
        bool $objectStreamCompression,
        ?int $streamEffort,
        bool $fastWebView
    ): array {
        $args = [];

        if ($autoRotatePages !== null) {
            $args[] = '-dAutoRotatePages=/' . $autoRotatePages;
        }

        if ($detectDuplicateImages !== null) {
            $args[] = '-dDetectDuplicateImages=' . ($detectDuplicateImages ? 'true' : 'false');
        }

        if ($imageDownsampleDpi !== null) {
            foreach (['Color', 'Gray'] as $channel) {
                $args[] = "-dDownsample{$channel}Images=true";
                $args[] = "-d{$channel}ImageResolution=" . $imageDownsampleDpi;
                $args[] = "-d{$channel}ImageDownsampleType=/" . $imageDownsampleType;
                $args[] = "-d{$channel}ImageDownsampleThreshold=" . $imageDownsampleThreshold;
            }
        }

        if ($objectStreamCompression) {
            $args[] = '-dWriteObjStms=true';
            $args[] = '-dWriteXRefStm=true';
        }

        if ($streamEffort !== null) {
            $args[] = '-dStreamEffort=' . $streamEffort;
        }

        if ($fastWebView) {
            $args[] = '-dFastWebView=true';
        }

        return $args;
    }
}
