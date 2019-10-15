<?php

namespace App\Tests\Command;

use App\Command\CheckScanCommand;
use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests @see CheckScanCommand.
 *
 * @author Oscar Reimer <oscar.reimer@debricked.com>
 */
class CheckScanCommandTest extends KernelTestCase
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
        $this->command = $application->find(CheckScanCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteInvalidUploadId()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => \strval($_SERVER['USERNAME']),
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => \strval($_SERVER['PASSWORD']),
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('No upload with ID', $output);
    }

    public function testExecuteInvalidCredentials()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => 'invalid@invalid.invalid',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'invalid',
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertContains('Bad credentials', $output);
    }
}
