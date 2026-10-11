<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  VERO CFO — Authentication Routes
//
//  Standard Laravel Breeze authentication routes.
//  Registration is removed — users are created by Company Admin only.
//  All pages use GuestLayout.vue via the controller responses.
// ══════════════════════════════════════════════════════════════════

Route::middleware('guest')->group(function () {

    // ── Login ─────────────────────────────────────────────────────
    Route::get('login',  [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // ── Password reset ────────────────────────────────────────────
    Route::get('forgot-password',  [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password',        [NewPasswordController::class, 'store'])->name('password.store');

});

Route::middleware('auth')->group(function () {

    // ── Email verification ────────────────────────────────────────
    Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');

    // Audit L2: the link in the e-mail must run the controller that
    // actually marks the address verified (it used to open the prompt page).
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // ── Confirm password ──────────────────────────────────────────
    Route::get('confirm-password',  [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    // ── Change password ───────────────────────────────────────────
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    // ── Logout ────────────────────────────────────────────────────
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

});
