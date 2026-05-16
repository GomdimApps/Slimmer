<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;

describe('GhostscriptEngine', function () {

    beforeEach(function () {
        $this->samplePdf  = dirname(__DIR__, 3) . '/document/sample.pdf';
        $this->outputPath = sys_get_temp_dir() . '/slimmer_engine_test_' . uniqid() . '.pdf';
        $this->engine     = new GhostscriptEngine();
    });

    afterEach(function () {
        if (is_file($this->outputPath)) {
            @unlink($this->outputPath);
        }
    });

    // -------------------------------------------------------------------------
    // Real compression — uses document/sample.pdf
    // -------------------------------------------------------------------------

    it('compresses sample.pdf and produces an output file', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and(filesize($this->outputPath))->toBeGreaterThan(0);
    });

    it('produces a valid PDF header after compressing sample.pdf', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath);

        $handle = fopen($this->outputPath, 'rb');
        $header = fread($handle, 5);
        fclose($handle);

        expect($header)->toBe('%PDF-');
    });

    it('compresses sample.pdf with the /screen preset', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath, '/screen');

        expect(is_file($this->outputPath))->toBeTrue();
    });

    it('compresses sample.pdf with the /printer preset', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath, '/printer');

        expect(is_file($this->outputPath))->toBeTrue();
    });

    it('compresses sample.pdf with a custom compatibility level', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath, '/ebook', '1.5');

        expect(is_file($this->outputPath))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the gs binary is not found', function () {
        expect(fn () => new GhostscriptEngine('gs_binary_that_does_not_exist_xyz'))
            ->toThrow(SlimmerException::class);
    });

    it('includes the missing binary name in the exception message', function () {
        expect(fn () => new GhostscriptEngine('nonexistent_gs_binary'))
            ->toThrow(SlimmerException::class, 'nonexistent_gs_binary');
    });

});
