<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminPulseController extends Controller
{
    /**
     * A fingerprint of the latest activity, for the admin's live updates. The
     * admin asks every few seconds and reloads its data only when this
     * changes. Each part is a quick indexed lookup; nothing personal is returned.
     */
    public function __invoke(PlatformSettings $settings): JsonResponse
    {
        $today = now()->toDateString();
        $online = now()->subMinutes((int) $settings->get('presence.online_minutes'));
        $max = function (string $table, string $column = 'id') {
            return (string) DB::table($table)->max($column);
        };

        $parts = [
            // New events of any kind: sign-ins, requests, decisions, posts, reports…
            $max('audit_logs'),
            $max('messages'),
            $max('community_posts'), $max('community_comments'),
            (string) DB::table('follows')->count(),
            $max('user_notifications'), $max('user_notifications', 'updated_at'),
            $max('service_requests', 'updated_at'), $max('quotations', 'updated_at'), $max('reviews', 'updated_at'),
            $max('reports', 'updated_at'), $max('provider_profiles', 'updated_at'), $max('users', 'updated_at'),
            $max('document_verifications', 'updated_at'), $max('verification_documents', 'updated_at'),
            $max('app_install_events'), $max('push_subscriptions'),
            // Usage today, and who is online now
            (string) DB::table('app_daily_counts')->where('counted_on', $today)->sum('total'),
            (string) DB::table('user_activity_days')->where('active_on', $today)->count(),
            (string) DB::table('users')->where('last_seen_at', '>=', $online)->count(),
        ];

        return response()->json(['data' => ['stamp' => md5(implode('|', $parts)), 'checked_at' => now()->toIso8601String()]]);
    }
}
