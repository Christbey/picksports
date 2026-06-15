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
