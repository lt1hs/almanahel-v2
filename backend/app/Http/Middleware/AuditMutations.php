<?php

namespace App\Http\Middleware;

use App\Support\ActivityLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        ActivityLogger::beginRequest($request);
        $response = $next($request);

        if ($this->shouldRecordFallback($request, $response)) {
            [$module, $action] = $this->classify($request);
            $subject = $this->routeSubject($request);
            $branchId = $this->branchId($request, $subject);

            ActivityLogger::record(
                $module,
                $action,
                sprintf('%s %s', $request->method(), trim($request->path(), '/')),
                $subject,
                [
                    'automatic' => true,
                    'changed_fields' => array_values(array_diff(
                        array_keys($request->except(ActivityLogger::secretKeys())),
                        ['_method']
                    )),
                    'route_parameters' => collect($request->route()?->parameters() ?? [])
                        ->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)
                        ->all(),
                ],
                $branchId,
                null,
                $response->getStatusCode() >= 300 ? 'warning' : 'info',
                $response->getStatusCode(),
            );
        }

        ActivityLogger::finalizeRequest($request, $response->getStatusCode());

        return $response;
    }

    private function shouldRecordFallback(Request $request, Response $response): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->getStatusCode() < 400
            && !ActivityLogger::wasRecorded($request);
    }

    /** @return array{string, string} */
    private function classify(Request $request): array
    {
        $segment = (string) $request->segment(2);
        $module = match ($segment) {
            'invoices', 'checks', 'credits' => 'sales',
            'consignments' => 'consignment',
            'customers' => 'customers',
            'notifications' => 'notifications',
            default => $segment ?: 'system',
        };
        $action = match ($request->method()) {
            'DELETE' => 'deleted',
            'PUT', 'PATCH' => 'updated',
            default => str_contains($request->path(), '/payments') ? 'paid' : 'created',
        };

        return [$module, $action];
    }

    private function routeSubject(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    private function branchId(Request $request, ?Model $subject): ?int
    {
        $value = $request->input('branch_id') ?? $subject?->getAttribute('branch_id');

        return $value === null ? null : (int) $value;
    }
}
