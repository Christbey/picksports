<?php

namespace App\Services\Api\V2;

class SportContext
{
    /**
     * @param  array<string, class-string>  $models
     * @param  array<string, class-string>  $resources
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $web
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $namespace,
        public readonly array $models,
        public readonly array $resources,
        public readonly array $capabilities,
        public readonly array $web,
    ) {}

    public function supports(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    public function requiresAuthenticatedDataAccess(): bool
    {
        return true;
    }

    public function requiresPredictionPermission(): bool
    {
        return (bool) ($this->web['requires_prediction_permission'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'namespace' => $this->namespace,
            'capabilities' => $this->capabilities,
            'web' => [
                'pages' => (array) ($this->web['pages'] ?? []),
                'details' => (array) ($this->web['details'] ?? []),
                'player_props' => (bool) ($this->web['player_props'] ?? false),
                'requires_prediction_permission' => $this->requiresPredictionPermission(),
            ],
            'access' => [
                'authenticated_data_access' => $this->requiresAuthenticatedDataAccess(),
                'free_access_is_policy_driven' => true,
            ],
        ];
    }
}
