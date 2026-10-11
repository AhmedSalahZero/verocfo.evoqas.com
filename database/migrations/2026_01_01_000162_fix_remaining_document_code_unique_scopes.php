<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000162_fix_remaining_document_code_unique_scopes
//
//  Same bug already fixed once for purchase_orders (000150),
//  sales_orders (000151), goods_receipt_notes (000156), and
//  delivery_notes (000157) — a document code column was made unique
//  across the ENTIRE system instead of unique per company. Every one
//  of these codes is generated per company per month (restarting at
//  00001 for each company), so two different companies legitimately
//  land on the same code in the same month — and the old
//  global-unique rule wrongly rejected the second company's document
//  as a "duplicate" (reported by Mahmoud: Customer Invoice
//  CI/2026/08/00001 failing on a second company).
//
//  This migration closes out every remaining table with the same
//  pattern, found by auditing every plain ->unique() call across all
//  migrations and cross-checking each against its service's
//  generate*Code() method to confirm it's genuinely generated per
//  company (not a coincidentally-named column that's meant to be
//  global — company_tax_settings.company_id, users.email, and
//  jobs.uuid were checked and are correctly global, not touched
//  here):
//
//    customer_invoices.invoice_code   → CustomerInvoiceService  (the reported bug)
//    projects.contract_code           → ProjectService
//    accruals.accrual_code            → AccrualService
//    supplier_bills.bill_code         → SupplierBillService
//    credit_notes.credit_note_code    → CreditNoteService
//    customer_payments.payment_code   → CustomerPaymentService
//    supplier_payments.payment_code   → SupplierPaymentService
//
//  Each fix is identical in shape: drop the plain column-level unique
//  index, add a compound (company_id, code) unique index instead —
//  same two-line change as the four precedent migrations.
//
//  @layer    Migration
//  @depends  customer_invoices, projects, accruals, supplier_bills,
//            credit_notes, customer_payments, supplier_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropUnique('customer_invoices_invoice_code_unique');
            $table->unique(['company_id', 'invoice_code']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_contract_code_unique');
            $table->unique(['company_id', 'contract_code']);
        });

        Schema::table('accruals', function (Blueprint $table) {
            $table->dropUnique('accruals_accrual_code_unique');
            $table->unique(['company_id', 'accrual_code']);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropUnique('supplier_bills_bill_code_unique');
            $table->unique(['company_id', 'bill_code']);
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropUnique('credit_notes_credit_note_code_unique');
            $table->unique(['company_id', 'credit_note_code']);
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropUnique('customer_payments_payment_code_unique');
            $table->unique(['company_id', 'payment_code']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique('supplier_payments_payment_code_unique');
            $table->unique(['company_id', 'payment_code']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'invoice_code']);
            $table->unique('invoice_code');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'contract_code']);
            $table->unique('contract_code');
        });

        Schema::table('accruals', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'accrual_code']);
            $table->unique('accrual_code');
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'bill_code']);
            $table->unique('bill_code');
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'credit_note_code']);
            $table->unique('credit_note_code');
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'payment_code']);
            $table->unique('payment_code');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'payment_code']);
            $table->unique('payment_code');
        });
    }
};
