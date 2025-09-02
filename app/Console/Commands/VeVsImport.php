<?php

namespace App\Console\Commands;

use App\Services\VeVsImporter;
use Illuminate\Console\Command;

class VeVsImport extends Command
{
    protected $signature = 'vevs:import {--ref=}';
    protected $description = 'Import a single reservation from VEVS by reference and show the local booking id';

    public function handle(VeVsImporter $importer): int
    {
        $ref = strtoupper((string)$this->option('ref'));
        if (!$ref) {
            $this->error('Usage: php artisan vevs:import --ref=QT123...');
            return self::INVALID;
        }

        $booking = $importer->importByRef($ref);

        $this->info("Imported/Found booking #{$booking->id} ({$booking->reference}) for customer_id={$booking->customer_id}");
        return self::SUCCESS;
    }
}
