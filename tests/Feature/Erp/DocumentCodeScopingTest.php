<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  DocumentCodeScopingTest
//
//  Every document code in this system is generated from a PER-COMPANY
//  counter — "the Nth document this company raised this month". A
//  unique index on the code column alone is therefore a hard
//  multi-tenant blocker rather than a tidiness issue: two companies
//  raising their first debit note in the same month generate the same
//  code, and the second insert fails outright.
//
//  Migration 000162 (fix_remaining_document_code_unique_scopes) fixed
//  exactly this across the documents that existed at the time. Tables
//  added afterwards can reintroduce it — and five of them did
//  (audit finding TEN-001), which only surfaced once a test cycle ran
//  TWO companies through the same month.
//
//  This test is a schema guard. It needs no data: it reads the index
//  definitions directly, so a new document table that gets the scope
//  wrong fails here immediately rather than in production the first
//  time a second company uses the feature.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;

class DocumentCodeScopingTest extends ErpTestCase
{
    /**
     * REGRESSION GUARD — audit finding TEN-001.
     *
     * DISCOVERED rather than listed. A hard-coded table list only
     * covers what someone remembered to add, and this defect arrives
     * with new tables — so the test asks the schema directly: every
     * table with a company_id and a *_code column, does its unique
     * index include company_id?
     *
     * Five tables currently fail: customer_debit_notes,
     * supplier_debit_notes, supplier_credit_notes, landing_costs and
     * internal_settlements.
     * The test asserts the CORRECT behaviour, so it fails until they
     * are fixed — which is the point. A second company genuinely
     * cannot use those features today.
     */
    public function test_every_document_code_is_unique_per_company_not_globally(): void
    {
        $unscoped = array_map(
            fn ($row) => "{$row->tbl}: unique({$row->cols})",
            DB::select(
                "select s.TABLE_NAME tbl,
                        GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) cols
                   from INFORMATION_SCHEMA.STATISTICS s
                  where s.TABLE_SCHEMA = DATABASE()
                    and s.NON_UNIQUE = 0
                    and exists (
                          select 1 from INFORMATION_SCHEMA.COLUMNS c
                           where c.TABLE_SCHEMA = s.TABLE_SCHEMA
                             and c.TABLE_NAME = s.TABLE_NAME
                             and c.COLUMN_NAME = 'company_id')
                  group by s.TABLE_NAME, s.INDEX_NAME
                 having cols like '%\_code'
                    and cols not like '%company_id%'
                  order by s.TABLE_NAME"
            )
        );

        $this->assertSame(
            [],
            $unscoped,
            "These document codes are unique platform-wide instead of per company, so a second "
            ."company cannot raise its first document of that type in a month another company "
            ."already used:\n  ".implode("\n  ", $unscoped)
            ."\nThe correct shape is unique(company_id, code) — see customer_invoices and "
            .'supplier_bills, and migration 000162 which fixed the same class of bug.'
        );
    }

    /** The tables that already have it right, so the fix has a model. */
    public function test_the_correctly_scoped_tables_stay_correctly_scoped(): void
    {
        foreach (['customer_invoices' => 'invoice_code', 'supplier_bills' => 'bill_code'] as $table => $column) {
            $scoped = false;

            foreach ($this->uniqueIndexesOn($table) as $index) {
                if (str_contains($index, $column) && str_contains($index, 'company_id')) {
                    $scoped = true;
                }
            }

            $this->assertTrue(
                $scoped,
                "{$table}.{$column} lost its per-company unique index — this is the shape every "
                .'other document code should be copying.'
            );
        }
    }

    /** @return array<int, string> comma-joined column lists, one per unique index */
    private function uniqueIndexesOn(string $table): array
    {
        return array_map(
            fn ($row) => $row->cols,
            DB::select(
                'select GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
                   from INFORMATION_SCHEMA.STATISTICS
                  where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and NON_UNIQUE = 0
                  group by INDEX_NAME',
                [$table]
            )
        );
    }
}
