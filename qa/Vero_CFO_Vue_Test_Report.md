# Vero CFO — Front-End Test Report

**Date:** 11 Oct 2026  |  **Tester:** signed-in check on the real app  |  **Scope:** all 252 Vue pages

**Company used:** NileTD, signed in as the company admin (Ahmed Salah).

## Fixed the same evening — retested in the browser

All 7 issues below are fixed on this copy of the app. Each one was opened again after the change.

| # | What was done | Retest |
|---|---|---|
| 1 | Production Order create now asks for an Active bill of materials, not the old column. | NileTD → New Production Order opened. Heading "New Production Order". No error. |
| 2 | Migration `000312` applied. The stock-adjustment table now exists. | Stock Adjustments list opened. Heading "Stock Adjustments". |
| 3 | Migration `000317` applied. The holiday table now exists. | Holidays opened. Heading "Weekly rest days & holidays". |
| 4 | The spare rate address now uses the same history the screen already used. | Currencies still opens. `settings/currencies/1/rates` answers `[]` instead of an error. |
| 5 | Breakeven is a real screen, and it is in the Analytics menu. | The page opened. Calculate returned NileTD's posted figures, including Cost of Goods Sold 953,485.00 (variable) and Office Rent 120,000.00 (fixed). |
| 6 | Migration `000320` applied (`run_type` and the payments table). | A regular October 2026 run was created on NileTD and the screen "October 2026 Payroll" opened. That draft had no employees and no journal, and it was then deleted. The saved January 2023 run on Cycle Test Co. A opened as "January 2023 Payroll". |
| 7 | Migration `000318` applied. A manual journal now stores its accounting period. | On Cycle Test Co. C, a 1.00 entry dated 15 Dec 2023 (debit 511, credit 512) was submitted, approved, and the view opened as "Manual Journal Entry JE/2026/10/00001". It was then set back to draft, which posted the opposite entry JE/2026/10/00002. The two cancel. Nothing is waiting on Approvals. |

Migrations `000312` through `000326` are now applied on this database. That also creates the document-numbering table (`000321`), so a new invoice, collection or bill is no longer blocked by a missing table. One chart change came with `000325`: account 227 is no longer a posting account, and account 241 (end of service) now sits under long-term liabilities.

## Latest pass — typing, validation, and edit (11 Oct 2026, afternoon)

This pass did what the morning pass did not. On each create screen the Save button was pressed with the form still empty. On screens that already have a saved document, the edit page was opened and the numbers were compared with the database.

**Validation, seen on screen**
- 63 create screens answered an empty Save with a sentence on the page and stayed on the form. Examples: customer invoice ("The Customer field is required." and "The lines field is required."), leave ("The employee field is required."), journal ("The Narration field is required."), bank ("The name en field is required." next to the name).
- Leave: a reason was typed ("QA keep this reason"), then Save was pressed with the employee and dates still empty. The four required-field sentences appeared, and the reason was still in the box.

**Edit, compared with the database**
- Customer invoice 244: screen date 13 Aug 2026, database the same.
- Customer collection 162: screen date 4 Aug 2026 and amount 38,640.75, database the same.
- Supplier bill 228: screen date 13 Aug 2026 and supplier invoice INV-E45823AF, database the same.
- Sales order 274: screen dates 10 Aug 2026, 10 Aug 2026 and 9 Sep 2026, database the same (issue, start, end).

**Confirmed problems added by this pass**

| # | Severity | Page | Problem | Basis |
|---|---|---|---|---|
| 6 | **Major** | Accounting/Payroll/PayrollRunCreate | Create Run does not create a run. The button shows Saving, then the form comes back with no explanation. The server answer is "Unknown column run_type". No payroll run was saved. | Browser click, then the server reply |

Cause of #6: migration `2026_01_01_000320` had not been run on this database, so the payroll table had no `run_type` column. It is applied now. See the retest at the top.

**Checked and not a bug**
- Internal settlement: Save stays off until the customer side and the supplier side both equal the amount. An empty form cannot be saved.
- FX revaluation: Confirm and post stays off when there is nothing to revalue, and the red bar says so.
- EOSB provision: the form is already filled with this month, so Create Run does save a draft. One draft (October 2026, no lines, no journal) was created by this test and then deleted. It is not in the list anymore.

**Still not claimed**
- A brand-new invoice, collection or bill was not saved and then reopened. The numbering table from migration `000321` is on this database now, but no new document was created in the retest. The edit checks above used documents that were already in the database.

## Continuation — the pages that had been left closed

These were the screens the first pass could not open because it did not have a saved plan or a chosen account. They were opened with NileTD's real records. None of them crashed, and the figures on the edit screens match the database.

**Plans (the company does have two drafts)**
- Annual plan AP-00001 "Year 2027 Annual Plan": setup, sales, cost of goods, manpower, expenses, fixed assets, opening balance, and results all opened. Setup shows 1 Jan 2027, tax 22.50% and manpower increase 15%, the same as the saved plan.
- Business plan BP-00001 "New 5 Yrs BP": the same eight steps opened. Setup shows 1 Jan 2027, tax 22.50%, WACC 15%, growth 3%, and multiplier 5, the same as the saved plan.

**Reports after a real choice**
- General ledger for account 143 Accrued Revenue.
- Bank statement for CIB current account 1009325396.
- Safe statement for 131001 Main Safe.
- Supplier invoices for Nasr City Wholesale Suppliers in EGP.
- Project inventory for sales order SO/2026/08/00003.

**More edit screens, compared with the database**
- Purchase order 220: 8 Aug 2026, 8 Aug 2026, 7 Sep 2026.
- Goods receipt 192 and delivery note 218: 13 Aug 2026.
- Supplier payment 247: 2 Sep 2026 and 359,738.33.
- Other payment 45: 21 Jul 2026 and 40,000.00.
- Other collection 52: 21 Aug 2026 and 2,000.00.
- Clean overdraft 29: limit 2,000,000.00, outstanding 76,791.98, borrowing rate 18%, margin 3%, review date 1 Jan 2027.
- Product 227: name Stretch Film Roll.

**Variance closing on NileTD:** Preview for October 2026 stays on the list and says "No Payroll Run has been posted for 10/2026 yet".

## Continuation — detail screens NileTD does not have

NileTD has no saved expense claim, fixed asset, job order, production order, payroll run, end-of-service run, leasing contract, loan schedule, or the other facility types. Those screens were opened on **Cycle Test Co. A (Manufacturing)**, a company that already has them. Nothing on those documents was saved, posted, or deleted. The system-owner sign-in was put back afterwards.

**Opened, title matches the saved document**
- Fixed asset FA-00001, Delivery Vehicle 1.
- Job order JOB/2026/09/00001.
- Production order PROD/2026/09/00001. The create page was fixed afterwards (#1) and opened on NileTD.
- End-of-service provision for December 2023.
- Leasing schedule for Delivery van.
- Loan schedule for Expansion Loan 2023, and the instalment payment screen.
- Saved agreements: letter of guarantee, letter of credit, commercial-paper overdraft, fully secured overdraft, and overdraft against contract assignment.
- Leave request edit, and an employee payroll profile edit.
- Variance preview for January 2023. The review screen opened. Nothing was posted.

**Payroll view.** Opening the saved January 2023 run used to crash because `payroll_run_payments` did not exist. After migration `000320` it opens. See the retest at the top.

**Bank reconciliation (NileTD).** The screen was opened by starting a draft on the CIB current account, closing balance 0.00, statement date 11 Oct 2026. The heading was the reconcile screen for that account. The draft was then deleted. The reconciliation list is empty again. No journal was posted.

**Reset password.** The form opened (email, new password, confirm). It was not submitted, so no password changed.

**Expense claim view.** Opened claim EC/2026/09/00001 on Cycle Test Co. A. The screen shows Quarterly travel, employee Karim Fouad, claim date 31 Mar 2023, total 1,200.00 EGP, status Paid, and the receipt line "Client visits". That matches the database. Nothing was saved or paid.

**Manual journal view.** An earlier temporary entry was removed. After migration `000318`, a real approval was retested: JE/2026/10/00001 posted and the view opened, then the cancelling entry JE/2026/10/00002 was posted. See the retest at the top.

## 1. How the test was done

This is a different test from the 10 Oct report. That report opened pages against a **fake server**. This one opened them on the **real application and the real company database**.

- Signed in through the normal login page.
- Opened every screen address the application has (lists, create forms, and one saved document where this company has one).
- A page counts as **Opened OK** only when the screen came back without an error page.
- The three crashes below were then opened again in the browser and the error was read on screen.
- **This pass checks that the screen opens.** It does not repeat the deep form scenarios from 10 Oct (switching a customer quickly, negative numbers, double-click, phone width, Arabic) on all 252 pages. Those were not re-run here.

## 2. Summary

- Pages in the project: **252**
- Opened with no crash: **246** (the 4 pages that crashed earlier opened after the fix)
- Not opened: **0**. Approving a manual journal was retested after the fix and the view opened (issue 7).
- Old plan files the menu does not open (Create and Show for both plans): **4**. The live setup and step pages opened.
- Closed to this user (company screens, for the system owner only): **2**
- Pages with a confirmed problem: **0** after the evening retest. The 7 issues in section 3 were fixed and opened again.
- Issues found, then fixed: **7** — Major: 5, Minor: 2.

**What worked well**
- Sign-in works, and the dashboard, settings, and almost every list and create form open.
- Currencies opens and shows EGP as the company base currency, with a fixed rate of 1.
- Customer balances → a customer's invoices opens (Cairo Mall, 5 invoices).
- Reports that used to crash on fake data (balance sheet, income statement, trial balance, dashboards) open on this company's real data.

**Already fixed earlier today** (not re-opened as new issues)
- A manual journal with equal amounts and no account no longer says "out of balance by 0.00".
- The leave day-count sentence is fully Arabic.
- An empty leave save says "employee" and "leave type" instead of "partner id" and "leave type id".

## 3. Issues found

| # | Severity | Page / area | Problem | Now |
|---|---|---|---|---|
| 1 | **Major** | Accounting/Manufacturing/ProductionOrderForm | Create Production Order crashed as soon as it opened | Fixed. Form opens. |
| 2 | **Major** | Accounting/Inventory/StockAdjustmentIndex | Stock adjustment list crashed on this copy of the database | Fixed. List opens. |
| 3 | **Major** | Accounting/Leave/Holidays | Holiday calendar crashed on this copy of the database | Fixed. Page opens. |
| 4 | **Minor** | Settings/Currencies (a spare address, not the screen) | One unused rate address crashed. The Currencies screen itself opened | Fixed. The address returns the rate history. |
| 5 | **Minor** | Analytics/Expenses/Breakeven | The address opened a blank page and was not in the menu | Fixed. Screen and menu item are there. |
| 6 | **Major** | Accounting/Payroll/PayrollRunCreate and PayrollRunShow | Create Run returned with no sentence. Opening a saved run crashed. | Fixed. Create and the saved run both open. |
| 7 | **Major** | Accounting/JournalEntries/Show | Approving a manual journal crashed, so the view never opened | Fixed. Approve posts, and the view opens. |

## 4. Issue details

### #1 — Create Production Order crashes as soon as it opens  (Major)
- **Page / area:** Accounting → Manufacturing → Production Order → Create
- **How found:** run in the browser
- **Evidence / steps:** Opened the create page. The screen is an error, not the form. The list of production orders does open.
- **Likely cause:** The create page still asks the database for bills of materials marked "active" using an old column (`is_active`). That column was removed when bills of materials moved to Draft / Active / Superseded. This will crash on any database that has that change. This database has it.
- **Fixed:** the page now uses Active status. Retested: New Production Order opened on NileTD.

### #2 — Stock adjustment list crashes on this copy of the database  (Major)
- **Page / area:** Accounting → Inventory → Stock adjustments
- **How found:** run in the browser
- **Evidence / steps:** Opened the list. Error: the table `stock_adjustment_requests` does not exist. The **create** form for a stock adjustment does open.
- **Likely cause:** Migration `2026_01_01_000312` had not been run on this database.
- **Fixed:** that migration is applied. Retested: the Stock Adjustments list opened.

### #3 — Holiday calendar crashes on this copy of the database  (Major)
- **Page / area:** HR → Leave → Holidays
- **How found:** run in the browser
- **Evidence / steps:** Opened the holiday page. Error: the table `company_holidays` does not exist. Leave requests and leave types do open.
- **Likely cause:** Migration `2026_01_01_000317` had not been run on this database.
- **Fixed:** that migration is applied. Retested: the holiday page opened.

### #4 — A spare rate address crashes  (Minor)
- **Page / area:** Settings → Currencies
- **How found:** the screen was run in the browser; the crash was found by opening an address the screen does not use
- **Evidence / steps:** The Currencies page opens, shows EGP as the base currency, and the rate form. A separate address, `settings/currencies/{id}/rates`, returns an error because it calls a function that does not exist (`ratesForCurrency`). The history the screen actually uses is a different address, and that one answers normally.
- **Likely cause:** The screen was pointed at the working history function. The old address was left behind.
- **Fixed:** the old address now calls that same history. Retested: it answers an empty list, not an error, and the Currencies screen still opens.

### #5 — Breakeven address is a blank page  (Minor)
- **Page / area:** Analytics → Expenses → Breakeven
- **How found:** run in the browser
- **Evidence / steps:** Opened `/analytics/expenses/breakeven`. The page is blank. There is no Vue file for it. The side menu does not list it; the menu note says the item will be added when the page exists. A person using the menu will not land here. Typing the address does.
- **Likely cause:** The server route was added before the screen.
- **Fixed:** the screen exists and is in the Analytics menu. Retested: Calculate returned NileTD's posted expenses, split into fixed and variable.

### #6 — Create Payroll Run comes back with no explanation  (Major)
- **Page / area:** Accounting → Payroll → Create Run
- **How found:** run in the browser, then the server reply
- **Evidence / steps:** Filled a regular run for October 2026 and pressed Create Run. The button showed Saving, then the form came back with no red sentence and no new run. The server answer is "Unknown column run_type". Separately, opening a saved January 2023 run on Cycle Test Co. A shows an error page: table `payroll_run_payments` does not exist.
- **Likely cause:** Migration `2026_01_01_000320` had not been run on this database. It adds both the `run_type` column and the payments table.
- **Fixed:** that migration is applied. Retested: October 2026 was created on NileTD and its screen opened, then that empty draft was deleted. January 2023 on Cycle Test Co. A opened.

### #7 — Approving a manual journal crashes before the view opens  (Major)
- **Page / area:** Accounting → Journal Entries → the view that opens after approval
- **How found:** run in the browser, then the server reply
- **Evidence / steps:** On Cycle Test Co. C, a balanced 1.00 entry dated 15 Dec 2023 was submitted (debit 511, credit 512). It showed on Approvals. Approve was not on the screen because the same person submitted it. Approving as the system owner returned an error page. The server says the column `accounting_period_id` does not exist. No journal row was saved. The waiting request was then cancelled. Separately, the view screen itself was opened with a temporary entry and then that entry was removed. The screen works. The normal approve path does not, on this database.
- **Likely cause:** Migration `2026_01_01_000318` had not been run on this database.
- **Fixed:** that migration is applied. Retested: JE/2026/10/00001 posted on Cycle Test Co. C and the view opened. Setting it back to draft posted the cancelling entry JE/2026/10/00002. The two net to zero.

## 5. Per-page results (all 252)

"Opened OK" means the screen came up with no error. It does not mean every calculation was checked.

| Page | Result | Severity | Note |
|---|---|---|---|
| Accounting/Accruals/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Accruals/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/AdvancesSettlements/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/AdvancesSettlements/Show | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Approvals/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/BalanceSheet/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CashExpensePayments/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CashExpensePayments/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CashFlowStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/ChequesPayable/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/ChequesReceivable/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CreditNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CreditNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerBalances/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerBalances/InvoiceReport | Opened OK |  | Opens when a customer is chosen (Cairo Mall, 5 invoices). The bare address with no customer says not found, which is expected. |
| Accounting/CustomerBalances/Statement | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerCredits/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerDebitNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerDebitNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerInvoices/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerInvoices/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerPayments/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/CustomerPayments/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Dashboard/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/DeliveryNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/DeliveryNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/ExpenseClaims/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/ExpenseClaims/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/ExpenseClaims/Show | Opened OK |  | Opened EC/2026/09/00001 on Cycle Test Co. A: Quarterly travel, 31 Mar 2023, 1,200.00 EGP, Paid. Matches the database. |
| Accounting/FixedAssetReceipts/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssetReceipts/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssets/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssets/Manage | Opened OK |  | Opened FA-00001 Delivery Vehicle 1 on Cycle Test Co. A. No crash. |
| Accounting/FxRevaluations/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FxRevaluations/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/GeneralLedger/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/GeneralLedger/Result | Opened OK |  | Opened for account 143 Accrued Revenue. No crash. |
| Accounting/GoodsReceiptNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/GoodsReceiptNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/GovernmentRemittance/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/IncomeStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/IncomeTax/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/InternalSettlements/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/InternalSettlements/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/CategoryValueStatement | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/InventoryStatement | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockAdjustmentForm | Opened OK |  | The create form opens. |
| Accounting/Inventory/StockAdjustmentIndex | Opened OK |  | #2 fixed. The list opened after migration 000312. |
| Accounting/Inventory/StockBalanceIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockReservations/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockTransferForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockTransferIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Show | Opened OK |  | #7 fixed. JE/2026/10/00001 opened after approval, then the cancelling entry JE/2026/10/00002 was posted. |
| Accounting/LandingCosts/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/LandingCosts/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Holidays | Opened OK |  | #3 fixed. The holiday page opened after migration 000317. |
| Accounting/Leave/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Types | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/BomForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/BomIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderShow | Opened OK |  | Opened JOB/2026/09/00001 on Cycle Test Co. A. No crash. |
| Accounting/Manufacturing/JobOrderTemplateForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderTemplateIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/KitAssemblyForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/KitAssemblyIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/KitRecipeForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/KitRecipeIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/LaborCategoryForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/LaborCategoryIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/MachineForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/MachineIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/OverheadPoolForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/OverheadPoolIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/ProcessForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/ProcessIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/ProductionOrderForm | Opened OK |  | #1 fixed. New Production Order opened on NileTD. |
| Accounting/Manufacturing/ProductionOrderIndex | Opened OK |  | The list opens. Create does not (#1). |
| Accounting/Manufacturing/ProductionOrderShow | Opened OK |  | Opened PROD/2026/09/00001 on Cycle Test Co. A. Create is still issue #1. |
| Accounting/Manufacturing/StandardRateForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/StandardRateIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/VarianceClosingIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/VarianceClosingPreview | Opened OK |  | Preview for January 2023 opened on Cycle Test Co. A. Nothing was posted. NileTD's October 2026 preview correctly refuses because no payroll is posted. |
| Accounting/OtherCollections/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/OtherCollections/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/OtherPayments/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/OtherPayments/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/Dashboard | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/DeductionReasonIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/EmployeeProfileForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/EmployeeProfileIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/EosbProvisionRunCreate | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/EosbProvisionRunIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/EosbProvisionRunShow | Opened OK |  | Opened the December 2023 provision on Cycle Test Co. A. No crash. |
| Accounting/Payroll/PayrollRunCreate | Opened OK |  | #6 fixed. An October 2026 draft was created and then deleted. |
| Accounting/Payroll/PayrollRunIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/PayrollRunShow | Opened OK |  | #6 fixed. January 2023 on Cycle Test Co. A opened, and the NileTD October draft opened before it was removed. |
| Accounting/PurchaseOrders/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/PurchaseOrders/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/PurchaseReturns/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/RevenueSchedules/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SalesReturns/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierBalances/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierBalances/InvoiceReport | Opened OK |  | Opened for Nasr City Wholesale Suppliers in EGP. No crash. |
| Accounting/SupplierBalances/Statement | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierBills/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierBills/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierCreditNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierCreditNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierDebitNotes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierDebitNotes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierPayments/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/SupplierPayments/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/TrialBalance/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/TrialBalance/Result | Opened OK |  | Page opened while signed in. No crash. |
| Analytics/Expenses/Dashboard | Opened OK |  | Page opened while signed in. No crash. |
| Analytics/Expenses/Reports | Opened OK |  | Page opened while signed in. No crash. |
| Analytics/Sales/Dashboard | Opened OK |  | Page opened while signed in. No crash. |
| Analytics/Sales/Reports | Opened OK |  | Page opened while signed in. No crash. |
| Auth/ConfirmPassword | Opened OK |  | Page opened while signed in. No crash. |
| Auth/ForgotPassword | Opened OK |  | While signed in, the address sends you to the dashboard. |
| Auth/Login | Opened OK |  | Sign-in with the company user succeeded. A later visit while already signed in goes to the dashboard. |
| Auth/ResetPassword | Opened OK |  | The form opened (email, new password, confirm). It was not submitted. |
| Auth/VerifyEmail | Opened OK |  | Page opened while signed in. No crash. |
| ComingSoon | Opened OK |  | Page opened while signed in. No crash. |
| Companies/Form | Closed to this user |  | Same as the companies list. |
| Companies/Index | Closed to this user |  | Company admin gets "not allowed". This screen is for the system owner. |
| Dashboard | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/AccountForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/AccountsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/CleanOverdraftForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/CleanOverdraftIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/CommercialPaperOverdraftIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/ContractAssignmentOdForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/ContractAssignmentOdIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/FacilitySettlements | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/FullySecuredOverdraftIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/LetterOfCreditForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/LetterOfCreditIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/LetterOfGuaranteeIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/MtlForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/MtlIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Facilities/MtlScheduleIndex | Opened OK |  | Opened Expansion Loan 2023 on Cycle Test Co. A. No crash. |
| FinancialInstitutions/Banks/Facilities/MtlSettlementForm | Opened OK |  | The instalment payment screen opened. Nothing was paid. |
| FinancialInstitutions/Banks/Form | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Index | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/CashStatus | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/Forecast | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/LgLcStatus | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/AgreementForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/AgreementsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/CompaniesIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/CompanyForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/AmortizationSchedule | Opened OK |  | Opened the Delivery van contract schedule on Cycle Test Co. A. No crash. |
| FinancialInstitutions/Leasing/CompaniesIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/CompanyForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/ContractForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/ContractsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Reconciliation/Index | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Reconciliation/Reconcile | Opened OK |  | Started a draft on NileTD's CIB account, saw the reconcile screen, then deleted the draft. The list is empty again. |
| FinancialInstitutions/Shared/InstitutionCompanyForm | Opened OK |  | Shared form inside Add Leasing Company and Add Factoring Company, both of which opened. |
| FinancialInstitutions/Treasury/BuySellCurrencyForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/BuySellCurrencyIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/FactoringWithRecourseForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/FactoringWithRecourseIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/FactoringWithoutRecourseForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/FactoringWithoutRecourseIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/InternalMoneyTransferForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/InternalMoneyTransferIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/LcIssuanceForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/LcIssuanceIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/LcSettlementsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/LgIssuanceForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/LgIssuanceIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/ReverseFactoringForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Treasury/ReverseFactoringIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/TreasurySettings/BorrowingRateIndex | Opened OK |  | Page opened while signed in. No crash. |
| Messaging/Inbox | Opened OK |  | Page opened while signed in. No crash. |
| Planning/AnnualPlan/Cogs | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/Create | Not its own screen |  | The menu opens Setup, which loaded the saved plan. This file is not what the server shows. |
| Planning/AnnualPlan/Expenses | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/FixedAssets | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/Index | Opened OK |  | Page opened while signed in. No crash. |
| Planning/AnnualPlan/Manpower | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/OpeningBalance | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/Results | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/Sales | Opened OK |  | Opened on plan AP-00001. No crash. |
| Planning/AnnualPlan/Setup | Opened OK |  | Edit of AP-00001 shows 1 Jan 2027, tax 22.50% and manpower increase 15%, matching the database. |
| Planning/AnnualPlan/Show | Not its own screen |  | The wizard steps above are the live screens. This file is not what the server shows. |
| Profile/Edit | Opened OK |  | Page opened while signed in. No crash. |
| Projects/Dashboard/ContractStatus | Opened OK |  | Page opened while signed in. No crash. |
| Projects/Form | Opened OK |  | Page opened while signed in. No crash. |
| Projects/Index | Opened OK |  | Page opened while signed in. No crash. |
| Projects/Inventory/Index | Opened OK |  | Page opened while signed in. No crash. |
| Projects/Inventory/Result | Opened OK |  | Opened for sales order SO/2026/08/00003. This company has no project, so the sales order was used. No crash. |
| Projects/SalesOrders/Dashboard | Opened OK |  | Page opened while signed in. No crash. |
| Projects/SalesOrders/Form | Opened OK |  | Page opened while signed in. No crash. |
| Projects/SalesOrders/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/ChartOfAccounts/CoaClassBlock | Opened OK |  | Not its own screen. It is the block inside Chart of Accounts, which opened. |
| Settings/ChartOfAccounts/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/ChartOfAccounts/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/CostCenters/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/CostCenters/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/Currencies/Index | Opened OK |  | Screen opens and shows EGP as the base currency. A spare web address for rate history crashes (#4); the screen itself does not use that address. |
| Settings/FiscalYears/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/FixedAssetTypes/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/FixedAssetTypes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/InventoryLocations/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/InventoryLocations/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/OpeningBalances/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/OpeningBalances/NotesTab | Opened OK |  | Tab inside Opening Balances, which opened. |
| Settings/OpeningBalances/PartnerInvoicesTab | Opened OK |  | Tab inside Opening Balances, which opened. |
| Settings/Partners/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/Partners/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/PaymentTerms/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/PaymentTerms/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/ProductCategories/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/ProductCategories/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/Products/Form | Opened OK |  | Page opened while signed in. No crash. |
| Settings/Products/Index | Opened OK |  | Page opened while signed in. No crash. |
| Settings/Taxes/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/BankStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/BankStatement/Result | Opened OK |  | Opened for CIB account 1009325396. No crash. |
| Statements/LcStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/LcStatement/Result | Opened OK |  | Page opened while signed in. No crash. |
| Statements/LgStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/LgStatement/Result | Opened OK |  | Page opened while signed in. No crash. |
| Statements/SafeStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/SafeStatement/Result | Opened OK |  | Opened for safe 131001 Main Safe. No crash. |
| Statements/WithdrawalStatement/Index | Opened OK |  | Page opened while signed in. No crash. |
| Statements/WithdrawalStatement/Result | Opened OK |  | Page opened while signed in. No crash. |
| Users/Form | Opened OK |  | Page opened while signed in. No crash. |
| Users/Index | Opened OK |  | Page opened while signed in. No crash. |
| Users/Permissions | Opened OK |  | Page opened while signed in. No crash. |
| Valuation/BusinessPlan/Cogs | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/Create | Not its own screen |  | The menu opens Setup, which loaded the saved plan. This file is not what the server shows. |
| Valuation/BusinessPlan/Expenses | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/FixedAssets | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/Index | Opened OK |  | Page opened while signed in. No crash. |
| Valuation/BusinessPlan/Manpower | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/OpeningBalance | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/Results | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/Sales | Opened OK |  | Opened on plan BP-00001. No crash. |
| Valuation/BusinessPlan/Setup | Opened OK |  | Edit of BP-00001 shows 1 Jan 2027, tax 22.50%, WACC 15%, growth 3% and multiplier 5, matching the database. |
| Valuation/BusinessPlan/Show | Not its own screen |  | The wizard steps above are the live screens. This file is not what the server shows. |

## 6. Not tested / limits

- Deep form behaviour (fast switching of customer, supplier, bank or currency; a failed save keeping what you typed; double-click; negative, zero and very large numbers; Arabic and phone width) was **not** repeated on all 252 pages in this pass. The 10 Oct report covered that against a fake server. This pass answers "does the screen open on NileTD?"
- The plan steps and the result pages (general ledger, bank statement, safe statement, supplier invoices, project inventory) were opened later with real records. See the continuation section.
- Approving a manual journal was retested after migration `000318`. JE/2026/10/00001 and its reversal JE/2026/10/00002 remain on Cycle Test Co. C and cancel each other.
- Companies list and company form: this user is a company admin, and those screens correctly refuse him. They were not opened as the system owner.
- Password reset was opened earlier and not submitted.
- Migrations `000312` through `000326` are applied on this database. A brand-new invoice was still not saved in this pass.

## 7. Suggested next steps

1. On your own server, run the same pending migrations (`000312` through `000326`) if they are not there yet. The screen fixes for production orders, the spare currency address, and Breakeven are in the code and need a normal deploy. Breakeven also needs the front end rebuilt.
2. The two cancelling journals on Cycle Test Co. C can stay. They net to zero.
3. The deep form scenarios (fast switching, negative numbers, Arabic, phone width) were still not repeated on all 252 pages.
