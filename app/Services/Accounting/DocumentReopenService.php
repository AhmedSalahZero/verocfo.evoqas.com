<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DocumentReopenService
//
//  Controls "Set to Draft" on POSTED documents (Audit H5, Sept 2026 —
//  decisions confirmed with Mahmoud):
//
//  1. Tax documents — customer invoices and supplier bills.
//     requestReopen() records a PENDING request with a written reason
//     (min. 10 characters), only while the document's month is open.
//     A DIFFERENT user with the Approve right approves it — approve()
//     then performs the real reopen through the document's own
//     service (CustomerInvoiceService / SupplierBillService::
//     setToDraft, which keeps every existing safety check) — or
//     rejects it. Opening-balance documents are never reopenable.
//
//  2. Everything else that can be reopened — collections, payments,
//     other collections/payments, cash expenses, delivery notes,
//     goods receipts. withReason() requires a written reason and
//     writes it to the audit log in the SAME transaction as the
//     reopen itself, so a reopen can never happen without its reason
//     on record (and a failed reopen leaves no stray reason behind).
//
//  The platform Super Admin may approve his own request (support and
//  test operations only); nobody else can.
//
//  @layer    Service
//  @depends  DocumentReopenRequest, CustomerInvoice, SupplierBill,
//            CustomerInvoiceService, SupplierBillService,
//            FiscalYearService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CustomerInvoice;
use App\Models\DocumentReopenRequest;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DocumentReopenService
{
    public function __construct(private FiscalYearService $fiscalYears)
    {
    }

    // ── 1. Tax documents: request → approve / reject ─────────────

    public function requestReopen(CustomerInvoice|SupplierBill $document, User $actor, string $reason): DocumentReopenRequest
    {
        $reason = $this->assertReason($reason);
        [$type, $code, $date] = $this->describe($document);

        if (! $document->isPosted()) {
            throw new \DomainException("{$code} is not posted — there is nothing to reopen.");
        }
        if ($document->is_opening_balance) {
            throw new \DomainException("{$code} is an opening-balance document — correct it with a credit or debit note instead.");
        }
        if ($this->fiscalYears->isPeriodClosed($document->company_id, $date)) {
            throw new \DomainException("{$code} is in a closed month — it can only be corrected with a credit or debit note.");
        }

        $alreadyPending = DocumentReopenRequest::withoutGlobalScopes()
            ->where('document_type', $type)->where('document_id', $document->id)
            ->where('status', DocumentReopenRequest::STATUS_PENDING)->exists();
        if ($alreadyPending) {
            throw new \DomainException("A request to reopen {$code} is already waiting for approval.");
        }

        return DB::transaction(function () use ($document, $actor, $reason, $type, $code) {
            $request = DocumentReopenRequest::create([
                'company_id'    => $document->company_id,
                'document_type' => $type,
                'document_id'   => $document->id,
                'document_code' => $code,
                'reason'        => $reason,
                'status'        => DocumentReopenRequest::STATUS_PENDING,
                'requested_by'  => $actor->id,
            ]);

            AuditLog::record($actor, 'reopen.requested', $request, null, $request->toArray());

            return $request;
        });
    }

    public function approve(DocumentReopenRequest $request, User $actor): DocumentReopenRequest
    {
        $this->assertDecidable($request, $actor);

        return DB::transaction(function () use ($request, $actor) {
            $locked = DocumentReopenRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->id);
            if (! $locked->isPending()) {
                throw new \DomainException('This request has already been decided.');
            }

            // The real reopen, through the document's own service — every
            // existing safety check (closed period, linked payments, …)
            // still applies. If it refuses, the approval rolls back.
            if ($locked->document_type === DocumentReopenRequest::TYPE_CUSTOMER_INVOICE) {
                $document = CustomerInvoice::withoutGlobalScopes()->where('company_id', $locked->company_id)->findOrFail($locked->document_id);
                app(CustomerInvoiceService::class)->setToDraft($document, $actor);
            } else {
                $document = SupplierBill::withoutGlobalScopes()->where('company_id', $locked->company_id)->findOrFail($locked->document_id);
                app(SupplierBillService::class)->setToDraft($document, $actor);
            }

            $before = $locked->toArray();
            $locked->update([
                'status'     => DocumentReopenRequest::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            AuditLog::record($actor, 'reopen.approved', $locked, $before, $locked->fresh()->toArray());

            return $locked->fresh();
        });
    }

    public function reject(DocumentReopenRequest $request, User $actor, string $reason): DocumentReopenRequest
    {
        $this->assertDecidable($request, $actor);

        return DB::transaction(function () use ($request, $actor, $reason) {
            $before = $request->toArray();
            $request->update([
                'status'           => DocumentReopenRequest::STATUS_REJECTED,
                'decided_by'       => $actor->id,
                'decided_at'       => now(),
                'rejection_reason' => $reason,
            ]);

            AuditLog::record($actor, 'reopen.rejected', $request, $before, $request->fresh()->toArray());

            return $request->fresh();
        });
    }

    public function pending(int $companyId): Collection
    {
        return DocumentReopenRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', DocumentReopenRequest::STATUS_PENDING)
            ->with('requestedBy:id,name')
            ->orderBy('created_at')
            ->get();
    }

    /** The pending request for one document, if any (shown on its page). */
    public function pendingFor(string $type, int $documentId): ?DocumentReopenRequest
    {
        return DocumentReopenRequest::withoutGlobalScopes()
            ->where('document_type', $type)->where('document_id', $documentId)
            ->where('status', DocumentReopenRequest::STATUS_PENDING)
            ->first();
    }

    // ── 2. Other documents: reason recorded with the reopen ──────

    /**
     * Runs $reopen (the document's own setToDraft) and records the
     * reason in the audit log, both in one transaction.
     */
    public function withReason(Model $document, User $actor, string $reason, callable $reopen): mixed
    {
        $reason = $this->assertReason($reason);

        return DB::transaction(function () use ($document, $actor, $reason, $reopen) {
            $result = $reopen();

            AuditLog::record($actor, 'document.reopen_reason', $document, null, [
                'document' => class_basename($document).' #'.$document->getKey(),
                'reason'   => $reason,
            ]);

            return $result;
        });
    }

    // ── Private ───────────────────────────────────────────────────

    private function assertReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 10) {
            throw new \DomainException('Please give a reason (at least 10 characters) for reopening this document.');
        }

        return $reason;
    }

    private function assertDecidable(DocumentReopenRequest $request, User $actor): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('This request has already been decided.');
        }
        if (! $actor->isSuperAdmin() && (int) $request->requested_by === (int) $actor->id) {
            throw new \DomainException('You asked for this reopen — it must be approved by someone else.');
        }

        // The Approve right on the document's OWN feature is needed.
        $feature = $request->document_type === DocumentReopenRequest::TYPE_CUSTOMER_INVOICE ? 'customer_invoices' : 'supplier_bills';
        if (! $actor->canOnFeature('approve', 'accounting', $feature)) {
            throw new \DomainException('You do not have the Approve right for this kind of document.');
        }
    }

    /** [type, code, date] for a tax document. */
    private function describe(CustomerInvoice|SupplierBill $document): array
    {
        return $document instanceof CustomerInvoice
            ? [DocumentReopenRequest::TYPE_CUSTOMER_INVOICE, $document->invoice_code, $document->invoice_date->toDateString()]
            : [DocumentReopenRequest::TYPE_SUPPLIER_BILL, $document->bill_code, $document->bill_date->toDateString()];
    }
}
