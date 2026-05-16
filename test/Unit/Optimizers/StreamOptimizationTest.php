<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

describe('Stream & Buffer Optimization', function () {

    beforeEach(function () {
        $this->optimizer = new PdfOptimizer();
        $this->samplePdf = dirname(__DIR__, 3) . '/document/sample.pdf';
        $this->outputPath = sys_get_temp_dir() . '/slimmer_stream_test_' . uniqid() . '.pdf';
    });

    afterEach(function () {
        if (is_file($this->outputPath)) @unlink($this->outputPath);
    });

    it('optimizes a PDF from a string buffer', function () {
        $content = file_get_contents($this->samplePdf);
        
        $ratio = $this->optimizer
            ->fromString($content)
            ->optimize(null, $this->outputPath);

        expect($ratio)->toBeFloat()
            ->and(is_file($this->outputPath))->toBeTrue()
            ->and(filesize($this->outputPath))->toBeGreaterThan(0);
        
        // Verify output is a valid PDF
        $handle = fopen($this->outputPath, 'rb');
        expect(fread($handle, 5))->toBe('%PDF-');
        fclose($handle);
    });

    it('optimizes a PDF from a stream resource', function () {
        $stream = fopen($this->samplePdf, 'rb');
        
        $ratio = $this->optimizer
            ->fromStream($stream)
            ->optimize(null, $this->outputPath);

        fclose($stream);

        expect($ratio)->toBeFloat()
            ->and(is_file($this->outputPath))->toBeTrue();
    });

    it('automatically cleans up temporary files after destruction', function () {
        $content = "fake pdf content";
        
        $optimizer = new PdfOptimizer();
        $optimizer->fromString($content);
        
        // Use reflection to get the private property 'temporaryInputFile' from the trait
        $reflection = new ReflectionClass($optimizer);
        $property = $reflection->getProperty('temporaryInputFile');
        $property->setAccessible(true);
        
        $tempFile = $property->getValue($optimizer);
        
        expect(is_file($tempFile))->toBeTrue();
        
        // Destroy the object to trigger __destruct()
        unset($optimizer);
        
        expect(is_file($tempFile))->toBeFalse();
    });

    it('throws exception if optimize is called with null and no buffer was provided', function () {
        expect(fn() => $this->optimizer->optimize(null, $this->outputPath))
            ->toThrow(\GomdimApps\Slimmer\Exceptions\SlimmerException::class, 'No input file provided');
    });

});
