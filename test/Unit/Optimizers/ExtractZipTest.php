<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\CompressZip;
use GomdimApps\Slimmer\Optimizers\ExtractZip;

describe('ExtractZip', function () {

    beforeEach(function () {
        $this->outputDir = sys_get_temp_dir() . '/slimmer_extract_zip_out_' . uniqid();
        mkdir($this->outputDir);

        $this->sourceDir = sys_get_temp_dir() . '/slimmer_extract_zip_src_' . uniqid();
        mkdir($this->sourceDir);
        mkdir($this->sourceDir . '/subdir');
        file_put_contents($this->sourceDir . '/hello.txt', str_repeat('Hello, Slimmer!', 50));
        file_put_contents($this->sourceDir . '/subdir/nested.txt', str_repeat('Nested content.', 50));

        $this->archive = $this->outputDir . '/source.zip';
        (new CompressZip())->optimize($this->sourceDir, $this->archive);

        $this->extractor = new ExtractZip();
    });

    afterEach(function () {
        removeDir($this->outputDir);
        removeDir($this->sourceDir);
    });

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    it('can be instantiated with the default ZipEngine', function () {
        expect(new ExtractZip())->toBeInstanceOf(ExtractZip::class);
    });

    it('accepts an injected ZipEngine instance', function () {
        expect(new ExtractZip(new ZipEngine()))->toBeInstanceOf(ExtractZip::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API — returns same instance
    // -------------------------------------------------------------------------

    it('withExclude returns the same instance', function () {
        expect($this->extractor->withExclude('*.log'))->toBe($this->extractor);
    });

    it('withProgress returns the same instance', function () {
        expect($this->extractor->withProgress(function () {}))->toBe($this->extractor);
    });

    // -------------------------------------------------------------------------
    // extract()
    // -------------------------------------------------------------------------

    it('extracts an archive and returns extracted file paths', function () {
        $extractDir = $this->outputDir . '/extracted';

        $files = $this->extractor->extract($this->archive, $extractDir);

        expect($files)->not->toBeEmpty();

        $baseName = basename($this->sourceDir);
        expect(file_get_contents("{$extractDir}/{$baseName}/hello.txt"))
            ->toBe(file_get_contents($this->sourceDir . '/hello.txt'));
    });

    it('extracted file paths returned are absolute and actually exist', function () {
        $extractDir = $this->outputDir . '/extracted_paths';

        $files = $this->extractor->extract($this->archive, $extractDir);

        foreach ($files as $file) {
            expect($file)->toStartWith('/')
                ->and(is_file($file))->toBeTrue();
        }
    });

    it('extract with withExclude omits matched files', function () {
        $withLog = $this->outputDir . '/with_log_archive.zip';
        $srcWithLog = $this->outputDir . '/src_with_log';
        mkdir($srcWithLog);
        file_put_contents($srcWithLog . '/a.txt', 'keep me');
        file_put_contents($srcWithLog . '/b.log', 'exclude me');
        (new CompressZip())->optimize($srcWithLog, $withLog);

        $extractDir = $this->outputDir . '/extracted_excluded';
        $this->extractor->withExclude('*.log')->extract($withLog, $extractDir);

        $baseName = basename($srcWithLog);
        expect(is_file("{$extractDir}/{$baseName}/a.txt"))->toBeTrue()
            ->and(is_file("{$extractDir}/{$baseName}/b.log"))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // listContents()
    // -------------------------------------------------------------------------

    it('lists the member paths of the archive', function () {
        $entries  = $this->extractor->listContents($this->archive);
        $baseName = basename($this->sourceDir);

        expect($entries)->toContain("{$baseName}/hello.txt")
            ->and($entries)->toContain("{$baseName}/subdir/nested.txt");
    });

    // -------------------------------------------------------------------------
    // dryRun()
    // -------------------------------------------------------------------------

    it('dryRun returns a non-empty string containing -o', function () {
        $cmd = $this->extractor->dryRun($this->archive, $this->outputDir . '/dry');

        expect($cmd)->toBeString()->toContain('-o');
    });

    it('dryRun does not create the output directory or extract any files', function () {
        $extractDir = $this->outputDir . '/never_created';

        $this->extractor->dryRun($this->archive, $extractDir);

        expect(is_dir($extractDir))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Progress callback
    // -------------------------------------------------------------------------

    it('withProgress invokes the callback during extraction', function () {
        $seen = [];

        $this->extractor
            ->withProgress(function (string $line) use (&$seen) { $seen[] = $line; })
            ->extract($this->archive, $this->outputDir . '/progress_extract');

        expect($seen)->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the archive does not exist', function () {
        expect(fn () => $this->extractor->extract($this->outputDir . '/missing.zip', $this->outputDir . '/x'))
            ->toThrow(SlimmerException::class);
    });

});
