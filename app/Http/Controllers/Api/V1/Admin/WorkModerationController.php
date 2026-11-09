<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderWorkItem;
use App\Services\ProviderWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Taking down, or putting back, work a provider shows on their profile */
class WorkModerationController extends Controller
{
    /** @var ProviderWork */
    private $work;

    public function __construct(ProviderWork $work)
    {
        $this->work = $work;
    }

    public function remove(Request $request, string $item): JsonResponse
    {
        $item = $this->work->remove($this->find($item), $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $item->uuid, 'status' => $item->status]]);
    }

    public function restore(Request $request, string $item): JsonResponse
    {
        $item = $this->work->restore($this->find($item), $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $item->uuid, 'status' => $item->status]]);
    }

    private function find(string $uuid): ProviderWorkItem
    {
        return ProviderWorkItem::withTrashed()->where('uuid', $uuid)->firstOrFail();
    }

    /** Shown to the provider, so it says which rule was broken */
    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }
}
