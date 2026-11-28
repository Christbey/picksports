<?php

use PHPUnit\Event\Event;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Tracer\Tracer;

// Explicit test infrastructure only; the ordinary application bootstrap is unchanged.
require_once dirname(__DIR__).'/vendor/autoload.php';

$canonicalLogPath = getenv('PICKSPORTS_PHP_CI_EVENTS');
// Pest's --shard launches an internal --list-tests child with inherited argv/env.
// Discovery is not execution and must never create/consume its parent's log.
$canonicalDiscoveryOnly = false;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (in_array($argument, ['--list-tests', '--list-tests-xml', '--list-groups', '--list-suites'], true)
        || str_starts_with($argument, '--list-tests-xml=')) {
        $canonicalDiscoveryOnly = true;
    }
}
if ($canonicalLogPath !== false && $canonicalLogPath !== '' && ! $canonicalDiscoveryOnly) {
    Facade::instance()->registerTracer(new class($canonicalLogPath) implements Tracer
    {
        private $stream;

        public function __construct(string $path)
        {
            // Never append to a stale run or share a file across parallel processes.
            $this->stream = fopen($path, 'xb');
            if ($this->stream === false) {
                throw new RuntimeException('Cannot create a fresh canonical PHP CI event log.');
            }
            $this->write(['schema' => 1, 'event' => 'start']);
        }

        public function trace(Event $event): void
        {
            $kind = match (true) {
                $event instanceof Passed => 'passed',
                $event instanceof Finished => 'finished',
                $event instanceof Failed,
                $event instanceof Errored,
                $event instanceof Skipped,
                $event instanceof MarkedIncomplete,
                $event instanceof ConsideredRisky,
                $event instanceof PreparationFailed => 'unsuccessful',
                default => null,
            };
            if ($kind !== null) {
                // The pinned PHPUnit identity contains the exact method and dataset key.
                $this->write(['event' => $kind, 'id' => $event->test()->id()]);
            } elseif ($event instanceof PHPUnit\Event\TestRunner\Finished) {
                $this->write(['event' => 'end']);
                fclose($this->stream);
            }
        }

        private function write(array $record): void
        {
            // JSON escaping retains arbitrary quotes and embedded newlines in dataset names.
            $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($this->stream, $line) !== strlen($line) || ! fflush($this->stream)) {
                throw new RuntimeException('Cannot persist canonical PHP CI evidence.');
            }
        }
    });
}
