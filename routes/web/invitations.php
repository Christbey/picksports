<?php

use App\Http\Controllers\Auth\GroupInvitationController;
use App\Http\Controllers\Auth\GroupJoinLinkController;
use Illuminate\Support\Facades\Route;

Route::get('group-invitations/{token}', [GroupInvitationController::class, 'show'])
    ->name('group-invitations.show');

Route::get('groups/join/{token}', [GroupJoinLinkController::class, 'show'])
    ->name('groups.join.show');

Route::post('group-invitations/{token}', [GroupInvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('group-invitations.accept');

Route::post('groups/join/{token}', [GroupJoinLinkController::class, 'accept'])
    ->middleware('auth')
    ->name('groups.join.accept');
