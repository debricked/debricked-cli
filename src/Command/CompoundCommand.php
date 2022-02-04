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

use App\Console\CombinedOutput;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CompoundCommand extends FindAndUploadFilesCommand
{
    protected static $defaultName = 'debricked:scan';

    public const OPTION_DISABLE_CONDITIONAL_SKIP_SCAN = 'disable-conditional-skip-scan';
    public const OPTION_DISABLE_CONDITIONAL_SKIP_SCAN_WITH_DASHES = '--'.self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN;

    protected function configure(): void
    {
        parent::configure();

        $findAndUploadCommand = FindAndUploadFilesCommand::getDefaultName();
        $checkScanCommand = CheckScanCommand::getDefaultName();

        $this
            ->setDescription(
                "Runs $findAndUploadCommand and $checkScanCommand, resulting in a full vulnerability scan."
            )
            ->addOption(
                self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN,
                null,
                InputOption::VALUE_NONE,
                'Use this option to disable skip scan from ever triggering. Default is to allow skip scan triggering because of long queue times (=false).'
            );
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (($application = $this->getApplication()) === null) {
            $io->error('Could not get application instance');

            return 5;
        }

        $findAndUploadCommandName = FindAndUploadFilesCommand::getDefaultName();
        if ($findAndUploadCommandName === null) {
            $io->error('Could not find name of find and upload files command');

            return 3;
        }

        $io->writeln("Executing $findAndUploadCommandName", OutputInterface::VERBOSITY_VERBOSE);
        [$findAndUploadReturnCode, $findAndUploadOutput] = $this->runFindAndUploadCommand($application, $input, $output, $findAndUploadCommandName);

        if ($findAndUploadReturnCode === 3) {
            $io->error('Could not find name of find and upload files command');

            return 3;
        }

        if ($findAndUploadReturnCode !== 0) {
            return 1;
        }

        $checkScanCommandName = CheckScanCommand::getDefaultName();
        if ($checkScanCommandName === null) {
            $io->error('Could not find name of check scan command');

            return 4;
        }
        $checkScanCommand = $application->find($checkScanCommandName);
        $findAndUploadOutput = $findAndUploadOutput->fetch();
        $uploadIdMatches = [];
        if (\preg_match(
                "/bin\/console {$checkScanCommand->getName()} your-username your-password (\w+)/m",
                $findAndUploadOutput,
                $uploadIdMatches
            ) !== 1) {
            return 0;
        }
        $uploadId = $uploadIdMatches[1];

        $io->newLine(2);
        $io->writeln("Executing {$checkScanCommand->getName()} with $uploadId", OutputInterface::VERBOSITY_VERBOSE);
        $checkScanArguments =
            [
                FindAndUploadFilesCommand::ARGUMENT_USERNAME => $input->getArgument(
                    FindAndUploadFilesCommand::ARGUMENT_USERNAME
                ),
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $input->getArgument(
                    FindAndUploadFilesCommand::ARGUMENT_PASSWORD
                ),
                CheckScanCommand::ARGUMENT_UPLOAD_ID => $uploadId,
                self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN_WITH_DASHES => $input->getOption(self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN),
            ];
        $checkScanInput = new ArrayInput($checkScanArguments);
        $checkScanReturnCode = $checkScanCommand->run($checkScanInput, $output);
        if ($checkScanReturnCode !== 0) {
            return 2;
        }

        return 0;
    }

    /**
     * @return array{int, CombinedOutput}
     */
    private function runFindAndUploadCommand(
        Application $application,
        InputInterface $input,
        OutputInterface $output,
        string $findAndUploadCommandName
    ): array {
        $findAndUploadCommand = $application->find($findAndUploadCommandName);

        $findAndUploadOutput = new CombinedOutput(
            $output->getVerbosity(),
            $output->isDecorated(),
            $output->getFormatter()
        );

        //This will return all given options merged with default values for ungiven options
        $options = $input->getOptions();
        //Unset option because it does not exist in FindAndUploadFilesCommand
        unset($options[self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN]);
        $newOptions = [];
        foreach ($options as $option => $value) {
            $newOptions['--'.$option] = $value;
        }
        $parameters = \array_merge($input->getArguments(), $newOptions);
        $findAndUploadInput = new ArrayInput($parameters);
        $returnCode = $findAndUploadCommand->run($findAndUploadInput, $findAndUploadOutput);

        return [$returnCode, $findAndUploadOutput];
    }
}
