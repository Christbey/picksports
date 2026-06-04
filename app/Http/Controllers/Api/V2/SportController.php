<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportIndexRequest;
use App\Http\Resources\Api\V2\SportResource;
use App\Services\Api\V2\SportContextResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SportController extends Controller
{
    public function index(SportIndexRequest $request, SportContextResolver $sports): AnonymousResourceCollection
    {
        return SportResource::collection($sports->all())
            ->additional([
                'meta' => [
                    'version' => 'v2',
                    'authenticated_data_access' => true,
                ],
            ]);
    }

    public function show(string $sport, SportContextResolver $sports): JsonResource
    {
        return SportResource::make($sports->resolve($sport))
            ->additional([
                'meta' => [
                    'version' => 'v2',
                    'authenticated_data_access' => true,
                ],
            ]);
    }
}
