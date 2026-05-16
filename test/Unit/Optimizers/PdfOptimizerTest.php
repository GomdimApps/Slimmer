<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

describe('PdfOptimizer', function () {

    beforeEach(function () {
        $this->samplePdf  = dirname(__DIR__, 3) . '/document/sample.pdf';
        $this->outputPath = sys_get_temp_dir() . '/slimmer_optimizer_test_' . uniqid() . '.pdf';
        $this->optimizer  = new PdfOptimizer();
    });

    afterEach(function () {
        if (is_file($this->outputPath)) {
            @unlink($this->outputPath);
        }
    });

    // -------------------------------------------------------------------------
    // Real compression — uses document/sample.pdf
    // -------------------------------------------------------------------------

    it('compresses sample.pdf with the default ebook preset and returns a float ratio', function () {
        $ratio = $this->optimizer->optimize($this->samplePdf, $this->outputPath);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('produces a non-empty output file when compressing sample.pdf', function () {
        $this->optimizer->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and(filesize($this->outputPath))->toBeGreaterThan(0);
    });

    it('produces a valid PDF file when compressing sample.pdf', function () {
        $this->optimizer->optimize($this->samplePdf, $this->outputPath);

        $handle = fopen($this->outputPath, 'rb');
        $header = fread($handle, 5);
        fclose($handle);

        expect($header)->toBe('%PDF-');
    });

    it('compresses sample.pdf with the /screen preset', function () {
        $ratio = $this->optimizer->withQuality('screen')->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with the /printer preset', function () {
        $ratio = $this->optimizer->withQuality('printer')->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with the /prepress preset', function () {
        $ratio = $this->optimizer->withQuality('prepress')->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with a custom compatibility level', function () {
        $ratio = $this->optimizer
            ->withCompatibilityLevel('1.5')
            ->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with /screen preset and returns a valid ratio', function () {
        $ratio = $this->optimizer->withQuality('screen')->optimize($this->samplePdf, $this->outputPath);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0)
            ->and(is_file($this->outputPath))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // Input / output validation
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the input file does not exist', function () {
        expect(fn () => $this->optimizer->optimize('/nonexistent/file.pdf', $this->outputPath))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the output directory does not exist', function () {
        expect(fn () => $this->optimizer->optimize($this->samplePdf, '/nonexistent_dir/out.pdf'))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API
    // -------------------------------------------------------------------------

    it('accepts all valid quality presets without throwing', function (string $preset) {
        expect(fn () => $this->optimizer->withQuality($preset))
            ->not->toThrow(\InvalidArgumentException::class);
    })->with(['screen', 'ebook', 'printer', 'prepress', 'default']);

    it('throws InvalidArgumentException for an unknown quality preset', function () {
        expect(fn () => $this->optimizer->withQuality('ultra'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns the same instance for fluent method chaining', function () {
        expect($this->optimizer->withQuality('screen'))->toBe($this->optimizer)
            ->and($this->optimizer->withCompatibilityLevel('1.5'))->toBe($this->optimizer)
            ->and($this->optimizer->withExtraArgs('-dColorConversionStrategy=/sRGB'))->toBe($this->optimizer);
    });

});
