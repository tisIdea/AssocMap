<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\UserProfile;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): View
    {
        $this->authorizeAdministrator($request);
        $filters = array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');
        $query = AuditLog::query()->select(['id', 'actor_user_id', 'action_type', 'module', 'entity_type', 'entity_id', 'performed_at'])
            ->with('actor:id,display_name');
        foreach (['action_type', 'module', 'entity_type'] as $field) {
            if (isset($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (isset($filters['actor_user_id'])) {
            $filters['actor_user_id'] === 'unavailable' ? $query->whereNull('actor_user_id') : $query->where('actor_user_id', $filters['actor_user_id']);
        }
        $timezone = config('app.timezone', 'UTC');
        if (isset($filters['date_from'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone);
            $query->where('performed_at', '>=', $from->toIso8601String());
        }
        if (isset($filters['date_to'])) {
            $until = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone)->addDay();
            $query->where('performed_at', '<', $until->toIso8601String());
        }
        $logs = $query->orderByDesc('performed_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $logs->appends(array_diff_key($filters, ['page' => true]));
        $choices = [];
        foreach (['action_type', 'module', 'entity_type'] as $field) {
            $choices[$field] = AuditLog::query()->whereNotNull($field)->select($field)->distinct()->orderBy($field)->limit(100)->pluck($field)->all();
        }
        $actors = UserProfile::query()->select(['id', 'display_name'])
            ->whereExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs')->whereColumn('audit_logs.actor_user_id', 'user_profiles.id'))
            ->orderBy('display_name')->orderBy('id')->limit(100)->get();
        if (isset($filters['actor_user_id']) && $filters['actor_user_id'] !== 'unavailable' && ! $actors->contains('id', $filters['actor_user_id'])) {
            $actor = UserProfile::query()->find($filters['actor_user_id'], ['id', 'display_name']);
            if ($actor) $actors->push($actor);
        }

        return view('admin.audit-logs.index', compact('logs', 'filters', 'choices', 'actors', 'timezone'));
    }

    public function show(Request $request, string $auditLog, AuditLogService $service): View
    {
        $this->authorizeAdministrator($request);
        $event = AuditLog::query()->with('actor:id,display_name')->findOrFail($auditLog);
        if ($event->action_type === 'DELETE GIS' && $event->entity_type === 'gis_location') {
            $oldDetails = $newDetails = ['json' => null, 'message' => 'Permanent deletion audits do not display record snapshots.'];
        } else {
            $oldDetails = $service->formatSnapshot($event->old_values, $event->entity_type);
            $newDetails = $service->formatSnapshot($event->new_values, $event->entity_type);
        }

        return view('admin.audit-logs.show', compact('event', 'oldDetails', 'newDetails') + ['timezone' => config('app.timezone', 'UTC')]);
    }

    private function authorizeAdministrator(Request $request): void
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor instanceof UserProfile && $actor->canAccessApplication() && $actor->role?->code === 'system_admin', 403);
    }
}
