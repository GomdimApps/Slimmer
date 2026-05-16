<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;

describe('TarEngine', function () {

    beforeEach(function () {
        $this->documentDir = dirname(__DIR__, 3) . '/document';

        // Writable output directory for archive files
        $this->outputDir = sys_get_temp_dir() . '/slimmer_tar_engine_out_' . uniqid();
        mkdir($this->outputDir);

        // Source directory with a predictable file tree (including one empty subdir)
        $this->sourceDir = sys_get_temp_dir() . '/slimmer_tar_engine_src_' . uniqid();
        mkdir($this->sourceDir);
        mkdir($this->sourceDir . '/subdir');
        mkdir($this->sourceDir . '/empty');
        file_put_contents($this->sourceDir . '/hello.txt', str_repeat('Hello, Slimmer!', 50));
        file_put_contents($this->sourceDir . '/subdir/nested.txt', str_repeat('Nested content.', 50));

        $this->engine = new TarEngine();
    });

    afterEach(function () {
        removeDir($this->outputDir);
        removeDir($this->sourceDir);
    });

    // -------------------------------------------------------------------------
    // Constructor & binary resolution
    // -------------------------------------------------------------------------

    it('resolves the tar binary and instantiates successfully', function () {
        expect(new TarEngine())->toBeInstanceOf(TarEngine::class);
    });

    it('throws SlimmerException when the binary name cannot be found on PATH', function () {
        expect(fn () => new TarEngine('__nonexistent_tar_binary__'))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException for a non-executable absolute path', function () {
        expect(fn () => new TarEngine('/nonexistent/path/to/tar'))
            ->toThrow(SlimmerException::class);
    });

    it('includes the missing binary name in the exception message', function () {
        expect(fn () => new TarEngine('__missing_tar__'))
            ->toThrow(SlimmerException::class, '__missing_tar__');
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

    it('withThreads returns the same instance', function () {
        expect($this->engine->withThreads(4))->toBe($this->engine);
    });

    it('withExclude returns the same instance', function () {
        expect($this->engine->withExclude('*.log'))->toBe($this->engine);
    });

    it('withIgnoreEmptyDirectories returns the same instance', function () {
        expect($this->engine->withIgnoreEmptyDirectories())->toBe($this->engine);
    });

    it('withCustomArgs returns the same instance', function () {
        expect($this->engine->withCustomArgs('--verbose'))->toBe($this->engine);
    });

    it('withThreads clamps values below 1 to 1', function () {
        expect($this->engine->withThreads(0))->toBe($this->engine);
        expect($this->engine->withThreads(-10))->toBe($this->engine);
    });

    // -------------------------------------------------------------------------
    // resolveOutputPath()
    // -------------------------------------------------------------------------

    it('resolveOutputPath returns the path unchanged when it already ends with .tar.gz', function () {
        $path = '/tmp/my_archive.tar.gz';

        expect($this->engine->resolveOutputPath('/input/dir', $path, 'gz'))->toBe($path);
    });

    it('resolveOutputPath returns the path unchanged when it already ends with .tar.zst', function () {
        $path = '/tmp/my_archive.tar.zst';

        expect($this->engine->resolveOutputPath('/input/dir', $path, 'zst'))->toBe($path);
    });

    it('resolveOutputPath generates a .tar.gz filename when outputPath has no extension', function () {
        $result = $this->engine->resolveOutputPath('/data/mydir', $this->outputDir, 'gz');

        expect($result)->toStartWith($this->outputDir . DIRECTORY_SEPARATOR . 'mydir_')
            ->and($result)->toEndWith('.tar.gz');
    });

    it('resolveOutputPath generates a .tar.zst filename when outputPath has no extension', function () {
        $result = $this->engine->resolveOutputPath('/data/mydir', $this->outputDir, 'zst');

        expect($result)->toStartWith($this->outputDir . DIRECTORY_SEPARATOR . 'mydir_')
            ->and($result)->toEndWith('.tar.zst');
    });

    it('resolveOutputPath embeds the current date in the auto-generated filename', function () {
        $result = $this->engine->resolveOutputPath('/data/mydir', $this->outputDir, 'gz');

        expect($result)->toContain(date('Y-m-d'));
    });

    it('resolveOutputPath uses the input basename in the auto-generated filename', function () {
        $result = $this->engine->resolveOutputPath('/var/data/project_name', $this->outputDir, 'gz');

        expect($result)->toContain('project_name_');
    });

    it('resolveOutputPath strips trailing slashes from the input path when deriving the basename', function () {
        $result = $this->engine->resolveOutputPath('/var/data/mydir/', $this->outputDir, 'gz');

        expect($result)->toContain('mydir_');
    });

    // -------------------------------------------------------------------------
    // buildArgv()
    // -------------------------------------------------------------------------

    it('buildArgv includes the binary, -cf and the output path', function () {
        $output = $this->outputDir . '/out.tar.gz';
        $argv   = $this->engine->buildArgv($this->sourceDir, $output, 'gz');
        $cmd    = implode(' ', $argv);

        expect($cmd)->toContain('-cf')
            ->and($cmd)->toContain($output);
    });

    it('buildArgv uses gzip as the compress program for gz format', function () {
        $argv = $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz');

        expect(implode(' ', $argv))->toContain('--use-compress-program=gzip');
    });

    it('buildArgv uses zstd as the compress program for zst format', function () {
        $argv = $this->engine->withCompressionLevel(3)->buildArgv($this->sourceDir, '/tmp/out.tar.zst', 'zst');

        expect(implode(' ', $argv))->toContain('--use-compress-program=zstd');
    });

    it('buildArgv embeds the compression level in the compress program', function () {
        $argv = $this->engine->withCompressionLevel(9)->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz');

        expect(implode(' ', $argv))->toContain('gzip -9');
    });

    it('buildArgv adds --exclude flags for each exclusion pattern', function () {
        $this->engine->withExclude('*.log', '*.tmp');
        $cmd = implode(' ', $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz'));

        expect($cmd)->toContain('--exclude=*.log')
            ->and($cmd)->toContain('--exclude=*.tmp');
    });

    it('buildArgv replaces exclusion patterns on each withExclude call', function () {
        $this->engine->withExclude('*.log');
        $this->engine->withExclude('*.bak'); // replaces, not appends

        $cmd = implode(' ', $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz'));

        expect($cmd)->not->toContain('--exclude=*.log')
            ->and($cmd)->toContain('--exclude=*.bak');
    });

    it('buildArgv appends -T - when a fileList is provided', function () {
        $argv = $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz', [], ['file.txt']);
        $cmd  = implode(' ', $argv);

        expect($cmd)->toContain('-T')
            ->and($cmd)->toContain('-');
    });

    it('buildArgv omits -T when fileList is null', function () {
        $argv = $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz', [], null);
        $cmd  = implode(' ', $argv);

        expect($cmd)->not->toContain('-T -')
            ->and($cmd)->toContain(basename($this->sourceDir));
    });

    it('buildArgv injects custom args into the command', function () {
        $this->engine->withCustomArgs('--verbose');
        $cmd = implode(' ', $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz'));

        expect($cmd)->toContain('--verbose');
    });

    it('buildArgv injects per-call extra args into the command', function () {
        $cmd = implode(' ', $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz', ['-p']));

        expect($cmd)->toContain('-p');
    });

    it('buildArgv uses -C with the parent directory of the input path', function () {
        $cmd = implode(' ', $this->engine->buildArgv($this->sourceDir, '/tmp/out.tar.gz', 'gz'));

        expect($cmd)->toContain('-C')
            ->and($cmd)->toContain(dirname($this->sourceDir));
    });

    it('buildArgv includes the thread count in the zst compress program', function () {
        $argv = $this->engine->withThreads(4)->buildArgv($this->sourceDir, '/tmp/out.tar.zst', 'zst');

        expect(implode(' ', $argv))->toContain('-T4');
    });

    // -------------------------------------------------------------------------
    // compress() — real compression with the document folder
    // -------------------------------------------------------------------------

    it('compresses the document folder into a .tar.gz archive', function () {
        $output = $this->outputDir . '/document.tar.gz';

        $this->engine->compress($this->documentDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    it('compresses the document folder into a .tar.zst archive', function () {
        $output = $this->outputDir . '/document.tar.zst';

        $this->engine->compress($this->documentDir, $output, 'zst');

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    // -------------------------------------------------------------------------
    // compress() — source directory with known content
    // -------------------------------------------------------------------------

    it('compresses a source directory into a .tar.gz archive', function () {
        $output = $this->outputDir . '/archive.tar.gz';

        $this->engine->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    it('compress auto-names the archive when outputPath carries no extension', function () {
        $this->engine->compress($this->sourceDir, $this->outputDir);

        $files = glob($this->outputDir . '/*.tar.gz') ?: [];

        expect($files)->not->toBeEmpty();
    });

    it('compress with compression level 9 produces a valid archive', function () {
        $output = $this->outputDir . '/max.tar.gz';

        $this->engine->withCompressionLevel(9)->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('compress with compression level 1 produces a valid archive', function () {
        $output = $this->outputDir . '/fast.tar.gz';

        $this->engine->withCompressionLevel(1)->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('compress with withExclude omits matched files from the archive', function () {
        $output = $this->outputDir . '/no_txt.tar.gz';

        $this->engine->withExclude('*.txt')->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('compress with withIgnoreEmptyDirectories produces a valid archive', function () {
        $output = $this->outputDir . '/no_empty.tar.gz';

        $this->engine->withIgnoreEmptyDirectories()->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('compress with custom args produces a valid archive', function () {
        $output = $this->outputDir . '/custom.tar.gz';

        $this->engine->withCustomArgs('--verbose')->compress($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('compresses into .tar.zst with multiple threads', function () {
        $output = $this->outputDir . '/parallel.tar.zst';

        $this->engine->withThreads(2)->compress($this->sourceDir, $output, 'zst');

        expect(is_file($output))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // compress() — exception paths
    // -------------------------------------------------------------------------

    it('throws TarException for an unsupported format string', function () {
        expect(fn () => $this->engine->compress($this->sourceDir, '/tmp/out.tar.bz2', 'bz2'))
            ->toThrow(TarException::class);
    });

    it('TarException message mentions the unsupported format', function () {
        expect(fn () => $this->engine->compress($this->sourceDir, '/tmp/out.tar.xz', 'xz'))
            ->toThrow(TarException::class, 'xz');
    });

});
