<?php

namespace App\Http\Requests;

class StoreMobileMemberApplicationRequest extends StoreMemberApplicationRequest
{
    public function rules(): array
    {
        return parent::rules() + array_fill_keys([
            'id', 'association_id', 'submitted_by_user_id', 'status', 'reviewed_by',
            'reviewed_at', 'rejection_reason', 'applicant_photo_path',
        ], ['prohibited']);
    }
}
