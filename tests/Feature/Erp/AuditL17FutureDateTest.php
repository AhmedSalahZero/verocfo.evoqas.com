<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL17FutureDateTest
//
//  A supplier bill, a customer invoice, the general ledger, and
//  the trial balance all accept a date after today.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL17FutureDateTest extends ErpTestCase
{
    public function test_documents_and_reports_accept_a_date_after_today(): void
    {
        $scenario = (new ErpScenario($this->actor))->createCompany();
        $tomorrow = now()->addDay()->toDateString();

        $user = User::withoutGlobalScopes()->create([
            'name'       => 'Date Checker',
            'email'      => 'dates-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $scenario->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->from(route('accounting.supplier-bills.create'))
            ->post(route('accounting.supplier-bills.store'), [
                'bill_type'             => 'expense',
                'bill_date'             => $tomorrow,
                'supplier_invoice_date' => $tomorrow,
            ])
            ->assertSessionHasErrors()
            ->assertSessionDoesntHaveErrors(['bill_date', 'supplier_invoice_date']);

        $this->actingAs($user)
            ->from(route('accounting.customer-invoices.create'))
            ->post(route('accounting.customer-invoices.store'), [
                'invoice_date' => $tomorrow,
            ])
            ->assertSessionHasErrors()
            ->assertSessionDoesntHaveErrors(['invoice_date']);

        $this->actingAs($user)
            ->get(route('accounting.general-ledger.result', [
                'account_id' => $scenario->coaId('314'),
                'end_date'   => $tomorrow,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('accounting.trial-balance.result', [
                'end_date' => $tomorrow,
            ]))
            ->assertOk();
    }
}
