<?php

namespace App\Tests\Command;

use App\Command\FindAndUploadFilesCommand;
use App\Command\FindFilesCommand;
use App\Service\FileGroupFinder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class FindFilesCommandTest extends KernelTestCase
{
    private Command $command;

    private CommandTester $commandTester;

    public function setUp(): void
    {
        parent::setUp();

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $this->command = $application->find(FindFilesCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecute(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => '.',
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('composer.json', $output);
        $this->assertStringContainsString('* composer.lock', $output);
        $this->assertStringContainsString('bin/.phpunit/phpunit-9.5-0/composer.json', $output);
        $this->assertStringContainsString('* bin/.phpunit/phpunit-9.5-0/composer.lock', $output);
    }

    public function testExecuteJson(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => '.',
            '--json' => null,
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertJson($output);
        $fileGroups = \json_decode($output, true);
        $this->assertCount(2, $fileGroups);
        foreach ($fileGroups as $fileGroup) {
            $this->assertArrayHasKey('dependencyFile', $fileGroup);
            $this->assertArrayHasKey('lockFiles', $fileGroup);
            $this->assertIsArray($fileGroup['lockFiles']);
        }
        [$fileGroup1, $fileGroup2] = [$fileGroups[0], $fileGroups[1]];
        $this->assertEquals('bin/.phpunit/phpunit-9.5-0/composer.json', $fileGroup1['dependencyFile']);
        $this->assertCount(1, $fileGroup1['lockFiles']);
        $lockFile = $fileGroup1['lockFiles'][0];
        $this->assertEquals('bin/.phpunit/phpunit-9.5-0/composer.lock', $lockFile);

        $this->assertEquals('composer.json', $fileGroup2['dependencyFile']);
        $this->assertCount(1, $fileGroup2['lockFiles']);
        $lockFile = $fileGroup2['lockFiles'][0];
        $this->assertEquals('composer.lock', $lockFile);
    }

    public function testExecuteInvalidCredentials(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => 'invalid@invalid.invalid',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'invalid',
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => '.',
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Invalid credentials.', $output);
    }

    public function testExecuteDirectoryNotFound(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => 'test',
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Failed to find directory', $output);
    }

    public function testExecuteNoFiles(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => 'src',
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertEmpty($output);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => 'src',
            '--json' => null,
        ]);
        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertJson($output);
        $fileGroups = \json_decode($output);
        $this->assertIsArray($fileGroups);
        $this->assertEmpty($fileGroups);
    }

    public function testExecuteWithInvalidStrictOption(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => 'test',
            '--strict' => 123,
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString("'strict' supports values within range 0-2", $output);
    }

    public function testExecuteWithBothStrictAndLockOnlyOptionsSet(): void
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY => 'test',
            '--strict' => FileGroupFinder::STRICT_PAIRS,
            '--lockfile' => null,
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString("'lockfile' and 'strict' flags are mutually exclusive", $output);
    }
}
