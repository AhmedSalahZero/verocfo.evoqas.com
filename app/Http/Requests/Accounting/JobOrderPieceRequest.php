<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderPieceRequest
//
//  Validates adding a Piece to a Job Order (Section 6). name is the
//  only required field — quantity is optional, purely descriptive
//  (e.g. "4 Uprights") and only used as an Overhead Pool base for a
//  production_units-allocated pool if one is ever linked.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class JobOrderPieceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:150'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
        ];
    }
}
