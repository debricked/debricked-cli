<?php

namespace App\Model;

use App\Utility\Utility;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A FileGroup is a group of connected files.
 * It has one dependency file and if existing, one or several lock files.
 */
class FileGroup
{
    private SplFileInfo $dependencyFile;
    private ?DependencyFileFormat $dependencyFileFormat;
    private bool $isLockFileGroup;
    /**
     * @var SplFileInfo[]
     */
    private array $lockFiles;

    public function __construct(SplFileInfo $dependencyFile, ?DependencyFileFormat $dependencyFileFormat, bool $isLockFileGroup = false)
    {
        $this->dependencyFile = $dependencyFile;
        $this->dependencyFileFormat = $dependencyFileFormat;
        $this->isLockFileGroup = $isLockFileGroup;
        $this->lockFiles = [];
    }

    public function getDependencyFile(): SplFileInfo
    {
        return $this->dependencyFile;
    }

    public function setDependencyFile(SplFileInfo $dependencyFile): void
    {
        $this->dependencyFile = $dependencyFile;
    }

    /**
     * @return SplFileInfo[]
     */
    public function getLockFiles(): array
    {
        return $this->lockFiles;
    }

    public function addLockFile(SplFileInfo $lockFile): void
    {
        $this->lockFiles[] = $lockFile;
    }

    /**
     * Returns `true` if the dependency file has a lock file. Otherwise `false`.
     * If the FileGroup is a lockFileGroup then `true` is always returned.
     */
    public function isComplete(): bool
    {
        return !empty($this->lockFiles);
    }

    public function ioPrint(SymfonyStyle $io, string $searchDirectory): void
    {
        $pathName = $this->dependencyFile->getPathname();
        $pathName = \str_replace($searchDirectory, '', $pathName);
        $pathName = Utility::normaliseRelativePath($pathName);
        $io->writeln("<options=bold;>$pathName</>");

        if ($this->isComplete()) {
            foreach ($this->lockFiles as $lockFile) {
                $lockFileName = \str_replace($searchDirectory, '', $lockFile->getPathname());
                $lockFileName = Utility::normaliseRelativePath($lockFileName);
                $io->write(" * <fg=green;>$lockFileName</>");
            }
        } else {
            $io->text('* <fg=yellow;options=bold>Missing related dependency file(s)!</>');
            if (!$this->isLockFileGroup) {
                $io->warning('This will result in slow scans and less precise results!');
                $io->text('Make sure to generate at least one of the following prior to scanning:');
                foreach ($this->dependencyFileFormat->getLockFileRegexes() as $lockFileRegex) {
                    $lockFileName = stripslashes($lockFileRegex);
                    $io->text("\t* <fg=green;options=bold>$lockFileName</>");
                    $io->writeln(' For more info: <fg=blue;options=bold>https://debricked.com/docs/language-support</>');
                }
            }
        }
        $io->newLine(2);
    }

    /**
     * @return SplFileInfo[]
     */
    public function getFiles(): array
    {
        return \array_merge([$this->dependencyFile], $this->lockFiles);
    }

    public function getDependencyFileFormat(): ?DependencyFileFormat
    {
        return $this->dependencyFileFormat;
    }

    public function setDependencyFileFormat(DependencyFileFormat $dependencyFileFormat): void
    {
        $this->dependencyFileFormat = $dependencyFileFormat;
    }
}
