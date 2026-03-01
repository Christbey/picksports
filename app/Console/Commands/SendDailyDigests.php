<?php

namespace App\Console\Commands;

use App\Actions\Alerts\SelectTopBetsForDigest;
use App\Services\NotificationTemplateDefaultService;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Notifications\DailyBettingDigest;
use App\Support\SportCatalog;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyDigests extends Command
{
    protected $signature = 'alerts:send-daily-digests
                            {--sport=all : Sport to generate digest for (or "all")}
                            {--date= : Date to generate digest for (YYYY-MM-DD)}
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send daily betting digest emails to users based on their preferences';

    public function __construct(
        protected SelectTopBetsForDigest $selectTopBets,
        protected NotificationTemplateDefaultService $templateDefaultService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sportOption = strtolower((string) $this->option('sport'));
        $dateStr = $this->option('date');
        $dryRun = $this->option('dry-run');

        $date = $dateStr ? Carbon::parse($dateStr) : now();
        $sports = $sportOption === 'all' ? SportCatalog::ALL : [$sportOption];

        if ($sportOption !== 'all' && ! in_array($sportOption, SportCatalog::ALL, true)) {
            $this->error("Unsupported sport '{$sportOption}'. Valid values: all, ".implode(', ', SportCatalog::ALL));

            return self::FAILURE;
        }

        $this->info('Generating daily digests for '.implode(', ', $sports).' on '.$date->format('Y-m-d'));

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No emails will be sent');
        }

        // Find notification template
        $template = $this->templateDefaultService->resolve('daily_betting_digest');

        if (! $template) {
            $this->warn('Daily Betting Digest template not found. Run database seeders.');
        }

        $digestsSent = 0;
        $errors = 0;
        $users = $this->getQualifyingUsers($date);
        $qualifyingUsers = $users->count();

        if ($users->isEmpty()) {
            $this->info('No users qualify for digest at this time.');

            return self::SUCCESS;
        }

        $this->info("Found {$qualifyingUsers} qualifying users");

        foreach ($users as $user) {
            try {
                $eligibleSports = $this->resolveEligibleSportsForUser($user, $sports);

                if ($eligibleSports === []) {
                    $this->line("  Skipping {$user->email} - no eligible sports available");
                    continue;
                }

                // Get top bets across all eligible sports for this user
                $topBets = $this->selectTopBets->executeAcrossSports($user, $eligibleSports, $date);

                // Count total games analyzed across eligible sports
                $totalGames = collect($eligibleSports)
                    ->sum(fn (string $sport): int => $this->getTotalGamesCount($sport, $date));

                // Send digest if configured to send empty or if bets are available
                $shouldSend = config('alerts.digest.send_empty', true) || $topBets->isNotEmpty();

                if (! $shouldSend) {
                    $this->line("  Skipping {$user->email} - no bets and empty digests disabled");
                    continue;
                }

                if ($dryRun) {
                    $this->line("  Would send to: {$user->email} (sports: ".implode(', ', $eligibleSports).", {$topBets->count()} bets)");
                    continue;
                }

                // Send notification
                $user->notify(new DailyBettingDigest(
                    topBets: $topBets,
                    totalGamesAnalyzed: $totalGames,
                    digestDate: $date,
                    sport: 'all',
                    template: $template
                ));

                // Record alert sent
                UserAlertSent::create([
                    'user_id' => $user->id,
                    'sport' => 'all',
                    'alert_type' => 'daily_digest',
                    'prediction_id' => null,
                    'prediction_type' => null,
                    'expected_value' => null,
                    'sent_at' => now(),
                ]);

                $this->line("  ✓ Sent to: {$user->email} (sports: ".implode(', ', $eligibleSports).", {$topBets->count()} bets)");
                $digestsSent++;
            } catch (\Exception $e) {
                $this->error("  ✗ Failed for {$user->email}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("  Users qualified: {$qualifyingUsers}");
        $this->info("  Digests sent: {$digestsSent}");
        if ($errors > 0) {
            $this->error("  Errors: {$errors}");
        }

        return self::SUCCESS;
    }

    protected function getQualifyingUsers(Carbon $date)
    {
        $windowMinutes = config('alerts.digest.time_window_minutes', 30);

        // Calculate time window (e.g., if digest_time is 10:00, and window is 30 mins,
        // we'll send digests between 9:30 and 10:30)
        $startWindow = now()->subMinutes($windowMinutes)->format('H:i:s');
        $endWindow = now()->addMinutes($windowMinutes)->format('H:i:s');

        return User::query()
            ->with(['alertPreference'])
            ->whereHas('alertPreference', function ($query) use ($startWindow, $endWindow) {
                $query->where('enabled', true)
                    ->where('digest_mode', 'daily_summary')
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

    /**
     * @param  array<int, string>  $requestedSports
     * @return array<int, string>
     */
    protected function resolveEligibleSportsForUser(User $user, array $requestedSports): array
    {
        return collect($requestedSports)
            ->map(fn ($sport) => strtolower((string) $sport))
            ->filter(fn ($sport) => in_array($sport, SportCatalog::ALL, true))
            ->filter(fn ($sport) => $user->canAccessSport($sport))
            ->unique()
            ->values()
            ->all();
    }

    protected function getTotalGamesCount(string $sport, Carbon $date): int
    {
        return $this->selectTopBets->totalGamesForDate($sport, $date);
    }
}
