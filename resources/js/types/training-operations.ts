export type TrainingOperations = {
    enabled: boolean;
    as_of: string;
    workers: Array<{
        id: string;
        name: string;
        credential_status: string;
        connectivity: string;
        last_seen_at: string | null;
        metrics: null | {
            version: string;
            cpu_count?: number | null;
            load_one?: number | null;
            disk_free_bytes?: number | null;
            disk_total_bytes?: number | null;
            memory_total_bytes?: number | null;
            training_rss_bytes?: number | null;
        };
    }>;
    jobs: Array<{
        id: string;
        worker_id: string | null;
        status: string;
        stage: string | null;
        started_at: string | null;
        completed_at: string | null;
        progress_at: string | null;
        log_tail: string;
        files_received: number;
        bytes_received: number;
        attempts: Array<{
            number: number;
            status: string;
            started_at: string;
            lease_expires_at: string;
            completed_at: string | null;
        }>;
        result: null | {
            artifact_id: string;
            status: string;
            model_version: string;
            eligible_markets: string[];
            evaluation_url: string;
        };
    }>;
};
