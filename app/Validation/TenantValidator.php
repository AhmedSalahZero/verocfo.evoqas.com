<?php

namespace App\Validation;

// ══════════════════════════════════════════════════════════════════
//  TenantValidator
//
//  AUDIT H2 FIX (Sept 2026). The app's Validator. Identical to
//  Laravel's, except that while an "exists" rule runs it switches
//  TenantPresenceVerifier into company-scoped mode, so every
//  'exists:<table>,id' in every Form Request, controller and service
//  only accepts records of the current company (or system records).
//
//  Covers both forms of the rule — 'exists:table,col' strings and
//  Rule::exists(...) objects — because both end up here.
//
//  Registered once in AppServiceProvider (Validator::resolver), so no
//  Form Request had to change.
//
//  @layer    Validation
//  @depends  TenantPresenceVerifier, AppServiceProvider
// ══════════════════════════════════════════════════════════════════

use Illuminate\Validation\Validator;

class TenantValidator extends Validator
{
    public function validateExists($attribute, $value, $parameters)
    {
        $verifier = $this->presenceVerifier;

        if (! $verifier instanceof TenantPresenceVerifier) {
            return parent::validateExists($attribute, $value, $parameters);
        }

        $verifier->scoping++;

        try {
            return parent::validateExists($attribute, $value, $parameters);
        } finally {
            $verifier->scoping--;
        }
    }
}
