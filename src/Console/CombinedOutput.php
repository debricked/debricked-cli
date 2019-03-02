<?php
/**
 * @license
 *
 * Copyright (C) debricked AB
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code (usually found in the root of this application).
 */

namespace App\Console;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Combines write logic of @see BufferedOutput with output of @see ConsoleOutput.
 */
class CombinedOutput extends ConsoleOutput
{
    private $buffer = '';

    /**
     * Empties buffer and returns its content.
     *
     * @return string
     */
    public function fetch()
    {
        $content = $this->buffer;
        $this->buffer = '';

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    protected function doWrite($message, $newline)
    {
        $this->buffer .= $message;

        if ($newline) {
            $this->buffer .= PHP_EOL;
        }

        parent::doWrite($message, $newline);
    }
}
