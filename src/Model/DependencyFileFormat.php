<?php

namespace App\Model;

use App\Utility\Utility;

class DependencyFileFormat
{
    private string $regex;
    private ?string $docsLink;
    /** @var string[] */
    private array $lockFiles;

    /**
     * @param array{regex: string, documentationUrl: null|string, lockFileRegexes: array}[] $formats
     *
     * @return DependencyFileFormat[]
     */
    public static function make(array $formats): array
    {
        $dependencyFormats = [];
        foreach ($formats as $format) {
            $dependencyFormats[] = new self($format['regex'], $format['documentationUrl'], $format['lockFileRegexes']);
        }

        return $dependencyFormats;
    }

    /**
     * @param array<string|int, self> $dependencyFileFormats
     */
    public static function findFormatByFileName(array $dependencyFileFormats, string $filename, bool $lockFile = false): ?self
    {
        foreach ($dependencyFileFormats as $dependencyFileFormat) {
            if ($lockFile) {
                $regexes = $dependencyFileFormat->getLockFileRegexes();
            } else {
                $regexes = [$dependencyFileFormat->getRegex()];
            }
            if (Utility::pregMatchInArray($filename, $regexes)) {
                return $dependencyFileFormat;
            }
        }

        return null;
    }

    /**
     * @param string[] $lockFiles
     */
    public function __construct(
        string $regex,
        ?string $docsLink,
        array $lockFiles
    ) {
        $this->regex = $regex;
        $this->docsLink = $docsLink;
        $this->lockFiles = $lockFiles;
    }

    /**
     * @return string[]
     */
    public function getLockFileRegexes(): array
    {
        return \array_map(fn ($lockFileRegex) => $lockFileRegex, $this->lockFiles);
    }

    /**
     * @param string[] $lockFiles
     */
    public function setLockFilesRegexes(array $lockFiles): void
    {
        $this->lockFiles = $lockFiles;
    }

    public function getRegex(): string
    {
        return $this->regex;
    }

    /**
     * @return string[] returns format and lock file regexes
     */
    public function getRegexes(): array
    {
        return \array_merge([$this->getRegex()], $this->getLockFileRegexes());
    }

    public function setRegex(string $regex): void
    {
        $this->regex = $regex;
    }

    public function getDocsLink(): string
    {
        return $this->docsLink ?? 'https://debricked.com/docs/language-support';
    }

    public function setDocsLink(string $docsLink): void
    {
        $this->docsLink = $docsLink;
    }
}
