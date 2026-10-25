<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AuditLogFilterOptions
{
    public function get(): array
    {
        $choices = [];
        foreach (['action_type', 'module', 'entity_type'] as $field) {
            $choices[$field] = AuditLog::query()->whereNotNull($field)->where($field, '<>', '')
                ->select($field)->distinct()->orderBy($field)->pluck($field)->all();
        }
        $actors = DB::table('audit_logs as events')
            ->leftJoin('user_profiles as profiles', 'profiles.id', '=', 'events.actor_user_id')
            ->whereNotNull('events.actor_user_id')->select(['events.actor_user_id as id', 'profiles.display_name'])
            ->distinct()->orderBy('profiles.display_name')->orderBy('events.actor_user_id')->get();

        return compact('choices', 'actors');
    }
}
