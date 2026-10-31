<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /** @var PlatformSettings */
    private $settings;

    public function __construct(PlatformSettings $settings)
    {
        $this->settings = $settings;
    }

    /** Only the settings this administrator may see. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->visibleTo($request->user())]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->settings->exists($key) && $this->settings->canView($user, $key), 404);
        abort_unless($this->settings->canChange($user, $key), 403);

        $data = $request->validate([
            'value' => $this->settings->rules($key),
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->settings->set($key, $data['value'], $user, $data['reason'] ?? null);

        return response()->json(['data' => $this->settings->present($key, $user)]);
    }
}
