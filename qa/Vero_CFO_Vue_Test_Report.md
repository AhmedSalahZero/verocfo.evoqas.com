# Vero CFO — Front-End Test Report

**Date:** 11 Oct 2026  |  **Tester:** signed-in check on the real app  |  **Scope:** all 252 Vue pages

**Company used:** NileTD, signed in as the company admin (Ahmed Salah).

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

Cause of #6: migration `2026_01_01_000320` has not been run on this database, so the payroll table has no `run_type` column. Same family as #2 and #3. I did not run the migration. If your server already has that column, Create Run should get past this error. Please try it once there.

**Checked and not a bug**
- Internal settlement: Save stays off until the customer side and the supplier side both equal the amount. An empty form cannot be saved.
- FX revaluation: Confirm and post stays off when there is nothing to revalue, and the red bar says so.
- EOSB provision: the form is already filled with this month, so Create Run does save a draft. One draft (October 2026, no lines, no journal) was created by this test and then deleted. It is not in the list anymore.

**Still not claimed**
- A brand-new invoice, collection or bill was not saved and then reopened. The numbering table `document_sequences` is still missing on this database (migration `000321`), so that save crashes before a document exists. The edit checks above used documents that were already in the database.

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

**Variance closing:** Preview for October 2026 stays on the list and says "No Payroll Run has been posted for 10/2026 yet". That is a clear refusal, not a crash. The preview screen itself does not open because there is no posted payroll.

**Still no saved document on this company, so these screens cannot be opened:** expense claim, fixed asset, job order, production order, payroll run, end-of-service run, leasing contract, bank reconciliation, and a manual journal. Other overdraft types (fully secured, commercial paper, contract assignment, LG, LC) have no agreement saved either. Password reset still needs an email link.

## 1. How the test was done

This is a different test from the 10 Oct report. That report opened pages against a **fake server**. This one opened them on the **real application and the real company database**.

- Signed in through the normal login page.
- Opened every screen address the application has (lists, create forms, and one saved document where this company has one).
- A page counts as **Opened OK** only when the screen came back without an error page.
- The three crashes below were then opened again in the browser and the error was read on screen.
- **This pass checks that the screen opens.** It does not repeat the deep form scenarios from 10 Oct (switching a customer quickly, negative numbers, double-click, phone width, Arabic) on all 252 pages. Those were not re-run here.

## 2. Summary

- Pages in the project: **252**
- Opened with no crash: **230**
- Not opened, because this company has no saved document for that step: **13**
- Old plan files the menu does not open (Create and Show for both plans): **4**. The live setup and step pages opened.
- Closed to this user (company screens, for the system owner only): **2**
- Pages with a confirmed problem: **3** (production create, stock adjustments, holidays)
- One extra address, not one of the 252 files, shows a blank page (issue 5)
- Issues: **6** — Major: 4, Minor: 2. Issue 6 is the payroll save, on a page that itself opens.

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

| # | Severity | Page / area | Problem |
|---|---|---|---|
| 1 | **Major** | Accounting/Manufacturing/ProductionOrderForm | Create Production Order crashes as soon as it opens |
| 2 | **Major** | Accounting/Inventory/StockAdjustmentIndex | Stock adjustment list crashes on this copy of the database |
| 3 | **Major** | Accounting/Leave/Holidays | Holiday calendar crashes on this copy of the database |
| 4 | **Minor** | Settings/Currencies (a spare address, not the screen) | One unused rate address crashes. The Currencies screen itself opens |
| 5 | **Minor** | Analytics/Expenses/Breakeven (file missing) | The address opens a blank page. It is not in the side menu |
| 6 | **Major** | Accounting/Payroll/PayrollRunCreate | Create Run returns to the form with no sentence. The server says the column `run_type` is missing |

## 4. Issue details

### #1 — Create Production Order crashes as soon as it opens  (Major)
- **Page / area:** Accounting → Manufacturing → Production Order → Create
- **How found:** run in the browser
- **Evidence / steps:** Opened the create page. The screen is an error, not the form. The list of production orders does open.
- **Likely cause:** The create page still asks the database for bills of materials marked "active" using an old column (`is_active`). That column was removed when bills of materials moved to Draft / Active / Superseded. This will crash on any database that has that change. This database has it.

### #2 — Stock adjustment list crashes on this copy of the database  (Major)
- **Page / area:** Accounting → Inventory → Stock adjustments
- **How found:** run in the browser
- **Evidence / steps:** Opened the list. Error: the table `stock_adjustment_requests` does not exist. The **create** form for a stock adjustment does open.
- **Likely cause:** Migration `2026_01_01_000312` has not been run on this database. I did not run it. If your own server already has this table, the list should open there. Please try the list once on your server before treating this as a screen bug.

### #3 — Holiday calendar crashes on this copy of the database  (Major)
- **Page / area:** HR → Leave → Holidays
- **How found:** run in the browser
- **Evidence / steps:** Opened the holiday page. Error: the table `company_holidays` does not exist. Leave requests and leave types do open.
- **Likely cause:** Migration `2026_01_01_000317` has not been run on this database. I did not run it. Same check as #2: try it on your server.

### #4 — A spare rate address crashes  (Minor)
- **Page / area:** Settings → Currencies
- **How found:** the screen was run in the browser; the crash was found by opening an address the screen does not use
- **Evidence / steps:** The Currencies page opens, shows EGP as the base currency, and the rate form. A separate address, `settings/currencies/{id}/rates`, returns an error because it calls a function that does not exist (`ratesForCurrency`). The history the screen actually uses is a different address, and that one answers normally.
- **Likely cause:** The screen was pointed at the working history function. The old address was left behind.

### #5 — Breakeven address is a blank page  (Minor)
- **Page / area:** Analytics → Expenses → Breakeven
- **How found:** run in the browser
- **Evidence / steps:** Opened `/analytics/expenses/breakeven`. The page is blank. There is no Vue file for it. The side menu does not list it; the menu note says the item will be added when the page exists. A person using the menu will not land here. Typing the address does.
- **Likely cause:** The server route was added before the screen.

### #6 — Create Payroll Run comes back with no explanation  (Major)
- **Page / area:** Accounting → Payroll → Create Run
- **How found:** run in the browser, then the server reply
- **Evidence / steps:** Filled a regular run for October 2026 and pressed Create Run. The button showed Saving, then the form came back with no red sentence and no new run. The server answer is "Unknown column run_type". No payroll run was saved.
- **Likely cause:** Migration `2026_01_01_000320` has not been run on this database. I did not run it. If your server already has that column, this save should not hit the same error. Please try one run on your server.

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
| Accounting/ExpenseClaims/Show | Not opened |  | No expense claim saved for this company. |
| Accounting/FixedAssetReceipts/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssetReceipts/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssets/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/FixedAssets/Manage | Not opened |  | The asset id tried was not a fixed asset of this company. The assets list opened. |
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
| Accounting/Inventory/StockAdjustmentForm | Opened OK |  | The create form opens. The list does not (#2). |
| Accounting/Inventory/StockAdjustmentIndex | ISSUES FOUND | Major | #2 List crashes on this copy of the database (stock adjustment table is missing) |
| Accounting/Inventory/StockBalanceIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockReservations/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockTransferForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Inventory/StockTransferIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/JournalEntries/Show | Not opened |  | This company has no manual journal. The view page is only for manual journals. The list and the new-entry form opened. |
| Accounting/LandingCosts/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/LandingCosts/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Form | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Holidays | ISSUES FOUND | Major | #3 Holiday calendar crashes on this copy of the database (holiday table is missing) |
| Accounting/Leave/Index | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Leave/Types | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/BomForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/BomIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/JobOrderShow | Not opened |  | No job order of this company could be opened. |
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
| Accounting/Manufacturing/ProductionOrderForm | ISSUES FOUND | Major | #1 Create page crashes: the screen asks for a column the database no longer has |
| Accounting/Manufacturing/ProductionOrderIndex | Opened OK |  | The list opens. Create does not (#1). |
| Accounting/Manufacturing/ProductionOrderShow | Not opened |  | No production order on this company, and Create crashes before one can be made. |
| Accounting/Manufacturing/StandardRateForm | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/StandardRateIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/VarianceClosingIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Manufacturing/VarianceClosingPreview | Not opened |  | Preview for October 2026 stays on the list and says no payroll run has been posted yet. The preview screen itself has nothing to show. |
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
| Accounting/Payroll/EosbProvisionRunShow | Not opened |  | No EOSB run of this company could be opened. |
| Accounting/Payroll/PayrollRunCreate | Opened OK | Major | The form opens. Pressing Create Run is issue #6: it comes back with no sentence because `run_type` is missing on this database. |
| Accounting/Payroll/PayrollRunIndex | Opened OK |  | Page opened while signed in. No crash. |
| Accounting/Payroll/PayrollRunShow | Not opened |  | No payroll run of this company could be opened. |
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
| Auth/ResetPassword | Not opened |  | Needs a real reset link from an email. Not requested. |
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
| FinancialInstitutions/Banks/Facilities/MtlScheduleIndex | Not opened |  | No medium-term loan schedule for the id that was tried. The loan list and the new-loan form opened. |
| FinancialInstitutions/Banks/Facilities/MtlSettlementForm | Not opened |  | No loan instalment of this company was opened. |
| FinancialInstitutions/Banks/Form | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Banks/Index | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/CashStatus | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/Forecast | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Dashboard/LgLcStatus | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/AgreementForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/AgreementsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/CompaniesIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Factoring/CompanyForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/AmortizationSchedule | Not opened |  | No leasing contract of this company was opened. The contract list and the new-contract form opened. |
| FinancialInstitutions/Leasing/CompaniesIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/CompanyForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/ContractForm | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Leasing/ContractsIndex | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Reconciliation/Index | Opened OK |  | Page opened while signed in. No crash. |
| FinancialInstitutions/Reconciliation/Reconcile | Not opened |  | No bank reconciliation of this company was opened. The reconciliation list opened. |
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
- These still have no saved document for NileTD, so the detail screen cannot be opened: expense claim, fixed asset, job order, production order, payroll run, end-of-service run, leasing contract, bank reconciliation, and a manual journal.
- Companies list and company form: this user is a company admin, and those screens correctly refuse him. They were not opened as the system owner.
- Password reset was not opened. It needs a real email link.
- On this database, migrations `000312` through `000326` are still pending. Besides #2 and #3, saving a **new** numbered document (collection, invoice, bill) crashed earlier today because the numbering table from `000321` is missing. I did not run those migrations, and I did not create a document in this pass. If your server already has them, saving there should not hit that crash.

## 7. Suggested next steps

1. Fix Production Order create (#1). It is a real code mismatch and will crash on an up-to-date database.
2. On your own server, open Stock adjustments, Leave holidays, and create one payroll run. If they work, #2, #3 and #6 are only this copy of the database (migrations `000312`, `000317` and `000320`).
3. Remove or repair the unused currency rate address (#4), and either add the Breakeven screen or remove its address (#5).
4. Tell me if you want the deep form scenarios (the 10 Oct style) run again on the real company, one area at a time. I did not change any code in this pass.
