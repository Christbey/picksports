<?php

// Read-only production analysis; does not install code or import/modify data.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
$source = file_get_contents($root.'/app/Services/NFL/NflQuarterbackReturnStudy.php');
$code = 'eval(base64_decode("'.base64_encode(substr($source, 5)).'"));'.<<<'PHP'
$games = \App\Models\NFL\Game::whereBetween("season", [2009, 2025])->where("season_type", "2")
    ->where("status", "STATUS_FINAL")->whereNotNull("home_score")->whereNotNull("away_score")
    ->orderBy("game_date")->orderBy("id")->get(["id","season","game_date","home_team_id","away_team_id",
        "home_score","away_score","home_qb_id","away_qb_id","home_qb_name","away_qb_name"]);
$lines = [];
$snapshots = \App\Models\GameOddsSnapshot::where("sport","nfl")->where("game_table","nfl_games")
    ->where("source","nflverse")->where("bookmaker_key","nflverse_closing")
    ->whereIntegerInRaw("game_id",$games->modelKeys())->get(["id","game_id","market_context"]);
foreach ($snapshots->groupBy("game_id") as $id => $group) {
    $valid = $group->filter(fn($s) => data_get($s->market_context,"source") === "nflverse_schedules"
        && data_get($s->market_context,"line_type") === "closing"
        && is_numeric(data_get($s->market_context,"spread_line"))
        && abs((float)data_get($s->market_context,"spread_line")) <= 60);
    $values = $valid->map(fn($s)=>(float)$s->market_context["spread_line"])->unique();
    if ($valid->count() === $group->count() && $values->count() === 1) $lines[$id] = -(float)$values->first();
}
$rows = $games->map(fn($g)=>[...$g->toArray(),"date"=>$g->game_date->toDateString(),"home_line"=>$lines[$g->id]??null])->all();
$report = (new \App\Services\NFL\NflQuarterbackReturnStudy)->analyze($rows);
$teams = \App\Models\NFL\Team::pluck("abbreviation","id");
$report["events"] = array_map(fn($r)=>[...$r,"team"=>$teams[$r["team_id"]]??null],$report["events"]);
$report["read_at"] = now()->toIso8601String();
$report["scope"] = "Production, regular seasons 2009–2025; closing-line archive only.";
$report["line_coverage"] = count($lines);
$report["structured_qb_injury_rows"] = \Illuminate\Support\Facades\DB::table("nflverse_injuries")->where("position","QB")->count();
echo json_encode($report,JSON_INVALID_UTF8_SUBSTITUTE);
PHP;
$process = new Process(['/Users/bey/.composer/vendor/bin/cloud', 'tinker', 'env-a27d3483-e0d2-445f-9993-e61fffd04683',
    '--timeout=60', '--json', '--code='.$code]);
$process->setTimeout(100);
$process->mustRun();
$report = null;
foreach (explode("\n", $process->getOutput()) as $line) {
    $envelope = json_decode($line, true);
    if (isset($envelope['output']) && trim($envelope['output']) !== '') {
        $report = json_decode(trim($envelope['output']), true, flags: JSON_THROW_ON_ERROR);
    }
}
if (! isset($report['all'])) {
    throw new RuntimeException('Production returned no study; nothing saved.');
}
$path = $root.'/outputs/nfl-qb-return-study-2026-09-25.json';
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
unset($report['events']);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\nSaved: $path\n";
