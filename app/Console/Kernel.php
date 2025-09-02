<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/** Jobs */
use App\Jobs\ChargeUpcomingBalancesJob;

/** Artisan Commands */
use App\Console\Commands\VeVsSync;
use App\Console\Commands\PlaceBondHolds;
use App\Console\Commands\DreamDrivesImport;
use App\Console\Commands\JimnyImport;

// Bookings lifecycle
use App\Console\Commands\Bookings\AutoCancelBookings;
use App\Console\Commands\Bookings\CloseCompletedBookings;

// Notifications
use App\Console\Commands\Notifications\AdminDailyDigest;
use App\Console\Commands\Notifications\CustomerPickupReminder;
use App\Console\Commands\Notifications\CustomerReturnReminder;

// Maintenance
use App\Console\Commands\Maintenance\PruneTempFiles;

// Monitoring
use App\Console\Commands\Monitoring\CheckFeeds;

class Kernel extends ConsoleKernel
{
    /**
     * Explicitly registered custom Artisan commands.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        VeVsSync::class,
        PlaceBondHolds::class,
        DreamDrivesImport::class,
        JimnyImport::class,
        AutoCancelBookings::class,
        CloseCompletedBookings::class,
        AdminDailyDigest::class,
        CustomerPickupReminder::class,
        CustomerReturnReminder::class,
        PruneTempFiles::class,
        CheckFeeds::class,
        BackfillBookingPortalTokens::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        /** ───── Bookings ───── */
        // Charge upcoming balances every 10 minutes
        $schedule->job(new ChargeUpcomingBalancesJob())
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Sync VEVS reservations (made in last week) hourly
        $schedule->command('vevs:sync', ['--mode' => 'weekMade'])
            ->hourly()
            ->withoutOverlapping();

        // Sync VEVS reservations (pickups in last week) hourly at :30
        $schedule->command('vevs:sync', ['--mode' => 'weekPickup'])
            ->hourlyAt(30)
            ->withoutOverlapping();

        // Daily customer balance reminders (3 days before due)
        $schedule->command('bookings:remind-balance', ['--days' => 3])
            ->dailyAt('09:00');

        // Place / refresh bond holds hourly
        $schedule->command('bookings:place-bond-holds')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Auto-cancel stale/unpaid bookings
        $schedule->command('bookings:auto-cancel')
            ->hourly()
            ->withoutOverlapping();

        // Auto-close bookings that have ended
        $schedule->command('bookings:close-completed')
            ->dailyAt('00:30')
            ->withoutOverlapping();

        // Import Dream Drives feed daily at 9am
        $schedule->command('dreamdrives:import')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Import Jimny feed daily at 9am
        $schedule->command('jimny:import')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->onOneServer();

        /** ───── Notifications ───── */
        $schedule->command('notify:admin-daily-digest')
            ->dailyAt('07:30');

        $schedule->command('notify:customer-pickup')
            ->dailyAt('12:00');

        $schedule->command('notify:customer-return')
            ->dailyAt('08:00');

        /** ───── Maintenance ───── */
        $schedule->command('queue:prune-batches', ['--hours' => 48])
            ->daily();

        $schedule->command('maintenance:prune-temp', ['--days' => 7])
            ->weeklyOn(1, '03:00'); // Mondays at 3am

        /** ───── Monitoring ───── */
        $schedule->command('feeds:check', ['feed' => 'dreamdrives'])
            ->dailyAt('09:05');

        $schedule->command('feeds:check', ['feed' => 'jimny'])
            ->dailyAt('09:05');

        /** API Automations */   
        $schedule->command('holds:run-automations')->everyFifteenMinutes()->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }

    /**
     * Use NZ time for the scheduler.
     */
    protected function scheduleTimezone(): \DateTimeZone|string|null
    {
        return 'Pacific/Auckland';
    }



}
