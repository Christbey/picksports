export const decisionLabel = (status?: string): string =>
    ({
        pass: 'No approved spread bet',
        candidate: 'Spread candidate — review recorded conditions',
        hold: 'On hold — evidence needs review',
        ready: 'Research ready',
        approved: 'Approved by recorded checks',
    })[status ?? ''] ?? 'Assessment unavailable';

export const researchReason = (reason: string): string => {
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
