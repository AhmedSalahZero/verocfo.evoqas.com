<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000310_rebase_sales_order_invoiced_amount
//
//  Audit M-11 — sales_orders.invoiced_amount was being kept in the
//  company's MAIN currency while so_value (what it is compared with)
//  is in the order's OWN currency. For an order in the main currency
//  the two are identical; for a USD order they were not, which caused
//  false "exceeds Sales Order value" refusals or silent over-billing.
//
//  From now on invoiced_amount is in the order's own currency. This
//  migration recomputes it once for every order NOT in its company's
//  main currency, from the posted invoices less the credit notes raised
//  by Sales Returns. It is safe to run again (it recomputes from the
//  documents, not from the old figure). Orders in the main currency are
//  not touched.
//
//  @layer    Migration
//  @depends  sales_orders, customer_invoices, sales_returns, credit_notes
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $orders = DB::table('sales_orders as so')
            ->join('company_active_currencies as f', function ($j) {
                $j->on('f.company_id', '=', 'so.company_id')->where('f.is_functional', true);
            })
            ->whereColumn('so.currency_id', '!=', 'f.currency_id')
            ->get(['so.id', 'so.company_id', 'so.currency_id', 'so.exchange_rate']);

        foreach ($orders as $so) {
            $rate = bccomp((string) $so->exchange_rate, '0', 8) > 0 ? (string) $so->exchange_rate : '1';

            $total = '0.00';
            $invoices = DB::table('customer_invoices')
                ->where('sales_order_id', $so->id)->where('status', 'posted')
                ->get(['currency_id', 'subtotal_amount', 'subtotal_amount_functional']);
            foreach ($invoices as $i) {
                $total = bcadd($total, $this->inOrderCurrency($so, $rate, $i), 2);
            }

            $returnNoteIds = DB::table('sales_returns')
                ->where('sales_order_id', $so->id)->where('status', 'posted')->whereNotNull('credit_note_ids')
                ->pluck('credit_note_ids')
                ->flatMap(fn ($json) => (array) json_decode((string) $json, true))
                ->map(fn ($id) => (int) $id)->all();

            if ($returnNoteIds !== []) {
                $notes = DB::table('credit_notes')->whereIn('id', $returnNoteIds)->where('status', 'posted')
                    ->get(['currency_id', 'subtotal_amount', 'subtotal_amount_functional']);
                foreach ($notes as $n) {
                    $total = bcsub($total, $this->inOrderCurrency($so, $rate, $n), 2);
                }
            }

            if (bccomp($total, '0', 2) < 0) {
                $total = '0.00';
            }

            DB::table('sales_orders')->where('id', $so->id)->update(['invoiced_amount' => $total]);
        }
    }

    public function down(): void
    {
        // Not reversible: the old figure was in the wrong unit.
    }

    private function inOrderCurrency(object $so, string $rate, object $doc): string
    {
        if ((int) $doc->currency_id === (int) $so->currency_id) {
            return bcadd((string) $doc->subtotal_amount, '0', 2);
        }

        return bcadd(bcdiv((string) $doc->subtotal_amount_functional, $rate, 6), '0.005', 2);
    }
};
