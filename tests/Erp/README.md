# ERP business-logic regression suite

A suite that tests **business outcomes**, not HTTP status codes. Every test drives
the same services the controllers drive, in the same `create → activate → post`
sequence, and then asserts against figures calculated independently inside the
test — never against whatever the application happened to produce.

## Why it is separate from `phpunit.xml`

The default `phpunit.xml` points the test run at an in-memory **SQLite**
database. Only `pdo_mysql` is compiled into the PHP on this platform, so every
database-touching test under that config errors out before its first assertion.
That config is left untouched; this suite uses `phpunit.erp.xml`, which runs
against real MySQL — the same engine, collation and decimal types production
uses, which matters when the thing under test is money arithmetic.

## One-time setup

The schema is built **once** and reused. `migrate:fresh` currently fails part way
through on a clean database (migration `000166` requires the Chart of Accounts
seeder to have run first), so the order below matters:

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS vero_cfo_test \
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# Runs up to migration 000166 and stops there.
DB_DATABASE=vero_cfo_test php artisan migrate --force

# Seed the shared Chart of Accounts that 000166 depends on...
DB_DATABASE=vero_cfo_test php artisan db:seed --class=ChartOfAccountSeeder --force

# ...then finish the remaining migrations.
DB_DATABASE=vero_cfo_test php artisan migrate --force

# Currencies, units of measure, payment terms, sales channels, sectors, admin user.
DB_DATABASE=vero_cfo_test php artisan db:seed --force
```

## Running

Since Audit M-42 a plain `php artisan test` runs this suite (the default
`phpunit.xml` now points at the same MySQL test database). The stock Laravel
login/profile tests are left out of that run on purpose: they use
`RefreshDatabase`, which would wipe and rebuild `vero_cfo_test`.

```bash
php artisan test                                 # the whole suite
vendor/bin/phpunit -c phpunit.erp.xml            # same thing, older command (~20 seconds)
vendor/bin/phpunit -c phpunit.erp.xml --testdox  # readable list of behaviours
vendor/bin/phpunit -c phpunit.erp.xml --filter OrderToCashTest
```

Each test runs inside a transaction that is rolled back afterwards, so the
schema build is never repeated and tests cannot see each other's data.
`ErpTestCase` refuses to run at all against a database whose name does not
contain `test`.

## Layout

| File | What it covers |
| --- | --- |
| `ErpTestCase` | Base class: transaction isolation, safety guard, shared assertions |
| `Support/ErpScenario` | Arranges companies and drives real business cycles through real services |
| `Feature/Erp/InventoryCostingTest` | Weighted-average cost engine, receipts, issues, rounding |
| `Feature/Erp/PurchaseToPayTest` | PO → GRN → GRNI → supplier bill, VAT and withholding, reversal |
| `Feature/Erp/OrderToCashTest` | SO → delivery → invoice, revenue recognised exactly once |
| `Feature/Erp/JobOrderSegmentTest` | Manufacturing job-order cost segment and its divergences |
| `Feature/Erp/BalanceSheetEquityTest` | The accounting identity across a fiscal-year boundary |
| `Feature/Erp/ReversalReportingTest` | What reports show after a document is reopened |
| `Feature/Erp/PeriodControlTest` | Open/closed period enforcement on both write paths |
| `Feature/Erp/StockReversalEdgeCaseTest` | Value stranding and average inflation on receipt reversal |
| `Feature/Erp/TenantIsolationTest` | Cross-company guards in the stock ledger |
| `Feature/Erp/LeaveManagementTest` | Leave lifecycle, entitlement, overlap, the payroll seam |
| `Feature/Erp/ExpenseClaimTest` | Claim lifecycle and both journal entries it posts |
| `Feature/Erp/HrHttpEndpointTest` | The HR modules through their real routes, requests and tenant guards |
| `Feature/Erp/AuditM42PaymentsWhtTest` | Supplier payment net of WHT; customer collection with Debit WHT |
| `Feature/Erp/AuditM42PayrollTaxTest` | Payroll posting with salaries tax and social insurance; government balances |
| `Feature/Erp/AuditM42TenantHttpTest` | Company A opens every single-record page with company B's ids — all must be refused |
| `Feature/Erp/AuditM43StubPagesAndNotificationsTest` | "Not available yet" pages; real, per-user, per-company notifications |

## Reading a failure

Some tests deliberately assert **current, incorrect behaviour** and say so in
their name and message, tagged with an audit finding id (`ACC-001`, `MFG-001`,
and so on). They exist so that a defect cannot be fixed silently or regress
unnoticed. If one of those fails, the behaviour it pins has changed — check the
audit report for that finding before assuming the test is wrong.

Everything else asserts behaviour that **must** hold. If one of those fails,
something is broken.

## Assertion helpers

`assertMoneyEquals` compares at 2 decimal places, `assertUnitCostEquals` at 4,
and `assertQuantityEquals` at 4 — matching the column definitions. The
distinction is not cosmetic: a mutation that recalculated the weighted average
on every issue survived the suite until unit costs were compared at their real
precision.

`assertLedgerBalanced` asserts that every journal entry a company owns balances
to the cent. `glBalance`/`glBalanceById` sum **every** journal line; the
`reportedGlBalanceById` variant applies the `status = 'posted'` filter the
application's own reports use, so the two can be contrasted.

## Mutation testing

The suite was checked by deliberately breaking the application and confirming it
noticed. All seven mutations were detected:

| Mutation | Detected by |
| --- | --- |
| Weighted average ignores existing stock | 6 failures |
| Issues recalculate the weighted average | 1 failure |
| 10% cost leak when relieving stock | 7 failures |
| COGS posted to the inventory account | 5 failures |
| Job order skips its own segment write | 1 error |
| Invoice credits Revenue instead of Accrued Revenue | 3 failures |
| Closed-period guard disabled | 2 failures |
| Leave day count off by one | 10 failures |
| Leave overlap check disabled | 2 failures |
| Leave entitlement check disabled | 2 failures |
| Unpaid-leave filter drops its `is_paid` condition | 2 failures |
| Claim liability posted to the wrong account | 10 failures |
| Claim total converted once instead of per line | 1 error |
| Payment settles the claim currency, not the accrued amount | 1 failure |
| Claim approval allowed from any status | 2 failures |

All production files were restored and verified byte-identical afterwards.

Two of these initially **survived**, and both exposed a real weakness rather
than a false alarm:

- *Issues recalculate the weighted average* passed because `assertMoneyEquals`
  compares at two decimal places while unit costs are stored at four.
  `assertUnitCostEquals` was added and the mutation was caught.
- *Payment settles the claim currency* passed because every payment test ran at
  an exchange rate of 1, where the claim-currency and functional figures are
  identical. A foreign-currency payment test was added and the mutation was
  caught.

Without the mutation pass, both weaknesses would have sat in the suite looking
like coverage.
