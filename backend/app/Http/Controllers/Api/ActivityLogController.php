<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ActivityLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    private function assertAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $actor = $request->user();
        if (!in_array($actor?->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        return null;
    }

    private function filteredQuery(Request $request)
    {
        $query = ActivityLog::query()->with([
            'user:id,name,email,role',
            'branch:id,name,city',
        ]);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($inner) use ($q) {
                $inner->where('description', 'like', "%{$q}%")
                    ->orWhere('subject_type', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%")
                    ->orWhere('event_uuid', 'like', "%{$q}%")
                    ->orWhere('request_id', 'like', "%{$q}%")
                    ->orWhere('route', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%"));
                if (ctype_digit($q)) {
                    $inner->orWhere('subject_id', (int) $q);
                }
            });
        }

        return $query->latest('created_at')->latest('id');
    }

    public function meta(Request $request)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $modules = ActivityLog::query()
            ->select('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $users = User::query()
            ->select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get();

        $branches = Branch::query()
            ->select('id', 'name', 'city', 'type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'modules'  => $modules,
            'actions'  => $actions,
            'severities' => ['info', 'warning', 'critical'],
            'users'    => $users,
            'branches' => $branches,
            'known_modules' => [
                'auth', 'books', 'inventory', 'sales', 'transfers', 'consignment',
                'settlements', 'returns', 'gifts', 'expenses', 'suppliers',
                'branches', 'users', 'settings', 'categories', 'warehouse',
                'customers', 'notifications', 'system',
            ],
            'known_actions' => [
                'created', 'updated', 'deleted', 'sold', 'settled', 'shipped',
                'received', 'closed', 'login', 'logout', 'login_failed',
                'status_changed', 'priced', 'exported', 'paid', 'archived',
            ],
        ]);
    }

    public function summary(Request $request)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $base = $this->filteredQuery($request)->reorder();
        $total = (clone $base)->count();
        $today = (clone $base)->whereDate('created_at', today())->count();
        $important = (clone $base)->whereIn('severity', ['warning', 'critical'])->count();
        $uniqueUsers = (clone $base)->whereNotNull('user_id')->distinct()->count('user_id');
        $modules = (clone $base)
            ->select('module', DB::raw('COUNT(*) as total'))
            ->groupBy('module')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
        $actions = (clone $base)
            ->select('action', DB::raw('COUNT(*) as total'))
            ->groupBy('action')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return response()->json([
            'total' => $total,
            'today' => $today,
            'important' => $important,
            'unique_users' => $uniqueUsers,
            'modules' => $modules,
            'actions' => $actions,
            'latest_at' => (clone $base)->max('created_at'),
        ]);
    }

    public function index(Request $request)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $perPage = min(100, max(10, (int) ($request->per_page ?? 25)));

        return response()->json(
            $this->filteredQuery($request)->paginate($perPage)
        );
    }

    public function show(Request $request, ActivityLog $activityLog)
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        return response()->json(
            $activityLog->load(['user:id,name,email,role', 'branch:id,name,city'])
        );
    }

    public function export(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        if ($denied = $this->assertAdmin($request)) {
            return $denied;
        }

        $rows = $this->filteredQuery($request)
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $filename = 'activity-logs-' . now()->format('Y-m-d-His') . '.csv';

        ActivityLogger::record(
            'system',
            'exported',
            'دریافت خروجی لاگ فعالیت‌ها',
            null,
            ['filters' => $request->only(['date_from', 'date_to', 'module', 'action', 'severity', 'user_id', 'branch_id', 'q']), 'row_count' => $rows->count()],
            null,
            null,
            'warning',
            200,
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'id',
                'created_at',
                'severity',
                'module',
                'action',
                'description',
                'user_id',
                'user_name',
                'user_email',
                'branch_id',
                'branch_name',
                'subject_type',
                'subject_id',
                'ip_address',
                'http_method',
                'route',
                'status_code',
                'event_uuid',
                'request_id',
                'properties',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    optional($row->created_at)?->toDateTimeString(),
                    $row->severity,
                    $row->module,
                    $row->action,
                    $row->description,
                    $row->user_id,
                    $row->user?->name,
                    $row->user?->email,
                    $row->branch_id,
                    $row->branch?->name,
                    $row->subject_type,
                    $row->subject_id,
                    $row->ip_address,
                    $row->http_method,
                    $row->route,
                    $row->status_code,
                    $row->event_uuid,
                    $row->request_id,
                    $row->properties ? json_encode($row->properties, JSON_UNESCAPED_UNICODE) : '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
