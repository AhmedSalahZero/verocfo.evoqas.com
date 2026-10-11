<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000209_alter_customer_invoices_add_due_dates
//
//  Two date columns, both nullable (draft invoices have neither —
//  only set when the invoice posts):
//
//  original_due_date — set ONCE, at posting time, computed from the
//    customer's own Collection Term (Partner::collection_term_id) —
//    invoice_date + the LAST installment's due_days (i.e. when the
//    whole invoice is expected to be fully settled). NEVER changed
//    again after that, by anything — this is the promise as it stood
//    when the invoice was raised.
//
//  due_date — starts equal to original_due_date at posting time, but
//    CAN be moved later via the "Adjust Due Date" action (per
//    Mahmoud, September 2026) when a customer asks for an extension.
//    This is the date actually used for Aging / Past Due today.
//
//  Keeping both — rather than overwriting one field — is deliberate:
//  it lets a future report compare what was promised vs. what was
//  actually granted, per customer, without needing anything beyond
//  these two columns plus the audit_log entries every adjustment
//  already writes (see CustomerInvoiceService::adjustDueDate()).
//
//  @layer    Migration
//  @depends  customer_invoices
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->date('original_due_date')->nullable()->after('invoice_date');
            $table->date('due_date')->nullable()->after('original_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn(['original_due_date', 'due_date']);
        });
    }
};
