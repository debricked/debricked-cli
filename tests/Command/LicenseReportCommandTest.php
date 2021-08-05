<?php

namespace App\Tests\Command;

use App\Command\CheckScanCommand;
use App\Command\FindAndUploadFilesCommand;
use App\Command\LicenseReportCommand;
use http\Exception\RuntimeException;
use SebastianBergmann\Environment\Runtime;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tests @see LicenseReportCommand.
 *
 * @author Linus Karlsson <linus.karlsson@debricked.com>
 */
class LicenseReportCommandTest extends KernelTestCase
{
    private Command $command;
    private CommandTester $commandTester;

    public function testExecuteInvalidFormat()
    {
        $this->setUpMocks([]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            LicenseReportCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'yaml',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/Invalid format/', $output);
    }

    public function testExecuteInvalidCredentials()
    {
        $this->setUpMocks([
            new MockResponse('{"code":401,"message":"Invalid credentials."}', ['http_code' => 401]),
            new MockResponse('{"code":401,"message":"Invalid credentials."}', ['http_code' => 401]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => 'invalid@invalid.invalid',
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => 'invalid',
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/Invalid\s+credentials./', $output);
    }

    public function testExecuteWrongRoleOrNotOwnScan()
    {
        $this->setUpMocks([
            new MockResponse('{"code":403,"message":"Access Denied."}', ['http_code' => 403]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/Access Denied/', $output);
    }

    public function testExecuteInternalServerError()
    {
        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":3}', ['http_code' => 202]),
            new MockResponse('Failed to perform snippet scan', ['http_code' => 500]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(1, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/Failed\s+to\s+perform\s+snippet\s+scan/', $output);
    }

    public function testExecuteWithoutSnippetsJsonStdout()
    {
        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse('{"dependencyLicenses": []}', ['http_code' => 200]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'json',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/License\s+report\s+generation\s+finished.\s+See below.*dependencyLicenses.*/s', $output);
    }

    public function testExecuteWithoutSnippetsDefaultProfileJsonStdout()
    {
        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse('{"dependencyLicenses": []}', ['http_code' => 200]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'json',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/License\s+report\s+generation\s+finished.\s+See below.*dependencyLicenses.*/s', $output);
    }

    public function testExecuteSuccessWithSnippetsCsvStdout()
    {
        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse("name,version,licenses,risks\na,b,c,d\n\nfile,lines,oss_file,oss_lines,oss_archive,licenses,risks\n", ['http_code' => 200]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'csv',
            '--' . LicenseReportCommand::OPTION_SNIPPETS => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/License\s+report\s+generation\s+finished.\s+See below.*a,b,c,d/s', $output);
    }

    public function testExecuteSuccessWithSnippetsJsonStdout()
    {
        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse('{"dependencyLicenses": [], "snippetLicenses":[]}', ['http_code' => 200]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'json',
            '--' . LicenseReportCommand::OPTION_SNIPPETS => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/License\s+report\s+generation\s+finished.\s+See below/', $output);
        $this->assertRegExp('/dependencyLicenses/', $output);
        $this->assertRegExp('/snippetLicenses/', $output);
    }

    public function testExecuteSuccessWithSnippetsJsonToFile()
    {
        $reportContent = '{"dependencyLicenses": [], "snippetLicenses":[]}';
        $outputFilename = 'test_report.json';

        $filesystem = new Filesystem();
        $this->assertFalse($filesystem->exists($outputFilename), 'Output file should not exist before test!');

        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse($reportContent, ['http_code' => 200]),
        ]);

        $this->commandTester->execute([
            'command' => $this->command->getName(),
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
            LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
            '--' . LicenseReportCommand::OPTION_FORMAT => 'json',
            '--' . LicenseReportCommand::OPTION_OUTPUT_FILE => $outputFilename,
            '--' . LicenseReportCommand::OPTION_SNIPPETS => null,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertEquals(0, $this->commandTester->getStatusCode(), $output);
        $this->assertRegExp('/License\s+report\s+generation\s+finished.\s+See\s+test_report\.json\s+for\s+the/s', $output);
        $this->assertNotRegExp('/dependencyLicenses/', $output);

        $this->assertEquals($reportContent, \file_get_contents($outputFilename));
        $filesystem->remove($outputFilename);
    }

    public function testExecuteSuccessJsonToNonWritableFile()
    {
        $reportContent = '{"dependencyLicenses": [], "snippetLicenses":[]}';
        $outputFilename = 'test_report2.json';

        $filesystem = new Filesystem();
        $this->assertFalse($filesystem->exists($outputFilename), 'Output file should not exist before test!');

        // Simulate a non-writable file.
        $filesystem->touch($outputFilename);
        $filesystem->chmod($outputFilename, 0444);

        $this->setUpMocks([
            new MockResponse('{"progress":0}', ['http_code' => 202]),
            new MockResponse('{"progress":10}', ['http_code' => 202]),
            new MockResponse('{"progress":50}', ['http_code' => 202]),
            new MockResponse('{"progress":99}', ['http_code' => 202]),
            new MockResponse($reportContent, ['http_code' => 200]),
        ]);

        try {
            $this->commandTester->execute([
                'command' => $this->command->getName(),
                FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
                CheckScanCommand::ARGUMENT_UPLOAD_ID => '1337',
                LicenseReportCommand::ARGUMENT_PROFILE => 'distributed',
                '--' . LicenseReportCommand::OPTION_FORMAT => 'json',
                '--' . LicenseReportCommand::OPTION_OUTPUT_FILE => $outputFilename,
            ]);
            $this->fail('Should get exception');
        } catch (\Exception $e) {
            // Need empty catch to avoid linter errors.
        } finally {
            $filesystem->remove($outputFilename);
        }
    }

    private function setUpMocks(array $responses): void
    {
        $mockClient = new MockHttpClient($responses, $_ENV['DEBRICKED_API_URI']);

        $kernel = self::createKernel();
        $application = new Application($kernel);
        $application->add(new LicenseReportCommand($mockClient));
        $this->command = $application->find(LicenseReportCommand::getDefaultName());
        $this->commandTester = new CommandTester($this->command);
    }
}
