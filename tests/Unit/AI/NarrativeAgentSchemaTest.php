<?php

use App\AI\Agents\PlayerPropNarrativeAgent;
use App\AI\Agents\SportsDailyPredictionAnalysisAgent;
use App\AI\Agents\SportsPredictionNarrativeAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('marks sports prediction betting plan as a closed object schema', function () {
    $schema = (new SportsPredictionNarrativeAgent)->schema(new JsonSchemaTypeFactory);

    expect($schema['betting_plan']->toArray())
        ->additionalProperties->toBeFalse();
});

it('marks player prop betting plan as a closed object schema', function () {
    $schema = (new PlayerPropNarrativeAgent)->schema(new JsonSchemaTypeFactory);

    expect($schema['betting_plan']->toArray())
        ->additionalProperties->toBeFalse();
});

it('marks daily prediction market notes as a closed object schema', function () {
    $schema = (new SportsDailyPredictionAnalysisAgent)->schema(new JsonSchemaTypeFactory);

    expect($schema['market_notes']->toArray())
        ->additionalProperties->toBeFalse();
});
