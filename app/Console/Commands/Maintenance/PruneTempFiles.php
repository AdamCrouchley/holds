<?php

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneTempFiles extends Command
{
    protected $signature = 'maintenance:prune-temp {--days=7 : Delete temp files older than this many days}';
    protected $description = 'Remove old temporary exports/reports from storage.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        $deleted = 0;
        foreach (Storage::files('temp') as $file) {
            if (Storage::lastModified($file) <= $cutoff->timestamp) {
                Storage::delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} temp files older than {$this->option('days')} days.");

        return Command::SUCCESS;
    }
}
