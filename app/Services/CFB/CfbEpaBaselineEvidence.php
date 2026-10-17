<?php

namespace App\Services\CFB;

use App\Models\EpaStateBaseline;

class CfbEpaBaselineEvidence
{
    public function availableAt(int $season): ?string
    {
        return EpaStateBaseline::where('sport', 'cfb')->where('season', $season)
            ->where('source_season', '<', $season)->max('updated_at');
    }
}
