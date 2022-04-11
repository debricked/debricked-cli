<?php

namespace App\Service;

use App\Model\DependencyFileFormat;
use App\Model\FileGroup;
use App\Utility\Utility;
use Debricked\Shared\API\API;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class FileGroupFinder
{
    /**
     * @param string[] $excludedDirectories
     *
     * @return FileGroup[]
     *
     * @throws TransportExceptionInterface|HttpExceptionInterface|DirectoryNotFoundException
     */
    public static function find(API $api, string $searchDirectory, bool $recursiveFileSearch, array $excludedDirectories): array
    {
        $finder = self::makeFinder($searchDirectory, $recursiveFileSearch, $excludedDirectories);

        // Fetch supported dependency file formats
        $dependencyFileNamesResponse = $api->makeApiCall(
            Request::METHOD_GET,
            '/api/1.0/open/files/supported-formats'
        );
        $dependencyFileFormats = \json_decode($dependencyFileNamesResponse->getContent(), true);
        $dependencyFileFormats = DependencyFileFormat::make($dependencyFileFormats);

        $lockFiles = self::findLockFiles($finder, $dependencyFileFormats);

        return self::createFileGroups($lockFiles, $finder, $dependencyFileFormats, $searchDirectory);
    }

    /**
     * @param string[] $excludedDirectories
     */
    public static function makeFinder(string $searchDirectory, bool $recursiveFileSearch, array $excludedDirectories): Finder
    {
        $finder = new Finder();
        $finder->ignoreDotFiles(false);
        $finder->files()->in($searchDirectory);
        $finder->exclude($excludedDirectories);
        if (!$recursiveFileSearch) {
            $finder->depth(0);
        }

        return $finder;
    }

    /**
     * Find and return all lock files in finder based on inputted dependency file formats.
     *
     * @param DependencyFileFormat[] $dependencyFileFormats
     *
     * @return SplFileInfo[]
     */
    private static function findLockFiles(Finder $finder, array $dependencyFileFormats): array
    {
        $lockFiles = [];
        $lockFileRegexes = \array_merge(...\array_map(fn ($format) => $format->getLockFileRegexes(), $dependencyFileFormats));
        foreach ($finder as $file) {
            if (Utility::pregMatchInArray($file->getFilename(), $lockFileRegexes)) {
                $lockFiles[$file->getPathname()] = $file;
            }
        }

        return $lockFiles;
    }

    /**
     * @param SplFileInfo[]          $lockFiles
     * @param DependencyFileFormat[] $dependencyFileFormats
     *
     * @return FileGroup[]
     */
    private static function createFileGroups(array $lockFiles, Finder $finder, array $dependencyFileFormats, string $searchDirectory): array
    {
        $fileGroups = [];
        foreach ($finder as $file) {
            if (($dependencyFileFormat = DependencyFileFormat::findFormatByFileName($dependencyFileFormats, $file->getFilename())) !== null) {
                $fileGroup = new FileGroup($file, $dependencyFileFormat, $searchDirectory);
                $lockFileRegexes = $dependencyFileFormat->getLockFileRegexes();
                // Find matching lock file
                foreach ($lockFileRegexes as $lockFileRegex) {
                    foreach ($lockFiles as $key => $lockFile) {
                        $quotedLockfilePath = \preg_quote($file->getPath(), '/');
                        if (\preg_match("/$quotedLockfilePath\/$lockFileRegex/", $key) === 1) {
                            $fileGroup->addLockFile($lockFile);
                            unset($lockFiles[$key]);
                            break;
                        }
                    }
                }
                $fileGroups[] = $fileGroup;
            }
        }

        // Create FileGroups from leftover lock files.
        foreach ($lockFiles as $key => $lockFile) {
            $lockFileGroup = new FileGroup(null, null, $searchDirectory);
            $lockFileGroup->addLockFile($lockFile);
            $fileGroups[] = $lockFileGroup;
            unset($lockFiles[$key]);
        }

        return $fileGroups;
    }
}
