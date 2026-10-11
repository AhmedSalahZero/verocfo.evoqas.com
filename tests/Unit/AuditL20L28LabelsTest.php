<?php

namespace Tests\Unit;

// ══════════════════════════════════════════════════════════════════
//  AuditL20L28LabelsTest
//
//  Kit index pages take their eyebrow from a translation, and the
//  supplier note titles say what the document does to the bill.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use PHPUnit\Framework\TestCase;

class AuditL20L28LabelsTest extends TestCase
{
    public function test_kit_index_pages_do_not_hard_code_the_accounting_eyebrow(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'resources/js/Pages/Accounting/Manufacturing/KitAssemblyIndex.vue',
            'resources/js/Pages/Accounting/Manufacturing/KitRecipeIndex.vue',
        ] as $path) {
            $this->assertStringNotContainsString(
                'eyebrow="Accounting"',
                file_get_contents($root.'/'.$path),
                $path,
            );
        }
    }

    public function test_supplier_note_titles_describe_the_bill_effect(): void
    {
        $root = dirname(__DIR__, 2);
        $credit = file_get_contents($root.'/resources/js/i18n/en/accounting/supplierCreditNotes.js');
        $debit = file_get_contents($root.'/resources/js/i18n/en/accounting/supplierDebitNotes.js');

        $this->assertStringContainsString("creditNotes: 'Increase Supplier Bill'", $credit);
        $this->assertStringContainsString("debitNotes: 'Decrease Supplier Bill'", $debit);
        $this->assertStringNotContainsString("creditNotes: 'Credit Notes'", $credit);
        $this->assertStringNotContainsString("debitNotes: 'Debit Notes'", $debit);
    }
}
