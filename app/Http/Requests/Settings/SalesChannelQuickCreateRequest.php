<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SalesChannelQuickCreateRequest
//
//  Validates the quick-add modal on the Partner / Sales Order form
//  (SalesChannelController::store()). Split out of that controller
//  (July 2026 audit) — validation belongs in a Form Request, not
//  inline in the controller (Building Bible Rule 3.2), even for a
//  small two-field form like this one.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SalesChannelQuickCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
        ];
    }
}
