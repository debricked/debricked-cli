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
use Symfony\Contracts\HttpClient\ResponseInterface;

class LicenseReportCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:license-report';

    public const ARGUMENT_PROFILE = 'profile';
    public const ARGUMENT_UPLOAD_ID = 'upload-id';
    public const OPTION_FORMAT = 'format';
    public const OPTION_OUTPUT_FILE = 'output';
    public const OPTION_SNIPPETS = 'snippets';

    private HttpClientInterface $debrickedClient;

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
            ->addArgument(
                self::ARGUMENT_PROFILE,
                InputArgument::OPTIONAL,
                'The license risk profile you wish to use for this scan: internal, network, distributed, or consumer-electronic',
                null,
            )
            ->addOption(
                self::OPTION_FORMAT,
                'f',
                InputOption::VALUE_REQUIRED,
                'The format of the output, either "csv" or "json"',
                'json'
            )
            ->addOption(
                self::OPTION_OUTPUT_FILE,
                'o',
                InputOption::VALUE_REQUIRED,
                'The file to write the resulting report to. If not given, print to stdout.'
            )
            ->addOption(
                self::OPTION_SNIPPETS,
                's',
                InputOption::VALUE_NONE,
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $api = new API(
            $this->debrickedClient,
            \strval($input->getArgument(FindAndUploadFilesCommand::ARGUMENT_USERNAME)),
            \strval($input->getArgument(FindAndUploadFilesCommand::ARGUMENT_PASSWORD))
        );
        $uploadId = \intval($input->getArgument(self::ARGUMENT_UPLOAD_ID));
        $profile = $input->getArgument(self::ARGUMENT_PROFILE);
        if ($profile !== null) {
            $profile = \strval($profile);
        }
        $outputFilename = $input->getOption(self::OPTION_OUTPUT_FILE);
        $format = \strval($input->getOption(self::OPTION_FORMAT));

        // Validate arguments.
        if ($format !== 'json' && $format !== 'csv') {
            $io->error("Invalid format given, must be either json or csv, you entered: $format");

            return 1;
        } else {
            $format = $format === 'csv' ? 'text/csv' : 'application/json';
        }

        $outputFile = null;
        if ($outputFilename !== null) {
            // Try to open output file already here, such that a permission error will be evident early.
            $outputFile = \fopen(\strval($outputFilename), 'w');
            if ($outputFile === false) {
                $io->error("Failed to open $outputFile for writing. Aborting.");

                return 1;
            }
        }

        $snippets = \boolval($input->getOption(self::OPTION_SNIPPETS));

        // Fetch license report
        $io->section("Generating license report for ID $uploadId");

        $progressBar = $io->createProgressBar();
        $progressBar->setMessage('Generating report');
        $progressBar->setFormat(
            ' %current%% done [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%'
        );
        $this->setProgressBarStyle($progressBar);
        $progressBar->start(100);
        $reportData = null;

        try {
            while (true) {
                $statusResponse = $this->makeRequest($api, $uploadId, $profile, $format, $snippets);

                if ($statusResponse->getStatusCode() === Response::HTTP_OK) {
                    // License report generation is finished!
                    $reportData = $statusResponse->getContent();
                    break;
                }

                $status = \json_decode($statusResponse->getContent(), true);
                $progressBar->setProgress($status['progress']);
                sleep(1);
            }
        } catch (TransportExceptionInterface $e) {
            $io->error("\n\nAn error occurred while generating license report: {$e->getMessage()}");

            return 1;
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("\n\nAn error occurred while generating license report: {$e->getResponse()->getContent(false)}");

            return 1;
        }
        $progressBar->finish();

        $io->newLine(2);

        // Finally write report either to stdout or to file.
        if ($outputFilename !== null && $outputFile !== null) {
            $outputFilename = \strval($outputFilename);
            $io->success("License report generation finished. See $outputFilename for the resulting report.");
            \fwrite($outputFile, $reportData);
            \fclose($outputFile);
        } else {
            $io->success('License report generation finished. See below for the resulting report.');
            $io->write($reportData);
        }

        return 0;
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function makeRequest(API $api, int $uploadId, ?string $profile, string $format, bool $snippets): ResponseInterface
    {
        $query = [
            'scanId' => $uploadId,
        ];

        if ($profile !== null) {
            $query = \array_merge($query, ['profile' => $profile]);
        }

        if ($snippets) {
            $query = \array_merge($query, ['snippets' => '1']);
        }

        return $api->makeApiCall(
            Request::METHOD_GET,
            '/api/1.0/license/report',
            [
                'headers' => [
                    'Accept' => $format,
                ],
                'query' => $query,
            ]
        );
    }
}
