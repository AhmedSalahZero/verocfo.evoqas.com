{{-- ══════════════════════════════════════════════════════════════════
  payslip.blade.php   (audit M-36)

  Printable payslip for one employee on one payroll run. Plain page,
  opened in a new tab and printed / saved as PDF from the browser.

  @route   hr.payroll.lines.payslip
  @props   company, period, run_date, employee, currency, line, items
══════════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payslip — {{ $employee }} — {{ $period }}</title>
<style>
  body{font-family:Arial,sans-serif;max-width:720px;margin:24px auto;color:#0F2554}
  h1{font-size:20px;margin:0}h2{font-size:14px;margin:18px 0 6px;border-bottom:2px solid #0F2554}
  table{width:100%;border-collapse:collapse}td{padding:5px 4px;border-bottom:1px solid #ddd}td.n{text-align:right}
  .tot td{font-weight:bold;border-top:2px solid #0F2554}.muted{color:#666;font-size:12px}
  @media print{button{display:none}}
</style>
</head>
<body>
<button onclick="window.print()">Print</button>
<h1>{{ $company }}</h1>
<div class="muted">Payslip — {{ $period }} · Run date {{ $run_date }}</div>
<h2>Employee</h2>
<div>{{ $employee }} <span class="muted">({{ $currency }})</span></div>

<h2>Earnings and deductions</h2>
<table>
  <tr><td>Gross pay</td><td class="n">{{ number_format((float) $line->gross_pay, 2) }}</td></tr>
  @foreach ($items as $it)
    <tr><td>{{ $it->deductionReason?->name_en ?? $it->description ?? 'Item' }} <span class="muted">({{ $it->type ?? '' }})</span></td><td class="n">{{ number_format((float) $it->amount, 2) }}</td></tr>
  @endforeach
  <tr><td>Income tax</td><td class="n">− {{ number_format((float) $line->tax_amount, 2) }}</td></tr>
  <tr><td>Social insurance (employee)</td><td class="n">− {{ number_format((float) $line->social_insurance_employee_amount, 2) }}</td></tr>
  <tr><td>Advance recovery</td><td class="n">− {{ number_format((float) $line->advance_deduction_amount, 2) }}</td></tr>
  <tr><td>Deductions / penalties</td><td class="n">− {{ number_format((float) $line->payout_penalty_total, 2) }}</td></tr>
  <tr class="tot"><td>Net pay</td><td class="n">{{ number_format((float) $line->gross_pay - (float) $line->payout_penalty_total - (float) $line->tax_amount - (float) $line->social_insurance_employee_amount - (float) $line->advance_deduction_amount, 2) }}</td></tr>
</table>
</body>
</html>
