<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogger
{
    private const SECRET_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'plaintexttoken',
        'access_token',
        'current_password',
        'secret',
        'api_key',
    ];

    public static function beginRequest(Request $request): void
    {
        $request->attributes->set('activity_log_recorded', false);
        if (!$request->attributes->has('activity_request_id')) {
            $request->attributes->set('activity_request_id', (string) Str::uuid());
        }
    }

    public static function wasRecorded(Request $request): bool
    {
        return (bool) $request->attributes->get('activity_log_recorded', false);
    }

    public static function finalizeRequest(Request $request, int $statusCode): void
    {
        try {
            $requestId = $request->attributes->get('activity_request_id');
            if (!$requestId) {
                return;
            }

            ActivityLog::query()
                ->where('request_id', $requestId)
                ->whereNull('status_code')
                ->update(['status_code' => $statusCode]);
        } catch (Throwable $e) {
            Log::warning('activity_log.finalize_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, string> */
    public static function secretKeys(): array
    {
        return self::SECRET_KEYS;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $module,
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?int $branchId = null,
        ?int $userId = null,
        ?string $severity = null,
        ?int $statusCode = null,
    ): void {
        try {
            $request = request();
            $user = Auth::user();

            $resolvedUserId = $userId;
            if ($resolvedUserId === null && $user) {
                $resolvedUserId = (int) $user->id;
            }

            $resolvedBranchId = $branchId;
            if ($resolvedBranchId === null && $user && !empty($user->branch_id)) {
                $resolvedBranchId = (int) $user->branch_id;
            }

            $severity ??= self::severityFor($action);
            $requestId = $request?->attributes->get('activity_request_id') ?: (string) Str::uuid();
            $request?->attributes->set('activity_request_id', $requestId);
            $request?->attributes->set('activity_log_recorded', true);

            ActivityLog::create([
                'event_uuid'   => (string) Str::uuid(),
                'request_id'   => $requestId,
                'user_id'      => $resolvedUserId,
                'branch_id'    => $resolvedBranchId,
                'module'       => $module,
                'action'       => $action,
                'severity'     => $severity,
                'http_method'  => $request?->method(),
                'route'        => $request?->route()?->uri() ?? $request?->path(),
                'status_code'  => $statusCode,
                'subject_type' => $subject ? self::subjectType($subject) : ($properties['subject_type'] ?? null),
                'subject_id'   => $subject?->getKey() ?? ($properties['subject_id'] ?? null),
                'description'  => mb_substr($description, 0, 500),
                'properties'   => self::sanitize($properties),
                'ip_address'   => $request?->ip(),
                'user_agent'   => mb_substr((string) ($request?->userAgent() ?? ''), 0, 500) ?: null,
                'created_at'   => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('activity_log.failed', [
                'module' => $module,
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private static function severityFor(string $action): string
    {
        return match ($action) {
            'deleted' => 'critical',
            'login_failed', 'bounced', 'cancelled' => 'warning',
            default => 'info',
        };
    }

    private static function subjectType(Model $subject): string
    {
        return strtolower(class_basename($subject));
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function sanitize(array $properties): array
    {
        $clean = [];
        foreach ($properties as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, self::SECRET_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
                continue;
            }
            if (is_object($value)) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
