<?php

namespace App\Tests\Service;

use App\Service\FileGroupFinder;
use App\Tests\Command\FindAndUploadFilesCommandTest;
use Debricked\Shared\API\API;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class FileGroupFinderTest extends TestCase
{
    /** @var API|MockObject|null */
    private $apiMock = null;

    public function setUp(): void
    {
        $this->apiMock = $this->getMockBuilder(API::class)->disableOriginalConstructor()->getMock();
        $responseMock = $this->getMockBuilder(ResponseInterface::class)->disableOriginalConstructor()->getMock();

        $responseMock->expects($this->atMost(1))->method('getContent')->willReturn(FindAndUploadFilesCommandTest::FORMATS_JSON_STRING);

        $this->apiMock->expects($this->once())->method('makeApiCall')->willReturn($responseMock);
    }

    public function testFindWithoutRecursiveFileSearch(): void
    {
        $fileGroups = FileGroupFinder::find($this->apiMock, getcwd(), false, []);
        $this->assertCount(1, $fileGroups);
        $fileGroup = $fileGroups[0];
        $this->assertInstanceOf(\SplFileInfo::class, $fileGroup->getDependencyFile());
        $this->assertNotEmpty($fileGroup->getLockFiles());
        $this->assertCount(1, $fileGroup->getLockFiles());
        $lockFile = $fileGroup->getLockFiles()[0];
        $this->assertInstanceOf(\SplFileInfo::class, $lockFile);
    }

    public function testFindWithRecursiveFileSearch(): void
    {
        $fileGroups = FileGroupFinder::find($this->apiMock, getcwd(), true, ['vendor']);
        $this->assertGreaterThan(1, $fileGroups);
    }

    public function testFindWithoutExcludedDirectories(): void
    {
        $fileGroups = FileGroupFinder::find($this->apiMock, getcwd(), true, []);
        $this->assertGreaterThan(100, $fileGroups);
    }

    public function testFindWithBadFormatResponse(): void
    {
        $this->apiMock->method('makeApiCall')->willThrowException(new TimeoutException());
        $this->expectException(TransportExceptionInterface::class);
        FileGroupFinder::find($this->apiMock, getcwd(), true, []);
    }

    public function testFindExcludedFilesAreIgnored(): void
    {
        $excludedFile1 = 'bin/.phpunit/phpunit-7.5-0/composer.lock';
        $excludedFile2 = 'bin/.phpunit/phpunit-9.5-0/composer.lock';
        $excludedFiles = [$excludedFile1, $excludedFile2];
        $fileGroups = FileGroupFinder::find($this->apiMock, getcwd(), true, ['vendor', 'tests', $excludedFile1, $excludedFile2]);
        $this->assertGreaterThan(2, $fileGroups);
        foreach ($fileGroups as $fileGroup) {
            foreach ($fileGroup->getLockFiles() as $file) {
                $fileName = $file->getBasename();
                $this->assertFalse(\in_array($fileName, $excludedFiles), 'failed to assert that the file was excluded');
            }
        }
    }
}
