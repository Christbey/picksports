<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GradeBrackets;
use Illuminate\Console\Command;

class GradeBracketsCommand extends Command
{
    protected $signature = 'cbb:grade-brackets {season : Tournament season to grade}';

    protected $description = 'Grade saved CBB brackets against finalized NCAA tournament games';

    public function handle(GradeBrackets $gradeBrackets): int
    {
        $season = (int) $this->argument('season');
        $graded = $gradeBrackets->execute($season);

        $this->info("Graded {$graded} bracket(s) for season {$season}.");

        return self::SUCCESS;
    }
}
