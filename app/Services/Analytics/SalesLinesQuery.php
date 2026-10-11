<?php

namespace App\Services\Analytics;

// ══════════════════════════════════════════════════════════════════
//  SalesLinesQuery
//
//  ONE definition of "net sales" for every analytics report
//  (Audit H13, Sept 2026). Before this, Sales Analysis and the
//  revenue figures in Expense Analysis summed raw invoice-line
//  amounts, which had three problems:
//
//    1. MIXED CURRENCIES — each line is stored in its invoice's own
//       currency, so USD 1,000 + EGP 1,000 was reported as 2,000.
//       Every amount here is converted to the company's FUNCTIONAL
//       currency with the document's own exchange rate (the same
//       rate that was posted to the ledger), rounded per line.
//
//    2. GROSS, NOT NET — credit notes were ignored. Posted customer
//       credit notes are now SUBTRACTED and posted customer debit
//       notes ADDED, each on its own date, carrying the product /
//       cost centre / project / payment term of the invoice line it
//       adjusts. So a report shows net sales.
//
//       Quantity: credit and debit notes adjust VALUE only (the system
//       has no goods-return document yet — Audit H16), so they carry
//       quantity 0. Quantity therefore stays "units invoiced", and
//       Unit Price becomes net value ÷ units invoiced — the average
//       selling price after credit notes.
//
//    3. SAME-NAME CUSTOMERS MERGED — handled in SalesAnalysisService
//       (labels now carry the customer / product code), but every row
//       here keeps partner_id so grouping can always be by ID.
//
//  Output: a derived table (alias of your choice) with one row per
//  document line:
//    doc_type        invoice | credit_note | debit_note
//    doc_id, doc_code, doc_date
//    invoice_id, invoice_code, invoice_date   (the invoice adjusted)
//    partner_id, product_id, cost_center_id, sales_order_id, payment_term_id
//    quantity, net_amount, vat_amount, total_amount   (functional, signed)
//
//  Only POSTED documents of the company, dated inside the range.
//  Opening-balance invoices have no lines, so they never appear.
//
//  @layer    Service (query helper, read-only)
//  @depends  customer_invoices, customer_invoice_lines, credit_notes,
//            credit_note_lines, customer_debit_notes, customer_debit_note_lines
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesLinesQuery
{
    /** Query builder over the unified sales lines, aliased as $alias. */
    public static function from(int $companyId, string $dateFrom, string $dateTo, string $alias = 'sl'): Builder
    {
        return DB::query()->fromSub(self::union($companyId, $dateFrom, $dateTo), $alias);
    }

    /** Net sales (functional, excl. VAT) for a date range. */
    public static function netSales(int $companyId, string $dateFrom, string $dateTo): string
    {
        return (string) (self::from($companyId, $dateFrom, $dateTo)->sum('sl.net_amount') ?? '0');
    }

    private static function union(int $companyId, string $dateFrom, string $dateTo): Builder
    {
        $invoices = DB::table('customer_invoice_lines as l')
            ->join('customer_invoices as ci', 'ci.id', '=', 'l.customer_invoice_id')
            ->where('ci.company_id', $companyId)
            ->where('ci.status', 'posted')
            ->whereBetween('ci.invoice_date', [$dateFrom, $dateTo])
            ->selectRaw("'invoice' as doc_type, ci.id as doc_id, ci.invoice_code as doc_code, ci.invoice_date as doc_date,
                ci.id as invoice_id, ci.invoice_code as invoice_code, ci.invoice_date as invoice_date,
                ci.partner_id, l.product_id, l.cost_center_id, ci.sales_order_id, ci.payment_term_id,
                l.quantity as quantity,
                ROUND(l.line_subtotal * ci.exchange_rate, 2) as net_amount,
                ROUND(l.vat_amount * ci.exchange_rate, 2) as vat_amount,
                ROUND(l.line_total * ci.exchange_rate, 2) as total_amount");

        $credits = DB::table('credit_note_lines as l')
            ->join('credit_notes as cn', 'cn.id', '=', 'l.credit_note_id')
            ->join('customer_invoice_lines as il', 'il.id', '=', 'l.customer_invoice_line_id')
            ->join('customer_invoices as ci', 'ci.id', '=', 'cn.customer_invoice_id')
            ->where('cn.company_id', $companyId)
            ->where('cn.status', 'posted')
            ->whereBetween('cn.credit_date', [$dateFrom, $dateTo])
            ->selectRaw("'credit_note' as doc_type, cn.id as doc_id, cn.credit_note_code as doc_code, cn.credit_date as doc_date,
                ci.id as invoice_id, ci.invoice_code as invoice_code, ci.invoice_date as invoice_date,
                cn.partner_id, il.product_id, il.cost_center_id, ci.sales_order_id, ci.payment_term_id,
                0 as quantity,
                -ROUND(l.line_subtotal * cn.exchange_rate, 2) as net_amount,
                -ROUND(l.vat_amount * cn.exchange_rate, 2) as vat_amount,
                -ROUND(l.line_total * cn.exchange_rate, 2) as total_amount");

        $debits = DB::table('customer_debit_note_lines as l')
            ->join('customer_debit_notes as dn', 'dn.id', '=', 'l.customer_debit_note_id')
            ->join('customer_invoice_lines as il', 'il.id', '=', 'l.customer_invoice_line_id')
            ->join('customer_invoices as ci', 'ci.id', '=', 'dn.customer_invoice_id')
            ->where('dn.company_id', $companyId)
            ->where('dn.status', 'posted')
            ->whereBetween('dn.debit_date', [$dateFrom, $dateTo])
            ->selectRaw("'debit_note' as doc_type, dn.id as doc_id, dn.debit_note_code as doc_code, dn.debit_date as doc_date,
                ci.id as invoice_id, ci.invoice_code as invoice_code, ci.invoice_date as invoice_date,
                dn.partner_id, il.product_id, il.cost_center_id, ci.sales_order_id, ci.payment_term_id,
                0 as quantity,
                ROUND(l.line_subtotal * dn.exchange_rate, 2) as net_amount,
                ROUND(l.vat_amount * dn.exchange_rate, 2) as vat_amount,
                ROUND(l.line_total * dn.exchange_rate, 2) as total_amount");

        return $invoices->unionAll($credits)->unionAll($debits);
    }
}
