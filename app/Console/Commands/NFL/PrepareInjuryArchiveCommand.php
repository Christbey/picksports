<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\ArchivedInjuryReportParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PrepareInjuryArchiveCommand extends Command
{
    protected $signature = 'nfl:prepare-injury-archive {manifest} {output}';

    protected $description = 'Validate archived official NFL reports against a reviewed manifest and prepare game-context JSON';

    public function handle(ArchivedInjuryReportParser $parser): int
    {
        try {
            $manifest = json_decode(file_get_contents($this->argument('manifest')), true, flags: JSON_THROW_ON_ERROR);
            $facts = [];
            foreach ($manifest as $source) {
                $url = $source['url'];
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'www.nfl.com') {
                    throw new RuntimeException('Only official HTTPS NFL.com reports are supported.');
                }
                $html = Http::timeout(45)->retry(2, 1000)->get($url)->throw()->body();
                $parsed = $parser->parse($html, $url, $source['season'], $source['type'], $source['week']);
                // Widgets may change; verify the extracted, timestamped evidence itself.
                $canonical = array_map(function ($fact) {
                    unset($fact['source_sha256']);

                    return $fact;
                }, $parsed);
                if (! hash_equals($source['facts_sha256'], hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)))) {
                    throw new RuntimeException('Archived evidence changed; review required: '.$url);
                }
                array_push($facts, ...$parsed);
            }
            if ($facts === []) {
                throw new RuntimeException('Empty archive manifest.');
            }
            if (file_put_contents($this->argument('output'), json_encode($facts, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not write output.');
            }
            $this->info(count($facts).' validated injury facts prepared; no database changes.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
