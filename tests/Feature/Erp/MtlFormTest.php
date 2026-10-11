<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  MtlFormTest
//
//  The Medium-Term Loan form, driven the way a customer drives it —
//  through the HTTP routes, not the service.
//
//  Written after a self-inflicted break: TRS-002 made
//  bank_account_id required for a new loan in MtlRequest, and the
//  Vue form was never given the field. The customer could fill the
//  screen in correctly and still be unable to save, with an error on
//  a field that was not on the page. The service-level tests all
//  passed throughout, because they call the service directly and
//  never touch the form.
//
//  These tests assert the whole seam: the create screen offers the
//  accounts, the store route accepts a correct submission, and it
//  rejects an incorrect one on the field the user can actually see.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\CreditFacility;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class MtlFormTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->createCompany();
        $this->bank = $this->scenario->createBankAccount('Loan Bank');

        $this->actingAs($this->actor);
        session(['impersonate_company_id' => $this->scenario->companyId]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bank_id'              => $this->bank->bank_id,
            'name'                 => 'Expansion Loan',
            'entry_type'           => 'new',
            'start_date'           => '2026-01-15',
            'review_date'          => '2028-01-15',
            'currency_id'          => $this->scenario->currencyId,
            'facility_limit'       => '1200000.00',
            'account_number'       => 'MTL-FORM-'.random_int(1000, 9999),
            'borrowing_rate'       => '18',
            'bank_margin_rate'     => '2',
            'tenure_months'        => '24',
            'installment_interval' => 'monthly',
            'bank_account_id'      => $this->bank->id,
        ], $overrides);
    }

    // ══════════════════════════════════════════════════════════════
    //  The create screen
    // ══════════════════════════════════════════════════════════════

    /**
     * The screen must offer the bank accounts the form now requires.
     * This is the exact gap that broke it: the rule was added, the
     * prop was not.
     */
    public function test_the_create_screen_offers_the_banks_current_accounts(): void
    {
        $response = $this->get(route('fi.facilities.mtl.create', ['bank' => $this->bank->bank_id]));

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey(
            'bankAccounts', $props,
            'MtlRequest requires bank_account_id for a new loan, so the screen has to '.
            'offer the accounts to choose from. Without this prop the field cannot be '.
            'filled and the loan cannot be saved at all.'
        );

        $this->assertContains(
            $this->bank->id,
            collect($props['bankAccounts'])->pluck('id')->all(),
            "The bank's own active current account must be offered."
        );
    }

    /** Editing offers them too, so the link can be changed. */
    public function test_the_edit_screen_offers_the_banks_current_accounts(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload())->assertRedirect();
        $facility = CreditFacility::withoutGlobalScopes()->where('facility_type', 'mtl')->latest('id')->firstOrFail();

        $response = $this->get(route('fi.facilities.mtl.edit', ['facility' => $facility->id]));

        $response->assertOk();
        $this->assertArrayHasKey('bankAccounts', $response->viewData('page')['props']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Saving
    // ══════════════════════════════════════════════════════════════

    /** A correctly filled form saves, and the loan is ready to draw on. */
    public function test_a_complete_new_loan_saves_and_is_ready_to_draw_on(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload())
             ->assertSessionHasNoErrors()
             ->assertRedirect();

        $facility = CreditFacility::withoutGlobalScopes()
            ->where('facility_type', 'mtl')->latest('id')->firstOrFail();

        $this->assertSame($this->bank->id, (int) $facility->bank_account_id);
        $this->assertSame(
            $this->scenario->coaId('211'), (int) $facility->coa_account_id,
            'Linked to 211 Long-Term Loans, which is what lets a payment be made from it.'
        );
        $this->assertMoneyEquals('0.00', (string) $facility->outstanding_balance,
            'Nothing is drawn until a payment is actually made from the loan.');
    }

    /**
     * The break itself: submitting without the account must fail on
     * bank_account_id — a field the user can see and fix — rather
     * than throwing somewhere in the service.
     */
    public function test_a_new_loan_without_the_linked_account_fails_on_that_field(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload(['bank_account_id' => null]))
             ->assertSessionHasErrors('bank_account_id');

        $this->assertSame(
            0,
            CreditFacility::withoutGlobalScopes()->where('facility_type', 'mtl')->count(),
            'And nothing was created.'
        );
    }

    /**
     * An EXISTING loan needs no linked account — it was drawn and
     * spent before this system and is only ever repaid. The field is
     * hidden on the form for exactly this case, so the rule must
     * agree with the screen.
     */
    public function test_an_existing_loan_saves_without_a_linked_account(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload([
            'entry_type'      => 'existing',
            'bank_account_id' => null,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $facility = CreditFacility::withoutGlobalScopes()
            ->where('facility_type', 'mtl')->latest('id')->firstOrFail();

        $this->assertSame('existing', $facility->entry_type);
        $this->assertNull($facility->coa_account_id,
            'Nothing left to draw, so no liability account link — and not a payment source.');
    }

    /**
     * The account must belong to the same bank AND the same currency
     * as the loan. The form filters the dropdown to match; the rule
     * has to enforce the same thing, or a stale selection slips past
     * after a currency change.
     */
    public function test_an_account_in_another_currency_is_refused(): void
    {
        $other = \App\Models\Currency::withoutGlobalScopes()
            ->whereNull('company_id')->where('id', '!=', $this->scenario->currencyId)->value('id');

        if (! $other) {
            $this->markTestSkipped('Only one currency is seeded on this database.');
        }

        $this->post(route('fi.facilities.mtl.store'), $this->payload(['currency_id' => $other]))
             ->assertSessionHasErrors('bank_account_id');
    }

    // ══════════════════════════════════════════════════════════════
    //  The rest of the customer's journey
    // ══════════════════════════════════════════════════════════════

    /**
     * Create → schedule → settle, entirely through the routes a
     * customer clicks, ending in a real journal entry.
     *
     *   instalment 50,000.00 = interest 10,000.00 + principal 40,000.00
     *   paid in full:
     *       Dr 211 Long-Term Loans    40,000.00
     *       Dr 551 Interest on Loans  10,000.00
     *       Cr the linked bank        50,000.00
     */
    public function test_the_whole_journey_from_the_screens_creates_a_real_entry(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload())->assertSessionHasNoErrors();

        $facility = CreditFacility::withoutGlobalScopes()
            ->where('facility_type', 'mtl')->latest('id')->firstOrFail();

        // The schedule screen loads and saves.
        $this->get(route('fi.facilities.mtl.schedule', ['facility' => $facility->id]))->assertOk();

        $this->post(route('fi.facilities.mtl.schedule.save', ['facility' => $facility->id]), [
            'lines' => [[
                'due_date'          => '2026-02-15',
                'beginning_balance' => '1200000.00',
                'schedule_payment'  => '50000.00',
                'interest_amount'   => '10000.00',
                'principal_amount'  => '40000.00',
                'end_balance'       => '1160000.00',
            ]],
        ])->assertSessionHasNoErrors();

        $line = \App\Models\FinancialInstitutions\MtlScheduleLine::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)->firstOrFail();

        // The settlement screen loads and posts.
        $this->get(route('fi.mtl.settlement-form', ['scheduleLine' => $line->id]))->assertOk();

        $this->post(route('fi.mtl.settle', ['scheduleLine' => $line->id]), [
            'settlement_date'    => '2026-02-15',
            'settlement_amount'  => '50000.00',
            'current_account_id' => $this->bank->id,
        ])->assertSessionHasNoErrors();

        // 211 reads DEBIT-positive here because this journey never drew
        // on the loan — no supplier was paid out of it, so nothing ever
        // credited the liability. The repayment debits it all the same,
        // which is the point: the screens now post. The drawn-then-
        // repaid case, where the balance walks back toward zero, is
        // covered in MediumTermLoanAccountingTest.
        $this->assertMoneyEquals(
            '40000.00',
            $this->glBalance($this->scenario->companyId, '211'),
            'The principal repayment reached the ledger. Before TRS-002 the whole '.
            'journey posted nothing at all.'
        );
        $this->assertMoneyEquals(
            '10000.00',
            $this->glBalance($this->scenario->companyId, '551'),
            'And so did the interest.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After the full journey.');
    }

    /** The list screen carries what a payment source has to show. */
    public function test_the_list_screen_carries_the_drawn_and_limit_figures(): void
    {
        $this->post(route('fi.facilities.mtl.store'), $this->payload())->assertSessionHasNoErrors();

        $response = $this->get(route('fi.facilities.mtl.index'));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['facilities']['data'])->first();

        foreach (['facility_limit', 'outstanding_balance', 'entry_type'] as $field) {
            $this->assertArrayHasKey(
                $field, $row,
                "The list needs {$field} to show how much of the loan is drawn and how ".
                'much is left to pay suppliers with.'
            );
        }
    }

    /** An account at a different bank is refused too. */
    public function test_an_account_at_another_bank_is_refused(): void
    {
        $otherBank = $this->scenario->createBankAccount('Other Bank');

        $this->post(route('fi.facilities.mtl.store'), $this->payload(['bank_account_id' => $otherBank->id]))
             ->assertSessionHasErrors('bank_account_id');
    }
}
