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

use App\API\API;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function configure()
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
                'Your Debricked username',
                null
            )
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD,
                InputArgument::REQUIRED,
                'Your Debricked password',
                null
            )
            ->addArgument(
                self::ARGUMENT_UPLOAD_ID,
                InputArgument::REQUIRED,
                "Upload id you got from running $findAndUploadCommand",
                null
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
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

                $status = \json_decode($statusResponse->getContent(), true);
                if (\intval($status['progress']) !== -1) {
                    $progressBar->setMessage("{$status['vulnerabilitiesFound']} vulnerabilities found ({$status['unaffectedVulnerabilitiesFound']} have been marked as unaffected)");
                }
                if ($statusResponse->getStatusCode() === Response::HTTP_OK) {
                    break;
                }

                $progressBar->setProgress($status['progress']);
                sleep(1);
            }
        } catch (TransportExceptionInterface $e) {
            $io->error("\n\nAn error occurred while getting scan status: {$e->getMessage()}");

            return 1;
        } catch (ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("\n\nAn error occurred while getting scan status: {$e->getResponse()->getContent(false)}");

            return 1;
        }
        $progressBar->finish();

        $io->newLine(2);
        $urlMessage = "Please visit {$status['detailsUrl']} for more information.";
        if ($status['vulnerabilitiesFound'] > 0) {
            $io->error("\n\nScan completed, {$status['vulnerabilitiesFound']} vulnerabilities found. An additional {$status['unaffectedVulnerabilitiesFound']} vulnerabilities have been marked as unaffected.");
        } else {
            $io->success("\n\nScan completed, no vulnerabilities ({$status['unaffectedVulnerabilitiesFound']} have been marked as unaffected) found at this moment.");
        }
        $io->text($urlMessage);

        return 0;
    }
}
