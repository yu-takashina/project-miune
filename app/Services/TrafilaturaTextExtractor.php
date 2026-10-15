<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class TrafilaturaTextExtractor
{
    public const string PYTHON = '/opt/trafilatura/bin/python';

    public const int TIMEOUT_SECONDS = 15;

    public function extract(string $html): ?string
    {
        if (! is_file(self::PYTHON)) {
            return null;
        }

        $process = new Process([self::PYTHON, base_path('bin/extract_main_text.py')]);
        $process->setInput($html);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->setEnv([
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONUTF8' => '1',
            'LANG' => 'C.UTF-8',
        ]);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }
}
