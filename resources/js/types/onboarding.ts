export type OnboardingStep = 'welcome' | 'sport_selection' | 'alert_setup' | 'methodology_review';

export type BettingExperience = 'beginner' | 'intermediate' | 'advanced';

export type Sport = 'nfl' | 'nba' | 'cbb' | 'wcbb' | 'mlb' | 'cfb' | 'wnba';

export interface OnboardingProgress {
    started: boolean;
    current_step: OnboardingStep | null;
    progress_percentage: number;
    completed: boolean;
    completed_steps?: OnboardingStep[];
    started_at?: string;
    is_abandoned?: boolean;
    favorite_sports?: Sport[];
    betting_experience?: BettingExperience;
}

export interface ChecklistItem {
    id: string;
    title: string;
    description: string;
    completed: boolean;
    url: string;
}

export interface ChecklistResponse {
    checklist: ChecklistItem[];
    total_items: number;
    completed_items: number;
}

export interface OnboardingSteps {
    welcome: string;
    sport_selection: string;
    alert_setup: string;
    methodology_review: string;
}

export interface PersonalizationData {
    favorite_sports?: Sport[];
    betting_experience?: BettingExperience;
    interests?: string[];
    goals?: string[];
}

export interface CompleteStepRequest {
    step: OnboardingStep;
    data?: Record<string, unknown>;
}

export interface OnboardingApiResponse {
    message: string;
    progress: OnboardingProgress;
}
