<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\ZipException;

describe('ZipEngine', function () {

    beforeEach(function () {
        $this->outputDir = sys_get_temp_dir() . '/slimmer_zip_engine_out_' . uniqid();
        mkdir($this->outputDir);

        $this->sourceDir = sys_get_temp_dir() . '/slimmer_zip_engine_src_' . uniqid();
        mkdir($this->sourceDir);
        mkdir($this->sourceDir . '/subdir');
        file_put_contents($this->sourceDir . '/hello.txt', str_repeat('Hello, Slimmer!', 50));
        file_put_contents($this->sourceDir . '/log.log', str_repeat('log content', 50));
        file_put_contents($this->sourceDir . '/subdir/nested.txt', str_repeat('Nested content.', 50));

        $this->engine = new ZipEngine();
    });

    afterEach(function () {
        removeDir($this->outputDir);
        removeDir($this->sourceDir);
    });

    // -------------------------------------------------------------------------
    // Lazy binary resolution
    // -------------------------------------------------------------------------

    it('does not resolve either binary at construction time', function () {
        expect(new ZipEngine('__nonexistent_zip__', '__nonexistent_unzip__'))->toBeInstanceOf(ZipEngine::class);
    });

    it('throws only when the operation needing the missing binary is actually called', function () {
        $engine = new ZipEngine('zip', '__nonexistent_unzip__');
        $archive = $this->outputDir . '/x.zip';

        // zip binary is fine, so compress() must succeed...
        $engine->compress($this->sourceDir, $archive);
        expect(is_file($archive))->toBeTrue();

        // ...but extract() needs the (broken) unzip binary and must throw.
        expect(fn () => $engine->extract($archive, $this->outputDir . '/out'))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the zip binary cannot be found', function () {
        $engine = new ZipEngine('__nonexistent_zip__');

        expect(fn () => $engine->compress($this->sourceDir, $this->outputDir . '/x.zip'))
            ->toThrow(SlimmerException::class, '__nonexistent_zip__');
    });

    // -------------------------------------------------------------------------
    // getVersion()
    // -------------------------------------------------------------------------

    it('getVersion returns a non-empty string', function () {
        expect($this->engine->getVersion())->toBeString()->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // Fluent configuration — returns same instance
    // -------------------------------------------------------------------------

    it('setTimeout returns the same instance', function () {
        expect($this->engine->setTimeout(30.0))->toBe($this->engine);
    });

    it('withCompressionLevel returns the same instance', function () {
        expect($this->engine->withCompressionLevel(9))->toBe($this->engine);
    });

    it('withExclude returns the same instance', function () {
        expect($this->engine->withExclude('*.log'))->toBe($this->engine);
    });

    it('withCustomArgs returns the same instance', function () {
        expect($this->engine->withCustomArgs('-v'))->toBe($this->engine);
    });

    // -------------------------------------------------------------------------
    // withCompressionLevel() validation
    // -------------------------------------------------------------------------

    it('accepts compression level 0 (store)', function () {
        expect($this->engine->withCompressionLevel(0))->toBe($this->engine);
    });

    it('accepts compression level 9 (max)', function () {
        expect($this->engine->withCompressionLevel(9))->toBe($this->engine);
    });

    it('throws InvalidArgumentException for compression level below 0', function () {
        expect(fn () => $this->engine->withCompressionLevel(-1))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for compression level above 9', function () {
        expect(fn () => $this->engine->withCompressionLevel(10))
            ->toThrow(\InvalidArgumentException::class);
    });

    // -------------------------------------------------------------------------
    // resolveOutputPath()
    // -------------------------------------------------------------------------

    it('resolveOutputPath returns the path unchanged when it already ends with .zip', function () {
        $path = '/tmp/my_archive.zip';

        expect($this->engine->resolveOutputPath('/input/dir', $path))->toBe($path);
    });

    it('resolveOutputPath generates a .zip filename when outputPath has no extension', function () {
        $result = $this->engine->resolveOutputPath('/data/mydir', $this->outputDir);

        expect($result)->toStartWith($this->outputDir . DIRECTORY_SEPARATOR . 'mydir_')
            ->and($result)->toEndWith('.zip');
    });

    // -------------------------------------------------------------------------
    // buildCompressArgv()
    // -------------------------------------------------------------------------

    it('buildCompressArgv includes -r for a directory input', function () {
        $cmd = implode(' ', $this->engine->buildCompressArgv($this->sourceDir, '/tmp/out.zip'));

        expect($cmd)->toContain('-r');
    });

    it('buildCompressArgv omits -r for a single file input', function () {
        $cmd = implode(' ', $this->engine->buildCompressArgv($this->sourceDir . '/hello.txt', '/tmp/out.zip'));

        expect($cmd)->not->toContain(' -r ');
    });

    it('buildCompressArgv embeds the compression level', function () {
        $cmd = implode(' ', $this->engine->withCompressionLevel(0)->buildCompressArgv($this->sourceDir, '/tmp/out.zip'));

        expect($cmd)->toContain('-0');
    });

    it('buildCompressArgv adds -x for each exclusion pattern', function () {
        $cmd = implode(' ', $this->engine->withExclude('*.log', '*.tmp')->buildCompressArgv($this->sourceDir, '/tmp/out.zip'));

        expect($cmd)->toContain('-x *.log')
            ->and($cmd)->toContain('-x *.tmp');
    });

    it('buildCompressArgv includes -q by default and omits it when verbose', function () {
        $quiet   = implode(' ', $this->engine->buildCompressArgv($this->sourceDir, '/tmp/out.zip'));
        $verbose = implode(' ', $this->engine->buildCompressArgv($this->sourceDir, '/tmp/out.zip', [], true));

        expect($quiet)->toContain('-q')
            ->and($verbose)->not->toContain('-q');
    });

    it('buildCompressArgv includes custom args', function () {
        $cmd = implode(' ', $this->engine->withCustomArgs('--symlinks')->buildCompressArgv($this->sourceDir, '/tmp/out.zip'));

        expect($cmd)->toContain('--symlinks');
    });

    // -------------------------------------------------------------------------
    // compress() — real compression
    // -------------------------------------------------------------------------

    it('compresses a source directory into a .zip archive', function () {
        $output = $this->outputDir . '/archive.zip';

        $this->engine->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    it('compress with withExclude omits matched files from the archive', function () {
        $output = $this->outputDir . '/no_log.zip';

        $this->engine->withExclude('*.log')->compress($this->sourceDir, $output);

        $entries = $this->engine->listContents($output);

        expect($entries)->not->toContain(basename($this->sourceDir) . '/log.log');
    });

    it('compress invokes the progress callback with adding: lines', function () {
        $output = $this->outputDir . '/progress.zip';
        $seen   = [];

        $this->engine->compress($this->sourceDir, $output, [], function (string $line) use (&$seen) {
            $seen[] = $line;
        });

        expect($seen)->not->toBeEmpty()
            ->and(implode("\n", $seen))->toContain('adding:');
    });

    // -------------------------------------------------------------------------
    // extract()
    // -------------------------------------------------------------------------

    it('buildExtractArgv uses -o, the archive path and -d outputDir', function () {
        $cmd = implode(' ', $this->engine->buildExtractArgv('/tmp/a.zip', $this->outputDir));

        expect($cmd)->toContain('-o')
            ->and($cmd)->toContain('/tmp/a.zip')
            ->and($cmd)->toContain('-d')
            ->and($cmd)->toContain($this->outputDir);
    });

    it('extracts a compressed archive back to matching file contents', function () {
        $archive = $this->outputDir . '/roundtrip.zip';
        $this->engine->compress($this->sourceDir, $archive);

        $extractDir = $this->outputDir . '/extracted';
        $this->engine->extract($archive, $extractDir);

        $baseName = basename($this->sourceDir);

        expect(file_get_contents("{$extractDir}/{$baseName}/hello.txt"))
            ->toBe(file_get_contents($this->sourceDir . '/hello.txt'));
    });

    it('extract creates the output directory when missing', function () {
        $archive = $this->outputDir . '/create_dir.zip';
        $this->engine->compress($this->sourceDir, $archive);

        $extractDir = $this->outputDir . '/does_not_exist_yet';
        expect(is_dir($extractDir))->toBeFalse();

        $this->engine->extract($archive, $extractDir);

        expect(is_dir($extractDir))->toBeTrue();
    });

    it('extract throws ZipException for a nonexistent archive', function () {
        expect(fn () => $this->engine->extract($this->outputDir . '/missing.zip', $this->outputDir . '/out'))
            ->toThrow(ZipException::class);
    });

    it('extract invokes the progress callback', function () {
        $archive = $this->outputDir . '/progress_extract.zip';
        $this->engine->compress($this->sourceDir, $archive);

        $seen = [];
        $this->engine->extract($archive, $this->outputDir . '/progress_out', [], function (string $line) use (&$seen) {
            $seen[] = $line;
        });

        expect($seen)->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // listContents()
    // -------------------------------------------------------------------------

    it('buildListArgv uses -Z1 and the archive path', function () {
        $cmd = implode(' ', $this->engine->buildListArgv('/tmp/a.zip'));

        expect($cmd)->toContain('-Z1')
            ->and($cmd)->toContain('/tmp/a.zip');
    });

    it('lists the member paths of a compressed archive', function () {
        $archive = $this->outputDir . '/list.zip';
        $this->engine->compress($this->sourceDir, $archive);

        $entries  = $this->engine->listContents($archive);
        $baseName = basename($this->sourceDir);

        expect($entries)->toContain("{$baseName}/hello.txt");
    });

    it('listContents throws ZipException for a nonexistent archive', function () {
        expect(fn () => $this->engine->listContents($this->outputDir . '/missing.zip'))
            ->toThrow(ZipException::class);
    });

});
