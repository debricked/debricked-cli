<?php

namespace App\Tests\Service;

use App\Model\DependencyFileFormat;
use App\Service\FileGroupFinder;
use App\Tests\Command\FindAndUploadFilesCommandTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class CreateFileGroupsTest extends TestCase
{
    public function testCreateFileGroups(): void
    {
        $iterator = [
            new SplFileInfo('package.json', 'app', 'app/package.json'),
            new SplFileInfo('package-lock.json', 'app', 'app/package-lock.json'),
        ];
        $iterator = new \ArrayIterator($iterator);

        $finderMock = $this->getMockBuilder(Finder::class)->disableOriginalConstructor()->getMock();
        $finderMock->expects($this->once())->method('getIterator')->willReturn($iterator);
        $dependencyFileFormats = DependencyFileFormat::make(
            \json_decode(FindAndUploadFilesCommandTest::FORMATS_JSON_STRING, true)
        );

        $lockFiles = ['app/package-lock.json' => new SplFileInfo('package-lock.json', 'app', 'app/package-lock.json')];
        $fileGroups = FileGroupFinder::createFileGroups($lockFiles, $finderMock, $dependencyFileFormats, '.');

        $this->assertCount(1, $fileGroups);
        $fileGroup = $fileGroups[0];
        $this->assertInstanceOf(\SplFileInfo::class, $fileGroup->getDependencyFile());
        $this->assertNotEmpty($fileGroup->getLockFiles());
        $this->assertCount(1, $fileGroup->getLockFiles());
        $lockFile = $fileGroup->getLockFiles()[0];
        $this->assertInstanceOf(\SplFileInfo::class, $lockFile);
    }

    public function testCreateFileGroupsOnWindows(): void
    {
        $iterator = [
            new SplFileInfo('package.json', 'app', 'app\package.json'),
            new SplFileInfo('package-lock.json', 'app', 'app\package-lock.json'),
        ];
        $iterator = new \ArrayIterator($iterator);

        $finderMock = $this->getMockBuilder(Finder::class)->disableOriginalConstructor()->getMock();
        $finderMock->expects($this->once())->method('getIterator')->willReturn($iterator);
        $dependencyFileFormats = DependencyFileFormat::make(
            \json_decode(FindAndUploadFilesCommandTest::FORMATS_JSON_STRING, true)
        );

        $lockFiles = ['app\package-lock.json' => new SplFileInfo('package-lock.json', 'app', 'app\package-lock.json')];
        $fileGroups = FileGroupFinder::createFileGroups($lockFiles, $finderMock, $dependencyFileFormats, '.');

        $this->assertCount(1, $fileGroups);
        $fileGroup = $fileGroups[0];
        $this->assertInstanceOf(\SplFileInfo::class, $fileGroup->getDependencyFile());
        $this->assertNotEmpty($fileGroup->getLockFiles());
        $this->assertCount(1, $fileGroup->getLockFiles());
        $lockFile = $fileGroup->getLockFiles()[0];
        $this->assertInstanceOf(\SplFileInfo::class, $lockFile);
    }
}