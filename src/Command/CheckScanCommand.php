<?php
/**
 * @license
 *
 * Copyright (C) debricked AB
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code (usually found in the root of this application).
 */

namespace App\Command;

use Debricked\Shared\API\API;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CheckScanCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:check-scan';

    public const ARGUMENT_UPLOAD_ID = 'upload-id';

    /**
     * @var HttpClientInterface
     */
    private $debrickedClient;

    public function __construct(HttpClientInterface $debrickedClient, $name = null)
    {
        parent::__construct($name);

        $this->debrickedClient = $debrickedClient;
    }

    protected function configure(): void
    {
        $findAndUploadCommand = FindAndUploadFilesCommand::getDefaultName();

        $this
            ->setDescription('Checks and prints status of given upload until it finish')
            ->setHelp(
                "Make sure to use run $findAndUploadCommand before so you have an upload to check."
            )
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_USERNAME,
                InputArgument::REQUIRED,
                'Your Debricked username. Set to an empty string if you use an access token.',
                null
            )
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD,
                InputArgument::REQUIRED,
                'Your Debricked password or access token',
                null
            )
            ->addArgument(
                self::ARGUMENT_UPLOAD_ID,
                InputArgument::REQUIRED,
                "Upload id you got from running $findAndUploadCommand",
                null
            )
            ->addOption(
                CompoundCommand::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN,
                null,
                InputOption::VALUE_NONE,
                'Use this option to disable skip scan from ever triggering. Default is to allow skip scan triggering because of long queue times (=false).'
            );
    }

    private const ACTION_STRINGS = [
        'warnPipeline' => 'a pipeline warning',
        'failPipeline' => 'a pipeline failure',
        'sendEmail' => 'an email notification',
        'markUnaffected' => 'the vulnerabilities to be marked as unaffected',
        'markVulnerable' => 'the vulnerabilities to be flagged as vulnerable',
        'triggerWebhook' => 'a webhook notification',
    ];

    /**
     * @param mixed[] $ruleOutputData
     */
    private function writeAutomationOutput(array $ruleOutputData, SymfonyStyle $io): void
    {
        $io->block($ruleOutputData['ruleDescription'], null, 'fg=cyan;bg=default', ' | ');

        if ($ruleOutputData['triggered'] === false) {
            $io->text('<fg=green>✔</> The rule did not trigger');
        } else {
            $actions = $ruleOutputData['ruleActions'];

            $causingString = '';
            for ($i = 0; $i < \count($actions); ++$i) {
                if ($i !== 0) {
                    $causingString .= $i + 1 === \count($actions) ? ' and ' : ', ';
                }
                if (\array_key_exists($actions[$i], self::ACTION_STRINGS) === true) {
                    $causingString .= self::ACTION_STRINGS[$actions[$i]];
                }
            }

            if (\in_array('failPipeline', $actions)) {
                $fgColor = 'red';
            } elseif (\in_array('warnPipeline', $actions)) {
                $fgColor = 'yellow';
            } else {
                $fgColor = 'blue';
            }

            $io->text("<fg=${fgColor};options=bold>⨯ The rule triggered, causing ${causingString}</>");
        }

        $io->text('  Manage rule: <fg=blue>'.$ruleOutputData['ruleLink'].'</>');

        if ($ruleOutputData['triggered'] === true) {
            $io->newLine();
            $io->text('The rule triggered for:');

            $hasCves = $ruleOutputData['hasCves'];
            if ($hasCves === true) {
                $tableHeader = ['Vulnerability', 'CVSS2', 'CVSS3', 'Dependency', 'Dependency Licenses'];
            } else {
                $tableHeader = ['Dependency', 'Dependency Licenses'];
            }

            $tableRows = \array_map(function ($trigger) use ($hasCves) {
                $row = [];
                if ($hasCves === true) {
                    $row[] = $trigger['cve']."\n<fg=blue>".$trigger['cveLink']."</>\n";
                    $row[] = $trigger['cvss2'] ?? '';
                    $row[] = $trigger['cvss3'] ?? '';
                }

                $row[] = $trigger['dependency']."\n<fg=blue>".$trigger['dependencyLink']."</>\n";
                $row[] = \implode(', ', $trigger['licenses']);

                return $row;
            }, $ruleOutputData['triggerEvents']);

            $io->table($tableHeader, $tableRows);
        }

        $io->newLine(3);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $api = new API(
            $this->debrickedClient,
            \strval($input->getArgument(FindAndUploadFilesCommand::ARGUMENT_USERNAME)),
            \strval($input->getArgument(FindAndUploadFilesCommand::ARGUMENT_PASSWORD))
        );
        $uploadId = strval($input->getArgument(self::ARGUMENT_UPLOAD_ID));

        $io->section("Checking scan status of upload with ID $uploadId");

        $progressBar = $io->createProgressBar();
        $progressBar->setMessage('Upload is in scan queue');
        $progressBar->setFormat(
            ' %current%% done [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%'
        );
        $this->setProgressBarStyle($progressBar);
        $progressBar->start(100);
        $status = [
            'progress' => 0,
            'vulnerabilitiesFound' => 0,
        ];

        $disableConditionalSkipScan = \boolval($input->getOption(CompoundCommand::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN));
        try {
            while (true) {
                $statusResponse = $api->makeApiCall(
                    Request::METHOD_GET,
                    '/api/1.0/open/ci/upload/status',
                    [
                        'query' => [
                            'ciUploadId' => $uploadId,
                        ],
                    ]
                );

                $statusCode = $statusResponse->getStatusCode();
                if ($disableConditionalSkipScan === false && $statusCode === Response::HTTP_CREATED) {
                    break;
                }

                $status = \json_decode($statusResponse->getContent(), true);
                if (isset($status['progress']) && \intval($status['progress']) !== -1) {
                    $progressBar->setMessage("{$status['vulnerabilitiesFound']} vulnerabilities found ({$status['unaffectedVulnerabilitiesFound']} have been marked as unaffected)");
                }

                if ($statusCode === Response::HTTP_OK) {
                    break;
                }

                if (isset($status['progress'])) {
                    $progressBar->setProgress($status['progress']);
                }

                sleep(1);
            }
        } catch (TransportExceptionInterface $e) {
            $io->error("\n\nAn error occurred while getting scan status: {$e->getMessage()}");

            return 1;
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("\n\nAn error occurred while getting scan status: {$e->getResponse()->getContent(false)}");

            return 1;
        }
        $progressBar->finish();

        $io->newLine(2);

        if ($disableConditionalSkipScan === false && $statusCode === Response::HTTP_CREATED) {
            $urlMessage = $statusResponse->getContent();
            $io->text($urlMessage);

            return 0;
        }

        $urlMessage = "Please visit {$status['detailsUrl']} for more information.";
        if ($status['vulnerabilitiesFound'] > 0) {
            $message = "\n\nScan completed, {$status['vulnerabilitiesFound']} vulnerabilities found.\n\nAn additional {$status['unaffectedVulnerabilitiesFound']} vulnerabilities have been marked as unaffected.";
            $io->block($message, 'VULNERABILITIES FOUND', 'fg=white;bg=red', ' ', true);
        } else {
            $io->success("\n\nScan completed, no vulnerabilities ({$status['unaffectedVulnerabilitiesFound']} have been marked as unaffected) found at this moment.");
        }

        $io->text($urlMessage);

        if (isset($status['automationRules'])) {
            $io->section('Output from automations');

            $numRulesChecked = \count($status['automationRules']);
            if ($numRulesChecked === 0) {
                $io->text('No rules were checked');
            } elseif ($numRulesChecked === 1) {
                $io->text('1 rule was checked:');
            } else {
                $io->text("${numRulesChecked} rules were checked:");
            }

            foreach ($status['automationRules'] as $rule) {
                $this->writeAutomationOutput($rule, $io);
            }
        }

        $automationsAction = 'none';
        if (isset($status['automationsAction'])) {
            $automationsAction = $status['automationsAction'];
        } elseif (isset($status['policyEngineAction'])) {
            $automationsAction = $status['policyEngineAction'];
        }
        if ($automationsAction !== 'none') {
            if ($automationsAction === 'fail') {
                $io->error("\n\nAn automation rule triggered a pipeline failure.");

                return 2;
            } elseif ($automationsAction === 'warn') {
                $io->caution("\n\nAn automation rule triggered a pipeline warning.");
            }
        }

        return 0;
    }
}
