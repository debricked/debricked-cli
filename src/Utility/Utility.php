<?php

namespace App\Utility;

class Utility
{
    /**
     * Normalises a relative path, i.e., ensure there are no double slashes and that it doesn't start with a slash.
     *
     * @param string $path The path to normalise
     *
     * @return string the normalized path
     */
    public static function normaliseRelativePath(string $path): string
    {
        $path = ltrim($path, '/');

        return preg_replace('#/+#', '/', $path);
    }

    /**
     * Goes through an array containing regexes, returns true if at least one of the regexes matches $stringToMatch, otherwise false.
     *
     * @param string[] $arrayOfRegexes
     *
     * @return bool if any regex matches
     */
    public static function pregMatchInArray(string $stringToMatch, array $arrayOfRegexes): bool
    {
        return \array_reduce(
            $arrayOfRegexes,
            function ($matchExists, $regex) use ($stringToMatch) {
                return $matchExists || \preg_match('/^'.$regex.'$/', $stringToMatch);
            },
            false);
    }
}
