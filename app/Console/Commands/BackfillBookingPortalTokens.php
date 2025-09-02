<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class BackfillBookingPortalTokens extends Command
{
    protected $signature = 'bookings:backfill-portal-tokens
                            {--chunk=500 : Number of bookings to process per chunk}
                            {--length=40 : Token length}
                            {--dry : Dry run (don\'t write changes)}
                            {--only-missing : Only backfill where portal_token is NULL/empty (default)}
                            {--all : Overwrite tokens for ALL rows (dangerous)}
                            ';

    protected $description = 'Backfill (or overwrite) portal_token values for bookings, generating unique tokens.';

    public function handle(): int
    {
        $chunk  = (int) $this->option('chunk');
        $length = (int) $this->option('length');
        $dry    = (bool) $this->option('dry');
        $onlyMissing = ! $this->option('all'); // default true unless --all is set

        if ($length < 24) {
            $this->warn("Token length {$length} is quite short; recommend >= 32.");
        }
        if (! $onlyMissing) {
            $this->warn('You are about to overwrite tokens for ALL rows. Use --all intentionally.');
            if (! $this->confirm('Proceed and overwrite all portal_token values?', false)) {
                return self::FAILURE;
            }
        }

        // Build base query
        $query = Booking::query()->select(['id', 'portal_token']);
        if ($onlyMissing) {
            $query->where(function ($q) {
                $q->whereNull('portal_token')->orWhere('portal_token', '');
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No bookings require backfill.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Processing {$total} booking(s) in chunks of {$chunk}…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById($chunk, function ($bookings) use (&$updated, &$skipped, $length, $dry, $bar) {
            // Preload existing tokens to check collisions quickly
            $existing = Booking::query()->whereNotNull('portal_token')->pluck('portal_token')->flip();

            foreach ($bookings as $booking) {
                // If we’re only filling missing and this one already has a token, skip.
                if (!empty($booking->portal_token)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Generate a unique token (retry a few times if collision)
                $token = null;
                for ($i = 0; $i < 5; $i++) {
                    $candidate = Str::random($length);
                    if (!isset($existing[$candidate])) {
                        $token = $candidate;
                        break;
                    }
                }

                if ($token === null) {
                    // Extremely unlikely
                    $this->error("\nFailed to generate unique token for booking #{$booking->id} after 5 attempts.");
                    $bar->advance();
                    continue;
                }

                if ($dry) {
                    $updated++;
                } else {
                    DB::transaction(function () use ($booking, $token, &$updated, &$existing) {
                        // double-check at DB level for safety
                        $collision = Booking::where('portal_token', $token)->exists();
                        if ($collision) {
                            // regenerate one more time and bail if still colliding
                            $token2 = Str::random(strlen($token));
                            if (Booking::where('portal_token', $token2)->exists()) {
                                throw new \RuntimeException("Collision detected twice; aborting for booking #{$booking->id}");
                            }
                            $token = $token2;
                        }

                        $booking->portal_token = $token;
                        $booking->save();

                        $existing[$token] = true;
                        $updated++;
                    });
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(($dry ? '[DRY RUN] ' : '') . "Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
