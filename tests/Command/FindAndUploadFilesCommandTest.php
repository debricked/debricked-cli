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
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Invalid credentials.', $output);
    }

    public function testExecute()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
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
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
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
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
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
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            'base-directory' => '/tests/',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No directories will be ignored', $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('tests/DependencyFiles/composer.lock', $output);
        $this->assertContains('tests/DependencyFiles/package-lock.json', $output);
        $this->assertNotContains('Successfully created zip file', $output);
        $this->assertRegExp('/Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+/', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/Command\/GradleRecursive\/MPChartExample\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/Command\/GradleRecursive\/MPChartLib\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertRegExp('/Skipping\s+\/home\/tests\/Command\/GradleRecursive\/build.gradle.\s+Found\s+file\s+which\s+requires\s+that\s+all\s+files\s+needs\s+to\s+be\s+uploaded.\s+Please\s+enable\s+the\s+upload\-all\-files\s+option\s+if\s+you\s+want\s+to\s+scan\s+this\s+file./', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }

    public function testUploadAllFiles()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-repository',
            'commit-name' => 'test-commit',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--branch-name' => 'test-branch',
            '--recursive-file-search' => true,
            '--excluded-directories' => '',
            '--upload-all-files' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No directories will be ignored', $output);
        $this->assertContains('Successfully found and uploaded', $output);
        $this->assertContains('Successfully created zip file', $output);
        $this->assertContains('GradleRecursive/MPChartExample/build.gradle', $output);
        $this->assertContains('GradleRecursive/MPChartLib/build.gradle ', $output);
        $this->assertContains('GradleRecursive/build.gradle ', $output);
        $this->assertNotContains('Recursive search is disabled', $output);
    }
}
