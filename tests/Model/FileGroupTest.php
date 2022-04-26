<?php

namespace App\Tests\Model;

use App\Model\DependencyFileFormat;
use App\Model\FileGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\SplFileInfo;

class FileGroupTest extends TestCase
{
    
    public function testIsComplete(): void
    {
        $fileGroup = new FileGroup(null, null, '');
        $this->assertFalse($fileGroup->isComplete());

        $fileGroup->setDependencyFileFormat(new DependencyFileFormat('', '', ['']));
        $this->assertFalse($fileGroup->isComplete());

        $fileGroup->getDependencyFileFormat()->setLockFilesRegexes([]);
        $this->assertTrue($fileGroup->isComplete());

        $fileGroup->addLockFile(new SplFileInfo(__DIR__.'/FileGroupTest.php', '', ''));
        $this->assertTrue($fileGroup->isComplete());
    }

    public function testUnsetFile(): void
    {
        $file = new SplFileInfo(__DIR__.'/../DependencyFiles/Gradle/build.gradle', '', '');
        $fileGroup = new FileGroup($file, new DependencyFileFormat('', '', ['']), '');
        $fileGroup->unsetFile($file);
        $this->assertNull($fileGroup->getDependencyFile());

        $fileGroup->addLockFile($file);
        $this->assertCount(1, $fileGroup->getFiles());
        $this->assertNull($fileGroup->getDependencyFile());
        $fileGroup->unsetFile($file);
        $this->assertEmpty($fileGroup->getFiles());

        $dependencyFile = $file;
        $lockFile = new SplFileInfo(__DIR__.'/../DependencyFiles/composer.lock', '', '');
        $fileGroup = new FileGroup($dependencyFile, $fileGroup->getDependencyFileFormat(), '');
        $fileGroup->addLockFile($lockFile);
        $this->assertCount(2, $fileGroup->getFiles());
        $this->assertCount(1, $fileGroup->getLockFiles());
        $fileGroup->unsetFile($lockFile);
        $this->assertCount(1, $fileGroup->getFiles());
        $this->assertCount(0, $fileGroup->getLockFiles());
        $fileGroup->unsetFile($lockFile);
        $this->assertCount(1, $fileGroup->getFiles());
        $this->assertCount(0, $fileGroup->getLockFiles());
        $fileGroup->unsetFile($dependencyFile);
        $this->assertCount(0, $fileGroup->getFiles());
        $this->assertCount(0, $fileGroup->getLockFiles());
    }
}
