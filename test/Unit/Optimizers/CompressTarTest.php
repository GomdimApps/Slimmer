<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\CompressTar;

describe('CompressTar', function () {

    beforeEach(function () {
        $this->documentDir = dirname(__DIR__, 3) . '/document';

        // Writable output directory for archive files
        $this->outputDir = sys_get_temp_dir() . '/slimmer_tar_opt_out_' . uniqid();
        mkdir($this->outputDir);

        // Source directory with a predictable file tree (including one empty subdir)
        $this->sourceDir = sys_get_temp_dir() . '/slimmer_tar_opt_src_' . uniqid();
        mkdir($this->sourceDir);
        mkdir($this->sourceDir . '/subdir');
        mkdir($this->sourceDir . '/empty');
        file_put_contents($this->sourceDir . '/hello.txt', str_repeat('Hello, Slimmer!', 100));
        file_put_contents($this->sourceDir . '/data.json', str_repeat('{"key":"value"}', 150));
        file_put_contents($this->sourceDir . '/subdir/nested.txt', str_repeat('Nested content.', 100));

        $this->optimizer = new CompressTar();
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

    it('can be instantiated with the default TarEngine', function () {
        expect(new CompressTar())->toBeInstanceOf(CompressTar::class);
    });

    it('accepts an injected TarEngine instance', function () {
        $engine = new TarEngine();

        expect(new CompressTar($engine))->toBeInstanceOf(CompressTar::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API — returns same instance
    // -------------------------------------------------------------------------

    it('withFormat returns the same instance', function () {
        expect($this->optimizer->withFormat('gz'))->toBe($this->optimizer);
    });

    it('withCompressionLevel returns the same instance', function () {
        expect($this->optimizer->withCompressionLevel(9))->toBe($this->optimizer);
    });

    it('withThreads returns the same instance', function () {
        expect($this->optimizer->withThreads(4))->toBe($this->optimizer);
    });

    it('withExclude returns the same instance', function () {
        expect($this->optimizer->withExclude('*.log'))->toBe($this->optimizer);
    });

    it('withCustomArgs returns the same instance', function () {
        expect($this->optimizer->withCustomArgs('--verbose'))->toBe($this->optimizer);
    });

    it('preservePermissions returns the same instance', function () {
        expect($this->optimizer->preservePermissions())->toBe($this->optimizer);
    });

    it('ignoreEmptyDirectories returns the same instance', function () {
        expect($this->optimizer->ignoreEmptyDirectories())->toBe($this->optimizer);
    });

    // -------------------------------------------------------------------------
    // withFormat() validation
    // -------------------------------------------------------------------------

    it('withFormat accepts gz', function () {
        expect($this->optimizer->withFormat('gz'))->toBe($this->optimizer);
    });

    it('withFormat accepts zst', function () {
        expect($this->optimizer->withFormat('zst'))->toBe($this->optimizer);
    });

    it('withFormat accepts bz2', function () {
        expect($this->optimizer->withFormat('bz2'))->toBe($this->optimizer);
    });

    it('withFormat throws InvalidArgumentException for an unknown format', function () {
        expect(fn () => $this->optimizer->withFormat('rar'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('withFormat exception message mentions the unsupported format', function () {
        expect(fn () => $this->optimizer->withFormat('xz'))
            ->toThrow(\InvalidArgumentException::class, 'xz');
    });

    // -------------------------------------------------------------------------
    // withExclude() — accumulates patterns
    // -------------------------------------------------------------------------

    it('withExclude accumulates patterns across multiple calls', function () {
        $this->optimizer->withExclude('*.log')->withExclude('*.tmp');

        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--exclude=*.log')
            ->and($cmd)->toContain('--exclude=*.tmp');
    });

    // -------------------------------------------------------------------------
    // optimize() — .tar.gz with the document folder
    // -------------------------------------------------------------------------

    it('optimize compresses the document folder and returns a float ratio', function () {
        $output = $this->outputDir . '/document.tar.gz';
        $ratio  = $this->optimizer->optimize($this->documentDir, $output);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('optimize creates a non-empty .tar.gz archive of the document folder', function () {
        $output = $this->outputDir . '/document.tar.gz';

        $this->optimizer->optimize($this->documentDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    // -------------------------------------------------------------------------
    // optimize() — source directory with known content
    // -------------------------------------------------------------------------

    it('optimize creates a .tar.gz archive from the source directory', function () {
        $output = $this->outputDir . '/archive.tar.gz';

        $this->optimizer->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    it('optimize returns a ratio between 0.0 and 1.0', function () {
        $output = $this->outputDir . '/ratio_test.tar.gz';
        $ratio  = $this->optimizer->optimize($this->sourceDir, $output);

        expect($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('optimize auto-names the archive when outputPath has no extension', function () {
        $this->optimizer->optimize($this->sourceDir, $this->outputDir);

        $files = glob($this->outputDir . '/*.tar.gz') ?: [];

        expect($files)->not->toBeEmpty();
    });

    it('optimize with compression level 9 produces a valid archive', function () {
        $output = $this->outputDir . '/max.tar.gz';

        $ratio = $this->optimizer->withCompressionLevel(9)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize with withExclude omits the specified pattern', function () {
        $output = $this->outputDir . '/no_json.tar.gz';

        $this->optimizer->withExclude('*.json')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with ignoreEmptyDirectories produces a valid archive', function () {
        $output = $this->outputDir . '/no_empty.tar.gz';

        $this->optimizer->ignoreEmptyDirectories()->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with preservePermissions produces a valid archive', function () {
        $output = $this->outputDir . '/perms.tar.gz';

        $this->optimizer->preservePermissions()->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with custom args produces a valid archive', function () {
        $output = $this->outputDir . '/custom.tar.gz';

        $this->optimizer->withCustomArgs('--verbose')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // optimize() — .tar.zst
    // -------------------------------------------------------------------------

    it('optimize creates a .tar.zst archive', function () {
        $output = $this->outputDir . '/archive.tar.zst';
        $ratio  = $this->optimizer->withFormat('zst')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize with zst and multiple threads produces a valid archive', function () {
        $output = $this->outputDir . '/parallel.tar.zst';

        $this->optimizer->withFormat('zst')->withThreads(2)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize compresses the document folder into a .tar.zst archive', function () {
        $output = $this->outputDir . '/document.tar.zst';

        $this->optimizer->withFormat('zst')->optimize($this->documentDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // optimize() — fromString / fromStream
    // -------------------------------------------------------------------------

    it('optimize compresses content supplied via fromString', function () {
        $output  = $this->outputDir . '/from_string.tar.gz';
        $content = str_repeat('sample content for compression test', 200);

        $ratio = $this->optimizer->fromString($content)->optimize(null, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize compresses content supplied via fromStream', function () {
        $output = $this->outputDir . '/from_stream.tar.gz';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('stream content for compression test', 200));
        rewind($stream);

        $ratio = $this->optimizer->fromStream($stream)->optimize(null, $output);
        fclose($stream);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    // -------------------------------------------------------------------------
    // optimize() — exception paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the input path does not exist', function () {
        expect(fn () => $this->optimizer->optimize('/nonexistent/path/to/dir', $this->outputDir))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the output directory does not exist', function () {
        expect(fn () => $this->optimizer->optimize($this->sourceDir, '/nonexistent/dir/archive.tar.gz'))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when no input is provided and fromString/fromStream were not called', function () {
        expect(fn () => $this->optimizer->optimize(null, $this->outputDir . '/out.tar.gz'))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // dryRun()
    // -------------------------------------------------------------------------

    it('dryRun returns a non-empty string', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toBeString()->not->toBeEmpty();
    });

    it('dryRun contains the gzip compress program for gz format', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('gzip');
    });

    it('dryRun contains the zstd compress program for zst format', function () {
        $cmd = $this->optimizer->withFormat('zst')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.zst');

        expect($cmd)->toContain('zstd');
    });

    it('dryRun includes -p when preservePermissions is enabled', function () {
        $cmd = $this->optimizer->preservePermissions()
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain(' -p');
    });

    it('dryRun includes --exclude when withExclude is set', function () {
        $cmd = $this->optimizer->withExclude('*.log')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--exclude=*.log');
    });

    it('dryRun includes custom args', function () {
        $cmd = $this->optimizer->withCustomArgs('--verbose')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--verbose');
    });

    it('dryRun auto-resolves the output filename in the returned command', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir);

        expect($cmd)->toContain('.tar.gz');
    });

    it('dryRun does not create any files', function () {
        $this->optimizer->dryRun($this->sourceDir, $this->outputDir);

        $files = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($files)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // compressAndRetain()
    // -------------------------------------------------------------------------

    it('compressAndRetain compresses the source and returns a valid ratio', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_src_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('Retain test data.', 200));

        $ratio = $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('compressAndRetain deletes the source directory after compression', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_del_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/file.txt', 'content to compress and delete');

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        expect(is_dir($retainSrc))->toBeFalse();
    });

    it('compressAndRetain deletes a source file after compression', function () {
        $retainFile = sys_get_temp_dir() . '/slimmer_retain_file_' . uniqid() . '.txt';
        file_put_contents($retainFile, str_repeat('file content', 100));

        $this->optimizer->compressAndRetain($retainFile, $this->outputDir, 5);

        expect(is_file($retainFile))->toBeFalse();
    });

    it('compressAndRetain keeps only the $limit most-recent archives', function () {
        // Pre-populate output dir with 4 old fake archives
        for ($i = 1; $i <= 4; $i++) {
            $file = $this->outputDir . "/old_{$i}.tar.gz";
            file_put_contents($file, "fake archive {$i}");
            touch($file, time() - (500 - $i * 10));
        }

        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_limit_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('x', 1000));

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 2);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(2);
    });

    it('compressAndRetain creates the new archive in the output directory', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_new_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('archive content', 100));

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        $files = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($files)->not->toBeEmpty();
    });

    it('compressAndRetain throws SlimmerException when the input path does not exist', function () {
        expect(fn () => $this->optimizer->compressAndRetain('/nonexistent/path', $this->outputDir, 5))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // cleanDirectory()
    // -------------------------------------------------------------------------

    it('cleanDirectory removes archives beyond the $limit', function () {
        for ($i = 1; $i <= 5; $i++) {
            $file = $this->outputDir . "/archive_{$i}.tar.gz";
            file_put_contents($file, "archive {$i}");
            touch($file, time() - (500 - $i * 10));
        }

        $this->optimizer->cleanDirectory($this->outputDir, 3);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(3);
    });

    it('cleanDirectory retains the most-recent archives ordered by mtime', function () {
        $newest = $this->outputDir . '/newest.tar.gz';
        $oldest = $this->outputDir . '/oldest.tar.gz';

        file_put_contents($oldest, 'old');
        file_put_contents($newest, 'new');
        touch($oldest, time() - 300);
        touch($newest, time());

        $this->optimizer->cleanDirectory($this->outputDir, 1);

        expect(is_file($newest))->toBeTrue()
            ->and(is_file($oldest))->toBeFalse();
    });

    it('cleanDirectory does nothing when archive count is within the limit', function () {
        file_put_contents($this->outputDir . '/a.tar.gz', 'a');
        file_put_contents($this->outputDir . '/b.tar.gz', 'b');

        $this->optimizer->cleanDirectory($this->outputDir, 5);

        $remaining = glob($this->outputDir . '/*.tar.gz') ?: [];

        expect(count($remaining))->toBe(2);
    });

    it('cleanDirectory does nothing when the directory does not exist', function () {
        expect(fn () => $this->optimizer->cleanDirectory('/nonexistent/dir', 3))
            ->not->toThrow(\Throwable::class);
    });

    it('cleanDirectory handles .tar.zst and .tar.gz files together', function () {
        file_put_contents($this->outputDir . '/a.tar.gz',  'gz archive a');
        file_put_contents($this->outputDir . '/b.tar.zst', 'zst archive b');
        file_put_contents($this->outputDir . '/c.tar.gz',  'gz archive c');
        touch($this->outputDir . '/a.tar.gz',  time() - 200);
        touch($this->outputDir . '/b.tar.zst', time() - 100);
        touch($this->outputDir . '/c.tar.gz',  time());

        $this->optimizer->cleanDirectory($this->outputDir, 2);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(2);
    });

    it('cleanDirectory with limit 0 removes all archives', function () {
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->outputDir . "/archive_{$i}.tar.gz", "content {$i}");
        }

        $this->optimizer->cleanDirectory($this->outputDir, 0);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($remaining)->toBeEmpty();
    });

    it('cleanDirectory counts .tar.bz2 files together with .tar.gz and .tar.zst', function () {
        file_put_contents($this->outputDir . '/a.tar.gz',  'gz');
        file_put_contents($this->outputDir . '/b.tar.bz2', 'bz2');
        touch($this->outputDir . '/a.tar.gz',  time() - 100);
        touch($this->outputDir . '/b.tar.bz2', time());

        $this->optimizer->cleanDirectory($this->outputDir, 1);

        expect(is_file($this->outputDir . '/b.tar.bz2'))->toBeTrue()
            ->and(is_file($this->outputDir . '/a.tar.gz'))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // .tar.bz2 format
    // -------------------------------------------------------------------------

    it('optimize creates a .tar.bz2 archive', function () {
        $output = $this->outputDir . '/archive.tar.bz2';
        $ratio  = $this->optimizer->withFormat('bz2')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('dryRun contains the bzip2 compress program for bz2 format', function () {
        $cmd = $this->optimizer->withFormat('bz2')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.bz2');

        expect($cmd)->toContain('bzip2');
    });

    // -------------------------------------------------------------------------
    // Compression-level validation
    // -------------------------------------------------------------------------

    it('withCompressionLevel validates against the current format', function () {
        expect(fn () => $this->optimizer->withCompressionLevel(15))
            ->toThrow(\InvalidArgumentException::class); // default format is gz, max 9
    });

    it('withCompressionLevel accepts a level valid for the current format', function () {
        expect($this->optimizer->withFormat('zst')->withCompressionLevel(15))->toBe($this->optimizer);
    });

    it('withFormat re-validates the already-configured compression level', function () {
        $this->optimizer->withCompressionLevel(9); // valid for gz (default)

        expect(fn () => $this->optimizer->withFormat('zst')->withCompressionLevel(9)->withFormat('gz'))
            ->not->toThrow(\InvalidArgumentException::class);
    });

    it('withFormat throws when switching to a format the current level does not fit', function () {
        $this->optimizer->withFormat('zst')->withCompressionLevel(15); // only valid for zst

        expect(fn () => $this->optimizer->withFormat('gz'))
            ->toThrow(\InvalidArgumentException::class);
    });

    // -------------------------------------------------------------------------
    // withProgress()
    // -------------------------------------------------------------------------

    it('withProgress returns the same instance', function () {
        expect($this->optimizer->withProgress(function () {}))->toBe($this->optimizer);
    });

    it('withProgress invokes the callback while compressing', function () {
        $seen = [];

        $this->optimizer
            ->withProgress(function (string $line) use (&$seen) { $seen[] = $line; })
            ->optimize($this->sourceDir, $this->outputDir . '/progress.tar.gz');

        expect($seen)->not->toBeEmpty();
    });

});
