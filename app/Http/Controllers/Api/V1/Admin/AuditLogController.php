<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('actor:id,name')
            ->when($filters['action'] ?? null, function ($query, $action) {
                $query->where('action', $action);
            })
            ->when($filters['actor_id'] ?? null, function ($query, $actorId) {
                $query->where('actor_id', $actorId);
            })
            ->when($filters['from'] ?? null, function ($query, $from) {
                $query->where('created_at', '>=', $from);
            })
            ->when($filters['to'] ?? null, function ($query, $to) {
                $query->where('created_at', '<=', $to);
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
