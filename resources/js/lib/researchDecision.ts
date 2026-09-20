export const decisionLabel = (status?: string): string =>
    ({
        pass: 'No approved spread bet',
        candidate: 'Spread candidate — review recorded conditions',
        hold: 'On hold — evidence needs review',
        ready: 'Research ready',
        approved: 'Approved by recorded checks',
    })[status ?? ''] ?? 'Assessment unavailable';

export const researchReason = (reason: string): string => {
    if (reason === 'research_incomplete_or_stale')
        return 'Research is incomplete or no longer matches the current evidence. This alone does not mean its age limit expired.';
    if (reason === 'research_game_attempt_limit_reached')
        return 'Refresh blocked: the rolling 24-hour attempt limit, including available pregame reserves, was reached.';
    if (reason === 'research_game_daily_budget_reached')
        return 'Refresh blocked: the per-game spending limit was reached (older assessments may also use this code for attempt limits).';
    if (reason === 'research_daily_budget_reached')
        return 'Refresh blocked: the overall rolling 24-hour research budget was reached.';
    if (reason === 'research_evidence_expired')
        return 'Evidence expired: the report exceeds the current kickoff-aware freshness window.';
    if (reason === 'research_prediction_comparison_missing')
        return 'A newer prediction was saved, but this older assessment has no comparison snapshot. Reassessment is needed; a material change is not confirmed.';
    if (reason === 'research_evidence_unlinked')
        return 'The latest evidence is missing or has not been linked to this assessment.';
    if (reason === 'research_retry_not_due')
        return 'Refresh deferred until the retry interval passes; existing evidence has not been renewed.';
    if (reason === 'research_refresh_failed')
        return 'The last refresh failed. No fresh evidence was saved.';
    if (/specialist.*tier.*does_not_approve/.test(reason))
        return 'The spread did not meet the required approval threshold.';
    if (/research_.*budget/.test(reason))
        return 'New paid research is deferred by the research budget.';
    if (reason === 'research_candidate_changed')
        return 'The forecast changed after this research; its conclusions need revalidation.';
    if (/weather.*(stale|missing)/.test(reason))
        return 'A current kickoff weather report is unavailable.';
    if (/research.*(stale|expired)/.test(reason))
        return 'Research is out of date and must be refreshed.';
    if (/injur.*(stale|missing)/.test(reason))
        return 'Current injury evidence is incomplete.';
    return `Review required: ${reason.replaceAll('_', ' ')}.`;
};
