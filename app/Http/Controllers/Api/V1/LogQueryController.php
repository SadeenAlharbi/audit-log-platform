<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QueryLogsRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LogQueryController extends Controller
{
    /**
     * GET /api/v1/logs - filtered, newest first, cursor pagination.
     * Always limited to the key's project and to an occurred_at range.
     */
    public function index(QueryLogsRequest $request): AnonymousResourceCollection
    {
        [$from, $to] = $request->range();

        $query = AuditLog::query()
            ->where('project_id', $this->project($request)->id)
            ->whereBetween('occurred_at', [$from->format('Y-m-d H:i:s.v'), $to->format('Y-m-d H:i:s.v')]);

        foreach (['event', 'action', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'correlation_id', 'request_id', 'external_id'] as $field) {
            if ($request->filled($field)) {
                $value = (string) $request->query($field);
                // "user.*" matches every event that starts with "user."
                if ($field === 'event' && str_ends_with($value, '*')) {
                    $query->where('event', 'like', str_replace(['%', '_'], ['\%', '\_'], rtrim($value, '*')).'%');
                } else {
                    $query->where($field, $value);
                }
            }
        }

        if ($request->filled('severity')) {
            $query->whereIn('severity', explode(',', (string) $request->query('severity')));
        }

        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', (string) $request->query('tag'));
        }

        if ($request->filled('tags')) {
            foreach (array_unique(array_map('trim', explode(',', (string) $request->query('tags')))) as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }

        $perPage = (int) ($request->query('per_page') ?? 50);

        $logs = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    /**
     * GET /api/v1/logs/{uid}
     */
    public function show(Request $request, string $uid): AuditLogResource
    {
        $log = AuditLog::query()
            ->where('project_id', $this->project($request)->id)
            ->where('uid', $uid)
            ->firstOrFail();

        return new AuditLogResource($log);
    }

    private function project(Request $request): Project
    {
        return $request->attributes->get('project');
    }
}
