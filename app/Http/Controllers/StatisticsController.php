<?php

namespace App\Http\Controllers;

use App\Services\GameStatistics;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{
    public function __invoke(GameStatistics $statistics): JsonResponse
    {
        return response()->json($statistics->overview())->header('Cache-Control', 'no-store, private');
    }
}
