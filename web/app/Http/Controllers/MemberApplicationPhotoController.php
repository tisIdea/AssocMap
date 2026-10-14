<?php

namespace App\Http\Controllers;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\MemberApplication;
use App\Models\UserProfile;
use App\Services\MemberApplicationPhotoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemberApplicationPhotoController extends Controller
{
    public function __invoke(Request $request, MemberApplication $memberApplication,
        MemberApplicationPhotoService $photos): Response
    {
        $actor = $request->attributes->get('current_user');
        if (! $actor instanceof UserProfile || ! $actor->canAccessApplication()) {
            return $this->unavailable(403);
        }

        $allowed = match ($actor->role?->code) {
            'association_account' => $memberApplication->submitted_by_user_id === $actor->id
                && AssociationAccount::query()->useWritePdo()->where('user_id', $actor->id)
                    ->where('association_id', $memberApplication->association_id)->where('is_active', true)->exists(),
            'field_officer' => Association::query()->useWritePdo()->whereKey($memberApplication->association_id)
                ->where('primary_field_officer_id', $actor->id)->exists(),
            default => false,
        };
        if (! $allowed) return $this->unavailable(404);

        try {
            $photo = $photos->open($memberApplication->id, $memberApplication->applicant_photo_path);
        } catch (MemberApplicationPhotoUnavailableException $exception) {
            return $this->unavailable(503);
        }
        if ($photo === null) return $this->unavailable(404);

        $extension = $photo['mime_type'] === 'image/jpeg' ? 'jpg' : 'png';

        return response()->stream(static function () use ($photo): void {
            try {
                fpassthru($photo['stream']);
            } finally {
                fclose($photo['stream']);
            }
        }, 200, [
            'Content-Type' => $photo['mime_type'],
            'Content-Disposition' => 'inline; filename="applicant-photo.'.$extension.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function unavailable(int $status): Response
    {
        return response($status === 503 ? 'Photo storage is temporarily unavailable. Refresh the page to retry.' : 'Photo unavailable.', $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
