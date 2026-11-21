<?php

/**
 * Read-only deployment evidence. No Laravel bootstrap, HTTP, queues or DDL.
 * Run from the deployed application root, never print cached configuration.
 */
$root = getcwd();
$report = ['schema_version' => 'nba_rollout_preflight_v1', 'observed_at_utc' => gmdate('c'), 'application_root' => $root];
$pdo = null;

try {
    $configPath = $root.'/bootstrap/cache/config.php';
    if (! is_file($configPath)) {
        throw new RuntimeException('cached_configuration_missing');
    }
    $config = require $configPath;
    $get = static function (string $path) use ($config): mixed {
        $value = $config;
        foreach (explode('.', $path) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    };
    $report['app_environment'] = $get('app.env');
    $report['app_url'] = $get('app.url');
    $report['app_timezone'] = $get('app.timezone');
    if ($get('app.env') !== 'production' || parse_url((string) $get('app.url'), PHP_URL_HOST) !== 'picksports.app') {
        throw new RuntimeException('production_identity_not_verified');
    }
    $flags = [
        'prediction_lifecycle.canonical_reads.nba', 'prediction_lifecycle.canonical_pipeline.nba',
        'prediction_lifecycle.canonical_reads.nfl', 'prediction_lifecycle.canonical_pipeline.nfl',
        'research.enabled', 'research.sports.nba.enabled', 'research.sports.nfl.enabled',
        'prediction_review.enabled', 'prediction_review.sports.nba', 'prediction_review.sports.nfl',
        'game_analysis.schedule_enabled', 'game_analysis.publisher_enabled',
        'nfl_research.enabled', 'nfl_research.web_search_enabled',
        'ai.features.nfl_game_context_research.enabled', 'ai.features.nfl_game_context_research.model',
        'ai.features.daily_prediction_analysis.enabled', 'ai.features.daily_prediction_analysis.model',
    ];
    foreach ($flags as $flag) {
        $report['cached_settings'][$flag] = $get($flag);
    }
    $report['queue'] = [
        'connection' => $get('queue.default'),
        'retry_after' => $get('queue.connections.'.$get('queue.default').'.retry_after'),
        'declared_default_worker_timeout' => $get('queue.worker_contracts.default.timeout'),
    ];
    $files = [
        'database/migrations/2026_11_20_230100_create_prediction_review_work_tables.php',
        'database/migrations/2026_11_21_010000_create_shared_research_storage.php',
        'app/Services/Research/GameResearchService.php',
        'app/Services/Predictions/Review/PredictionReviewCoordinator.php',
        'app/Services/Predictions/PredictionResourcePreparer.php',
        'app/Services/Research/ResearchDocumentStore.php',
        'app/Console/Commands/Sports/IngestResearchDocumentCommand.php',
    ];
    foreach ($files as $file) {
        $report['release_files'][$file] = is_file($root.'/'.$file) ? hash_file('sha256', $root.'/'.$file) : null;
    }
    $connection = $get('database.connections.'.$get('database.default'));
    if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
        throw new RuntimeException('mysql_configuration_required');
    }
    if (! empty($connection['url'])) {
        $url = parse_url($connection['url']);
        if (! is_array($url)) {
            throw new RuntimeException('database_url_invalid');
        }
        foreach (['host' => 'host', 'port' => 'port', 'user' => 'username', 'pass' => 'password'] as $from => $to) {
            if (isset($url[$from])) {
                $connection[$to] = rawurldecode((string) $url[$from]);
            }
        }
        $connection['database'] = rawurldecode(ltrim($url['path'] ?? '', '/'));
    }
    $dsn = ! empty($connection['unix_socket'])
        ? 'mysql:unix_socket='.$connection['unix_socket'].';dbname='.$connection['database']
        : 'mysql:host='.$connection['host'].';port='.($connection['port'] ?? 3306).';dbname='.$connection['database'];
    $pdo = new PDO($dsn.';charset=utf8mb4', $connection['username'], $connection['password'],
        ($connection['options'] ?? []) + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    $pdo->exec('START TRANSACTION READ ONLY');
    $report['database'] = ['driver' => 'mysql', 'server_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'innodb_page_size' => $pdo->query('SELECT @@innodb_page_size')->fetchColumn(), 'transaction' => 'read_only'];
    $tables = ['migrations', 'sport_events', 'predictions', 'ai_generations', 'sports_game_context_reports',
        'analysis_work_items', 'prediction_review_revisions', 'research_sources', 'research_documents', 'game_research_assessments',
        'nba_games', 'nba_teams', 'nfl_research_sources', 'nfl_research_documents', 'nfl_research_revisions'];
    $statement = $pdo->prepare('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).')');
    $statement->execute($tables);
    $found = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables as $table) {
        $report['tables'][$table] = $found[$table] ?? null;
    }
    if (isset($found['migrations'])) {
        $applied = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $expected = ['2026_11_20_230100_create_prediction_review_work_tables', '2026_11_21_010000_create_shared_research_storage'];
        foreach ($expected as $migration) {
            $report['rollout_migrations'][$migration] = in_array($migration, $applied, true);
        }
        $local = array_map(static fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob($root.'/database/migrations/*.php'));
        $report['deployed_pending_migrations'] = array_values(array_diff($local, $applied));
        if (defined('NBA_ROLLOUT_CANDIDATE_MIGRATIONS')) {
            $report['candidate_pending_migrations'] = array_values(array_diff(NBA_ROLLOUT_CANDIDATE_MIGRATIONS, $applied));
        }
    }
    $report['parent_key_types'] = $pdo->query("SELECT TABLE_NAME AS table_name, COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id' AND TABLE_NAME IN ('sport_events','predictions','ai_generations','sports_game_context_reports') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?,?,?) ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $statement->execute(['analysis_work_items', 'prediction_review_revisions', 'research_sources', 'research_documents', 'game_research_assessments', 'nba_teams']);
    $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
    $report['shared_schema_columns'] = array_values(array_filter($columns, static fn ($row) => $row['table_name'] !== 'nba_teams'));
    if (isset($found['research_sources'])) {
        $report['nba_sources'] = $pdo->query("SELECT id, source_key, team_id, kind, url, allowed_hosts, enabled, checked_at, succeeded_at, last_error FROM research_sources WHERE sport = 'nba' ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $report['nba_sources'] = null;
    }
    if (isset($found['nba_games'], $found['sport_events'])) {
        $zone = new DateTimeZone($get('app.timezone') ?? 'UTC');
        $now = new DateTimeImmutable('now', $zone);
        $query = $pdo->prepare("SELECT g.id AS game_id, g.sport_event_id, g.season, g.season_type, g.home_team_id, g.away_team_id, g.status, e.starts_at AS starts_at_stored FROM nba_games g INNER JOIN sport_events e ON e.id = g.sport_event_id WHERE e.starts_at > ? AND e.starts_at <= ? AND g.status IN ('STATUS_SCHEDULED', 'STATUS_DELAYED') ORDER BY e.starts_at, g.id LIMIT 8");
        $query->execute([$now->format('Y-m-d H:i:s'), $now->modify('+3 days')->format('Y-m-d H:i:s')]);
        $report['nba_canary_candidates'] = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($report['nba_canary_candidates'] as &$candidate) {
            $start = new DateTimeImmutable($candidate['starts_at_stored'], $zone);
            $candidate['starts_at_utc'] = $start->setTimezone(new DateTimeZone('UTC'))->format('c');
            $candidate['starts_at_central'] = $start->setTimezone(new DateTimeZone('America/Chicago'))->format('c');
        }
        unset($candidate);
    }
    if (isset($found['nba_teams'])) {
        $names = array_column(array_filter($columns, static fn ($row) => $row['table_name'] === 'nba_teams'), 'column_name');
        $select = array_values(array_intersect(['id', 'abbreviation', 'display_name', 'name'], $names));
        $report['nba_team_lookup'] = $pdo->query('SELECT '.implode(',', $select).' FROM nba_teams ORDER BY id LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    }
    if (isset($found['nfl_research_revisions'])) {
        $report['nfl_research_latest_assessment_at'] = $pdo->query('SELECT MAX(created_at) FROM nfl_research_revisions')->fetchColumn();
    }
    $report['result'] = 'read_only_inspection_completed';
} catch (Throwable $exception) {
    $report['result'] = 'inspection_incomplete';
    // Never emit PDO connection details, credentials, cached config, or traces.
    $report['error_type'] = $exception::class;
    $report['error_code'] = $exception instanceof PDOException ? (string) $exception->getCode() : $exception->getMessage();
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['result'] === 'read_only_inspection_completed' ? 0 : 1);
