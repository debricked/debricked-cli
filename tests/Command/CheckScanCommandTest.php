<?php

namespace App\Tests\Command;

use App\Command\CheckScanCommand;
use App\Command\CompoundCommand;
use App\Command\FindAndUploadFilesCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

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

    private CommandTester $commandTester;

    public function setUp(): void
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
        $this->assertMatchesRegularExpression('/No\s+upload\s+with\s+ID/', $output);
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
        $this->assertMatchesRegularExpression('/Invalid\s+credentials./', $output);
    }

    private function runAutomationsActionTest(
        string $action,
        int $expectedStatusCode = 0,
        string $automationsActionFieldName = 'automationsAction',
        array $automationRules = null
    ): string {
        $response = new MockResponse(\json_encode([
            'progress' => 100,
            'vulnerabilitiesFound' => 0,
            'unaffectedVulnerabilitiesFound' => 0,
            $automationsActionFieldName => $action,
            'detailsUrl' => '',
            'automationRules' => $automationRules,
        ]));
        $httpClient = new MockHttpClient([$response], 'https://debricked.com');
        $command = new CheckScanCommand($httpClient, 'name');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);
        $output = $commandTester->getDisplay();
        $this->assertEquals($expectedStatusCode, $commandTester->getStatusCode(), $output);

        return $output;
    }

    public function testAutomationsActionNone()
    {
        $output = $this->runAutomationsActionTest('none');
        $this->assertStringNotContainsString('An automation rule triggered a pipeline warning.', $output);
        $this->assertStringNotContainsString('An automation rule triggered a pipeline failure.', $output);
    }

    public function testAutomationsActionWarn()
    {
        $output = $this->runAutomationsActionTest('warn');
        $this->assertStringContainsString('An automation rule triggered a pipeline warning.', $output);
        $this->assertStringNotContainsString('An automation rule triggered a pipeline failure.', $output);
    }

    public function testAutomationsActionFail()
    {
        $output = $this->runAutomationsActionTest('fail', 2);
        $this->assertStringContainsString('An automation rule triggered a pipeline failure.', $output);
        $this->assertStringNotContainsString('An automation rule triggered a pipeline warning.', $output);
    }

    public function testPolicyEngineActionFail()
    {
        $output = $this->runAutomationsActionTest('fail', 2, 'policyEngineAction');
        $this->assertStringContainsString('An automation rule triggered a pipeline failure.', $output);
        $this->assertStringNotContainsString('An automation rule triggered a pipeline warning.', $output);
    }

    public function testAutomationOutputUntriggered()
    {
        $output = $this->runAutomationsActionTest('none', 0, 'automationsAction', [
            [
                'ruleDescription' => 'untriggered description<fg=red>',
                'ruleLink' => 'link-to-rule',
                'triggered' => false,
            ],
        ]);

        $this->assertStringContainsString("\nOutput from automations\n", $output);
        $this->assertStringContainsString("\n 1 rule was checked:\n", $output);
        $this->assertStringContainsString("\n | untriggered description<fg=red>", $output);
        $this->assertStringContainsString("\n   Manage rule: link-to-rule\n", $output);
        $this->assertStringContainsString("\n ✔ The rule did not trigger\n", $output);
    }

    public function testAutomationOutputPipelineFailure()
    {
        $output = $this->runAutomationsActionTest('none', 0, 'automationsAction', [
            [
                'ruleDescription' => "rule description 1\nrule description 2",
                'ruleLink' => 'link-to-rule',
                'triggered' => true,
                'ruleActions' => ['failPipeline', 'sendEmail'],
                'hasCves' => true,
                'triggerEvents' => [],
            ],
        ]);

        $this->assertStringContainsString("\nOutput from automations\n", $output);
        $this->assertStringContainsString("\n 1 rule was checked:\n", $output);
        $this->assertStringContainsString("\n | rule description 1", $output);
        $this->assertStringContainsString("\n | rule description 2", $output);
        $this->assertStringContainsString("\n   Manage rule: link-to-rule\n", $output);
        $this->assertStringContainsString("\n ⨯ The rule triggered, causing a pipeline failure and an email notification\n", $output);
    }

    public function testAutomationOutputPipelineWarning()
    {
        $output = $this->runAutomationsActionTest('warn', 0, 'automationsAction', [
            [
                'ruleDescription' => "rule description 1\nrule description 2",
                'ruleLink' => 'link-to-rule',
                'triggered' => true,
                'ruleActions' => ['warnPipeline'],
                'hasCves' => true,
                'triggerEvents' => [],
            ],
        ]);

        $this->assertStringContainsString("\nOutput from automations\n", $output);
        $this->assertStringContainsString("\n 1 rule was checked:\n", $output);
        $this->assertStringContainsString("\n | rule description 1", $output);
        $this->assertStringContainsString("\n | rule description 2", $output);
        $this->assertStringContainsString("\n   Manage rule: link-to-rule\n", $output);
        $this->assertStringContainsString("\n ⨯ The rule triggered, causing a pipeline warning\n", $output);
    }

    public function testAutomationOutputMultipleRules()
    {
        $output = $this->runAutomationsActionTest('fail', 2, 'automationsAction', [
            [
                'ruleDescription' => 'rule description 1',
                'ruleLink' => 'link-to-rule-1',
                'triggered' => true,
                'ruleActions' => ['failPipeline'],
                'hasCves' => true,
                'triggerEvents' => [
                    [
                        'cve' => 'cve-1',
                        'cvss2' => 8,
                        'cvss3' => 9,
                        'cveLink' => 'cve-link-1',
                        'dependency' => 'dep-1',
                        'dependencyLink' => 'dep-link-1',
                        'licenses' => ['gpl3'],
                    ],
                    [
                        'cve' => 'cve-2',
                        'cveLink' => 'cve-link-2',
                        'cvss2' => 7,
                        'dependency' => 'dep-2',
                        'dependencyLink' => 'dep-link-2',
                        'licenses' => ['mit'],
                    ],
                ],
            ],
            [
                'ruleDescription' => 'rule description 2',
                'ruleLink' => 'link-to-rule-2',
                'triggered' => false,
                'ruleActions' => ['failPipeline'],
            ],
            [
                'ruleDescription' => 'rule description 3',
                'ruleLink' => 'link-to-rule-3',
                'triggered' => true,
                'ruleActions' => ['warnPipeline', 'sendEmail'],
                'hasCves' => false,
                'triggerEvents' => [
                    [
                        'dependency' => 'dep-1',
                        'dependencyLink' => 'dep-link-1',
                        'licenses' => ['apache', 'mit'],
                    ],
                    [
                        'dependency' => 'dep-3',
                        'dependencyLink' => 'dep-link-3',
                        'licenses' => ['mit'],
                    ],
                ],
            ],
        ]);

        $outputLines = \array_map('rtrim', \explode("\n", $output));

        $this->assertContains('Output from automations', $outputLines);
        $this->assertContains(' 3 rules were checked:', $outputLines);

        $rule1Begin = \array_search(' | rule description 1', $outputLines);
        $this->assertNotFalse($rule1Begin);
        $this->assertEquals(' ⨯ The rule triggered, causing a pipeline failure', $outputLines[$rule1Begin + 2]);
        $this->assertEquals('   Manage rule: link-to-rule-1', $outputLines[$rule1Begin + 3]);
        $this->assertEquals(' The rule triggered for:', $outputLines[$rule1Begin + 5]);
        $this->assertStringStartsWith(' ---', $outputLines[$rule1Begin + 6]);
        $this->assertEquals(
            ['Vulnerability', 'CVSS2', 'CVSS3', 'Dependency', 'Dependency', 'Licenses'],
            \preg_split('/\s+/', $outputLines[$rule1Begin + 7], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertStringStartsWith(' ---', $outputLines[$rule1Begin + 8]);
        $this->assertEquals(
            ['cve-1', '8', '9', 'dep-1', 'gpl3'],
            \preg_split('/\s+/', $outputLines[$rule1Begin + 9], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals(
            ['cve-link-1', 'dep-link-1'],
            \preg_split('/\s+/', $outputLines[$rule1Begin + 10], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals('', $outputLines[$rule1Begin + 11]);
        $this->assertEquals(
            ['cve-2', '7', 'dep-2', 'mit'],
            \preg_split('/\s+/', $outputLines[$rule1Begin + 12], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals(
            ['cve-link-2', 'dep-link-2'],
            \preg_split('/\s+/', $outputLines[$rule1Begin + 13], null, PREG_SPLIT_NO_EMPTY)
        );

        $rule2Begin = \array_search(' | rule description 2', $outputLines);
        $this->assertNotFalse($rule2Begin);
        $this->assertEquals(' ✔ The rule did not trigger', $outputLines[$rule2Begin + 2]);
        $this->assertEquals('   Manage rule: link-to-rule-2', $outputLines[$rule2Begin + 3]);

        $rule3Begin = \array_search(' | rule description 3', $outputLines);
        $this->assertNotFalse($rule3Begin);
        $this->assertEquals(' ⨯ The rule triggered, causing a pipeline warning and an email notification', $outputLines[$rule3Begin + 2]);
        $this->assertEquals('   Manage rule: link-to-rule-3', $outputLines[$rule3Begin + 3]);
        $this->assertEquals(' The rule triggered for:', $outputLines[$rule3Begin + 5]);
        $this->assertStringStartsWith(' ---', $outputLines[$rule3Begin + 6]);
        $this->assertEquals(
            ['Dependency', 'Dependency', 'Licenses'],
            \preg_split('/\s+/', $outputLines[$rule3Begin + 7], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertStringStartsWith(' ---', $outputLines[$rule3Begin + 8]);
        $this->assertEquals(
            ['dep-1', 'apache,', 'mit'],
            \preg_split('/\s+/', $outputLines[$rule3Begin + 9], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals(
            ['dep-link-1'],
            \preg_split('/\s+/', $outputLines[$rule3Begin + 10], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals('', $outputLines[$rule3Begin + 11]);
        $this->assertEquals(
            ['dep-3', 'mit'],
            \preg_split('/\s+/', $outputLines[$rule3Begin + 12], null, PREG_SPLIT_NO_EMPTY)
        );
        $this->assertEquals(
            ['dep-link-3'],
            \preg_split('/\s+/', $outputLines[$rule3Begin + 13], null, PREG_SPLIT_NO_EMPTY)
        );

        $this->assertGreaterThan($rule1Begin, $rule2Begin);
        $this->assertGreaterThan($rule2Begin, $rule3Begin);
    }

    public function testQueueTimeTooLongConditionalSkipScanEnabled()
    {
        $response = new MockResponse('The queue time was too long', ['http_code' => Response::HTTP_CREATED]);

        //test when option is omitted
        $httpClient = new MockHttpClient([$response], 'https://debricked.com');
        $this->assertQueueTimeTooLongConditionalSkipScan($httpClient, true, false);

        //test when option is set to false
        $httpClient = new MockHttpClient([$response], 'https://debricked.com');
        $this->assertQueueTimeTooLongConditionalSkipScan($httpClient, false, false);
    }

    public function testQueueTimeTooLongConditionalSkipScanDisabled()
    {
        $responseQueueTimeTooLong = new MockResponse('The queue time was too long', ['http_code' => Response::HTTP_CREATED]);
        $responseScanFinished = new MockResponse(
            \json_encode([
                'progress' => 100,
                'vulnerabilitiesFound' => 0,
                'unaffectedVulnerabilitiesFound' => 0,
                'detailsUrl' => '',
            ]),
            ['http_code' => Response::HTTP_OK]
        );

        //test when option is null (same as true but no value given to it)
        $httpClient = new MockHttpClient([$responseQueueTimeTooLong, $responseScanFinished], 'https://debricked.com');
        $this->assertQueueTimeTooLongConditionalSkipScan($httpClient, false, null);

        //test when option is true
        $httpClient = new MockHttpClient([$responseQueueTimeTooLong, $responseScanFinished], 'https://debricked.com');
        $this->assertQueueTimeTooLongConditionalSkipScan($httpClient, false, true);
    }

    private function assertQueueTimeTooLongConditionalSkipScan(MockHttpClient $httpClient, bool $optionOmitted, ?bool $disableConditionalSkipScan)
    {
        $command = new CheckScanCommand($httpClient, 'name');
        $commandTester = new CommandTester($command);

        $commandArguments = [
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ];

        if (!$optionOmitted) {
            $commandArguments[CompoundCommand::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN_WITH_DASHES] = $disableConditionalSkipScan;
        }

        $commandTester->execute($commandArguments);

        $output = $commandTester->getDisplay();
        $this->assertEquals(0, $commandTester->getStatusCode(), $output);

        if ($disableConditionalSkipScan === true || $disableConditionalSkipScan === null) {
            $this->assertStringNotContainsString('The queue time was too long', $output);
            $this->assertStringContainsString(CheckScanCommand::MESSAGE_LONG_SCAN_QUEUES, $output);
            $this->assertStringContainsString('Scan completed', $output);
        } else {
            $this->assertStringContainsString('The queue time was too long', $output);
            $this->assertStringNotContainsString(CheckScanCommand::MESSAGE_LONG_SCAN_QUEUES, $output);
            $this->assertStringNotContainsString('Scan completed', $output);
        }
    }

    public function testVulnerabilitiesFound()
    {
        $response = new MockResponse(\json_encode([
            'progress' => 100,
            'vulnerabilitiesFound' => 5,
            'unaffectedVulnerabilitiesFound' => 0,
            'detailsUrl' => '',
        ]));
        $httpClient = new MockHttpClient([$response], 'https://debricked.com');
        $command = new CheckScanCommand($httpClient, 'name');

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            FindAndUploadFilesCommand::ARGUMENT_USERNAME => $_ENV['DEBRICKED_USERNAME'],
            FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $_ENV['DEBRICKED_PASSWORD'],
            CheckScanCommand::ARGUMENT_UPLOAD_ID => '0',
        ]);

        $output = $commandTester->getDisplay();
        $this->assertEquals(0, $commandTester->getStatusCode(), $output);
        $this->assertStringContainsString('VULNERABILITIES FOUND', $output);
    }
}
