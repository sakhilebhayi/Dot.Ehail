<?php

namespace App\Http\Controllers\Ehail\Comms;

use App\Domain\Comms\Inbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON inbox: the person's own in-app notifications only. */
class InboxController extends Controller
{
    public function __construct(private readonly Inbox $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50'], 'unread' => ['nullable', 'boolean']]);
        $user = $request->user();
        $page = $this->inbox->page($user, (int) $request->input('per_page', 20), $request->boolean('unread'));

        return response()->json([
            'items' => $this->inbox->present($user, $page->items()),
            'unread_count' => $this->inbox->unreadCount($user),
            'next_cursor' => $page->nextCursor()?->encode(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->inbox->unreadCount($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->first();

        if ($record === null) {
            return response()->json(['error' => 'not_found', 'message' => 'That notification does not exist.'], 404);
        }

        $record->markAsRead();

        return response()->json(['read' => true, 'unread_count' => $this->inbox->unreadCount($request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
