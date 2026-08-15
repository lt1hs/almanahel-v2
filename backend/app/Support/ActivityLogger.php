<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivityLogger
{
    private const SECRET_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'plainTextToken',
        'access_token',
        'current_password',
        'secret',
        'api_key',
    ];

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

            ActivityLog::create([
                'user_id'      => $resolvedUserId,
                'branch_id'    => $resolvedBranchId,
                'module'       => $module,
                'action'       => $action,
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
