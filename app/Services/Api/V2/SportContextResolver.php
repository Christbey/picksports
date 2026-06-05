<?php

namespace App\Services\Api\V2;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SportContextResolver
{
    /**
     * @return Collection<int, SportContext>
     */
    public function all(): Collection
    {
        return collect(config('sports.domains', []))
            ->map(fn (array $definition, string $slug): ?SportContext => $this->fromDefinition($slug, $definition))
            ->filter()
            ->values();
    }

    public function find(string $sport): ?SportContext
    {
        $slug = Str::lower(trim($sport));
        $definition = config("sports.domains.{$slug}");

        if (! is_array($definition)) {
            return null;
        }

        return $this->fromDefinition($slug, $definition);
    }

    public function resolve(string $sport): SportContext
    {
        return $this->find($sport) ?? abort(404, "Unsupported sport: {$sport}");
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function fromDefinition(string $slug, array $definition): ?SportContext
    {
        $namespace = (string) ($definition['namespace'] ?? '');

        if ($namespace === '') {
            return null;
        }

        return new SportContext(
            slug: $slug,
            label: strtoupper($slug),
            namespace: $namespace,
            models: $this->classMap("App\\Models\\{$namespace}", [
                'team' => 'Team',
                'game' => 'Game',
                'prediction' => 'Prediction',
                'player' => 'Player',
                'player_stat' => 'PlayerStat',
                'team_stat' => 'TeamStat',
                'team_metric' => 'TeamMetric',
                'elo_rating' => 'EloRating',
                'play' => 'Play',
                'player_prop' => 'PlayerProp',
                'depth_chart_entry' => 'DepthChartEntry',
            ]),
            resources: $this->classMap("App\\Http\\Resources\\{$namespace}", [
                'team' => 'TeamResource',
                'game' => 'GameResource',
                'prediction' => 'PredictionResource',
                'player' => 'PlayerResource',
                'player_stat' => 'PlayerStatResource',
                'team_stat' => 'TeamStatResource',
                'team_metric' => 'TeamMetricResource',
                'elo_rating' => 'EloRatingResource',
                'play' => 'PlayResource',
            ]),
            capabilities: (array) ($definition['capabilities'] ?? []),
            web: (array) ($definition['web'] ?? []),
        );
    }

    /**
     * @param  array<string, string>  $classes
     * @return array<string, class-string>
     */
    private function classMap(string $baseNamespace, array $classes): array
    {
        return collect($classes)
            ->mapWithKeys(function (string $classBaseName, string $key) use ($baseNamespace): array {
                $class = "{$baseNamespace}\\{$classBaseName}";

                return class_exists($class) ? [$key => $class] : [];
            })
            ->all();
    }
}
