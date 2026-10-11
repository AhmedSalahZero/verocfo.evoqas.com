<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ForceClosePurchaseOrderRequest
//
//  Validates the single 'closure_reason' field on PurchaseOrderController
//  ::forceClose(). Kept as its own tiny Request rather than folded into
//  PurchaseOrderRequest, since it validates a completely different,
//  much smaller action (one required text field) than create/update do.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ForceClosePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'closure_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
