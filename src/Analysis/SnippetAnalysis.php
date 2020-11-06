<?php
/**
 * @license
 *
 * Copyright (C) debricked AB
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code (usually found in the root of this application).
 */

namespace App\Analysis;

class SnippetAnalysis
{
    /** @var string[] */
    private array $ignore;

    /** @var string[] */
    private array $snippets;

    public function __construct()
    {
        $this->ignore = ['bmp' => '', 'class' => '', 'conf' => '', 'csv' => '', 'eps' => '', 'gif' => '',
            'gitignore' => '', 'gz' => '', 'jpeg' => '', 'jpg' => '', 'lock' => '', 'md' => '', 'mp3' => '',
            'mp4' => '', 'pdf' => '', 'png' => '', 'rst' => '', 'sql' => '', 'tif' => '', 'zip' => '', ];
        $this->snippets = [];
    }

    /**
     * Analyses a file, calculates its hashes (if it is a relevant file), and adds the hashes to the internal state.
     *
     * @param string $absoluteFilename the filename to analyse
     * @param string $relativeFilename the filename to include in the WFP fingerprint
     *
     * @return bool true if the file was analysed, false if the file was ignored
     */
    public function analyseFile(string $absoluteFilename, string $relativeFilename): bool
    {
        // First determine if we want to scan it or not by checking the ignore list.
        $extension = \pathinfo($absoluteFilename, PATHINFO_EXTENSION);
        if (empty($extension) || \array_key_exists(\strtolower($extension), $this->ignore)) {
            return false;
        }

        // Now get the WFP signature for this file.
        $wfp = $this->calculateWfp($absoluteFilename, $relativeFilename);
        if ($wfp === null) {
            return false;
        }
        $this->snippets[] = $wfp;

        return true;
    }

    /**
     * Returns the analysed snippets currently stored as a WFP file.
     *
     * @return string the WFP file as a string based on the current state
     */
    public function dumpWfp(): string
    {
        return implode($this->snippets);
    }

    // The following functions are from https://github.com/scanoss/scanner.php and is licensed under the MIT license,
    // following a special agreement with SCANOSS. See the file LICENSE.SCANOSS for the full license.
    private function calculateWfp(string $absoluteFilename, string $relativeFilename): ?string
    {
        /* Read file contents */
        $src = file_get_contents($absoluteFilename);
        if ($src === false) {
            return null;
        }

        /* Gram/Window configuration. Modifying these values would require rehashing the KB	*/
        $GRAM = 30;
        $WINDOW = 64;
        $LIMIT = 10000;

        $line = 1;
        $last_line = 0;
        $counter = 0;
        $hash = 0xFFFFFFFF;
        $last_hash = 0;
        $last = 0;
        $gram = '';
        $gram_ptr = 0;
        $window = [];
        $window_ptr = 0;

        /* Add line entry */
        $out = 'file='.md5($src).','.strlen($src).",$relativeFilename";

        /* Process one byte at a time */
        $src_len = strlen($src);
        for ($i = 0; $i < $src_len; ++$i) {
            if ($src[$i] == "\n") {
                ++$line;
            }

            $byte = $this->normalize($src[$i]);
            if (!$byte) {
                continue;
            }

            /* Add byte to the gram */
            $gram[$gram_ptr++] = $byte;

            /* Got a full gram? */
            if ($gram_ptr >= $GRAM) {
                /* Add fingerprint to the window */
                $window[$window_ptr++] = (int) hexdec(hash('crc32c', $gram));

                /* Got a full window? */
                if ($window_ptr >= $WINDOW) {
                    /* Add hash */
                    $hash = \min($window);
                    if ($hash != $last_hash) {
                        $last_hash = $hash;
                        $hash = $this->crc32c_of_int32($hash);
                        if ($line != $last_line) {
                            $out .= "\n$line=".sprintf('%08x', $hash);
                            $last_line = $line;
                        } else {
                            $out .= ','.sprintf('%08x', $hash);
                        }

                        if ($counter++ >= $LIMIT) { // @phpstan-ignore-line
                            break;
                        }
                    }

                    /* Shift window */
                    array_shift($window);
                    $window_ptr = $WINDOW - 1;
                    $window[$window_ptr] = 0xFFFFFFFF;
                }

                /* Shift gram */
                $gram = substr($gram, 1);
                $gram_ptr = $GRAM - 1;
            }
        }
        $out .= "\n";

        return $out;
    }

    private function crc32c_of_int32(int $int32): int
    {
        $d = [];
        $d[1] = $int32 % 256;
        $d[2] = (($int32 - $d[1]) % 65536) / 256;
        $d[3] = (($int32 - $d[1] - $d[2] * 256) % 16777216) / 65536;
        $d[4] = ($int32 - $d[1] - $d[2] * 256 - $d[3] * 65536) / 16777216;

        $crc = 0xFFFFFFFF;
        for ($i = 1; $i <= 4; ++$i) {
            $crc = $crc ^ $d[$i];
            for ($j = 7; $j >= 0; --$j) {
                $crc = ($crc >> 1) ^ (0x82F63B78 & -($crc & 1));
            }
        }

        return $crc ^ 0xFFFFFFFF;
    }

    private function normalize(string $byte): string
    {
        /* Convert case to lowercase, and return zero if it isn't a letter or number. Do it fast and independent from the locale configuration (avoid string.h) */
        if ($byte < '0') {
            return '';
        }
        if ($byte > 'z') {
            return '';
        }
        if ($byte <= '9') {
            return $byte;
        }
        if ($byte >= 'a') {
            return $byte;
        }
        if (($byte >= 'A') && ($byte <= 'Z')) {
            return strtolower($byte);
        }

        return '';
    }
}
