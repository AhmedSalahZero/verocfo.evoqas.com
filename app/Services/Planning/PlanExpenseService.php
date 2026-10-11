<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanExpenseService
//
//  Operating Expenses step — CRUD for plan_expense_lines. category
//  maps to the real COA Level 2 groups (52/53/54/55 — spec Section
//  3.2); expense_type is one of pct_revenue / fixed_recurring /
//  one_time, same three shapes CFO Tools' own Expenses step uses.
//
//  @layer    Service
//  @depends  Plan, PlanExpenseLine, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanExpenseLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanExpenseService
{
    public function addLine(Plan $plan, array $data, User $actor): PlanExpenseLine
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $line = PlanExpenseLine::create([
                'company_id'            => $plan->company_id,
                'plan_id'               => $plan->id,
                'expense_name'          => $data['expense_name'],
                'category'              => $data['category'],
                'expense_type'          => $data['expense_type'],
                'amount'                => $data['amount'] ?? 0,
                'amortization_months'   => $data['amortization_months'] ?? 1,
                'annual_increase_pct'   => $data['annual_increase_pct'] ?? 0,
                'start_month'           => $data['start_month'] ?? 0,
                'end_month'             => $data['end_month'] ?? null,
            ]);

            AuditLog::record($actor, 'plan.expense_line_added', $line, null, $line->toArray());

            return $line;
        });
    }

    public function updateLine(PlanExpenseLine $line, array $data, User $actor): PlanExpenseLine
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $before = $line->toArray();

            $line->update([
                'expense_name'          => $data['expense_name']          ?? $line->expense_name,
                'category'              => $data['category']              ?? $line->category,
                'expense_type'          => $data['expense_type']          ?? $line->expense_type,
                'amount'                => $data['amount']                ?? $line->amount,
                'amortization_months'   => $data['amortization_months']   ?? $line->amortization_months,
                'annual_increase_pct'   => $data['annual_increase_pct']   ?? $line->annual_increase_pct,
                'start_month'           => $data['start_month']           ?? $line->start_month,
                'end_month'             => array_key_exists('end_month', $data) ? $data['end_month'] : $line->end_month,
            ]);

            AuditLog::record($actor, 'plan.expense_line_updated', $line, $before, $line->fresh()->toArray());

            return $line->fresh();
        });
    }

    public function removeLine(PlanExpenseLine $line, User $actor): void
    {
        DB::transaction(function () use ($line, $actor) {
            $before = $line->toArray();
            $line->delete();

            AuditLog::record($actor, 'plan.expense_line_removed', $line, $before, null);
        });
    }
}
