<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveHolidayController   (audit M-35)
//
//  The company's weekly rest days and public-holiday calendar. Leave
//  types set to "skip non-working days" do not count these dates.
//  Thin HTTP layer; the data is two simple facts, so the checks live
//  here as validation rather than in a service.
//
//  @layer    Controller
//  @depends  CompanyHoliday, companies.weekend_days
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanyHoliday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LeaveHolidayController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Leave/Holidays', [
            'holidays' => CompanyHoliday::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('holiday_date')->get(['id', 'holiday_date', 'name']),
            'weekendDays' => array_map('intval', explode(',', (string) (DB::table('companies')->where('id', $companyId)->value('weekend_days') ?: '5,6'))),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate(['holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:120']]);

        $holiday = CompanyHoliday::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'holiday_date' => $data['holiday_date']],
            ['name' => $data['name']]
        );
        AuditLog::record($request->user(), 'company_holiday.saved', $holiday, null, $holiday->toArray());

        return back()->with('success', 'Holiday saved.');
    }

    public function destroy(Request $request, int $holiday)
    {
        $row = CompanyHoliday::withoutGlobalScopes()->where('company_id', $this->companyId($request))->findOrFail($holiday);
        $before = $row->toArray();
        $row->delete();
        AuditLog::record($request->user(), 'company_holiday.deleted', $row, $before, null);

        return back()->with('success', 'Holiday removed.');
    }

    public function updateWeekend(Request $request)
    {
        $data = $request->validate(['weekend_days' => ['required', 'array', 'min:1', 'max:3'], 'weekend_days.*' => ['integer', 'between:1,7']]);
        DB::table('companies')->where('id', $this->companyId($request))
            ->update(['weekend_days' => implode(',', array_unique($data['weekend_days']))]);

        return back()->with('success', 'Weekly rest days saved.');
    }
}
