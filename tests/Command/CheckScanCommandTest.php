<?php

namespace App\Tests\Command;

use App\Command\CheckScanCommand;
use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        $this->assertRegExp('/Invalid\s+credentials./', $output);
    }

    private function runPolicyEngineTest(string $action): string
    {
        $response = new MockResponse(\json_encode([
            'progress' => 100,
            'vulnerabilitiesFound' => 0,
            'unaffectedVulnerabilitiesFound' => 0,
            'policyEngineAction' => $action,
            'detailsUrl' => '',
        ]));
        $httpClient = new MockHttpClient([$response], 'https://app.debricked.com');
        $command = new CheckScanCommand($httpClient, 'name');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);
        $output = $commandTester->getDisplay();
        $this->assertEquals(0, $commandTester->getStatusCode(), $output);

        return $output;
    }

    public function testPolicyEngineNone()
    {
        $output = $this->runPolicyEngineTest('none');
        $this->assertNotContains("A policy engine rule triggered a pipeline warning.", $output);
        $this->assertNotContains("A policy engine rule triggered a pipeline failure.", $output);
    }

    public function testPolicyEngineWarn()
    {
        $output = $this->runPolicyEngineTest('warn');
        $this->assertContains("A policy engine rule triggered a pipeline warning.", $output);
        $this->assertNotContains("A policy engine rule triggered a pipeline failure.", $output);
    }

    public function testPolicyEngineFail()
    {
        $output = $this->runPolicyEngineTest('fail');
        $this->assertContains("A policy engine rule triggered a pipeline failure.", $output);
        $this->assertNotContains("A policy engine rule triggered a pipeline warning.", $output);
    }

    public function testQueueTimeTooLong()
    {
        $response = new MockResponse("The queue time was too long", ['http_code' => Response::HTTP_CREATED]);
        $httpClient = new MockHttpClient([$response], 'https://app.debricked.com');
        $command = new CheckScanCommand($httpClient, 'name');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertEquals(0, $commandTester->getStatusCode(), $output);
        $this->assertContains("The queue time was too long", $output);
    }

    public function testVulnerabilitiesFound()
    {
        $response = new MockResponse(\json_encode([
            'progress' => 100,
            'vulnerabilitiesFound' => 5,
            'unaffectedVulnerabilitiesFound' => 0,
            'detailsUrl' => '',
        ]));
        $httpClient = new MockHttpClient([$response], 'https://app.debricked.com');
        $command = new CheckScanCommand($httpClient, 'name');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertEquals(0, $commandTester->getStatusCode(), $output);
        $this->assertContains("VULNERABILITIES FOUND", $output);
    }
}
