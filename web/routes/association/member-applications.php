<?php

use App\Http\Controllers\AssociationAccount\MemberApplicationController as AssociationAccountMemberApplicationController;
use App\Http\Controllers\MemberApplicationPhotoController;
use App\Http\Middleware\MemberApplicationEvidenceHeaders;
use Illuminate\Support\Facades\Route;

Route::get(
    '/association/member-applications',
    [
        AssociationAccountMemberApplicationController::class,
        'index',
    ]
)->middleware(MemberApplicationEvidenceHeaders::class)->name(
    'association.member-applications.index'
);

Route::get('/association/member-applications/{memberApplication}/photo', MemberApplicationPhotoController::class)
    ->whereUuid('memberApplication')
    ->middleware(MemberApplicationEvidenceHeaders::class)
    ->name('association.member-applications.photo');

Route::post(
    '/association/member-applications',
    [
        AssociationAccountMemberApplicationController::class,
        'store',
    ]
)->middleware(MemberApplicationEvidenceHeaders::class)->name(
    'association.member-applications.store'
);
