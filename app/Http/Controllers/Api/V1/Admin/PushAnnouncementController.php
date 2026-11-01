<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PushNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushAnnouncementController extends Controller
{
    /** Sends a push notification to every device that turned them on */
    public function store(Request $request, PushNotifications $push): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:200'],
            // Only WorkMate pages: a path such as /browse, never another site
            'url' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)[^\s\\\\]*$#'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'url.regex' => 'Use a WorkMate page starting with /, for example /browse.',
        ]);

        $announcement = $push->announce($request->user(), [
            'title' => $data['title'],
            'body' => $data['body'],
            'url' => $data['url'] ?? null,
        ], $data['reason']);

        return response()->json(['data' => [
            'id' => $announcement->id,
            'status' => $announcement->status,
            'recipients' => $announcement->recipients,
        ]], 202);
    }
}
