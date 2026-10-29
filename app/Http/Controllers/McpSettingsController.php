<?php

namespace App\Http\Controllers;

use App\Services\McpSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpSettingsController extends Controller
{
    public function show(McpSettings $settings): JsonResponse
    {
        return $this->privateResponse($settings->status());
    }

    public function update(Request $request, McpSettings $settings): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return $this->privateResponse($settings->setEnabled((bool) $data['enabled']));
    }

    public function store(string $access, McpSettings $settings): JsonResponse
    {
        return $this->privateResponse($settings->generate($access));
    }

    public function destroy(string $access, McpSettings $settings): JsonResponse
    {
        return $this->privateResponse($settings->revoke($access));
    }

    private function privateResponse(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
