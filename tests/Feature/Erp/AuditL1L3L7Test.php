<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL1L3L7Test
//
//  Audit L-1  one password rule everywhere (Password::defaults()).
//  Audit L-3  the public (not-logged-in) route list is small.
//  Audit L-7  document codes come from an atomic counter:
//             numbers rise one by one, each month has its own
//             counter, and the month follows the document date.
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerInvoice;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL1L3L7Test extends ErpTestCase
{
    public function test_password_default_rule_is_strong(): void
    {
        $rule = ['p' => Password::defaults()];

        $this->assertTrue(Validator::make(['p' => 'abcdefgh'], $rule)->fails(), 'Plain 8 letters must be refused.');
        $this->assertTrue(Validator::make(['p' => 'Abcdefg1'], $rule)->fails(), 'No symbol must be refused.');
        $this->assertFalse(Validator::make(['p' => 'Abcdef1!x'], $rule)->fails());
    }

    public function test_guest_route_group_is_small_and_has_no_business_routes(): void
    {
        $groups = config('ziggy.groups.guest');

        $this->assertContains('login', $groups);
        foreach ($groups as $g) {
            $this->assertStringNotContainsString('invoice', $g);
            $this->assertStringNotContainsString('journal', $g);
        }
    }

    public function test_document_numbers_rise_by_one_and_follow_the_document_month(): void
    {
        $cid = (new ErpScenario($this->actor))->bootstrap(1)->companyId;

        $a = DocumentNumber::code($cid, CustomerInvoice::class, 'invoice_code', 'CI', '2026-03-10');
        $b = DocumentNumber::code($cid, CustomerInvoice::class, 'invoice_code', 'CI', '2026-03-28');
        $c = DocumentNumber::code($cid, CustomerInvoice::class, 'invoice_code', 'CI', '2026-04-02');

        $this->assertSame('CI/2026/03/00001', $a);
        $this->assertSame('CI/2026/03/00002', $b);
        $this->assertSame('CI/2026/04/00001', $c, 'April has its own counter, taken from the document date.');
    }
}
