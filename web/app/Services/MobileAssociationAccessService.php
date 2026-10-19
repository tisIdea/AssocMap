<?php

namespace App\Services;

use App\Models\Role;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MobileAssociationAccessService
{
    public function resolve(array $verifiedIdentity): array
    {
        abort_unless(is_string($verifiedIdentity['id'] ?? null) && Str::isUuid($verifiedIdentity['id'])
            && is_string($verifiedIdentity['email'] ?? null)
            && filter_var($verifiedIdentity['email'], FILTER_VALIDATE_EMAIL), 401);

        $profile = UserProfile::query()->useWritePdo()->find($verifiedIdentity['id']);
        abort_unless($profile, 403);
        $profile->setRelation('role', Role::query()->useWritePdo()->find($profile->role_id));
        abort_unless($profile->canAccessApplication($verifiedIdentity, throwOnDatabaseError: true), 403);

        if ($profile->role->code !== 'association_account') {
            return ['profile' => $profile, 'mobile' => $this->ineligible('role_not_supported')];
        }

        // Read the current link and lifecycle together; never choose among inconsistent links.
        $links = DB::table('association_accounts as aa')->useWritePdo()
            ->leftJoin('associations as a', 'a.id', '=', 'aa.association_id')
            ->where('aa.user_id', $profile->id)->limit(2)
            ->select(['aa.id as link_id', 'aa.association_id', 'aa.is_active as link_is_active',
                'a.id as resolved_association_id', 'a.name as association_name',
                'a.status as association_status', 'a.archived_at'])
            ->selectSub(fn ($query) => $query->from('association_accounts as other_links')->selectRaw('COUNT(*)')
                ->whereColumn('other_links.association_id', 'aa.association_id'), 'association_link_count')->get();
        if ($links->count() !== 1) {
            return ['profile' => $profile, 'mobile' => $this->ineligible('association_link_unavailable')];
        }
        $link = $links->first();
        if ((int) $link->association_link_count !== 1 || ! in_array($link->link_is_active, [true, 1, '1', 't'], true)
            || ! is_string($link->link_id) || ! Str::isUuid($link->link_id)
            || ! is_string($link->association_id) || ! Str::isUuid($link->association_id)) {
            return ['profile' => $profile, 'mobile' => $this->ineligible('association_link_unavailable')];
        }
        if ($link->resolved_association_id !== $link->association_id
            || $link->association_status !== 'active' || $link->archived_at !== null
            || ! is_string($link->association_name) || trim($link->association_name) === '') {
            return ['profile' => $profile, 'mobile' => $this->ineligible('association_unavailable')];
        }

        return ['profile' => $profile, 'mobile' => [
            'eligible' => true, 'reason' => null,
            'association' => ['id' => $link->association_id, 'name' => $link->association_name],
        ]];
    }

    public function requireAssociation(array $verifiedIdentity): array
    {
        $context = $this->resolve($verifiedIdentity);
        abort_unless($context['mobile']['eligible'], 403);

        return $context;
    }

    public function scope(Builder|QueryBuilder $query, array $verifiedIdentity,
        string $associationColumn = 'association_id'): Builder|QueryBuilder
    {
        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/D', $associationColumn)) {
            throw new InvalidArgumentException('Use a server-defined association column.');
        }
        $context = $this->requireAssociation($verifiedIdentity);

        return $query->where($associationColumn, $context['mobile']['association']['id']);
    }

    private function ineligible(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'association' => null];
    }
}
