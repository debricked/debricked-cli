<?php

namespace App\Tests\Model;

use App\Model\DependencyFileFormat;
use App\Tests\Command\FindAndUploadFilesCommandTest;
use PHPUnit\Framework\TestCase;

class DependencyFileFormatTest extends TestCase
{
    public function testMake(): void
    {
        $this->assertIsArray(DependencyFileFormat::make(self::getFormats()));
    }

    public function testFindFormatByFileName(): void
    {
        $formats = DependencyFileFormat::make(self::getFormats());
        $this->assertNull(DependencyFileFormat::findFormatByFileName($formats, 'test'));
        // Test valid file
        $fileName = 'package.json';
        $format = DependencyFileFormat::findFormatByFileName($formats, $fileName);
        $this->assertInstanceOf(DependencyFileFormat::class, $format);
        $this->assertMatchesRegularExpression("/^{$format->getRegex()}$/", $fileName);
        // Test match Lock file
        $this->assertNull(DependencyFileFormat::findFormatByFileName($formats, $fileName, true));
        $lockFileName = 'yarn.lock';
        $format = DependencyFileFormat::findFormatByFileName($formats, $lockFileName, true);
        $this->assertInstanceOf(DependencyFileFormat::class, $format);
        $this->assertMatchesRegularExpression("/^{$format->getRegex()}$/", $fileName);

        // Test semi-valid file
        $this->assertNull(DependencyFileFormat::findFormatByFileName($formats, 'dev-package.json'));
    }

    private static function getFormats(): array
    {
        return \json_decode(FindAndUploadFilesCommandTest::FORMATS_JSON_STRING, true);
    }
}
