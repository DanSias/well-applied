<?php

namespace App\Support\Dev;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Asks the application-preparer's own startup wrapper whether it could start (`node src/start.ts --check`), so
 * `composer run dev` can say so BEFORE the dev processes take over the screen. It reuses the wrapper's checks (the
 * settings in the root .env, the installed dependencies, Chromium) and duplicates none of them. It starts nothing, polls
 * nothing and changes nothing. A problem never stops the other dev processes: the preparer itself stays idle with the same
 * message (see `--dev` in workers/application-preparer/src/start.ts).
 */
final class PreparerStartupCheck
{
    public const DIRECTORY = 'workers/application-preparer';

    /**
     * @return array{ready: bool, output: string}
     */
    public function run(): array
    {
        $directory = base_path(self::DIRECTORY);

        if (! is_file($directory.'/src/start.ts')) {
            return ['ready' => false, 'output' => 'The application-preparer is not in this checkout.'];
        }

        try {
            $result = Process::path($directory)->timeout(30)->run('node src/start.ts --check');
        } catch (Throwable) {
            return ['ready' => false, 'output' => 'Node could not be run, so the preparer cannot start (it needs Node 22.18 or newer).'];
        }

        // Node older than 22.18 cannot run the TypeScript at all; its raw error would be noise, so say what matters.
        $output = trim($result->output().$result->errorOutput());
        if (! $result->successful() && ! str_contains($output, '[agent]')) {
            $output = 'The preparer could not run (it needs Node 22.18 or newer, with `npm install` done in '.self::DIRECTORY.').';
        }

        return ['ready' => $result->successful(), 'output' => $output];
    }
}
