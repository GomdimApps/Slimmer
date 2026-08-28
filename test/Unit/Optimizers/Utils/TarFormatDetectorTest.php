<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Optimizers\Utils\Tar\TarFormatDetector;

describe('TarFormatDetector', function () {

    beforeEach(function () {
        $this->dir = sys_get_temp_dir() . '/slimmer_format_detect_' . uniqid();
        mkdir($this->dir);

        $this->srcDir = $this->dir . '/src';
        mkdir($this->srcDir);
        file_put_contents($this->srcDir . '/a.txt', 'content for format detection tests');
    });

    afterEach(function () {
        removeDir($this->dir);
    });

    // -------------------------------------------------------------------------
    // Magic-byte detection — real files, written by the actual tar/gzip/zstd/bzip2 binaries
    // -------------------------------------------------------------------------

    it('detects gz from magic bytes regardless of extension', function () {
        $real = $this->dir . '/archive.tar.gz';
        (new \GomdimApps\Slimmer\Engines\TarEngine())->compress($this->srcDir, $real, 'gz');

        $path = $this->dir . '/archive.bin';
        rename($real, $path);

        expect(TarFormatDetector::detect($path))->toBe('gz');
    });

    it('detects bz2 from magic bytes regardless of extension', function () {
        $real = $this->dir . '/archive2.tar.bz2';
        (new \GomdimApps\Slimmer\Engines\TarEngine())->compress($this->srcDir, $real, 'bz2');

        $path = $this->dir . '/archive2.bin';
        rename($real, $path);

        expect(TarFormatDetector::detect($path))->toBe('bz2');
    });

    it('detects zst from magic bytes regardless of extension', function () {
        $real = $this->dir . '/archive3.tar.zst';
        (new \GomdimApps\Slimmer\Engines\TarEngine())->compress($this->srcDir, $real, 'zst');

        $path = $this->dir . '/archive3.bin';
        rename($real, $path);

        expect(TarFormatDetector::detect($path))->toBe('zst');
    });

    // -------------------------------------------------------------------------
    // Extension fallback — unreadable / nonexistent paths
    // -------------------------------------------------------------------------

    it('falls back to the .tar.gz / .tgz extension for a nonexistent path', function () {
        expect(TarFormatDetector::detect('/nonexistent/archive.tar.gz'))->toBe('gz')
            ->and(TarFormatDetector::detect('/nonexistent/archive.tgz'))->toBe('gz');
    });

    it('falls back to the .tar.bz2 / .tbz / .tbz2 extension for a nonexistent path', function () {
        expect(TarFormatDetector::detect('/nonexistent/archive.tar.bz2'))->toBe('bz2')
            ->and(TarFormatDetector::detect('/nonexistent/archive.tbz'))->toBe('bz2')
            ->and(TarFormatDetector::detect('/nonexistent/archive.tbz2'))->toBe('bz2');
    });

    it('falls back to the .tar.zst / .tzst extension for a nonexistent path', function () {
        expect(TarFormatDetector::detect('/nonexistent/archive.tar.zst'))->toBe('zst')
            ->and(TarFormatDetector::detect('/nonexistent/archive.tzst'))->toBe('zst');
    });

    it('returns null for an unknown extension and nonexistent path', function () {
        expect(TarFormatDetector::detect('/nonexistent/archive.rar'))->toBeNull();
    });

    it('returns null for an existing file with no magic bytes and no known extension', function () {
        $path = $this->dir . '/mystery';
        file_put_contents($path, 'plain text, not an archive');

        expect(TarFormatDetector::detect($path))->toBeNull();
    });

});
