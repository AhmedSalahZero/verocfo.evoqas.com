<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  MachineRequest
//
//  Validates create/update for a Machine (Work Center) —
//  VeroCFO_Manufacturing_Costing_Redesign_v1_0.md, Section 4.
//
//  overhead_pool_ids is required with at least one entry — Section
//  4.4 states every Machine is linked to the Overhead Pool(s) its
//  running cost belongs to; a Machine with none would silently never
//  absorb any overhead once Section 7 (Applied vs. Actual) is built.
//
//  fixed_asset_id is the only genuinely optional identity field — see
//  Machine model / migration 000262 header for why. The "already
//  linked to a different machine" check is enforced in
//  MachineService::assertFixedAssetAvailable() as the real source of
//  truth (a raw ::create() call would otherwise bypass this request
//  entirely) — not duplicated here as a Rule::unique, since the
//  service's DomainException reads better than a bare validation
//  message for a cross-table collision like this.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class MachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:200'],
            'process_id'            => ['required', 'exists:processes,id'],
            'fixed_asset_id'        => ['nullable', 'exists:fixed_assets,id'],
            'status'                => ['required', 'in:active,under_maintenance,retired'],
            'overhead_pool_ids'     => ['required', 'array', 'min:1'],
            'overhead_pool_ids.*'   => ['integer', 'exists:overhead_pools,id'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'overhead_pool_ids.required' => 'Pick at least one Overhead Pool this machine\'s running cost feeds.',
            'overhead_pool_ids.min'      => 'Pick at least one Overhead Pool this machine\'s running cost feeds.',
        ];
    }
}
