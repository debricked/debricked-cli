<?php

namespace App\Model;

use App\Utility\Utility;
use JsonSerializable;
use stdClass;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A FileGroup is a group of connected files.
 * It has one dependency file and if existing, one or several lock files.
 */
class FileGroup implements JsonSerializable
{
    private ?SplFileInfo $dependencyFile;
    private ?DependencyFileFormat $dependencyFileFormat;
    private string $searchDirectory;
    /**
     * @var SplFileInfo[]
     */
    private array $lockFiles;

    public function __construct(?SplFileInfo $dependencyFile, ?DependencyFileFormat $dependencyFileFormat, string $searchDirectory)
    {
        $this->dependencyFile = $dependencyFile;
        $this->dependencyFileFormat = $dependencyFileFormat;
        $this->searchDirectory = $searchDirectory;
        $this->lockFiles = [];
    }

    public function getDependencyFile(): ?SplFileInfo
    {
        return $this->dependencyFile;
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
     * A FileGroup is considered complete if all the following are true:
     *  - The FileGroup has a dependencyFileFormat
     *  - The FileGroup's dependencyFileFormat has no lock file regexes,
     *    or has matched lock files.
     */
    public function isComplete(): bool
    {
        $hasFormat = $this->dependencyFileFormat instanceof DependencyFileFormat;
        if ($hasFormat) {
            $lockFileRegexes = $this->dependencyFileFormat->getLockFileRegexes();

            return empty($lockFileRegexes) || !empty($this->lockFiles);
        }

        return false;
    }

    public function ioPrint(SymfonyStyle $io, string $searchDirectory): void
    {
        // If this group is a lock file group, then print the lock file(s) followed by "missing file" warning.
        if ($this->dependencyFile === null) {
            foreach ($this->lockFiles as $lockFile) {
                $lockFileName = \str_replace($searchDirectory, '', $lockFile->getPathname());
                $lockFileName = Utility::normaliseRelativePath($lockFileName);
                $io->writeln("<options=bold;>$lockFileName</>");
                $io->text('* <fg=yellow;options=bold>Missing related dependency file(s)!</>');
            }
        } else { // Else we have found a dependency file with or without lock files
            $pathName = $this->dependencyFile->getPathname();
            $pathName = \str_replace($searchDirectory, '', $pathName);
            $pathName = Utility::normaliseRelativePath($pathName);
            $io->writeln("<options=bold;>$pathName</>");
            if ($this->isComplete()) {
                foreach ($this->lockFiles as $lockFile) {
                    $lockFileName = \str_replace($searchDirectory, '', $lockFile->getPathname());
                    $lockFileName = Utility::normaliseRelativePath($lockFileName);
                    $io->writeln(" * <fg=green;>$lockFileName</>");
                }
            } else {
                $io->text('* <fg=yellow;options=bold>Missing related dependency file(s)!</>');
                $io->warning('This will result in slow scans and less precise results!');
                $io->text('Make sure to generate at least one of the following prior to scanning:');
                foreach ($this->dependencyFileFormat->getLockFileRegexes() as $lockFileRegex) {
                    $lockFileName = stripslashes($lockFileRegex);
                    $io->text("\t* <fg=green;options=bold>$lockFileName</>");
                }
                $io->writeln(" For more info: <fg=blue;options=bold>{$this->dependencyFileFormat->getDocsLink()}</>");
            }
        }
        $io->newLine(2);
    }

    /**
     * @return SplFileInfo[]
     */
    public function getFiles(): array
    {
        if ($this->dependencyFile === null) {
            return $this->lockFiles;
        }

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

    public function unsetFile(SplFileInfo $file): void
    {
        if ($this->dependencyFile instanceof SplFileInfo && $this->dependencyFile->getPathname() === $file->getPathname()) {
            $this->dependencyFile = null;
        } else {
            foreach ($this->lockFiles as $index => $lockFile) {
                if ($lockFile->getPathname() === $file->getPathname()) {
                    unset($this->lockFiles[$index]);
                    break;
                }
            }
        }
    }

    public function serialize(string $searchDirectory): object
    {
        $fileGroup = new StdClass();
        $pathName = '';
        if ($this->dependencyFile instanceof SplFileInfo) {
            $pathName = $this->dependencyFile->getPathname() ?? '';
            $pathName = \str_replace($searchDirectory, '', $pathName);
            $pathName = Utility::normaliseRelativePath($pathName);
        }
        $fileGroup->{'dependencyFile'} = $pathName;
        $lockFiles = [];
        foreach ($this->lockFiles as $lockFile) {
            $lockFileName = \str_replace($searchDirectory, '', $lockFile->getPathname());
            $lockFileName = Utility::normaliseRelativePath($lockFileName);
            $lockFiles[] = $lockFileName;
        }
        $fileGroup->{'lockFiles'} = $lockFiles;

        return $fileGroup;
    }

    /**
     * @return array{dependencyFile: string, lockFiles: string[]}
     */
    public function jsonSerialize(): array
    {
        $fileGroup = [];
        $fileGroup['dependencyFile'] = $this->dependencyFile instanceof SplFileInfo ? $this->getDependencyFilePath() : '';
        $fileGroup['lockFiles'] = \array_map(fn ($lockFile) => $this->getLockFilePath($lockFile), $this->lockFiles);

        return $fileGroup;
    }

    private function getDependencyFilePath(): string
    {
        $pathName = $this->dependencyFile->getPathname() ?? '';
        $pathName = \str_replace($this->searchDirectory, '', $pathName);

        return Utility::normaliseRelativePath($pathName);
    }

    private function getLockFilePath(SplFileInfo $lockFile): string
    {
        $lockFileName = \str_replace($this->searchDirectory, '', $lockFile->getPathname());

        return Utility::normaliseRelativePath($lockFileName);
    }
}
