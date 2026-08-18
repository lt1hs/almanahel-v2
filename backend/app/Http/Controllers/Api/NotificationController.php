<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AppNotificationUserState;
use App\Services\Notifications\AlertInbox;
use App\Support\Authorization\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request, AlertInbox $inbox)
    {
        $inbox->sync();
        $userId = (int) $request->user()->id;
        $query = $this->scoped($request);

        if (!$request->boolean('history')) {
            $query->whereDoesntHave('viewerStates', function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereNotNull('dismissed_at');
            });
        }
        if ($request->boolean('unread')) {
            $query->whereDoesntHave('viewerStates', function ($q) use ($userId) {
                $q->where('user_id', $userId)->whereNotNull('read_at');
            });
        }

        $paginator = $query->latest('id')->paginate(50);
        $paginator->getCollection()->load([
            'viewerStates' => fn ($q) => $q->where('user_id', $userId),
        ]);
        $paginator->getCollection()->transform(function (AppNotification $row) {
            $state = $row->viewerStates->first();
            $row->setAttribute('read_at', $state?->read_at);
            $row->unsetRelation('viewerStates');

            return $row;
        });

        return response()->json($paginator);
    }

    public function unreadCount(Request $request, AlertInbox $inbox)
    {
        $inbox->sync();
        $userId = (int) $request->user()->id;

        $unread = $this->scoped($request)
            ->whereDoesntHave('viewerStates', function ($q) use ($userId) {
                $q->where('user_id', $userId)->where(function ($inner) {
                    $inner->whereNotNull('read_at')->orWhereNotNull('dismissed_at');
                });
            })
            ->count();

        return response()->json(['unread' => $unread]);
    }

    public function markRead(Request $request, AppNotification $notification)
    {
        $this->assertVisible($request, $notification);
        $this->touchState((int) $request->user()->id, (int) $notification->id, ['read_at' => now()]);

        return response()->json($notification);
    }

    public function markAllRead(Request $request)
    {
        $this->bulkTouch($request, ['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function dismiss(Request $request, AppNotification $notification)
    {
        $this->assertVisible($request, $notification);
        $this->touchState((int) $request->user()->id, (int) $notification->id, [
            'read_at' => now(),
            'dismissed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function dismissAll(Request $request)
    {
        $this->bulkTouch($request, [
            'read_at' => now(),
            'dismissed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function scoped(Request $request)
    {
        $query = AppNotification::query();
        $ids = BranchAccess::alertBranchIds($request->user());
        if ($ids !== null) {
            $query->where(function ($q) use ($ids, $request) {
                $q->whereIn('branch_id', $ids ?: [0])
                    ->orWhere('user_id', $request->user()->id);
            });
        }

        return $query;
    }

    private function touchState(int $userId, int $notificationId, array $attrs): void
    {
        $state = AppNotificationUserState::firstOrNew([
            'user_id' => $userId,
            'app_notification_id' => $notificationId,
        ]);
        foreach ($attrs as $key => $value) {
            if ($state->{$key}) {
                continue;
            }
            $state->{$key} = $value;
        }
        $state->save();
    }

    private function bulkTouch(Request $request, array $attrs): void
    {
        $userId = (int) $request->user()->id;
        $ids = $this->scoped($request)->pluck('id');
        DB::transaction(function () use ($ids, $userId, $attrs) {
            foreach ($ids as $id) {
                $this->touchState($userId, (int) $id, $attrs);
            }
        });
    }

    private function assertVisible(Request $request, AppNotification $notification): void
    {
        $ids = BranchAccess::alertBranchIds($request->user());
        if ($ids === null) {
            return;
        }
        $ok = ($notification->user_id && (int) $notification->user_id === (int) $request->user()->id)
            || ($notification->branch_id && in_array((int) $notification->branch_id, $ids, true));
        if (!$ok) {
            abort(403, 'دسترسی غیرمجاز');
        }
    }
}
