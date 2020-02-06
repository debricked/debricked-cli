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

use Symfony\Component\Console\Helper\ProgressBar;

trait Style
{
    private function setProgressBarStyle(ProgressBar &$progressBar): void
    {
        $progressBar->setBarCharacter('<fg=green>=</>');
        $progressBar->setEmptyBarCharacter('<fg=red>-</>');
        $progressBar->setProgressCharacter('<fg=green>➤</>');
    }
}
