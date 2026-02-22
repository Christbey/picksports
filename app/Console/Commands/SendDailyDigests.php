<?php

namespace App\Console\Commands;

use App\Actions\Alerts\SelectTopBetsForDigest;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\DailyBettingDigest;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyDigests extends Command
{
    protected $signature = 'alerts:send-daily-digests
                            {--sport=cbb : Sport to generate digest for}
                            {--date= : Date to generate digest for (YYYY-MM-DD)}
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send daily betting digest emails to users based on their preferences';

    public function __construct(
        protected SelectTopBetsForDigest $selectTopBets
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->option('sport');
        $dateStr = $this->option('date');
        $dryRun = $this->option('dry-run');

        $date = $dateStr ? Carbon::parse($dateStr) : now();

        $this->info("Generating daily digests for {$sport} on {$date->format('Y-m-d')}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No emails will be sent');
        }

        // Find notification template
        $template = NotificationTemplate::query()
            ->where('name', 'Daily Betting Digest')
            ->active()
            ->first();

        if (! $template) {
            $this->warn('Daily Betting Digest template not found. Run database seeders.');
        }

        // Get qualifying users
        $users = $this->getQualifyingUsers($sport, $date);

        if ($users->isEmpty()) {
            $this->info('No users qualify for digest at this time.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} qualifying users");

        $digestsSent = 0;
        $errors = 0;

        foreach ($users as $user) {
            try {
                // Get top bets for this user
                $topBets = $this->selectTopBets->execute($user, $sport, $date);

                // Count total games analyzed (all scheduled games for the date)
                $totalGames = $this->getTotalGamesCount($sport, $date);

                // Send digest if configured to send empty or if bets are available
                $shouldSend = config('alerts.digest.send_empty', true) || $topBets->isNotEmpty();

                if (! $shouldSend) {
                    $this->line("  Skipping {$user->email} - no bets and empty digests disabled");

                    continue;
                }

                if ($dryRun) {
                    $this->line("  Would send to: {$user->email} ({$topBets->count()} bets)");

                    continue;
                }

                // Send notification
                $user->notify(new DailyBettingDigest(
                    topBets: $topBets,
                    totalGamesAnalyzed: $totalGames,
                    digestDate: $date,
                    sport: $sport,
                    template: $template
                ));

                // Record alert sent
                UserAlertSent::create([
                    'user_id' => $user->id,
                    'sport' => strtolower($sport),
                    'alert_type' => 'daily_digest',
                    'prediction_id' => null,
                    'prediction_type' => null,
                    'expected_value' => null,
                    'sent_at' => now(),
                ]);

                $this->line("  ✓ Sent to: {$user->email} ({$topBets->count()} bets)");
                $digestsSent++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$user->email}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Digests sent: {$digestsSent}");
        if ($errors > 0) {
            $this->error("  Errors: {$errors}");
        }

        return self::SUCCESS;
    }

    protected function getQualifyingUsers(string $sport, Carbon $date)
    {
        $currentHour = now()->format('H:i:s');
        $windowMinutes = config('alerts.digest.time_window_minutes', 30);

        // Calculate time window (e.g., if digest_time is 10:00, and window is 30 mins,
        // we'll send digests between 9:30 and 10:30)
        $startWindow = now()->subMinutes($windowMinutes)->format('H:i:s');
        $endWindow = now()->addMinutes($windowMinutes)->format('H:i:s');

        return User::query()
            ->with(['alertPreference'])
            ->whereHas('alertPreference', function ($query) use ($sport, $startWindow, $endWindow) {
                $query->where('enabled', true)
                    ->where('digest_mode', 'daily_summary')
                    ->whereJsonContains('sports', strtolower($sport))
                    ->whereTime('digest_time', '>=', $startWindow)
                    ->whereTime('digest_time', '<=', $endWindow);
            })
            ->get()
            ->filter(function ($user) {
                // Additional tier checks
                if (! $user->subscriptionTier()) {
                    return false;
                }

                // Check if user's tier allows email alerts
                $tier = $user->subscriptionTier();
                $features = $tier->features ?? [];

                return ($features['email_alerts'] ?? false) === true;
            });
    }

    protected function getTotalGamesCount(string $sport, Carbon $date): int
    {
        // Currently only CBB is supported
        if ($sport !== 'cbb') {
            return 0;
        }

        return \App\Models\CBB\Game::query()
            ->whereDate('game_date', $date)
            ->where('status', 'STATUS_SCHEDULED')
            ->count();
    }
}
