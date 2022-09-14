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

use App\Service\FileGroupFinder;
use Debricked\Shared\API\API;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FindFilesCommand extends Command
{
    use Style;

    protected static $defaultName = 'debricked:files:find';

    private const OPTION_JSON = 'json';
    private const OPTION_LOCK_FILE_ONLY = 'lockfile';

    private HttpClientInterface $debrickedClient;

    public function __construct(HttpClientInterface $debrickedClient, $name = null)
    {
        parent::__construct($name);

        $this->debrickedClient = $debrickedClient;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Find and output dependency files in given base directory')
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_USERNAME,
                InputArgument::REQUIRED,
                'Your Debricked username. Set to an empty string if you use an access token.',
            )
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD,
                InputArgument::REQUIRED,
                'Your Debricked password or access token',
            )
            ->addArgument(
                FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY,
                InputArgument::REQUIRED,
                'The base directory (relative to current working directory) to recursively find dependency files in. Default is current working directory.',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Use this option to output files in json'
            )
            ->addOption(
                FindAndUploadFilesCommand::OPTION_RECURSIVE_FILE_SEARCH,
                null,
                InputOption::VALUE_REQUIRED,
                'Set to 0 to disable recursive search - only base directory will be searched',
                1
            )
            ->addOption(
                FindAndUploadFilesCommand::OPTION_DIRECTORIES_TO_EXCLUDE,
                null,
                InputOption::VALUE_REQUIRED,
                'Enter a comma separated list of directories to exclude. Such as: --excluded-directories="vendor,node_modules,tests"',
                'vendor,node_modules,tests'
            )
            ->addOption(
                self::OPTION_LOCK_FILE_ONLY,
                'l',
                InputOption::VALUE_NONE,
                'Use this option to output lock files only'
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

        $baseDirectory = $input->getArgument(FindAndUploadFilesCommand::ARGUMENT_BASE_DIRECTORY);
        $workingDirectory = \getcwd();
        if ($workingDirectory === false) {
            $io->warning(
                'Failed to get current working directory, command might be searching through unexpected directories and files'
            );
        }
        $searchDirectory = "{$workingDirectory}/{$baseDirectory}";
        $searchDirectory = preg_replace('#/+#', '/', $searchDirectory); // remove duplicate slashes.

        $recursiveFileSearch = (bool) $input->getOption(FindAndUploadFilesCommand::OPTION_RECURSIVE_FILE_SEARCH);

        $directoriesToExcludeString = $input->getOption(FindAndUploadFilesCommand::OPTION_DIRECTORIES_TO_EXCLUDE);
        $directoriesToExcludeArray = [];
        if (empty($directoriesToExcludeString) === false) {
            $directoriesToExcludeArray = \explode(',', $directoriesToExcludeString) ?? [];
        } else {
            $io->note('No directories will be ignored');
        }

        $lockFileOnly = (bool) $input->getOption(self::OPTION_LOCK_FILE_ONLY);

        try {
            $fileGroups = FileGroupFinder::find($api, $searchDirectory, $recursiveFileSearch, $directoriesToExcludeArray, $lockFileOnly);
        } catch (TransportExceptionInterface $e) {
            $io->error("Failed to get supported dependency file names: {$e->getMessage()}");

            return Command::FAILURE;
        } catch (HttpExceptionInterface $e) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $io->error("Failed to get supported dependency file names: {$e->getResponse()->getContent(false)}");

            return Command::FAILURE;
        } catch (DirectoryNotFoundException $e) {
            $io->error("Failed to find directory: {$e->getMessage()}");

            return Command::FAILURE;
        }

        // Output file groups
        $json = (bool) $input->getOption(FindFilesCommand::OPTION_JSON);
        if ($json) {
            try {
                $jsonEncodedFileGroups = \json_encode($fileGroups, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->error("Failed to encode JSON: {$e->getMessage()}");

                return Command::FAILURE;
            }
            $io->writeln($jsonEncodedFileGroups);
        } else {
            foreach ($fileGroups as $fileGroup) {
                $fileGroup->ioPrint($io, $searchDirectory);
            }
        }

        return 0;
    }
}
