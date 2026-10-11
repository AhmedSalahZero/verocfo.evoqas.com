<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000073_create_purchase_orders_table
//
//  Purchase Order — mirrors SalesOrder's structure closely (see
//  SalesOrderService/Controller for the pattern this is built from),
//  with the differences confirmed by Mahmoud:
//
//    - partner_id is a SUPPLIER, not a customer.
//    - project_id link is OPTIONAL and only meaningful for "Service
//      with Inventory" companies — Trading and Manufacturing (mass
//      production) companies buy raw materials/consumables with no
//      project link at all. Gated in the controller using
//      Company::hasMixedInvoices() (its documented condition —
//      "Service with Inventory" — is exactly this gate; reusing it
//      rather than adding a near-duplicate check, flagged here in
//      case the two concepts ever need to diverge).
//    - po_value / billed_amount mirror so_value / invoiced_amount —
//      billed_amount is a placeholder updated later by the future
//      Supplier Bill module (Accounting Engine Phase 2), same as
//      invoiced_amount is today for Sales Order.
//    - NOT linked to COGS or any expense account (confirmed) — a PO
//      is purely an inventory-bound commitment. The actual account
//      (Raw Materials / Goods in Transit / etc.) resolves
//      automatically from the product's own classification at
//      Goods Receipt Note time — nothing to store on the PO itself.
//
//  TWO INDEPENDENT STATUS TRACKS (confirmed with Mahmoud — this is
//  the important part for whoever builds the Accounting Engine
//  later):
//
//    1. `status` — the PO's own document lifecycle. Same enum as
//       SalesOrder (draft/active/on_hold/closed/cancelled), driven
//       by the user, not by receiving or billing progress. Includes
//       a force-close path (`closure_reason`) for when a supplier
//       stops supplying before the PO is fulfilled.
//
//    2. `receipt_status` — NOT_RECEIVED / PARTIALLY_RECEIVED /
//       FULLY_RECEIVED. Reserved for the future GOODS RECEIPT NOTE
//       module (Accounting Engine Phase 2). Nothing writes to this
//       column yet — it defaults to not_received and stays there
//       until GRN posting exists. Driven by comparing each line's
//       `received_quantity` (see purchase_order_lines) against its
//       ordered `quantity`, allowing for per-line tolerance %.
//
//    3. `billing_status` — NOT_BILLED / PARTIALLY_BILLED / FULLY_
//       BILLED. Reserved for the future SUPPLIER BILL module
//       (Accounting Engine Phase 2, right after GRN). Driven by
//       comparing `billed_amount` against `po_value`. Deliberately
//       independent of receipt_status — a PO can be fully received
//       but not yet billed, or the reverse, and the two must never
//       be conflated into one column.
//
//  WHEN BUILDING GRN / SUPPLIER BILL LATER: update receipt_status /
//  billing_status from those modules' services, never from
//  PurchaseOrderService directly — this file's job stops at defining
//  where the columns live and what they mean.
//
//  PO code format: PO/YYYY/MM/00001 — same generator pattern as
//  SalesOrder's SO/YYYY/MM/00001, immutable after creation.
//
//  @layer    Migration
//  @depends  companies, partners, projects, currencies, payment_terms
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('po_code', 30)->unique();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete(); // Service-with-Inventory only — see header
            $table->foreignId('partner_id')->constrained('partners'); // supplier

            $table->string('subject', 300)->nullable();
            $table->text('description')->nullable();

            $table->decimal('po_value', 15, 2)->default(0);       // auto-summed from lines
            $table->decimal('billed_amount', 15, 2)->default(0);  // future Supplier Bill placeholder

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();

            $table->enum('status', ['draft', 'active', 'on_hold', 'closed', 'cancelled'])->default('draft');
            $table->enum('receipt_status', ['not_received', 'partially_received', 'fully_received'])->default('not_received');
            $table->enum('billing_status', ['not_billed', 'partially_billed', 'fully_billed'])->default('not_billed');
            $table->text('closure_reason')->nullable(); // populated only on a force-close before full receipt

            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
