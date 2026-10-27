<?php

namespace App\Http\Controllers;

use App\Services\Settings\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications): mixed
    {
        $items = $notifications->forUser($request->user())->map(fn ($item) => ['id' => $item->id, 'title' => $item->context['title'] ?? 'Notification', 'message' => $item->context['message'] ?? '', 'href' => $item->context['href'] ?? null, 'read' => $item->read_at !== null, 'when' => $item->created_at->timezone('Asia/Manila')->format('d M, H:i')]);

        return response()->json(['items' => $items, 'unread_count' => $notifications->unreadCount($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, int $notification, NotificationService $notifications): mixed
    {
        $notifications->read($request->user(), $notification);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function all(Request $request, NotificationService $notifications): mixed
    {
        $notifications->readAll($request->user());

        return response()->json(['message' => 'Your notifications were marked as read.']);
    }
}
