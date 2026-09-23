<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\FileReadTool;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer FileReadTool (P1: URL-Ablehnung).
 *
 * file_read ist ausschliesslich fuer lokale Dateipfade gedacht. Der
 * dev-tail-Log zeigte einen geplanten Schritt file_read mit URL-Parameter
 * (https://visiongastro.de) — das Tool muss URLs deshalb explizit
 * ablehnen und auf den website_researcher-Sub-Agenten verweisen, statt
 * als Web-Crawler missverstanden zu werden.
 */
final class FileReadToolTest extends TestCase
{
    public function testRejectsHttpUrl(): void
    {
        $tool = new FileReadTool();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keine URLs');

        $tool(['path' => 'https://visiongastro.de']);
    }

    public function testRejectsHttpsUrlInUrlParameter(): void
    {
        $tool = new FileReadTool();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keine URLs');

        $tool(['url' => 'https://visiongastro.de']);
    }

    public function testReadsLocalFileWithinSandbox(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'file_read_test_');
        file_put_contents($path, 'inhalt');

        $tool = new FileReadTool();
        $result = $tool(['path' => $path]);

        self::assertSame('inhalt', $result['content']);
        unlink($path);
    }
}
