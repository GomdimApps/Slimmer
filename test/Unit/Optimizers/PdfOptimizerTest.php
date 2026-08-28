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
            ->and($this->optimizer->withExtraArgs('-dColorConversionStrategy=/sRGB'))->toBe($this->optimizer)
            ->and($this->optimizer->withDetectDuplicateImages())->toBe($this->optimizer)
            ->and($this->optimizer->withImageDownsampling(120))->toBe($this->optimizer)
            ->and($this->optimizer->withObjectStreamCompression())->toBe($this->optimizer)
            ->and($this->optimizer->withStreamEffort(9))->toBe($this->optimizer)
            ->and($this->optimizer->withFastWebView())->toBe($this->optimizer)
            ->and($this->optimizer->withAutoRotatePages())->toBe($this->optimizer)
            ->and($this->optimizer->withAggressiveCompression())->toBe($this->optimizer);
    });

    // -------------------------------------------------------------------------
    // Advanced compression options — dryRun() regression & flag assertions
    // -------------------------------------------------------------------------

    it('builds the same default command as before advanced options existed', function () {
        $command = $this->optimizer->dryRun($this->samplePdf, $this->outputPath);

        $expectedSuffix = "-sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH "
            . "-sOutputFile={$this->outputPath} {$this->samplePdf}";

        expect($command)->toEndWith($expectedSuffix);
    });

    it('adds -dDetectDuplicateImages=true when enabled', function () {
        $command = $this->optimizer->withDetectDuplicateImages()->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dDetectDuplicateImages=true');
    });

    it('adds -dDetectDuplicateImages=false when explicitly disabled', function () {
        $command = $this->optimizer->withDetectDuplicateImages(false)->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dDetectDuplicateImages=false');
    });

    it('adds Color/Gray downsampling flags with defaults', function () {
        $command = $this->optimizer->withImageDownsampling(120)->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dDownsampleColorImages=true')
            ->and($command)->toContain('-dColorImageResolution=120')
            ->and($command)->toContain('-dColorImageDownsampleType=/Bicubic')
            ->and($command)->toContain('-dColorImageDownsampleThreshold=1.5')
            ->and($command)->toContain('-dDownsampleGrayImages=true')
            ->and($command)->toContain('-dGrayImageResolution=120')
            ->and($command)->not->toContain('MonoImage');
    });

    it('adds downsampling flags with custom type and threshold', function () {
        $command = $this->optimizer
            ->withImageDownsampling(150, 'Subsample', 2.0)
            ->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dColorImageDownsampleType=/Subsample')
            ->and($command)->toContain('-dColorImageDownsampleThreshold=2');
    });

    it('throws InvalidArgumentException for an invalid downsample DPI', function () {
        expect(fn () => $this->optimizer->withImageDownsampling(0))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an invalid downsample type', function () {
        expect(fn () => $this->optimizer->withImageDownsampling(120, 'Nearest'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an invalid downsample threshold', function () {
        expect(fn () => $this->optimizer->withImageDownsampling(120, 'Bicubic', 0.5))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('adds -dStreamEffort with the given value', function () {
        $command = $this->optimizer->withStreamEffort(9)->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dStreamEffort=9');
    });

    it('throws InvalidArgumentException for stream effort out of range', function () {
        expect(fn () => $this->optimizer->withStreamEffort(0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $this->optimizer->withStreamEffort(10))->toThrow(\InvalidArgumentException::class);
    });

    it('adds -dFastWebView=true when enabled', function () {
        $command = $this->optimizer->withFastWebView()->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dFastWebView=true');
    });

    it('does not add FastWebView to the default command', function () {
        $command = $this->optimizer->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->not->toContain('FastWebView');
    });

    it('adds -dAutoRotatePages=/None by default', function () {
        $command = $this->optimizer->withAutoRotatePages()->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dAutoRotatePages=/None');
    });

    it('throws InvalidArgumentException for an invalid auto-rotate mode', function () {
        expect(fn () => $this->optimizer->withAutoRotatePages('Sideways'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException when enabling object stream compression below compat level 1.5', function () {
        expect(fn () => $this->optimizer->withObjectStreamCompression())
            ->toThrow(\InvalidArgumentException::class, '1.5');
    });

    it('adds object/xref stream flags once compat level is 1.5+', function () {
        $command = $this->optimizer
            ->withCompatibilityLevel('1.5')
            ->withObjectStreamCompression()
            ->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dWriteObjStms=true')
            ->and($command)->toContain('-dWriteXRefStm=true')
            ->and($command)->toContain('-dCompatibilityLevel=1.5');
    });

    it('bundles lossless flags and bumps compat level via withAggressiveCompression()', function () {
        $command = $this->optimizer->withAggressiveCompression()->dryRun($this->samplePdf, $this->outputPath);

        expect($command)->toContain('-dCompatibilityLevel=1.5')
            ->and($command)->toContain('-dWriteObjStms=true')
            ->and($command)->toContain('-dWriteXRefStm=true')
            ->and($command)->toContain('-dDetectDuplicateImages=true')
            ->and($command)->toContain('-dStreamEffort=9')
            ->and($command)->toContain('-dAutoRotatePages=/None')
            ->and($command)->not->toContain('PDFSETTINGS=/screen');
    });

    it('keeps user-supplied extraArgs winning over built-in advanced defaults', function () {
        $command = $this->optimizer
            ->withDetectDuplicateImages(true)
            ->withExtraArgs('-dDetectDuplicateImages=false')
            ->dryRun($this->samplePdf, $this->outputPath);

        expect(strrpos($command, '=false'))->toBeGreaterThan(strrpos($command, '=true'));
    });

    // -------------------------------------------------------------------------
    // Advanced compression options — real compression against sample.pdf
    // -------------------------------------------------------------------------

    it('compresses sample.pdf with duplicate image detection enabled', function () {
        $ratio = $this->optimizer->withDetectDuplicateImages()->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with aggressive image downsampling', function () {
        $ratio = $this->optimizer->withImageDownsampling(72)->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with object stream compression at compat level 1.5', function () {
        $ratio = $this->optimizer
            ->withCompatibilityLevel('1.5')
            ->withObjectStreamCompression()
            ->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();

        $handle = fopen($this->outputPath, 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        expect($header)->toStartWith('%PDF-1.5');
    });

    it('compresses sample.pdf at both extremes of stream effort', function () {
        $fast  = $this->optimizer->withStreamEffort(1)->optimize($this->samplePdf, $this->outputPath);
        expect(is_file($this->outputPath))->toBeTrue()->and($fast)->toBeFloat();

        @unlink($this->outputPath);

        $small = (new PdfOptimizer())->withStreamEffort(9)->optimize($this->samplePdf, $this->outputPath);
        expect(is_file($this->outputPath))->toBeTrue()->and($small)->toBeFloat();
    });

    it('compresses sample.pdf with Fast Web View enabled', function () {
        $ratio = $this->optimizer->withFastWebView()->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with auto-rotate pages disabled', function () {
        $ratio = $this->optimizer->withAutoRotatePages()->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('compresses sample.pdf with withAggressiveCompression()', function () {
        $ratio = $this->optimizer->withAggressiveCompression()->optimize($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and($ratio)->toBeFloat();

        $handle = fopen($this->outputPath, 'rb');
        $header = fread($handle, 5);
        fclose($handle);

        expect($header)->toBe('%PDF-');
    });

    it('throws before touching the filesystem when object stream compression is misconfigured', function () {
        expect(fn () => $this->optimizer->withObjectStreamCompression()->optimize($this->samplePdf, $this->outputPath))
            ->toThrow(\InvalidArgumentException::class);

        expect(is_file($this->outputPath))->toBeFalse();
    });

});
