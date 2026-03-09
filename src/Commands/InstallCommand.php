<?php

namespace Johind\Collate\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class InstallCommand extends Command
{
    public $signature = 'collate:install';

    public $description = 'Install Collate and verify the qpdf binary is available.';

    public function handle(): int
    {
        $this->components->info('Publishing Collate configuration...');

        $this->callSilently('vendor:publish', [
            '--tag' => 'collate-config',
        ]);

        $binaryPath = config('collate.binary_path', 'qpdf');

        $result = Process::run([$binaryPath, '--version']);

        if ($result->successful()) {
            $this->components->info('qpdf is installed: '.trim($result->output()));
        } else {
            $this->components->error("qpdf was not found at [{$binaryPath}]. Please install qpdf and update your config.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Collate installed successfully.');

        return self::SUCCESS;
    }
}
