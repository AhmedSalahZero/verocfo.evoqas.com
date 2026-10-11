<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL8L14Test
//
//  L-8  delivery / receipt line unit costs keep 4 decimals.
//  L-9  account 516 is flagged as a system account.
//  L-13 the contract-assignment label uses the real sales-order columns.
//  L-14 a liquidated LG reports "liquidated".
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\FacilityContractAssignment;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;

class AuditL8L14Test extends ErpTestCase
{
    public function test_line_unit_cost_columns_keep_four_decimals(): void
    {
        foreach ([['delivery_note_lines', 'wac_unit_cost'], ['goods_receipt_note_lines', 'unit_cost']] as [$table, $col]) {
            $scale = DB::selectOne(
                'SELECT NUMERIC_SCALE AS s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]
            )->s;
            $this->assertSame(4, (int) $scale, "{$table}.{$col} must keep 4 decimals.");
        }
    }

    public function test_inventory_adjustment_account_is_a_system_account(): void
    {
        $row = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '516')->first();
        $this->assertNotNull($row);
        $this->assertEquals(1, $row->is_system);
    }

    public function test_assignment_label_uses_the_real_sales_order_columns(): void
    {
        $a = new FacilityContractAssignment(['collateral_type' => 'sales_order']);
        $a->setRelation('collateralSo', (new SalesOrder)->forceFill(['id' => 7, 'so_code' => 'SO/2026/03/00001', 'subject' => 'Pump station']));

        $this->assertSame('SO/2026/03/00001 — Pump station', $a->collateralLabel());
    }

    public function test_a_liquidated_lg_reports_liquidated(): void
    {
        $lg = (new LgIssuance)->forceFill(['status' => 'cancelled', 'liquidated_at' => '2026-05-01']);
        $this->assertSame('liquidated', $lg->computedStatus());

        $plain = (new LgIssuance)->forceFill(['status' => 'cancelled']);
        $this->assertSame('cancelled', $plain->computedStatus());
    }
}
