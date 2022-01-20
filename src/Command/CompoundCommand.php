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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CompoundCommand extends FindAndUploadFilesCommand
{
    protected static $defaultName = 'debricked:scan';

    protected function configure(): void
    {
        parent::configure();

        $findAndUploadCommand = FindAndUploadFilesCommand::getDefaultName();
        $checkScanCommand = CheckScanCommand::getDefaultName();

        $this
            ->setDescription(
                "Runs $findAndUploadCommand and $checkScanCommand, resulting in a full vulnerability scan."
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

        $findAndUploadCommandName = FindAndUploadFilesCommand::getDefaultName();
        if ($findAndUploadCommandName === null) {
            $io->error('Could not find name of find and upload files command');

            return 3;
        }

        if (($application = $this->getApplication()) === null) {
            $io->error('Could not get application instance');

            return 5;
        }
        $findAndUploadCommand = $application->find($findAndUploadCommandName);
        $io->section("Executing {$findAndUploadCommand->getName()}");
        $findAndUploadOutput = new CombinedOutput(
            $output->getVerbosity(),
            $output->isDecorated(),
            $output->getFormatter()
        );
        $findAndUploadReturnCode = $findAndUploadCommand->run($input, $findAndUploadOutput);
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
        $io->section("Executing {$checkScanCommand->getName()} with $uploadId");
        $checkScanArguments =
            [
                FindAndUploadFilesCommand::ARGUMENT_USERNAME => $input->getArgument(
                    FindAndUploadFilesCommand::ARGUMENT_USERNAME
                ),
                FindAndUploadFilesCommand::ARGUMENT_PASSWORD => $input->getArgument(
                    FindAndUploadFilesCommand::ARGUMENT_PASSWORD
                ),
                CheckScanCommand::ARGUMENT_UPLOAD_ID => $uploadId,
                '--' . self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN => $input->getOption(self::OPTION_DISABLE_CONDITIONAL_SKIP_SCAN)
            ];
        $checkScanInput = new ArrayInput($checkScanArguments);
        $checkScanReturnCode = $checkScanCommand->run($checkScanInput, $output);
        if ($checkScanReturnCode !== 0) {
            return 2;
        }

        return 0;
    }
}
