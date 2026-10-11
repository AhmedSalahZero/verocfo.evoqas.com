<?php

namespace App\Http\Requests\Projects;

// ══════════════════════════════════════════════════════════════════
//  ChangeProjectStatusRequest
//
//  Validates the single 'status' field on ProjectController::
//  changeStatus(). Kept as its own tiny Request rather than folded
//  into ProjectRequest, since it validates a completely different,
//  much smaller action (one enum field) than create/update do.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ChangeProjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,on_hold,closed,cancelled'],
            // Fix (July 2026 audit — #6/#9, applied): lets the frontend
            // resubmit a close request after the user has seen and
            // accepted the "project site still has stock" warning
            // (ProjectSiteHasStockException). Optional — defaults to
            // false, meaning the warning is enforced unless the user
            // explicitly clicked through it.
            'confirm_stock_warning' => ['sometimes', 'boolean'],
        ];
    }
}
