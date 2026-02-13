<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3.28
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/cashier (CASHIER) - v16
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- spatie/laravel-permission (SPATIE_PERMISSION) - v6
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA) - v2
- tailwindcss (TAILWINDCSS) - v4
- vue (VUE) - v3
- @laravel/vite-plugin-wayfinder (WAYFINDER) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `wayfinder-development` — Activates whenever referencing backend routes in frontend components. Use when importing from @/actions or @/routes, calling Laravel routes from TypeScript, or working with Wayfinder route functions.
- `pest-testing` — Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works.
- `inertia-vue-development` — Develops Inertia.js v2 Vue client-side applications. Activates when creating Vue pages, forms, or navigation; using &lt;Link&gt;, &lt;Form&gt;, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions Vue with Inertia, Vue pages, Vue forms, or Vue navigation.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.
- `developing-with-fortify` — Laravel Fortify headless authentication backend development. Activate when implementing authentication features including login, registration, password reset, email verification, two-factor authentication (2FA/TOTP), profile updates, headless auth, authentication scaffolding, or auth guards in Laravel applications.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs for the user.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

=== inertia-laravel/v2 rules ===

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).
- This application uses Spatie Laravel-Permission for role-based access control - see spatie/laravel-permission rules section
- For permission checks, use Spatie's methods (`hasPermissionTo()`, `can()`) and `permission:` middleware
- For admin access control, check `$user->is_admin` boolean (separate from subscription roles)

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== wayfinder/core rules ===

# Laravel Wayfinder

Wayfinder generates TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

- IMPORTANT: Activate `wayfinder-development` skill whenever referencing backend routes in frontend components.
- Invokable Controllers: `import StorePost from '@/actions/.../StorePostController'; StorePost()`.
- Parameter Binding: Detects route keys (`{post:slug}`) — `show({ slug: "my-post" })`.
- Query Merging: `show(1, { mergeQuery: { page: 2, sort: null } })` merges with current URL, `null` removes params.
- Inertia: Use `.form()` with `<Form>` component or `form.submit(store())` with useForm.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.

=== laravel/fortify rules ===

# Laravel Fortify

- Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.
- IMPORTANT: Always use the `search-docs` tool for detailed Laravel Fortify patterns and documentation.
- IMPORTANT: Activate `developing-with-fortify` skill when working with Fortify authentication features.

=== spatie/laravel-permission rules ===

# Spatie Laravel-Permission

This application uses Spatie Laravel-Permission for role-based access control (RBAC). Follow these patterns:

## Core Concepts

- **Roles are synced from Subscription Tiers**: Each SubscriptionTier automatically creates a corresponding role (free, basic, pro, premium)
- **Permissions are feature-based**: Permissions represent specific features or access levels (view-nba-predictions, export-predictions, access-api, etc.)
- **User model uses HasRoles trait**: All permission checks should use Spatie's methods, never custom methods

## Permission Checking

- Use Spatie's built-in methods exclusively: `$user->hasPermissionTo()`, `$user->can()`, `$user->hasRole()`
- Use middleware for route protection: `middleware('permission:view-nba-predictions')`
- NEVER create custom permission checking methods - Spatie provides everything needed

## Role Management

- Roles are automatically created and synced via `RolesAndPermissionsSeeder`
- Role names match tier slugs exactly (free, basic, pro, premium)
- Use `$user->syncRoles([$roleName])` to assign roles, never `assignRole()` for subscription-based roles
- Each user should have exactly ONE role at a time corresponding to their subscription tier

## Permission Assignment

- Permissions are assigned to roles in `RolesAndPermissionsSeeder` based on tier features
- When tier features change, re-run the seeder to update role permissions
- Permissions are derived from SubscriptionTier features array

## Integration with Cashier

- User roles sync automatically via Stripe webhooks (customer.subscription.created/updated/deleted)
- `User::syncRoleFromTier()` method handles automatic role assignment based on subscription
- Role sync happens at: checkout success, subscription swap, and webhook events
- Free tier is assigned when user has no active subscription

=== laravel/cashier rules ===

# Laravel Cashier & Subscription Architecture

This application uses Laravel Cashier for Stripe subscription management with tight integration to Spatie permissions.

## Subscription Tiers

- **Database-driven tiers**: Subscription tiers are stored in `subscription_tiers` table
- **SubscriptionTier model**: Central source of truth for tier configuration, pricing, and features
- **Features stored as JSON**: `features` column contains structured JSON with predictions_per_day, sports_access, boolean flags, etc.
- **Stripe price IDs**: Each tier has `stripe_price_id_monthly` and `stripe_price_id_yearly` fields

## Tier Syncing

- Use `php artisan tiers:sync` to sync config/subscriptions.php to database
- This command creates/updates tiers but preserves existing Stripe price IDs
- Run after modifying tier features or adding new tiers

## User Subscription Flow

1. User selects tier and billing period
2. `CheckoutController` creates Stripe checkout or swaps existing subscription using `swapAndInvoice()`
3. After successful checkout, `syncRoleFromTier()` assigns appropriate role
4. Stripe webhooks keep subscription status in sync and trigger role updates
5. `User::subscriptionTier()` returns the current SubscriptionTier model

## Webhook Handling

- `WebhookController` extends `CashierController` and handles: customer.subscription.created, customer.subscription.updated, customer.subscription.deleted
- All webhook events trigger `syncUserRole()` to keep roles aligned with subscriptions
- Configure webhook in Stripe Dashboard with `STRIPE_WEBHOOK_SECRET` in `.env`

## Best Practices

- Use `$user->subscriptionTier()` to get current tier (returns model, not string)
- Use `$user->subscribed()` to check if user has active subscription
- For subscription changes, use `swapAndInvoice()` instead of creating new subscriptions
- Always call `syncRoleFromTier()` after subscription changes

=== admin rules ===

# Admin Access Control

## Admin Middleware

- Admin routes are protected by `admin` middleware defined in `app/Http/Middleware/EnsureUserIsAdmin.php`
- Middleware checks `$user->is_admin` boolean flag (not role-based)
- Admin status is separate from subscription roles - admins can have any subscription tier

## Admin Routes

- All admin routes use prefix `/admin` and middleware group `['auth', 'admin']`
- Admin routes include: subscriptions management, tier management, permission management
- Follow pattern: `Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group()`

## Admin UI

- Admin tab in settings sidebar shows conditionally based on `user.is_admin`
- Admin pages are in `resources/js/pages/Admin/` directory
- Use consistent breadcrumbs with Admin as first item
- Admin components should match existing design patterns (cards, tables, buttons)

## Admin Features

- **Subscription Management** (`/admin/subscriptions`): View all users, sync with Stripe, search users
- **Tier Management** (`/admin/tiers`): CRUD operations on subscription tiers
- **Permission Management** (`/admin/permissions`): View roles, permissions, and user counts (read-only)

## Separation of Concerns

- Admin access (`is_admin`) is for administrative functions
- Subscription roles (`free`, `basic`, etc.) are for feature access
- A user can be both an admin AND have a subscription tier
- Use `is_admin` for admin panels, use role permissions for feature access

=== sports-data rules ===

# CBB (College Basketball) Data Pipeline

This application syncs college basketball data from ESPN API, calculates advanced metrics, and generates predictions using ELO ratings.

## Data Flow Architecture

### 1. Data Synchronization (ESPN API → Database)

**Schedule Sync** (`espn:sync-cbb-all-team-schedules`):
- Runs weekly (Sunday 1:30 AM) to sync complete team schedules
- Dispatches `FetchTeamSchedule` jobs for all teams to queue
- **Critical:** Contains game status protection logic (see Known Issues section)

**Current Week Sync** (`espn:sync-cbb-current`):
- Runs daily (2:00 AM) to sync current week games
- Updates game statuses, scores, and basic info for recent games (past 2 weeks + upcoming)

**Game Details Sync** (`espn:sync-cbb-game-details`):
- Syncs detailed play-by-play data and player/team stats
- **Note:** Does NOT update game status - only syncs plays and stats for games that need details

**Other Sync Commands**:
- `espn:sync-cbb-teams` - Initial team data import
- `espn:sync-cbb-players` - Player roster data
- `espn:sync-cbb-plays` - Play-by-play data for specific games

### 2. Analytics Calculation Pipeline

After data sync, run these commands in sequence:

1. **Grade Predictions** (`cbb:grade-predictions --season=2026`):
   - Grades previous predictions against actual game results
   - Updates prediction accuracy metrics
   - Run at 2:30 AM daily (after current week sync)

2. **Calculate ELO** (`cbb:calculate-elo --season=2026`):
   - Computes ELO ratings for all teams based on completed games
   - Only processes games with `STATUS_FINAL`
   - Run at 5:00 AM daily
   - Requires: Completed games with final scores

3. **Calculate Team Metrics** (`cbb:calculate-team-metrics --season=2026`):
   - Calculates advanced stats (offensive/defensive efficiency, tempo, net rating)
   - Derives from team game statistics (box scores)
   - Run at 5:30 AM daily
   - Requires: Games with team stats populated

4. **Generate Predictions** (`cbb:generate-predictions --season=2026`):
   - Creates predictions for upcoming games using ELO ratings and team metrics
   - Run at 6:00 AM daily (after all calculations complete)
   - Requires: ELO ratings + team metrics + scheduled games

### 3. Automated Schedule (routes/console.php)

```php
// Weekly comprehensive schedule sync
Schedule::command('espn:sync-cbb-all-team-schedules')
    ->weeklyOn(0, '01:30'); // Sunday 1:30 AM

// Daily pipeline
Schedule::command('espn:sync-cbb-current')->dailyAt('02:00');
Schedule::command('cbb:grade-predictions --season=2026')->dailyAt('02:30');
Schedule::command('cbb:calculate-elo --season=2026')->dailyAt('05:00');
Schedule::command('cbb:calculate-team-metrics --season=2026')->dailyAt('05:30');
Schedule::command('cbb:generate-predictions --season=2026')->dailyAt('06:00');
```

## Database Models

- **Game**: Core game data (teams, scores, status, date/time)
- **Team**: Team information (name, logo, conference, ESPN ID)
- **EloRating**: Team ELO ratings by season
- **TeamMetric**: Advanced team statistics (efficiency, tempo, net rating)
- **TeamStat**: Per-game team box score statistics
- **Prediction**: Game predictions with confidence scores
- **Play**: Play-by-play data for detailed game analysis
- **Player**: Player roster information
- **PlayerStat**: Per-game player statistics

## Known Issues & Fixes

### Game Status Synchronization Issue

**Problem**: ESPN's team schedule API sometimes returns past games with `STATUS_SCHEDULED` instead of `STATUS_FINAL`, causing:
- Past games incorrectly marked as scheduled
- ELO calculations to skip completed games
- Team metrics to fail (no completed games found)

**Solution** (app/Actions/ESPN/CBB/SyncTeamSchedule.php:40-47):
```php
// If game date is in the past and ESPN still returns STATUS_SCHEDULED,
// override to STATUS_FINAL to prevent resetting completed games
$status = $dto->status;
if ($gameDate && $gameDate < now()->format('Y-m-d') && $status === 'STATUS_SCHEDULED') {
    $status = 'STATUS_FINAL';
}
```

This protection ensures:
- Past games are never reset from FINAL back to SCHEDULED
- Weekly schedule sync doesn't corrupt existing data
- Analytics pipeline continues to work correctly

### Data Sync Flow

1. **espn:sync-cbb-all-team-schedules** creates games as SCHEDULED (or FINAL if past date)
2. **espn:sync-cbb-current** updates statuses for recent games (±2 weeks)
3. **espn:sync-cbb-game-details** adds plays/stats but never touches game status

**Important**: Only `espn:sync-cbb-current` reliably updates game statuses. The team schedule sync has protection logic, but current week sync is the primary status updater.

## Health Checks

### Team Schedules Health Check

**Location**: `app/Console/Commands/HealthcheckRun.php:144-258`

**Purpose**: Validates team schedule data integrity using statistical analysis

**Checks**:
1. **No Games Check**: Identifies teams with zero games scheduled
2. **Outlier Detection**: Finds teams with abnormal game counts using statistical deviation
   - Calculates mean, variance, and standard deviation of game counts
   - Flags teams >2 standard deviations from mean as outliers

**Status Thresholds**:
- `failing`: >10% teams have no games OR >15% are outliers
- `warning`: Any teams have issues but below failing thresholds
- `passing`: No issues detected

**Metadata Stored**:
```json
{
  "total_teams": 362,
  "teams_with_no_games": [{"team": "TeamName", "espn_id": "123"}],
  "schedule_outliers": [
    {"team": "TeamName", "total_games": 45, "deviation_from_avg": 15.2, "outlier_type": "above"}
  ],
  "stats": {"mean": 30.5, "std_dev": 5.2, "min": 0, "max": 45}
}
```

### Viewing Health Checks

**Admin Interface**: `/admin/healthchecks`
- Filter by sport
- Run health checks on demand
- Sync data to fix issues
- View detailed metadata for each check type
- Special rendering for team_schedules check showing no-game teams and outliers

## API Resources

All CBB models have corresponding API resources for clean JSON serialization:
- `TeamResource`, `GameResource`, `PredictionResource`, `EloRatingResource`
- `TeamMetricResource`, `PlayerResource`, `PlayerStatResource`, `TeamStatResource`
- Located in `app/Http/Resources/CBB/`

## Betting Odds Integration

The system integrates with The Odds API to fetch and store betting odds for CBB games.

### How It Works

1. **Fetch Odds**: `OddsApiService` fetches odds from The Odds API for basketball_ncaab
2. **Match Events**: `SyncOddsForGames` action matches Odds API events to ESPN games using:
   - Game date matching
   - Fuzzy team name matching (>70% similarity)
   - Manual team name mappings (`odds_api_team_mappings` table)
3. **Store Data**: Odds stored in JSON format on `cbb_games.odds_data` column

### Commands

```bash
# Sync odds for upcoming games (default: next 7 days)
php artisan cbb:sync-odds

# Sync odds for specific number of days ahead
php artisan cbb:sync-odds --days=14
```

### Configuration

- **API Key**: Set `ODDS_API_KEY` in `.env`
- **Service Config**: `config/services.php` → `odds_api`
- **Bookmaker**: Currently DraftKings only (cost-efficient)
- **Market**: Moneyline (h2h) only

### Team Name Mappings

When automatic fuzzy matching fails (team names differ significantly between ESPN and Odds API), create manual mappings:

```php
OddsApiTeamMapping::create([
    'espn_team_name' => 'UConn',
    'odds_api_team_name' => 'Connecticut',
    'sport' => 'basketball_ncaab'
]);
```

### Database Schema

**Games Table**:
- `odds_api_event_id`: Odds API event identifier
- `odds_data`: JSON column storing full odds response
- `odds_updated_at`: Timestamp of last odds sync

**Example Odds Data**:
```json
{
  "event_id": "abc123",
  "commence_time": "2026-02-06T19:00:00Z",
  "home_team": "Duke",
  "away_team": "North Carolina",
  "bookmakers": [
    {
      "key": "draftkings",
      "title": "DraftKings",
      "markets": [
        {
          "key": "h2h",
          "outcomes": [
            {"name": "Duke", "price": -150},
            {"name": "North Carolina", "price": +130}
          ]
        }
      ]
    }
  ]
}
```

### Cost Management

- **Single Bookmaker**: DraftKings only (1 market × 1 region = 1 credit/request)
- **Caching**: 5-minute cache on API responses
- **Targeted Sync**: Only fetch odds for games on specific dates
- **API Limits**: Monitor usage at https://the-odds-api.com/account

## Best Practices

1. **Always run calculations in order**: ELO → Team Metrics → Predictions
2. **Verify game statuses** before running analytics (use health checks)
3. **Use `--season` parameter** for all calculation commands to ensure correct data
4. **Monitor queue workers** during schedule syncs (362 teams × queue jobs)
5. **Check health dashboard** regularly at `/admin/healthchecks`
6. **Run `php artisan queue:restart`** after code deployments affecting jobs

## Troubleshooting

**Issue**: "No completed games found" during ELO/metrics calculation
- **Cause**: Games have `STATUS_SCHEDULED` instead of `STATUS_FINAL`
- **Fix**: Run `espn:sync-cbb-current` to update statuses, or manually update via database

**Issue**: Queue jobs failing with "Call to undefined method"
- **Cause**: Queue workers cached old code
- **Fix**: Run `php artisan queue:restart` and `php artisan queue:flush`

**Issue**: Missing team metrics for some teams
- **Cause**: Teams have no completed games (new teams, haven't played yet)
- **Expected**: This is normal early in season - teams show up after first game completes
</laravel-boost-guidelines>
