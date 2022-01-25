<?php

namespace App\Tests\Command;

use App\Command\CompoundCommand;
use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests @see CompoundCommand.
 *
 * @author Oscar Reimer <oscar.reimer@debricked.com>
 */
class CompoundCommandTest extends KernelTestCase
{
    /**
     * @var Command
     */
    private $command;

    private CommandTester $commandTester;

    public function setUp(): void
    {
        parent::setUp();

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $this->command = $application->find(CompoundCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecute()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-product',
            'commit-name' => 'test-release',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            '--excluded-directories' => 'vendor,var,tests,bin',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Scan completed', $output);
        $this->assertStringContainsString('have been marked as unaffected', $output);
        $this->assertStringContainsString('Please visit', $output);
    }

    public function testDisableConditionalSkipScan()
    {
        //test when disable-conditional-skip-scan is null (this is the same as true). Scan should always complete
        $this->commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            'repository-name' => 'test-product',
            'commit-name' => 'test-release',
            'repository-url' => 'repository-url',
            'integration-name' => 'azureDevOps',
            CompoundCommand::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN_WITH_DASHES => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('Scan completed', $output);
    }
}
