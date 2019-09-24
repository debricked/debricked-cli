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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;

class FindAndUploadFilesCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:find-and-upload-files';

    private const ARGUMENT_BASE_DIRECTORY = 'base-directory';
    public const ARGUMENT_USERNAME = 'username';
    public const ARGUMENT_PASSWORD = 'password';
    private const ARGUMENT_REPOSITORY_NAME = 'repository-name';
    private const ARGUMENT_COMMIT_NAME = 'commit-name';
    private const ARGUMENT_BRANCH_NAME = 'branch-name';
    private const OPTION_RECURSIVE_FILE_SEARCH = 'recursive-file-search';
    private const OPTION_DIRECTORIES_TO_EXCLUDE = 'excluded-directories';

    /**
     * @var ClientInterface
     */
    private $debrickedClient;

    public function __construct(ClientInterface $debrickedClient, $name = null)
    {
        parent::__construct($name);

        $this->debrickedClient = $debrickedClient;
    }

    protected function configure()
    {
        $this
            ->setDescription('Searches given directory (by default current directory) after dependency files.')
            ->setHelp(
                'Supported dependency formats include NPM, Yarn, Composer, pip, Ruby Gems and more. For a full list' .
                ', please visit https://debricked.com'
            )
            ->addArgument(
                self::ARGUMENT_USERNAME,
                InputArgument::REQUIRED,
                'Your Debricked username',
                null
            )
            ->addArgument(
                self::ARGUMENT_PASSWORD,
                InputArgument::REQUIRED,
                'Your Debricked password',
                null
            )
            ->addArgument(
                self::ARGUMENT_REPOSITORY_NAME,
                InputArgument::REQUIRED,
                'Repository to associate found files with',
                null
            )
            ->addArgument(
                self::ARGUMENT_COMMIT_NAME,
                InputArgument::REQUIRED,
                'Commit to associate found files with',
                null
            )
            ->addArgument(
                self::ARGUMENT_BRANCH_NAME,
                InputArgument::OPTIONAL,
                'Branch to associate found files with',
                null
            )
            ->addArgument(
                self::ARGUMENT_BASE_DIRECTORY,
                InputArgument::OPTIONAL,
                'The base directory (relative to current working directory) to recursively find dependency files in. Default is current working directory.',
                ''
            )
            ->addOption(
                self::OPTION_RECURSIVE_FILE_SEARCH,
                null,
                InputOption::VALUE_REQUIRED,
                'Set to 0 to disable recursive search - only base directory will be searched',
                1
            )
            ->addOption(
                self::OPTION_DIRECTORIES_TO_EXCLUDE,
                null,
                InputOption::VALUE_REQUIRED,
                'Enter a comma separated list of directories to exclude. Such as: --excluded-directories="vendor,node_modules"',
                'vendor,node_modules'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $username = \strval($input->getArgument(self::ARGUMENT_USERNAME));
        $password = \strval($input->getArgument(self::ARGUMENT_PASSWORD));
        $api = new API(
            $this->debrickedClient,
            $username,
            $password
        );

        $workingDirectory = \getcwd();
        if ($workingDirectory === false) {
            $io->warning(
                'Failed to get current working directory, command might be searching through unexpected directories and files'
            );
        }

        /** @var string $baseDirectory */
        $baseDirectory = $input->getArgument(self::ARGUMENT_BASE_DIRECTORY);

        $io->section('Getting supported dependency file names from Debricked');
        try {
            $dependencyFileNamesResponse = $api->makeApiCall(
                Request::METHOD_GET,
                '/api/1.0/open/supported/dependency/files'
            );
        } catch (GuzzleException $e) {
            $io->error("Failed to get supported dependency file names: {$e->getMessage()}");

            return 1;
        }

        $dependencyFileNames = \json_decode($dependencyFileNamesResponse->getBody());

        $directoriesToExcludeString = \strval($input->getOption(self::OPTION_DIRECTORIES_TO_EXCLUDE));
        $searchDirectory = $workingDirectory . $baseDirectory;
        $finder = new Finder();
        $finder->files()->in($searchDirectory);
        if (empty($directoriesToExcludeString) === false && \is_array(
                $directoriesToExcludeArray = \explode(',', $directoriesToExcludeString)
            )) {
            $finder->exclude($directoriesToExcludeArray);
        } else {
            $io->note('No directories will be ignored');
        }

        if (\boolval($input->getOption(self::OPTION_RECURSIVE_FILE_SEARCH)) === false) {
            $finder->depth(0);
            $io->note('Recursive search is disabled, only base directory will be searched');
        }

        $io->section(
            "Uploading dependency files to Debricked, starting from {$searchDirectory}, ignoring \"{$directoriesToExcludeString}\""
        );
        $uploadId = null;
        $uploadedFilePaths = [];
        $progressBar = new ProgressBar($output);
        $progressBar->start();
        $progressBar->setFormat(' %current% file(s) found [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
        $this->setProgressBarStyle($progressBar);
        foreach ($finder as $file) {
            if (\in_array($fileName = $file->getFilename(), $dependencyFileNames) === true) {
                $uploadData =
                    [
                        ['name' => 'fileData', 'contents' => $file->getContents(), 'filename' => $file->getFilename()],
                        ['name' => 'repositoryName', 'contents' => $input->getArgument(self::ARGUMENT_REPOSITORY_NAME)],
                        ['name' => 'commitName', 'contents' => $input->getArgument(self::ARGUMENT_COMMIT_NAME)],
                    ];

                $branchName = $input->getArgument(self::ARGUMENT_BRANCH_NAME);

                if (empty($branchName) === false) {
                    $uploadData[] = ['name' => 'branchName', 'contents' => $branchName];
                }

                if ($uploadId !== null) {
                    $uploadData[] = ['name' => 'ciUploadId', 'contents' => $uploadId];
                }

                try {
                    $uploadResponse = $api->makeApiCall(
                        Request::METHOD_POST,
                        '/api/1.0/open/uploads/dependencies/files',
                        [
                            RequestOptions::MULTIPART => $uploadData,
                        ]
                    );
                } catch (GuzzleException $e) {
                    $io->warning("Failed to upload {$fileName}, error: {$e->getMessage()}");
                    continue;
                }

                $uploadContent = \json_decode($uploadResponse->getBody(), true);

                $uploadId = $uploadContent['ciUploadId'];
                $uploadedFilePaths[] = $file->getPathname();
                $progressBar->advance();
            }
        }
        $progressBar->finish();
        $io->newLine(2);

        if ($uploadId !== null) {
            try {
                $api->makeApiCall(
                    Request::METHOD_POST,
                    '/api/1.0/open/finishes/dependencies/files/uploads',
                    [
                        RequestOptions::JSON => [
                            'ciUploadId' => $uploadId,
                        ],
                    ]
                );
            } catch (GuzzleException $e) {
                $io->warning("Failed to conclude upload, error: {$e->getMessage()}");

                return 2;
            }

            $uploadedFilePathsString = \implode("\n ", $uploadedFilePaths);
            $io->success("Successfully found and uploaded {$uploadedFilePathsString}");
            $checkScanCommand = CheckScanCommand::getDefaultName();
            $io->text(
                "You can now execute <fg=green>bin/console $checkScanCommand your-username your-password $uploadId</> to track the vulnerability scan"
            );
        } else {
            $io->warning('Nothing to upload!');
        }

        return 0;
    }
}
