<?php

return [
    'enabled' => (bool) env('NFL_RESEARCH_PIPELINE_ENABLED', true),
    'poll_minutes' => 15,
    'max_articles_per_source' => 12,
    'lookback_days' => 14,
    'teams' => [
        'ARI' => 'www.azcardinals.com', 'ATL' => 'www.atlantafalcons.com',
        'BUF' => 'www.buffalobills.com', 'CAR' => 'www.panthers.com',
        'CHI' => 'www.chicagobears.com', 'CIN' => 'www.bengals.com',
        'DAL' => 'www.dallascowboys.com', 'DEN' => 'www.denverbroncos.com',
        'HOU' => 'www.houstontexans.com', 'KC' => 'www.chiefs.com',
        'LAC' => 'www.chargers.com', 'LAR' => 'www.therams.com',
        'LV' => 'www.raiders.com', 'MIA' => 'www.miamidolphins.com',
        'NE' => 'www.patriots.com', 'NYG' => 'www.giants.com',
        'NYJ' => 'www.newyorkjets.com', 'PIT' => 'www.steelers.com',
        'SEA' => 'www.seahawks.com', 'SF' => 'www.49ers.com',
        'TB' => 'www.buccaneers.com', 'TEN' => 'www.tennesseetitans.com',
        'BAL' => 'www.baltimoreravens.com', 'IND' => 'www.colts.com',
        'CLE' => 'www.clevelandbrowns.com', 'JAX' => 'www.jaguars.com',
        'NO' => 'www.neworleanssaints.com', 'DET' => 'www.detroitlions.com',
        'WAS' => 'www.commanders.com', 'PHI' => 'www.philadelphiaeagles.com',
        'GB' => 'www.packers.com', 'MIN' => 'www.vikings.com',
    ],
];
