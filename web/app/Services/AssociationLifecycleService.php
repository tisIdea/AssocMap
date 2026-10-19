<?php

namespace App\Services;

use App\Models\Association;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class AssociationLifecycleService
{
    public const READ_ONLY_MESSAGE = 'Archived or inactive associations are read-only. Reactivate the association before recording operational changes.';

    public function isOperational(Association $association): bool
    {
        return $association->status === 'active' && $association->archived_at === null;
    }

    public function assertOperational(Association $association, string $field = 'association_id'): void
    {
        if (! $this->isOperational($association)) {
            throw ValidationException::withMessages([$field => self::READ_ONLY_MESSAGE]);
        }
    }

    public function lockOperational(array $associationIds, string $field = 'association_id'): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Association eligibility locks require a transaction.');
        }

        // Lock both parents of a transfer in a stable order until the write commits.
        $ids = array_values(array_unique(array_map('strtolower',
            array_filter($associationIds, static fn ($id) => $id !== null))));
        if ($ids === []) return;

        $associations = Association::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($associations->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'The selected association is unavailable.']);
        }
        foreach ($associations as $association) {
            $this->assertOperational($association, $field);
        }
    }
}
