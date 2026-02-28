<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\ResourcePayload;
use Inertia\Inertia;
use Inertia\Response;

abstract class Controller
{
    protected function renderResourcePage(
        string $component,
        string $prop,
        Model $model,
        string $resourceClass,
        array $relations = []
    ): Response {
        if ($relations !== []) {
            $model->load($relations);
        }

        return Inertia::render($component, [
            $prop => $resourceClass::make($model)->resolve(),
        ]);
    }

    protected function renderIdPage(string $component, string $prop, int|string $id): Response
    {
        return Inertia::render($component, [
            $prop => $id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function renderFormPage(string $component, string $prop, mixed $value = null, array $extra = []): Response
    {
        return Inertia::render($component, array_merge(
            [$prop => $value],
            $extra
        ));
    }

    protected function redirectSuccess(string $route, string $message): RedirectResponse
    {
        return redirect()
            ->route($route)
            ->with('success', $message);
    }

    protected function routeFlash(string $route, string $key, mixed $value): RedirectResponse
    {
        return to_route($route)->with($key, $value);
    }

    protected function backFlash(string $key, mixed $value): RedirectResponse
    {
        return back()->with($key, $value);
    }

    protected function backSuccess(string $message): RedirectResponse
    {
        return $this->backFlash('success', $message);
    }

    protected function backWarning(string $message): RedirectResponse
    {
        return $this->backFlash('warning', $message);
    }

    protected function backError(string $message): RedirectResponse
    {
        return $this->backFlash('error', $message);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function resourcePayload(JsonResource|AnonymousResourceCollection $resource): array
    {
        return ResourcePayload::from($resource);
    }
}
