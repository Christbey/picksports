<?php

namespace App\Http\Controllers;

use App\Services\Api\V2\PublicSportPageData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicSportPageController extends Controller
{
    public function __construct(private readonly PublicSportPageData $data) {}

    public function __invoke(Request $request, string $sport): Response
    {
        return Inertia::render('PublicSport', $this->data->forSport($sport));
    }
}
