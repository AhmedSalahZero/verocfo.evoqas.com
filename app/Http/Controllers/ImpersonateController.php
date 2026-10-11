<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  ImpersonateController
//
//  Manages Super Admin company impersonation.
//
//  How impersonation works in Vero CFO:
//    1. Super Admin clicks "Open Company" on the companies index
//    2. enter() sets session('impersonate_company_id') to company ID
//    3. ActiveCompany middleware reads this session key and binds
//       app('currentCompanyId') — scoping ALL queries to that company
//    4. AppLayout shows the gold impersonation banner with "Exit"
//    5. exit() clears the session key and returns to companies index
//
//  Both enter and exit write to audit_log for full traceability.
//  Super Admin can only enter active companies.
//
//  Routes:
//    POST /impersonate/{company}/enter → enter()
//    POST /impersonate/exit            → exit()
//
//  @layer    Controller
//  @depends  App\Models\Company, App\Models\AuditLog
//  @used-by  Companies/Index.vue (Open Company button)
//            AppLayout.vue (Exit impersonation button)
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class ImpersonateController extends Controller
{
    // ── Enter company ─────────────────────────────────────────────

    public function enter(Request $request, Company $company)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless($company->is_active, 403, 'Company is inactive.');

        session(['impersonate_company_id' => $company->id]);

        AuditLog::create([
            'company_id'     => $company->id,
            'user_id'        => $request->user()->id,
            'action'         => 'impersonation_started',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
        ]);

        return redirect()->route('dashboard')
            ->with('success', "Now viewing as [{$company->name_en}].");
    }

    // ── Exit company ──────────────────────────────────────────────

    public function exit(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $companyId = session('impersonate_company_id');
        session()->forget('impersonate_company_id');

        if ($companyId) {
            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $request->user()->id,
                'action'         => 'impersonation_ended',
                'auditable_type' => Company::class,
                'auditable_id'   => $companyId,
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
            ]);
        }

        return redirect()->route('companies.index');
    }
}
