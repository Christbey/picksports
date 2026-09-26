<?php

use App\Support\NflReasonCodeCatalog;

it('classifies actionable and diagnostic nfl reason codes', function () {
    $catalog = app(NflReasonCodeCatalog::class);

    $metadata = $catalog->metadataForCodes([
        'qb_experience_edge',
        'spread_market_edge',
        'cold_outdoor_total_proxy',
        'high_trust_no_market_edge',
    ]);

    expect($metadata['qb_experience_edge'])->toMatchArray([
        'source' => 'quarterback',
        'is_actionable' => true,
        'is_diagnostic' => false,
    ])
        ->and($metadata['spread_market_edge'])->toMatchArray([
            'source' => 'market',
            'market_type' => 'spread',
            'requires_market' => true,
        ])
        ->and($metadata['cold_outdoor_total_proxy'])->toMatchArray([
            'source' => 'weather_proxy',
            'is_actionable' => false,
            'is_diagnostic' => true,
        ])
        ->and($metadata['high_trust_no_market_edge'])->toMatchArray([
            'is_actionable' => false,
            'is_diagnostic' => true,
        ]);
});

it('labels historical sack and total proxies honestly without approving them', function () {
    $catalog = new NflReasonCodeCatalog;

    foreach ([
        'weak_ol_vs_blitz_heavy_defense' => 'High Combined Sack Rate Proxy (Legacy)',
        'explosive_play_prevention_edge' => 'High Defensive Sack Rate Context (Legacy)',
        'poor_secondary_risk' => 'Low Defensive Sack Rate Context (Legacy)',
        'fast_pace_over_signal' => 'Positive Model Total Context; Pace Not Measured (Legacy)',
    ] as $code => $label) {
        expect($catalog->metadata($code))->toMatchArray([
            'code' => $code,
            'label' => $label,
            'is_diagnostic' => true,
            'is_actionable' => false,
        ]);
    }
});
