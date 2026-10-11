<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  UpdateLgIssuanceCommentRequest
//
//  Validates LgIssuanceController::update() — deliberately limited to
//  the comment field only. Core issuance fields are locked after
//  creation; only the lifecycle actions (renew/decrease/cancel/
//  liquidate) change an issuance post-creation. Kept as its own tiny
//  Request rather than folded into LgIssuanceRequest, since it
//  validates a completely different, much smaller action than
//  create does.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class UpdateLgIssuanceCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string'],
        ];
    }
}
