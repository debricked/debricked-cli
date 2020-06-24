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
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FindAndUploadFilesCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:find-and-upload-files';

    private const ARGUMENT_BASE_DIRECTORY = 'base-directory';
    public const ARGUMENT_USERNAME = 'username';
    public const ARGUMENT_PASSWORD = 'password';
    private const ARGUMENT_REPOSITORY_NAME = 'repository-name';
    private const ARGUMENT_COMMIT_NAME = 'commit-name';
    private const ARGUMENT_REPOSITORY_URL = 'repository-url';
    private const ARGUMENT_INTEGRATION_NAME = 'integration-name';
    private const OPTION_BRANCH_NAME = 'branch-name';
    private const OPTION_DIRECTORIES_TO_EXCLUDE = 'excluded-directories';
    private const OPTION_KEEP_ZIP = 'keep-zip';
    private const OPTION_RECURSIVE_FILE_SEARCH = 'recursive-file-search';
    private const OPTION_UPLOAD_ALL_FILES = 'upload-all-files';

    /**
     * @var HttpClientInterface
     */
    private $debrickedClient;

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
                self::ARGUMENT_REPOSITORY_URL,
                InputArgument::REQUIRED,
                'The repository uri, to create the link to the dependency\'s file in suggested fix.',
                null
            )
            ->addArgument(
                self::ARGUMENT_INTEGRATION_NAME,
                InputArgument::REQUIRED,
                'The integration name (azureDevOps, bitbucket or gitlab)',
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
                'Enter a comma separated list of directories to exclude. Such as: --excluded-directories="vendor,node_modules,tests"',
                'vendor,node_modules,tests'
            )
            ->addOption(
                self::OPTION_BRANCH_NAME,
                null,
                InputOption::VALUE_REQUIRED,
                'Branch to associate found files with'
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

            $dependencyFileNames = \json_decode($dependencyFileNamesResponse->getContent(), true);
        } catch (TransportExceptionInterface $e) {
            $io->error("Failed to get supported dependency file names: {$e->getMessage()}");

            return 1;
        } catch (ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("Failed to get supported dependency file names: {$e->getResponse()->getContent(false)}");

            return 1;
        }

        $directoriesToExcludeString = \strval($input->getOption(self::OPTION_DIRECTORIES_TO_EXCLUDE));
        $searchDirectory = "{$workingDirectory}/{$baseDirectory}";
        $searchDirectory = preg_replace('#/+#', '/', $searchDirectory); // remove duplicate slashes.

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

        $repository = \strval($input->getArgument(self::ARGUMENT_REPOSITORY_NAME));
        $commit = \strval($input->getArgument(self::ARGUMENT_COMMIT_NAME));
        $zippedRepositoryName = \str_replace('/', '-', "{$repository}_{$commit}.zip");
        $zip = new \ZipArchive();
        $zip->open($zippedRepositoryName, \ZipArchive::CREATE);

        $uploadId = null;
        $uploadedFilePaths = [];
        $uploadedAdjacentFilePaths = [];
        $progressBar = new ProgressBar($output);
        $progressBar->start();
        $progressBar->setFormat(' %current% file(s) found [%bar%] %percent:3s%% %elapsed:6s% %memory:6s%');
        $this->setProgressBarStyle($progressBar);
        $uploadAllFiles = \boolval($input->getOption(self::OPTION_UPLOAD_ALL_FILES));
        foreach ($finder as $file) {
            $absolutePathname = $file->getPathname();
            $extension = $file->getExtension();
            $fileName = $file->getFilename();
            $relativePathname = $this->normaliseRelativePath($baseDirectory.'/'.$file->getRelativePathname());

            if ($uploadAllFiles === true && \array_key_exists($extension, $this->blacklist) === false) {
                $zip->addFile($absolutePathname, $relativePathname);
            }

            if ($this->pregMatchInArray($fileName, $dependencyFileNames['dependencyFileNames']) === true) {
                if ($this->pregMatchInArray($fileName, $dependencyFileNames['dependencyFileNamesRequiresAllFiles']) === true) {
                    // If we find an adjacent dependency tree file already generated by the customer, use it, otherwise warn.
                    list($adjacentAbsolute, $adjacentRelative) = $this->getAdjacentDependencyTreeFile($file,
                        $dependencyFileNames['adjacentDependencyFileNames'], $baseDirectory);
                    if (!empty($adjacentAbsolute) && !$uploadAllFiles) {
                        // There is an adjacent file! Add it to the zip.
                        $zip->addFile($adjacentAbsolute, $adjacentRelative);
                        $uploadedAdjacentFilePaths[] = $adjacentRelative;
                    } elseif (!$uploadAllFiles) {
                        $optionNameUploadAllFiles = self::OPTION_UPLOAD_ALL_FILES;
                        $io->warning("Skipping {$absolutePathname}.\n\nFound file which requires that all files needs to be uploaded. Please enable the {$optionNameUploadAllFiles} option if you want to scan this file.");

                        continue;
                    }
                }

                $formFields =
                    [
                        'repositoryName' => $repository,
                        'commitName' => $commit,
                    ];

                $branchName = $input->getOption(self::OPTION_BRANCH_NAME);

                if (empty($branchName) === false) {
                    $formFields['branchName'] = $branchName;
                }

                if ($uploadId !== null) {
                    $formFields['ciUploadId'] = \strval($uploadId);
                }

                $formFields['repositoryUrl'] = $input->getArgument(self::ARGUMENT_REPOSITORY_URL);
                $formFields['fileData'] = DataPart::fromPath($file->getPathname());
                $formFields['fileRelativePath'] = $this->normaliseRelativePath($baseDirectory.'/'.$file->getRelativePath());

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
                } catch (TransportExceptionInterface $e) {
                    $io->warning("Failed to upload {$fileName}, error: {$e->getMessage()}");
                    continue;
                } catch (ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
                    /* @noinspection PhpUnhandledExceptionInspection */
                    $io->warning("Failed to upload {$fileName}, error: {$e->getResponse()->getContent(false)}");
                    continue;
                }

                $uploadId = $uploadContent['ciUploadId'];
                $uploadedFilePaths[] = $file->getPathname();
                $progressBar->advance();
            }
        }
        $progressBar->finish();
        $io->newLine(2);

        $zipFileCount = $zip->count();
        $successfullyCreatedZip = $zip->close();

        $shouldUploadZip = $uploadAllFiles === true || empty($uploadedAdjacentFilePaths) === false;

        if ($successfullyCreatedZip === false && $shouldUploadZip === true) {
            $io->warning('Failed to create zip file');
        } elseif ($shouldUploadZip === true) {
            $io->note("Successfully created zip file with {$zipFileCount} extra file(s)");
        }

        if ($uploadId !== null) {
            $formFields = ['ciUploadId' => \strval($uploadId)];
            $formFields['integrationName'] = $input->getArgument(self::ARGUMENT_INTEGRATION_NAME);

            if ($shouldUploadZip === true && $successfullyCreatedZip === true) {
                $formFields['repositoryName'] = $repository;
                $formFields['commitName'] = $commit;
                $formFields['repositoryZip'] = DataPart::fromPath($zippedRepositoryName);
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
            } catch (TransportExceptionInterface | ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
                $io->warning("Failed to conclude upload, error: {$e->getMessage()}");

                return 2;
            }

            $uploadedFilePathsString = \implode("\n ", $uploadedFilePaths);
            $io->success("Successfully found and uploaded {$uploadedFilePathsString}");
            if (!empty($uploadedAdjacentFilePaths) && $successfullyCreatedZip) {
                $adjacentCount = count($uploadedAdjacentFilePaths);
                $io->success("Successfully uploaded {$adjacentCount} dependency tree files");
            }
            $checkScanCommand = CheckScanCommand::getDefaultName();
            $io->text(
                "You can now execute <fg=green>bin/console $checkScanCommand your-username your-password $uploadId</> to track the vulnerability scan"
            );
        } else {
            $io->warning('Nothing to upload!');
        }

        if (\file_exists($zippedRepositoryName)) {
            $keepZip = \boolval($input->getOption(self::OPTION_KEEP_ZIP));
            $io->warning('keepZip is set to '.$keepZip);
            if (!$keepZip) {
                $result = \unlink($zippedRepositoryName);

                if ($result === false) {
                    $io->warning("Failed to remove zip {$zippedRepositoryName}");
                }
            }
        }

        return 0;
    }

    /**
     * @param SplFileInfo   $file                        The file object for the dependency file
     * @param array<string> $adjacentDependencyFileNames Assoc. array of filename => Dependency tree filename mappings.
     * @param string        $baseDirectory               the current base directory
     *
     * @return array<string>|null a [absolute, relative] path tuple, if it exists, otherwise null
     */
    private function getAdjacentDependencyTreeFile(
        SplFileInfo $file,
        array $adjacentDependencyFileNames,
        string $baseDirectory
    ): ?array {
        $filename = $file->getFilename();
        $pathname = $file->getPath();

        if (\array_key_exists($filename, $adjacentDependencyFileNames)) {
            $adjacentFilename = $adjacentDependencyFileNames[$filename];
            $dependencyTreePathname = "{$pathname}/{$adjacentFilename}";
            if (\file_exists($dependencyTreePathname)) {
                // The adjacent file is, by definition, adjacent to $file, so we can use its relative path.
                $relativePathname = $baseDirectory.'/'.$file->getRelativePath().'/'.$adjacentFilename;

                return [$dependencyTreePathname, $this->normaliseRelativePath($relativePathname)];
            }
        }

        return null;
    }

    /**
     * Normalises a relative path, i.e., ensure there are no double slashes and that it doesn't start with a slash.
     *
     * @param string $path The path to normalise
     */
    private function normaliseRelativePath(string $path): string
    {
        $path = ltrim($path, '/');

        return preg_replace('#/+#', '/', $path);
    }

    /**
     * Goes through an array containing regexes, returns true if at least one of the regexes matches $stringToMatch, otherwise false.
     *
     * @param string[] $arrayOfRegexes
     */
    private function pregMatchInArray(string $stringToMatch, array $arrayOfRegexes): bool
    {
        return \array_reduce(
            $arrayOfRegexes,
            function ($matchExists, $regex) use ($stringToMatch) {
                return $matchExists || \preg_match('/^'.$regex.'$/', $stringToMatch);
            },
            false);
    }
}
