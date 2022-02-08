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
    public static function findFormatByFileName(array $dependencyFileFormats, string $filename): ?self
    {
        foreach ($dependencyFileFormats as $dependencyFileFormat) {
            $regexes = $dependencyFileFormat->getRegexes();
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
        return $this->lockFiles;
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
        return "/$this->regex/";
    }

    public function isLockFileFormat(): bool
    {
        return empty($this->lockFiles);
    }

    /**
     * @return string[] returns format and lock file regexes
     */
    public function getRegexes(): array
    {
        return \array_merge([$this->regex], $this->getLockFileRegexes());
    }

    public function setRegex(string $regex): void
    {
        $this->regex = $regex;
    }

    public function getDocsLink(): string
    {
        return $this->docsLink ?? '';
    }

    public function setDocsLink(string $docsLink): void
    {
        $this->docsLink = $docsLink;
    }
}
