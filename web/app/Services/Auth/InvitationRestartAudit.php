<?php

namespace App\Services\Auth;

use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class InvitationRestartAudit
{
    private const OUTCOMES = ['requested', 'provider_accepted', 'provider_uncertain', 'verification_completed',
        'denied', 'reconciliation_hold', 'activation_completed'];

    // This writer accepts only fixed recovery events and server-loaded invitation references.
    public function record(?UserInvitation $invitation, string $outcome, bool $recipientVerified = false): void
    {
        if (! in_array($outcome, self::OUTCOMES, true)
            || ($invitation !== null && (! $invitation->exists || ! Str::isUuid($invitation->id)))) {
            throw new InvalidArgumentException('Unsupported invitation recovery event.');
        }
        if ($recipientVerified && (! $invitation || ! Str::isUuid($invitation->auth_user_id ?? '')
            || ! in_array($outcome, ['verification_completed', 'activation_completed'], true))) {
            throw new InvalidArgumentException('Unsupported recipient recovery event.');
        }
        if (! DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_user_id' => $recipientVerified ? $invitation->auth_user_id : null,
            'action_type' => 'INVITATION RECOVERY', 'module' => 'User Management',
            'entity_type' => $invitation ? 'user_invitation' : null, 'entity_id' => $invitation?->id,
            'new_values' => json_encode(['status' => $invitation?->status, 'outcome' => $outcome], JSON_THROW_ON_ERROR),
            'performed_at' => now()->format('Y-m-d\TH:i:s.uP'),
        ])) throw new RuntimeException('Invitation recovery audit unavailable.');
    }
}
