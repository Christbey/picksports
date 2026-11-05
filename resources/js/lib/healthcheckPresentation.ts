import type {
    Healthcheck,
    TeamScheduleOutlier,
    HealthcheckMetadata,
} from '@/types/healthchecks';
export function canSync(sport: string, checkType: string): boolean {
    return [
        'heartbeat_sync',
        'heartbeat_live_scoreboard',
        'heartbeat_prediction_pipeline',
        'heartbeat_model_pipeline',
        'heartbeat_odds',
        'heartbeat_player_props',
    ].includes(checkType);
}
export function getSyncLabel(checkType: string): string {
    switch (checkType) {
        case 'heartbeat_sync':
            return 'Run Sync';
        case 'heartbeat_live_scoreboard':
            return 'Run Scoreboard';
        case 'heartbeat_prediction_pipeline':
            return 'Generate Predictions';
        case 'heartbeat_model_pipeline':
            return 'Run Model Jobs';
        case 'heartbeat_odds':
            return 'Sync Odds';
        case 'heartbeat_player_props':
            return 'Sync Player Props';
        case 'validation_prediction_completeness':
            return 'Generate Predictions';
        case 'validation_odds_completeness':
            return 'Sync Odds';
        case 'validation_finalized_data_completeness':
            return 'Run Repair';
        default:
            return 'Run Command';
    }
}
export function getStatusColor(status: string): string {
    switch (status) {
        case 'passing':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
        case 'warning':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300';
        case 'failing':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
    }
}
export function getStatusIcon(status: string): string {
    switch (status) {
        case 'passing':
            return '✓';
        case 'warning':
            return '!';
        case 'failing':
            return '✕';
        default:
            return '?';
    }
}
export function formatStatus(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}
export function formatCheckType(type: string): string {
    const labels: Record<string, string> = {
        heartbeat_sync: 'Data Sync',
        heartbeat_live_scoreboard: 'Live Scoreboard Sync',
        heartbeat_prediction_pipeline: 'Prediction Pipeline',
        heartbeat_model_pipeline: 'Model Pipeline',
        heartbeat_odds: 'Odds Sync',
        heartbeat_player_props: 'Player Props Sync',
        validation_game_coverage: 'Game Coverage',
        validation_team_stat_coverage: 'Team Stat Coverage',
    };

    if (labels[type]) {
        return labels[type];
    }

    const displayType = type
        .replace(/^heartbeat_/, '')
        .replace(/^validation_/, '');

    return displayType
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
export function formatSportName(sport: string): string {
    return sport.toUpperCase();
}
export function formatDate(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
export function formatAbsoluteDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
}
export function formatDelta(value: number): string {
    if (value > 0) return `+${value}`;
    return `${value}`;
}
export function labelize(value: string): string {
    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
export function percent(value: number): string {
    return `${(value * 100).toFixed(1)}%`;
}
export function widthPercent(value: number): string {
    return `${Math.max(0, Math.min(100, value * 100))}%`;
}
export function countWidth(count: number, total: number): string {
    if (total <= 0) return '0%';
    return widthPercent(count / total);
}
export function getTeamScheduleOutliers(
    metadata: HealthcheckMetadata | null,
): TeamScheduleOutlier[] {
    if (!metadata || !Array.isArray(metadata.outliers)) {
        return [];
    }
    return metadata.outliers as TeamScheduleOutlier[];
}
export function getNoGameOutliers(
    metadata: HealthcheckMetadata | null,
): TeamScheduleOutlier[] {
    return getTeamScheduleOutliers(metadata).filter(
        (outlier) => outlier.type === 'no_games',
    );
}
export function getScheduleOutliers(
    metadata: HealthcheckMetadata | null,
): TeamScheduleOutlier[] {
    return getTeamScheduleOutliers(metadata).filter(
        (outlier) => outlier.type !== 'no_games',
    );
}
export function formatMetadataLabel(key: string): string {
    const labels: Record<string, string> = {
        in_season: 'In Season',
        is_during_game_hours: 'Game Window',
        current_hour: 'Current Hour',
        game_hours: 'Game Window Hours',
        age_minutes: 'Last Success Age',
        warning_after_minutes: 'Warning Threshold',
        failing_after_minutes: 'Failing Threshold',
        last_success_at: 'Last Success',
        last_failure_at: 'Last Failure',
        last_failure_error: 'Failure Error',
        command_patterns: 'Command Patterns',
        teams_with_games: 'Teams With Games',
        teams_missing_games: 'Teams Missing Games',
        teams_with_stats: 'Teams With Stats',
        teams_missing_stats: 'Teams Missing Stats',
        upcoming_games: 'Upcoming Games',
        expected_upcoming_games: 'Expected Upcoming Games',
        recommended_action: 'Recommended Command',
    };

    return (
        labels[key] ??
        key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase())
    );
}
export function formatMetadataValue(key: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return '';

    if (key === 'warning_after_minutes' || key === 'failing_after_minutes') {
        return `${value} min`;
    }

    if (key === 'age_minutes') {
        return `${value} min`;
    }

    if (key === 'current_hour' && typeof value === 'number') {
        return `${value}:00`;
    }

    if (key === 'last_success_at' || key === 'last_failure_at') {
        if (typeof value === 'string') {
            return formatDate(value);
        }
    }

    if (key === 'command_patterns' && Array.isArray(value)) {
        return value
            .map((pattern) => String(pattern).replace(/%+$/g, ''))
            .join(', ');
    }

    if (
        key === 'game_hours' &&
        typeof value === 'object' &&
        value !== null &&
        'start' in value &&
        'end' in value
    ) {
        const hours = value as { start: number; end: number };
        return `${String(hours.start).padStart(2, '0')}:00 - ${String(hours.end).padStart(2, '0')}:00`;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
export function displayMessage(message: string): string {
    if (!message) return '';

    // Legacy records may include debug lines after the first line; keep headline only.
    return message.split('\n')[0]?.trim() ?? message;
}
export function metadataEntries(
    check: Healthcheck,
): Array<{ key: string; label: string; value: string; raw: unknown }> {
    const metadata = check.metadata;
    if (!metadata) return [];

    const keys = [
        'in_season',
        'is_during_game_hours',
        'game_hours',
        'current_hour',
        'last_success_at',
        'last_failure_at',
        'age_minutes',
        'warning_after_minutes',
        'failing_after_minutes',
        'command_patterns',
        'last_failure_error',
        'teams_with_games',
        'teams_missing_games',
        'teams_with_stats',
        'teams_missing_stats',
        'upcoming_games',
        'expected_upcoming_games',
        'recommended_action',
    ];

    return keys
        .filter((key) => key in metadata)
        .map((key) => ({
            key,
            label: formatMetadataLabel(key),
            value: formatMetadataValue(key, metadata[key]),
            raw: metadata[key],
        }))
        .filter((entry) => entry.value !== '');
}
