<?php

namespace App\Services;

use App\Models\UserProfile;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class AuditLogService
{
    private const MAX_SNAPSHOT_BYTES = 131072;

    private const DISPLAY_FIELDS = [
        'account_reactivation' => ['user_id', 'requested_by', 'outcome'],
        'association' => ['primary_field_officer_id'],
        'monitoring_harvest_entry' => ['monitoring_production_id', 'harvest_date', 'quantity', 'remarks',
            'created_by', 'updated_by', 'voided_at', 'voided_by', 'void_reason'],
        'monitoring_production_goal' => ['target_output', 'reason'],
        'monitoring_daily_production' => ['monitoring_record_id', 'project_product_id', 'quantity_source', 'unit'],
        'user_invitation' => ['status', 'attempt_count', 'outcome'],
        'user_profile' => ['is_active', 'invitation_statuses', 'cancelled_invitation_ids', 'cancelled_reactivation_id'],
        'association_lifecycle' => ['association_id', 'status', 'archived_at', 'archived_by', 'updated_by'],
        'project_closure' => ['project_id', 'association_id', 'status', 'closed_at', 'closed_by', 'actor_role'],
        'project_termination' => ['project_id', 'association_id', 'status', 'closed_at', 'closed_by', 'actor_role'],
        'member' => ['association_id', 'member_status', 'archived_at', 'archived_by', 'changed_fields'],
        'member_officer_position' => ['association_id', 'position_id', 'member_status', 'archived_at'],
        'gis_location' => ['association_id', 'project_id', 'location_type', 'location_name', 'address',
            'public_title', 'public_description', 'public_address', 'is_published', 'published_by',
            'published_at', 'archived_at', 'archived_by', 'geometry'],
    ];

    // Event labels and permitted fields come from the workflow, never request input.
    // Call inside the business transaction when the event must commit atomically.
    public function record(
        Request $request,
        string $action,
        string $module,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $allowedSnapshotFields = [],
        bool $includeRequestContext = false,
    ): string {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor instanceof UserProfile && $actor->exists
            && Str::isUuid($actor->id) && $actor->canAccessApplication(), 403);
        $action = $this->metadata($action, 100);
        $module = $this->metadata($module, 100);
        $entityType = $entityType === null ? null : $this->metadata($entityType, 100);
        $entityId = $entityId === null ? null : $this->metadata($entityId, 255);
        if (($entityType === null) !== ($entityId === null)) {
            throw new InvalidArgumentException('Provide both entity type and identifier, or neither.');
        }
        if ($action === 'DELETE GIS' && $entityType === 'gis_location'
            && ($oldValues !== null || $newValues !== null || $includeRequestContext)) {
            throw new InvalidArgumentException('Permanent GIS deletion requires a minimal audit without snapshots or request context.');
        }
        foreach ($allowedSnapshotFields as $field) {
            if (! is_string($field) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) || $this->sensitiveKey($field)) {
                throw new InvalidArgumentException('Audit snapshot fields must be explicitly permitted and non-sensitive.');
            }
        }
        if (count($allowedSnapshotFields) > 50) {
            throw new InvalidArgumentException('Too many audit snapshot fields.');
        }
        $id = (string) Str::uuid();
        $values = [
            'id' => $id, 'actor_user_id' => $actor->id, 'action_type' => $action, 'module' => $module,
            'entity_type' => $entityType, 'entity_id' => $entityId,
            'old_values' => $this->encodeSnapshot($oldValues, $allowedSnapshotFields),
            'new_values' => $this->encodeSnapshot($newValues, $allowedSnapshotFields),
            'performed_at' => now()->format('Y-m-d\TH:i:s.uP'),
        ];
        if ($includeRequestContext) {
            $values['ip_address'] = filter_var($request->ip(), FILTER_VALIDATE_IP) ?: null;
            $values['user_agent'] = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $request->userAgent() ?? '') ?? '', 0, 500);
        }
        if (! DB::table('audit_logs')->insert($values)) {
            throw new RuntimeException('The required audit event could not be recorded.');
        }

        return $id;
    }

    public function formatSnapshot(mixed $values, ?string $entityType): array
    {
        if ($values === null) {
            return ['json' => null, 'message' => 'No snapshot was recorded.'];
        }
        if (! isset(self::DISPLAY_FIELDS[$entityType ?? ''])) {
            return ['json' => null, 'message' => 'Change details are not enabled for this entity type.'];
        }
        try {
            if (! is_array($values)) {
                throw new InvalidArgumentException('Unsupported snapshot.');
            }
            $encoded = $this->encodeSnapshot($values, self::DISPLAY_FIELDS[$entityType]);
            $json = $encoded === null ? null : json_encode(json_decode($encoded, true, 16, JSON_THROW_ON_ERROR),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return ['json' => $json, 'message' => $json === null ? 'No permitted change fields were recorded.' : null];
        } catch (InvalidArgumentException|JsonException $exception) {
            return ['json' => null, 'message' => 'The saved snapshot cannot be displayed within the supported limits.'];
        }
    }

    private function metadata(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('Audit event metadata is invalid or exceeds the supported length.');
        }

        return $value;
    }

    private function encodeSnapshot(?array $values, array $allowedFields): ?string
    {
        if ($values === null || $allowedFields === []) {
            return null;
        }
        $permitted = array_intersect_key($values, array_fill_keys($allowedFields, true));
        if ($permitted === []) {
            return null;
        }
        $remaining = 12000;
        $safe = $this->sanitize($permitted, 0, $remaining);
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_SNAPSHOT_BYTES) {
            throw new InvalidArgumentException('Audit snapshot exceeds the supported size.');
        }

        return $json;
    }

    private function sanitize(mixed $value, int $depth, int &$remaining): mixed
    {
        if ($depth > 8 || --$remaining < 0) {
            throw new InvalidArgumentException('Audit snapshot exceeds the supported structure limits.');
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ($this->sensitiveKey($key) || strlen($key) > 100)) {
                    continue;
                }
                $safe[$key] = $this->sanitize($item, $depth + 1, $remaining);
            }

            return $safe;
        }
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 4000) {
                throw new InvalidArgumentException('Audit snapshot text is invalid or too long.');
            }

            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new InvalidArgumentException('Audit snapshots must contain JSON values.');
    }

    private function sensitiveKey(string $key): bool
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));

        return (bool) preg_match('/password|passwd|passphrase|token|secret|credential|apikey|accesskey|privatekey|keys?$|authorization|cookie|session|email|phone|contactnumber|birthdate|nationalid|^(auth|authentication|jwt|bearer|pwd|hash)$/', $key);
    }
}
