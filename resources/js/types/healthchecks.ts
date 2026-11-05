export interface Healthcheck {
    id: number;
    sport: string;
    check_type: string;
    status: string;
    message: string;
    metadata: HealthcheckMetadata | null;
    checked_at: string;
}
export interface TeamScheduleOutlier {
    type: string;
    espn_id: number | string;
    team: string;
    games?: number;
    deviation_from_avg?: number;
}
export interface Props {
    checks_by_sport: Record<string, Healthcheck[]>;
    status_counts: Record<string, number>;
    sports: string[];
    latest_validation_run: {
        id: number;
        scope: string | null;
        status: string;
        summary: Record<string, number> | null;
        ai_summary: {
            headline: string;
            intro: string;
            highlights: string[];
            recommended_actions?: string[];
            latest_data_fresh_at?: string;
            data_schedule_today?: string[];
            tweak_recommendations?: string[];
            operational_status?: string;
            trust_score?: number;
            blocked_outputs?: string[];
            safe_adjustments?: string[];
            data_quality_notes?: string[];
            generated_by?: string;
        } | null;
        ai_generated_at: string | null;
        completed_at: string | null;
    } | null;
    recent_validation_runs: Array<{
        id: number;
        scope: string | null;
        status: string;
        summary: Record<string, number> | null;
        completed_at: string | null;
    }>;
    selected_validation_run: {
        id: number;
        scope: string | null;
        status: string;
        summary: Record<string, number> | null;
        ai_summary: {
            headline: string;
            intro: string;
            highlights: string[];
            recommended_actions?: string[];
            latest_data_fresh_at?: string;
            data_schedule_today?: string[];
            tweak_recommendations?: string[];
            operational_status?: string;
            trust_score?: number;
            blocked_outputs?: string[];
            safe_adjustments?: string[];
            data_quality_notes?: string[];
            generated_by?: string;
        } | null;
        ai_generated_at: string | null;
        completed_at: string | null;
        findings: Array<{
            id: number;
            sport: string;
            check_type: string;
            status: string;
            severity: string | null;
            message: string;
            facts: Record<string, unknown> | null;
            recommended_action: string | null;
            detected_at: string | null;
        }>;
    } | null;
    validation_trend: {
        current: {
            failing: number;
            warning: number;
            passing: number;
        };
        previous: {
            id: number;
            failing: number;
            warning: number;
            passing: number;
            completed_at: string | null;
        } | null;
        delta: {
            failing: number;
            warning: number;
            passing: number;
        };
        direction: 'improving' | 'regressing' | 'stable';
        points: Array<{
            id: number;
            failing: number;
            warning: number;
            passing: number;
            completed_at: string | null;
        }>;
    } | null;
    ai_publishing: {
        total: number;
        decisions: Record<string, number>;
        classifications: Record<string, number>;
        enforcement: {
            enabled: boolean;
            mode: string;
        };
        needs_attention: Array<{
            sport: string;
            matchup: string;
            decision: string;
            publishable_classification: string;
            freshness_status: string;
            market_status: string;
            model_status: string;
            summary: string;
            required_actions: string[];
        }>;
    };
    ai_publishing_trend: {
        days: number;
        total: number;
        changed_count: number;
        changed_rate: number;
        decisions: Record<string, number>;
        changed_rows: Array<{
            date: string | null;
            sport: string;
            matchup: string;
            decision: string;
            saved_classification: string;
            guardrail_classification: string;
            recommendation: string;
        }>;
    };
    filters: {
        sport: string | null;
        view: string | null;
        validation_run: number | null;
    };
}
export type HealthchecksProps = Props;
export interface HealthcheckMetadata extends Record<string, unknown> {
    std_dev?: number;
    average_games?: number;
    outliers?: TeamScheduleOutlier[];
}
