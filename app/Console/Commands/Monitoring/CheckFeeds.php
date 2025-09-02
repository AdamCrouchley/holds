<?php

namespace App\Console\Commands\Monitoring;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckFeeds extends Command
{
    protected $signature = 'feeds:check {feed : dreamdrives|jimny}';
    protected $description = 'Verify that the external booking feed responds successfully.';

    public function handle(): int
    {
        $feed = $this->argument('feed');

        $url = match ($feed) {
            'dreamdrives' => config('services.dreamdrives.base'),
            'jimny'       => config('services.jimny.base'),
            default       => null,
        };

        if (! $url) {
            $this->error("No config URL for {$feed}.");
            return Command::FAILURE;
        }

        $resp = Http::timeout(15)->get($url);

        if ($resp->successful()) {
            $this->info("{$feed} feed OK (HTTP {$resp->status()}).");
            return Command::SUCCESS;
        }

        $this->error("{$feed} feed FAILED (HTTP {$resp->status()}).");
        return Command::FAILURE;
    }
}
