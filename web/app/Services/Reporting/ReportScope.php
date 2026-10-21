<?php

namespace App\Services\Reporting;

use App\Exceptions\ReportingUnavailableException;
use App\Models\UserProfile;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportScope
{
    public function actor(Request $request, bool $export = false): UserProfile
    {
        $identity = $request->attributes->get('current_user');
        abort_unless($identity instanceof UserProfile, 403);
        $actor = UserProfile::findWithRole($identity->id, useWritePdo: true);
        abort_unless($actor?->canAccessApplication(throwOnDatabaseError: true)
            && $actor->role?->code === $identity->role?->code
            && in_array($actor->role?->code, ['system_admin', 'field_officer', 'association_account'], true), 403);
        abort_if($export && $actor->role->code === 'association_account', 403);

        return $actor;
    }

    public function associations(UserProfile $actor): Builder
    {
        abort_unless($actor->canAccessApplication(throwOnDatabaseError: true)
            && in_array($actor->role?->code, ['system_admin', 'field_officer', 'association_account'], true), 403);
        $query = app(\App\Services\AssociationGeographyService::class)
            ->joinGeography(DB::table('associations as a')->useWritePdo(), 'mu');
        if ($actor->role->code === 'field_officer') {
            $query->where('a.primary_field_officer_id', $actor->id)
                ->where('a.status', 'active')->whereNull('a.archived_at');
        } elseif ($actor->role->code === 'association_account') {
            // Match the existing mobile eligibility policy, including inconsistent-link denial.
            $associationLinkCount = DB::table('association_accounts as association_links')->selectRaw('COUNT(*)')
                ->whereColumn('association_links.association_id', 'account_link.association_id');
            $eligibleAssociation = (clone $query)->whereColumn('a.id', 'account_link.association_id')
                ->where('a.status', 'active')->whereNull('a.archived_at')->select('a.id')->limit(1);
            $links = DB::table('association_accounts as account_link')->useWritePdo()
                ->select(['account_link.association_id', 'account_link.is_active'])
                ->selectSub($associationLinkCount, 'association_link_count')
                ->selectSub($eligibleAssociation, 'eligible_association_id')
                ->where('account_link.user_id', $actor->id)->limit(2)->get();
            abort_unless($links->count() === 1 && in_array($links[0]->is_active, [true, 1, '1', 't'], true)
                && (int) $links[0]->association_link_count === 1 && $links[0]->eligible_association_id !== null, 403);
            $query->where('a.id', $links[0]->association_id)->where('a.status', 'active')->whereNull('a.archived_at');
        }

        return $query;
    }

    public function selected(UserProfile $actor, array $filters): Builder
    {
        $query = $this->associations($actor);
        if (isset($filters['association_id'])) {
            abort_unless((clone $query)->where('a.id', $filters['association_id'])->exists(), 403);
            $query->where('a.id', $filters['association_id']);
        }
        if (isset($filters['municipality_id'])) {
            abort_unless((clone $query)->where('mu.id', $filters['municipality_id'])->exists(), 403);
            $query->where('mu.id', $filters['municipality_id']);
        }

        return $query;
    }

    public function ids(UserProfile $actor, array $filters): array
    {
        $ids = $this->selected($actor, $filters)->orderBy('a.id')->limit(1001)->pluck('a.id')->all();
        if (count($ids) > 1000) throw new ReportingUnavailableException('scope_limit');

        return $ids;
    }

    public function recheck(Request $request, array $filters, array $ids, bool $export = false): void
    {
        $actor = $this->actor($request, $export);
        // Re-resolve assignments/lifecycle after calculation and before releasing any data.
        abort_unless($this->ids($actor, $filters) === $ids, 403);
    }
}
