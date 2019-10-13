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
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/No\s+upload\s+with\s+ID/', $output);
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
        $this->assertRegExp('/Bad\s+credentials/', $output);
    }
}
