<?php

namespace App\Console\Commands\ESPN;

use App\Console\Commands\ESPN\Concerns\ResolvesJobClass;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractDispatchOverRecordsCommand extends Command
{
    use ResolvesJobClass;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    public function __construct()
    {
        $this->signature = $this->commandName();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $records = $this->recordsToDispatch();
        $count = $records->count();

        if ($count === 0) {
            $this->info($this->emptyMessage());

            return Command::SUCCESS;
        }

        $this->info($this->startMessage($count));

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($records as $record) {
            $this->dispatchForRecord($record);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info($this->completeMessage($count));

        return Command::SUCCESS;
    }

    protected function emptyMessage(): string
    {
        return 'No records found to dispatch.';
    }

    protected function startMessage(int $count): string
    {
        return "Dispatching {$count} records...";
    }

    protected function completeMessage(int $count): string
    {
        return "Dispatched {$count} records successfully.";
    }

    protected function commandName(): string
    {
        return $this->requiredJobClass(static::COMMAND_NAME, 'COMMAND_NAME');
    }

    protected function commandDescription(): string
    {
        return $this->requiredJobClass(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION');
    }

    /**
     * @return Collection<int, Model>
     */
    abstract protected function recordsToDispatch(): Collection;

    abstract protected function dispatchForRecord(Model $record): void;
}
