<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\ImageOptimizer;

describe('ImageOptimizer', function () {

    beforeEach(function () {
        $this->sampleImage = dirname(__DIR__, 3) . '/document/image.jpg';
        $this->outputPath  = sys_get_temp_dir() . '/slimmer_image_optimizer_test_' . uniqid() . '.jpg';
        $this->optimizer   = new ImageOptimizer();
    });

    afterEach(function () {
        if (is_file($this->outputPath)) {
            @unlink($this->outputPath);
        }
    });

    // -------------------------------------------------------------------------
    // Real compression — uses document/image.jpg
    // -------------------------------------------------------------------------

    it('compresses image.jpg and achieves at least 80% compression ratio', function () {
        // Use a low quality to force an 80%+ file size reduction
        $ratio = $this->optimizer->withQuality(5)->optimize($this->sampleImage, $this->outputPath);

        // Expectation to ensure ratio is within 80% to 95%
        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.80)
            ->and($ratio)->toBeLessThanOrEqual(0.99);
    });

    it('produces a non-empty output file when compressing image.jpg', function () {
        $this->optimizer->optimize($this->sampleImage, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and(filesize($this->outputPath))->toBeGreaterThan(0);
    });

    it('produces a valid JPEG file when compressing image.jpg', function () {
        $this->optimizer->optimize($this->sampleImage, $this->outputPath);

        $handle = fopen($this->outputPath, 'rb');
        $header = fread($handle, 2);
        fclose($handle);

        // JPEG files start with FF D8
        expect(bin2hex($header))->toBe('ffd8');
    });

    it('compresses image.jpg with specific quality', function () {
        $ratio = $this->optimizer->withQuality(60)->optimize($this->sampleImage, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('maintains the original image dimensions after compression', function () {
        $this->optimizer->optimize($this->sampleImage, $this->outputPath);

        $originalDims = getimagesize($this->sampleImage);
        $optimizedDims = getimagesize($this->outputPath);

        expect($optimizedDims[0])->toBe($originalDims[0])
            ->and($optimizedDims[1])->toBe($originalDims[1]);
    });

    it('correlates the quality metric from 90 down to 30 without ruining the image', function () {
        $previousSize = null;

        for ($quality = 90; $quality >= 30; $quality -= 10) {
            $currentOutputPath = sys_get_temp_dir() . '/slimmer_img_test_' . $quality . '_' . uniqid() . '.jpg';
            
            $this->optimizer->withQuality($quality)->optimize($this->sampleImage, $currentOutputPath);
            $currentSize = filesize($currentOutputPath);

            // Ensure the image is valid (not ruined/corrupted)
            $info = @getimagesize($currentOutputPath);
            expect($info)->not->toBeFalse("Quality {$quality} ruined the image structure");

            // Metric validation: Lower quality should result in a smaller file size than the higher quality step
            if ($previousSize !== null) {
                expect($previousSize)->toBeGreaterThan($currentSize, "Quality metric failed: Quality {$quality} should be smaller than previous higher quality step");
            }

            // It shouldn't be suspiciously tiny (e.g. < 10KB) meaning it's completely destroyed
            expect($currentSize)->toBeGreaterThan(10000, "Quality {$quality} ruined the image (file size suspiciously small)");

            $previousSize = $currentSize;
            @unlink($currentOutputPath);
        }
    });

    // -------------------------------------------------------------------------
    // Input / output validation
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the input file does not exist', function () {
        expect(fn () => $this->optimizer->optimize('/nonexistent/image.jpg', $this->outputPath))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the output directory does not exist', function () {
        expect(fn () => $this->optimizer->optimize($this->sampleImage, '/nonexistent_dir/out.jpg'))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API
    // -------------------------------------------------------------------------

    it('accepts all valid quality numbers without throwing', function (int $preset) {
        expect(fn () => $this->optimizer->withQuality($preset))
            ->not->toThrow(\InvalidArgumentException::class);
    })->with([0, 50, 100]);

    it('throws InvalidArgumentException for an out of bounds quality preset', function () {
        expect(fn () => $this->optimizer->withQuality(101))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns the same instance for fluent method chaining', function () {
        expect($this->optimizer->withQuality(80))->toBe($this->optimizer)
            ->and($this->optimizer->withExtraArgs('-dColorConversionStrategy=/sRGB'))->toBe($this->optimizer);
    });

});
