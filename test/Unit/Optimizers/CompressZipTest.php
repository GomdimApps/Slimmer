<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\CompressZip;

describe('CompressZip', function () {

    beforeEach(function () {
        $this->outputDir = sys_get_temp_dir() . '/slimmer_zip_opt_out_' . uniqid();
        mkdir($this->outputDir);

        $this->sourceDir = sys_get_temp_dir() . '/slimmer_zip_opt_src_' . uniqid();
        mkdir($this->sourceDir);
        mkdir($this->sourceDir . '/subdir');
        file_put_contents($this->sourceDir . '/hello.txt', str_repeat('Hello, Slimmer!', 100));
        file_put_contents($this->sourceDir . '/data.json', str_repeat('{"key":"value"}', 150));
        file_put_contents($this->sourceDir . '/subdir/nested.txt', str_repeat('Nested content.', 100));

        $this->optimizer = new CompressZip();
    });

    afterEach(function () {
        removeDir($this->outputDir);
        if (is_dir($this->sourceDir)) {
            removeDir($this->sourceDir);
        }
    });

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    it('can be instantiated with the default ZipEngine', function () {
        expect(new CompressZip())->toBeInstanceOf(CompressZip::class);
    });

    it('accepts an injected ZipEngine instance', function () {
        expect(new CompressZip(new ZipEngine()))->toBeInstanceOf(CompressZip::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API — returns same instance
    // -------------------------------------------------------------------------

    it('withCompressionLevel returns the same instance', function () {
        expect($this->optimizer->withCompressionLevel(9))->toBe($this->optimizer);
    });

    it('withExclude returns the same instance', function () {
        expect($this->optimizer->withExclude('*.log'))->toBe($this->optimizer);
    });

    it('withCustomArgs returns the same instance', function () {
        expect($this->optimizer->withCustomArgs('--symlinks'))->toBe($this->optimizer);
    });

    it('withProgress returns the same instance', function () {
        expect($this->optimizer->withProgress(function () {}))->toBe($this->optimizer);
    });

    it('withCompressionLevel throws InvalidArgumentException out of range', function () {
        expect(fn () => $this->optimizer->withCompressionLevel(10))
            ->toThrow(\InvalidArgumentException::class);
    });

    // -------------------------------------------------------------------------
    // withExclude() — accumulates patterns
    // -------------------------------------------------------------------------

    it('withExclude accumulates patterns across multiple calls', function () {
        $this->optimizer->withExclude('*.log')->withExclude('*.tmp');

        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.zip');

        expect($cmd)->toContain('-x *.log')
            ->and($cmd)->toContain('-x *.tmp');
    });

    // -------------------------------------------------------------------------
    // optimize()
    // -------------------------------------------------------------------------

    it('optimize compresses the source directory and returns a float ratio', function () {
        $output = $this->outputDir . '/archive.zip';
        $ratio  = $this->optimizer->optimize($this->sourceDir, $output);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('optimize creates a non-empty .zip archive', function () {
        $output = $this->outputDir . '/archive.zip';

        $this->optimizer->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    it('optimize auto-names the archive when outputPath has no extension', function () {
        $this->optimizer->optimize($this->sourceDir, $this->outputDir);

        $files = glob($this->outputDir . '/*.zip') ?: [];

        expect($files)->not->toBeEmpty();
    });

    it('optimize with compression level 0 produces a valid archive', function () {
        $output = $this->outputDir . '/stored.zip';

        $ratio = $this->optimizer->withCompressionLevel(0)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize with withExclude omits the specified pattern', function () {
        $output = $this->outputDir . '/no_json.zip';

        $this->optimizer->withExclude('*.json')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize compresses content supplied via fromString', function () {
        $output  = $this->outputDir . '/from_string.zip';
        $content = str_repeat('sample content for compression test', 200);

        $ratio = $this->optimizer->fromString($content)->optimize(null, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize compresses content supplied via fromStream', function () {
        $output = $this->outputDir . '/from_stream.zip';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('stream content for compression test', 200));
        rewind($stream);

        $ratio = $this->optimizer->fromStream($stream)->optimize(null, $output);
        fclose($stream);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('withProgress invokes the callback while compressing', function () {
        $seen = [];

        $this->optimizer
            ->withProgress(function (string $line) use (&$seen) { $seen[] = $line; })
            ->optimize($this->sourceDir, $this->outputDir . '/progress.zip');

        expect($seen)->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // optimize() — exception paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the input path does not exist', function () {
        expect(fn () => $this->optimizer->optimize('/nonexistent/path/to/dir', $this->outputDir))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the output directory does not exist', function () {
        expect(fn () => $this->optimizer->optimize($this->sourceDir, '/nonexistent/dir/archive.zip'))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // dryRun()
    // -------------------------------------------------------------------------

    it('dryRun returns a non-empty string', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.zip');

        expect($cmd)->toBeString()->not->toBeEmpty();
    });

    it('dryRun does not create any files', function () {
        $this->optimizer->dryRun($this->sourceDir, $this->outputDir);

        $files = glob($this->outputDir . '/*.zip') ?: [];

        expect($files)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // compressAndRetain() / cleanDirectory()
    // -------------------------------------------------------------------------

    it('compressAndRetain compresses the source, deletes it and returns a valid ratio', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_zip_retain_src_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('Retain test data.', 200));

        $ratio = $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and(is_dir($retainSrc))->toBeFalse();
    });

    it('compressAndRetain keeps only the $limit most-recent archives', function () {
        for ($i = 1; $i <= 4; $i++) {
            $file = $this->outputDir . "/old_{$i}.zip";
            file_put_contents($file, "fake archive {$i}");
            touch($file, time() - (500 - $i * 10));
        }

        $retainSrc = sys_get_temp_dir() . '/slimmer_zip_retain_limit_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('x', 1000));

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 2);

        $remaining = glob($this->outputDir . '/*.zip') ?: [];

        expect(count($remaining))->toBe(2);
    });

    it('cleanDirectory removes archives beyond the $limit, ordered by mtime', function () {
        $newest = $this->outputDir . '/newest.zip';
        $oldest = $this->outputDir . '/oldest.zip';

        file_put_contents($oldest, 'old');
        file_put_contents($newest, 'new');
        touch($oldest, time() - 300);
        touch($newest, time());

        $this->optimizer->cleanDirectory($this->outputDir, 1);

        expect(is_file($newest))->toBeTrue()
            ->and(is_file($oldest))->toBeFalse();
    });

    it('cleanDirectory does nothing when the directory does not exist', function () {
        expect(fn () => $this->optimizer->cleanDirectory('/nonexistent/dir', 3))
            ->not->toThrow(\Throwable::class);
    });

});
