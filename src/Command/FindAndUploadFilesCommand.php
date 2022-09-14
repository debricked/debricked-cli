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

use App\Analysis\SnippetAnalysis;
use App\Service\FileGroupFinder;
use App\Utility\Utility;
use Debricked\Shared\API\API;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FindAndUploadFilesCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:find-and-upload-files';

    public const ARGUMENT_BASE_DIRECTORY = 'base-directory';
    public const ARGUMENT_USERNAME = 'username';
    public const ARGUMENT_PASSWORD = 'password';
    private const ARGUMENT_REPOSITORY_NAME = 'repository-name';
    private const ARGUMENT_COMMIT_NAME = 'commit-name';
    private const ARGUMENT_REPOSITORY_URL = 'repository-url';
    private const ARGUMENT_INTEGRATION_NAME = 'integration-name';
    private const OPTION_BRANCH_NAME = 'branch-name';
    private const OPTION_DEFAULT_BRANCH = 'default-branch';
    public const OPTION_DIRECTORIES_TO_EXCLUDE = 'excluded-directories';
    private const OPTION_SNIPPET_ANALYSIS = 'snippet-analysis';
    private const OPTION_KEEP_ZIP = 'keep-zip';
    public const OPTION_RECURSIVE_FILE_SEARCH = 'recursive-file-search';
    private const OPTION_UPLOAD_ALL_FILES = 'upload-all-files';
    private const OPTION_AUTHOR = 'author';
    private const OPTION_LOCK_FILE_ONLY = 'lockfile';

    private HttpClientInterface $debrickedClient;

    /**
     * @var array<string, string>
     */
    private $blacklist;

    public function __construct(HttpClientInterface $debrickedClient, $name = null)
    {
        parent::__construct($name);

        $this->debrickedClient = $debrickedClient;
        $this->blacklist = ['jpg' => '', 'png' => '', 'gif' => '', 'tif' => '', 'jpeg' => '', 'bmp' => '', 'mp3' => '', 'mp4' => '', 'sql' => '', 'pdf' => ''];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Searches given directory (by default current directory) after dependency files.')
            ->setHelp(
                'Supported dependency formats include NPM, Yarn, Composer, pip, Ruby Gems and more. For a full list'.
                ', please visit https://debricked.com'
            )
            ->addArgument(
                self::ARGUMENT_USERNAME,
                InputArgument::REQUIRED,
                'Your Debricked username. Set to an empty string if you use an access token.',
            )
            ->addArgument(
                self::ARGUMENT_PASSWORD,
                InputArgument::REQUIRED,
                'Your Debricked password or access token',
            )
            ->addArgument(
                self::ARGUMENT_REPOSITORY_NAME,
                InputArgument::REQUIRED,
                'Repository to associate found files with',
            )
            ->addArgument(
                self::ARGUMENT_COMMIT_NAME,
                InputArgument::REQUIRED,
                'Commit to associate found files with',
            )
            ->addArgument(
                self::ARGUMENT_REPOSITORY_URL,
                InputArgument::REQUIRED,
                'The repository uri, to create the link to the dependency\'s file in suggested fix.',
            )
            ->addArgument(
                self::ARGUMENT_INTEGRATION_NAME,
                InputArgument::REQUIRED,
                'The integration name (azureDevOps, bitbucket or gitlab)',
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
                'Enter a comma separated list of directories to exclude. Such as: --excluded-directories="vendor,node_modules,tests"',
                'vendor,node_modules,tests'
            )
            ->addOption(
                self::OPTION_SNIPPET_ANALYSIS,
                null,
                InputOption::VALUE_NONE,
                'Use this option to enable snippet analysis.'
            )
            ->addOption(
                self::OPTION_BRANCH_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'Branch to associate found files with',
                ''
            )
            ->addOption(
                self::OPTION_KEEP_ZIP,
                null,
                InputOption::VALUE_NONE,
                'Set this option to keep the zip file after upload. Default false.'
            )
            ->addOption(
                self::OPTION_UPLOAD_ALL_FILES,
                null,
                InputOption::VALUE_REQUIRED,
                'Set to 1 to upload all files.',
                0
            )
            ->addOption(
                self::OPTION_AUTHOR,
                null,
                InputOption::VALUE_REQUIRED,
                'The author of the commit',
                ''
            )
            ->addOption(
                self::OPTION_DEFAULT_BRANCH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Default branch for the repository'
            )
            ->addOption(
                self::OPTION_LOCK_FILE_ONLY,
                'l',
                InputOption::VALUE_NONE,
                'Use this option to output lock files only'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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
        $searchDirectory = "{$workingDirectory}/{$baseDirectory}";
        $searchDirectory = preg_replace('#/+#', '/', $searchDirectory); // remove duplicate slashes.

        $directoriesToExcludeString = \strval($input->getOption(self::OPTION_DIRECTORIES_TO_EXCLUDE));
        $directoriesToExcludeArray = [];
        if (empty($directoriesToExcludeString) === false) {
            $directoriesToExcludeArray = \explode(',', $directoriesToExcludeString) ?? [];
        } else {
            $io->note('No directories will be ignored');
        }

        $recursiveFileSearch = $this->parseBooleanOption($input->getOption(self::OPTION_RECURSIVE_FILE_SEARCH));
        if ($recursiveFileSearch === null) {
            $io->error('Invalid value for recursive file search flag');

            return 1;
        } elseif ($recursiveFileSearch === false) {
            $io->note('Recursive search is disabled, only base directory will be searched');
        }

        $io->title('Uploading dependency files to Debricked');
        $io->listing([
            "Starting from $searchDirectory",
            "Ignoring \"{$directoriesToExcludeString}\"",
        ]);

        $repository = \strval($input->getArgument(self::ARGUMENT_REPOSITORY_NAME));
        $commit = \strval($input->getArgument(self::ARGUMENT_COMMIT_NAME));
        $lockFileOnly = (bool) $input->getOption(self::OPTION_LOCK_FILE_ONLY);

        $uploadId = null;

        try {
            $io->writeln('Getting supported dependency file names from Debricked', OutputInterface::VERBOSITY_VERBOSE);
            $fileGroups = FileGroupFinder::find($api, $searchDirectory, $recursiveFileSearch, $directoriesToExcludeArray, $lockFileOnly);
        } catch (TransportExceptionInterface $e) {
            $io->error("Failed to get supported dependency file names: {$e->getMessage()}");

            return 1;
        } catch (HttpExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("Failed to get supported dependency file names: {$e->getResponse()->getContent(false)}");

            return 1;
        } catch (DirectoryNotFoundException $e) {
            $io->error("Failed to find directory: {$e->getMessage()}");

            return 1;
        }

        $numberOfMatchedFiles = 0;
        foreach ($fileGroups as $fileGroup) {
            $numberOfMatchedFiles += count($fileGroup->getFiles());
        }

        if ($numberOfMatchedFiles > 0) {
            // Upload FileGroups
            $progressBar = new ProgressBar($output);
            $progressBar->start();
            $progressBar->setFormat('%current% file(s) found [%bar%] %percent:3s%% %elapsed:6s%');
            $this->setProgressBarStyle($progressBar);
            $progressBar->setMaxSteps($numberOfMatchedFiles);
            foreach ($fileGroups as $fileGroup) {
                foreach ($fileGroup->getFiles() as $file) {
                    try {
                        $this->uploadDependencyFile(
                            $uploadId,
                            $repository,
                            $commit,
                            \strval($input->getOption(self::OPTION_BRANCH_NAME)),
                            \strval($input->getOption(self::OPTION_DEFAULT_BRANCH)),
                            \strval($input->getArgument(self::ARGUMENT_REPOSITORY_URL)),
                            $api,
                            $file,
                            $baseDirectory
                        );
                    } catch (\Exception $e) {
                        $io->warning($e->getMessage());
                        $fileGroup->unsetFile($file);
                    }
                    $progressBar->advance();
                }
            }
            $progressBar->finish();
            $io->newLine(2);

            // Print FileGroups
            $io->section('Uploaded files');
            foreach ($fileGroups as $fileGroup) {
                $fileGroup->ioPrint($io, $searchDirectory);
            }
        } else {
            $io->warning('No dependency files to upload!');
        }

        // If Snippet analysis or source code zipping is enabled traverse all files again
        $successfullyCreatedZip = false;
        $zippedRepositoryName = null;
        $enableSnippetAnalysis = \boolval($input->getOption(self::OPTION_SNIPPET_ANALYSIS));
        $snippetAnalysis = $enableSnippetAnalysis ? new SnippetAnalysis() : null;
        $uploadAllFiles = $this->parseBooleanOption($input->getOption(self::OPTION_UPLOAD_ALL_FILES));
        if ($uploadAllFiles === null) {
            $io->error('Invalid value for upload all files flag');

            return 1;
        }
        $uploadAllFiles = $uploadAllFiles && $numberOfMatchedFiles > 0;
        if ($enableSnippetAnalysis === true || $uploadAllFiles === true) {
            $finder = FileGroupFinder::makeFinder($searchDirectory, $recursiveFileSearch, $directoriesToExcludeArray);
            $zip = null;
            $progressBar = null;
            if ($uploadAllFiles === true) {
                $io->title('Uploading source code to Debricked');
                $zippedRepositoryName = \str_replace('/', '-', "{$repository}_{$commit}.zip");
                $zip = new \ZipArchive();
                $zip->open($zippedRepositoryName, \ZipArchive::CREATE);
                $progressBar = new ProgressBar($output, $finder->count());
                $progressBar->start();
                $progressBar->setFormat('[%bar%] %percent:3s%% %elapsed:6s%');
                $this->setProgressBarStyle($progressBar);
            }

            foreach ($finder as $file) {
                if (\array_key_exists($file->getExtension(), $this->blacklist) === false) {
                    $absolutePathname = $file->getPathname();
                    $relativePathname = Utility::normaliseRelativePath($baseDirectory.'/'.$file->getRelativePathname());

                    if ($uploadAllFiles === true) {
                        if (@$zip->addFile($absolutePathname, $relativePathname) === false) {
                            $io->warning("Failed to add file {$absolutePathname} to zip");
                        }
                        $progressBar->advance();
                    }

                    if ($enableSnippetAnalysis === true) {
                        // Include it in snippet analysis (SnippetAnalysis has its own file extension filter though).
                        $snippetAnalysis->analyseFile($absolutePathname, $relativePathname);
                    }
                }
            }
            if ($uploadAllFiles === true) {
                $progressBar->finish();
                $io->newLine(2);
                $successfullyCreatedZip = @$zip->close();
                if ($successfullyCreatedZip === false) {
                    $io->warning('Failed to create zip file, results may be less accurate. Make sure the command has '.
                        'write permission for current working directory.');
                } else {
                    $io->text('<fg=green;>[OK] Successfully uploaded zip file containing source code!</>');
                }
            }

            // Upload WFP fingerprints as a dependency file, if they exist.
            if ($enableSnippetAnalysis === true) {
                try {
                    $this->uploadWfpFingerprints(
                        $uploadId,
                        $repository,
                        $commit,
                        \strval($input->getOption(self::OPTION_BRANCH_NAME)),
                        \strval($input->getOption(self::OPTION_DEFAULT_BRANCH)),
                        \strval($input->getArgument(self::ARGUMENT_REPOSITORY_URL)),
                        $api,
                        $snippetAnalysis,
                        $baseDirectory);
                } catch (\Exception $e) {
                    $io->warning($e->getMessage());
                }
                $io->text('<fg=green;>[OK] Snippet analysis complete!</>');
                $io->newLine();
            }
        }

        if ($uploadId !== null) {
            $formFields = ['ciUploadId' => \strval($uploadId)];
            $formFields['integrationName'] = $input->getArgument(self::ARGUMENT_INTEGRATION_NAME);
            $formFields['author'] = $input->getOption(self::OPTION_AUTHOR);

            if ($uploadAllFiles && $successfullyCreatedZip) {
                $formFields['repositoryName'] = $repository;
                $formFields['commitName'] = $commit;
                $formFields['repositoryZip'] = DataPart::fromPath($zippedRepositoryName ?? '');
            }

            $formData = new FormDataPart($formFields);
            $headers = $formData->getPreparedHeaders()->toArray();
            $body = $formData->bodyToString();
            try {
                $response = $api->makeApiCall(
                    Request::METHOD_POST,
                    '/api/1.0/open/finishes/dependencies/files/uploads',
                    [
                        'headers' => $headers,
                        'body' => $body,
                    ]
                );
                $response->getContent();
            } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
                $io->warning("Failed to conclude upload, error: {$e->getMessage()}");

                return 2;
            }

            if (\file_exists($zippedRepositoryName)) {
                $keepZip = \boolval($input->getOption(self::OPTION_KEEP_ZIP));
                if (!$keepZip) {
                    $result = \unlink($zippedRepositoryName);

                    if ($result === false) {
                        $io->warning("Failed to remove zip {$zippedRepositoryName}");
                    }
                }
            }

            $checkScanCommand = CheckScanCommand::getDefaultName();
            $io->text(
                "You can now execute <fg=green;options=bold>bin/console $checkScanCommand your-username your-password $uploadId</> to track the vulnerability scan"
            );
        } else {
            $io->warning('Nothing to upload!');
        }

        return 0;
    }

    /**
     * Helper function to parse boolean option values in a reasonable way.
     *
     * @param mixed $value
     *
     * @return bool|null true if value is '1', 'true', 'yes', etc. false if '0', 'false', 'no', etc. null otherwise.
     */
    private function parseBooleanOption($value): ?bool
    {
        return \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Helper to upload dependency data to the service.
     *
     * @param ?int                   $uploadId   reference to the CI upload id
     * @param array<string|DataPart> $formFields associative array of form fields to upload
     * @param API                    $api        API instance to Debricked
     * @param string                 $filename   filename used only in error messages
     */
    protected function uploadDependencyData(?int &$uploadId, array $formFields, API $api, string $filename): void
    {
        $formData = new FormDataPart($formFields);
        $headers = $formData->getPreparedHeaders()->toArray();
        $body = $formData->bodyToString();
        try {
            $uploadResponse = $api->makeApiCall(
                Request::METHOD_POST,
                '/api/1.0/open/uploads/dependencies/files',
                [
                    'headers' => $headers,
                    'body' => $body,
                ]
            );

            $uploadContent = \json_decode($uploadResponse->getContent(), true);
            $uploadId = $uploadContent['ciUploadId'];
        } catch (TransportExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            throw new \Exception("Failed to upload {$filename}, error: {$e->getMessage()}");
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            throw new \Exception("Failed to upload {$filename}, error: {$e->getResponse()->getContent(false)}");
        }
    }

    /**
     * Uploads a dependency file to the service.
     *
     * @param ?int        $uploadId          Reference to the CI upload id
     * @param string      $repository        Repository name
     * @param string      $commit            Commit id
     * @param ?string     $branchName        Branch name
     * @param ?string     $defaultBranchName Default branch name
     * @param string      $repositoryUrl     URL of repository
     * @param API         $api               API instance to debricked
     * @param SplFileInfo $file              File object
     * @param string      $baseDirectory     base directory
     *
     * @return void the pathname of the uploaded file, if successful
     *
     * @throws \Exception
     */
    protected function uploadDependencyFile(
        ?int &$uploadId,
        string $repository,
        string $commit,
        ?string $branchName,
        ?string $defaultBranchName,
        string $repositoryUrl,
        API $api,
        SplFileInfo $file,
        string $baseDirectory
    ): void {
        $formFields = $this->getCommonFileFormFields($repository, $commit, $branchName, $defaultBranchName, $uploadId);

        $formFields['repositoryUrl'] = $repositoryUrl;
        $formFields['fileData'] = DataPart::fromPath($file->getPathname());
        $formFields['fileRelativePath'] = Utility::normaliseRelativePath($baseDirectory.'/'.$file->getRelativePath());

        $this->uploadDependencyData($uploadId, $formFields, $api, $file->getFilename());
    }

    /**
     * Uploads a WFP fingerprint string to the service.
     *
     * @param ?int             $uploadId          Reference to the CI upload id
     * @param string           $repository        Repository name
     * @param string           $commit            Commit id
     * @param ?string          $branchName        Branch name
     * @param ?string          $defaultBranchName Default branch name
     * @param string           $repositoryUrl     URL of repository
     * @param API              $api               API instance to debricked
     * @param ?SnippetAnalysis $snippetAnalysis   snippet analysis instance
     * @param string           $baseDirectory     Base directory
     */
    protected function uploadWfpFingerprints(
        ?int &$uploadId,
        string $repository,
        string $commit,
        ?string $branchName,
        ?string $defaultBranchName,
        string $repositoryUrl,
        API $api,
        ?SnippetAnalysis $snippetAnalysis,
        string $baseDirectory
    ): void {
        if ($snippetAnalysis === null) {
            return;
        }

        // If there isn't any fingerprints, don't try to upload anything.
        $wfpData = $snippetAnalysis->dumpWfp();
        if (empty($wfpData)) {
            return;
        }

        $formFields = $this->getCommonFileFormFields($repository, $commit, $branchName, $defaultBranchName, $uploadId);

        $formFields['repositoryUrl'] = $repositoryUrl;
        $formFields['fileData'] = new DataPart($wfpData, '.debricked-wfp-fingerprints.txt');
        $formFields['fileRelativePath'] = Utility::normaliseRelativePath($baseDirectory.'/.debricked-wfp-fingerprints.txt');

        /* @noinspection PhpUnhandledExceptionInspection */
        $this->uploadDependencyData($uploadId, $formFields, $api, 'WFP fingerprints');
        // Will return on success, otherwise exception will bubble up.
    }

    /**
     * @return string[]
     */
    private function getCommonFileFormFields(
        string $repository, string $commit, string $branchName, string $defaultBranchName, ?int $uploadId
    ): array {
        $formFields =
            [
                'repositoryName' => $repository,
                'commitName' => $commit,
            ];

        if (empty($branchName) === false) {
            $formFields['branchName'] = $branchName;
        }

        if (empty($defaultBranchName) === false) {
            $formFields['defaultBranchName'] = $defaultBranchName;
        }

        if ($uploadId !== null) {
            $formFields['ciUploadId'] = \strval($uploadId);
        }

        return $formFields;
    }
}
