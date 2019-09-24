<?php

namespace App\Tests\Command;

use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests @see FindAndUploadFilesCommand.
 *
 * @author Oscar Reimer <oscar.reimer@debricked.com>
 */
class FindAndUploadFilesCommandTest extends KernelTestCase
{
    /**
     * @var Command
     */
    private $command;

    /**
     * @var CommandTester
     */
    private $commandTester;

    public function setUp()
    {
        parent::setUp();

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $this->command = $application->find(FindAndUploadFilesCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteInvalidCredentials()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => 'invalid@invalid.invalid',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'invalid',
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Bad credentials', $output);
    }

    public function testExecute()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => \getenv('USERNAME'),
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => \getenv('PASSWORD'),
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testExecuteWithoutBranch()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => \getenv('USERNAME'),
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => \getenv('PASSWORD'),
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testExecuteDisabledRecursiveAndDifferentBase()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => \getenv('USERNAME'),
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => \getenv('PASSWORD'),
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'branch-name' => 'test-branch',
            'base-directory' => '/vendor/',
            '--recursive-file-search' => 0,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Recursive search is disabled', $output);
        $this->assertContains('Nothing to upload!', $output);
    }

    public function testExecuteNothingExcluded()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => \getenv('USERNAME'),
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => \getenv('PASSWORD'),
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'branch-name' => 'test-branch',
            'base-directory' => '/vendor/',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No directories will be ignored', $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('csa/guzzle-bundle/composer.lock', $output);
        $this->assertContains('csa/guzzle-bundle/package-lock.json', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }
}
