import { createI18n } from 'vue-i18n'

import en_analytics_salesAnalysis from './en/analytics/salesAnalysis'
import en_analytics_expenseAnalysis from './en/analytics/expenseAnalysis'
import en_dashboard from './en/dashboard'
import en_comingSoon from './en/comingSoon'
import en_projects_form from './en/projects/form'
import en_projects_index from './en/projects/index'
import en_projects_salesOrders from './en/projects/salesOrders'
import en_projects_dashboard from './en/projects/dashboard'
import en_projects_salesOrdersDashboard from './en/projects/salesOrdersDashboard'
import en_projects_inventory from './en/projects/inventory'
import en_messaging from './en/messaging'
import en_common from './en/common'
import en_financialInstitutions_factoring from './en/financialInstitutions/factoring'
import en_financialInstitutions_banks_accountsIndex from './en/financialInstitutions/banks_accountsIndex'
import en_financialInstitutions_banks_accountForm from './en/financialInstitutions/banks_accountForm'
import en_financialInstitutions_banks_form from './en/financialInstitutions/banks_form'
import en_financialInstitutions_banks_index from './en/financialInstitutions/banks_index'
import en_financialInstitutions_treasurySettings from './en/financialInstitutions/treasurySettings'
import en_financialInstitutions_treasury_buySellCurrencyIndex from './en/financialInstitutions/treasury_buySellCurrencyIndex'
import en_financialInstitutions_treasury_lcIssuanceIndex from './en/financialInstitutions/treasury_lcIssuanceIndex'
import en_financialInstitutions_treasury_internalMoneyTransferForm from './en/financialInstitutions/treasury_internalMoneyTransferForm'
import en_financialInstitutions_treasury_internalMoneyTransferIndex from './en/financialInstitutions/treasury_internalMoneyTransferIndex'
import en_financialInstitutions_treasury_buySellCurrencyForm from './en/financialInstitutions/treasury_buySellCurrencyForm'
import en_financialInstitutions_treasury_lgIssuanceForm from './en/financialInstitutions/treasury_lgIssuanceForm'
import en_financialInstitutions_treasury_factoringWithRecourseForm from './en/financialInstitutions/treasury_factoringWithRecourseForm'
import en_financialInstitutions_treasury_factoringWithRecourseIndex from './en/financialInstitutions/treasury_factoringWithRecourseIndex'
import en_financialInstitutions_treasury_factoringWithoutRecourseForm from './en/financialInstitutions/treasury_factoringWithoutRecourseForm'
import en_financialInstitutions_treasury_factoringWithoutRecourseIndex from './en/financialInstitutions/treasury_factoringWithoutRecourseIndex'
import en_financialInstitutions_treasury_reverseFactoringIndex from './en/financialInstitutions/treasury_reverseFactoringIndex'
import en_financialInstitutions_treasury_reverseFactoringForm from './en/financialInstitutions/treasury_reverseFactoringForm'
import en_financialInstitutions_reconciliation from './en/financialInstitutions/reconciliation'
import en_financialInstitutions_treasury_lcSettlementsIndex from './en/financialInstitutions/treasury_lcSettlementsIndex'
import en_financialInstitutions_treasury_lgIssuanceIndex from './en/financialInstitutions/treasury_lgIssuanceIndex'
import en_financialInstitutions_treasury_lcIssuanceForm from './en/financialInstitutions/treasury_lcIssuanceForm'
import en_financialInstitutions_leasing from './en/financialInstitutions/leasing'
import en_financialInstitutions_shared from './en/financialInstitutions/shared'
import en_financialInstitutions_banksFacilities_mtlForm from './en/financialInstitutions/banksFacilities_mtlForm'
import en_financialInstitutions_banksFacilities_letterOfCreditIndex from './en/financialInstitutions/banksFacilities_letterOfCreditIndex'
import en_financialInstitutions_banksFacilities_contractAssignmentOdIndex from './en/financialInstitutions/banksFacilities_contractAssignmentOdIndex'
import en_financialInstitutions_banksFacilities_letterOfGuaranteeForm from './en/financialInstitutions/banksFacilities_letterOfGuaranteeForm'
import en_financialInstitutions_banksFacilities_cleanOverdraftForm from './en/financialInstitutions/banksFacilities_cleanOverdraftForm'
import en_financialInstitutions_banksFacilities_fullySecuredOverdraftForm from './en/financialInstitutions/banksFacilities_fullySecuredOverdraftForm'
import en_financialInstitutions_banksFacilities_fullySecuredOverdraftIndex from './en/financialInstitutions/banksFacilities_fullySecuredOverdraftIndex'
import en_financialInstitutions_banksFacilities_commercialPaperOverdraftIndex from './en/financialInstitutions/banksFacilities_commercialPaperOverdraftIndex'
import en_financialInstitutions_banksFacilities_mtlScheduleIndex from './en/financialInstitutions/banksFacilities_mtlScheduleIndex'
import en_financialInstitutions_banksFacilities_contractAssignmentOdForm from './en/financialInstitutions/banksFacilities_contractAssignmentOdForm'
import en_financialInstitutions_banksFacilities_mtlIndex from './en/financialInstitutions/banksFacilities_mtlIndex'
import en_financialInstitutions_banksFacilities_cleanOverdraftIndex from './en/financialInstitutions/banksFacilities_cleanOverdraftIndex'
import en_financialInstitutions_banksFacilities_commercialPaperOverdraftForm from './en/financialInstitutions/banksFacilities_commercialPaperOverdraftForm'
import en_financialInstitutions_banksFacilities_letterOfCreditForm from './en/financialInstitutions/banksFacilities_letterOfCreditForm'
import en_financialInstitutions_banksFacilities_mtlSettlementForm from './en/financialInstitutions/banksFacilities_mtlSettlementForm'
import en_financialInstitutions_banksFacilities_letterOfGuaranteeIndex from './en/financialInstitutions/banksFacilities_letterOfGuaranteeIndex'
import en_companies from './en/companies'
import en_profile from './en/profile'
import en_auth from './en/auth'
import en_accounting_supplierBills from './en/accounting/supplierBills'
import en_accounting_supplierDebitNotes from './en/accounting/supplierDebitNotes'
import en_accounting_supplierCreditNotes from './en/accounting/supplierCreditNotes'
import en_accounting_chequesPayable from './en/accounting/chequesPayable'
import en_accounting_purchaseOrders from './en/accounting/purchaseOrders'
import en_accounting_creditNotes from './en/accounting/creditNotes'
import en_accounting_customerCredits from './en/accounting/customerCredits'
import en_accounting_customerBalances from './en/accounting/customerBalances'
import en_accounting_supplierBalances from './en/accounting/supplierBalances'
import en_accounting_internalSettlements from './en/accounting/internalSettlements'
import en_accounting_customerDebitNotes from './en/accounting/customerDebitNotes'
import en_accounting_supplierPayments from './en/accounting/supplierPayments'
import en_accounting_deliveryNotes from './en/accounting/deliveryNotes'

// HR — Leave Management and Expense Claims. These two files existed
// but were never registered here, so every t('accounting.hr.…') call
// on those pages fell through to the raw key and both screens showed
// dotted identifiers instead of text, in both languages.
import en_accounting_hrLeave from './en/accounting/hr_leave'
import ar_accounting_hrLeave from './ar/accounting/hr_leave'
import en_accounting_hrExpenseClaims from './en/accounting/hr_expenseClaims'
import ar_accounting_hrExpenseClaims from './ar/accounting/hr_expenseClaims'
import en_accounting_goodsReceiptNotes from './en/accounting/goodsReceiptNotes'
import en_accounting_landingCosts from './en/accounting/landingCosts'
import en_accounting_fixedAssetReceipts from './en/accounting/fixedAssetReceipts'
import en_accounting_fixedAssets from './en/accounting/fixedAssets'
import en_accounting_journalEntries from './en/accounting/journalEntries'
import en_accounting_approvals from './en/accounting/approvals'
import en_accounting_generalLedger from './en/accounting/generalLedger'
import en_accounting_trialBalance from './en/accounting/trialBalance'
import en_accounting_incomeStatement from './en/accounting/incomeStatement'
import en_accounting_dashboard from './en/accounting/dashboard'
import en_accounting_balanceSheet from './en/accounting/balanceSheet'
import en_accounting_cashFlowStatement from './en/accounting/cashFlowStatement'
import en_accounting_manufacturing_overheadPoolIndex from './en/accounting/manufacturing_overheadPoolIndex'
import en_accounting_manufacturing_laborCategoryIndex from './en/accounting/manufacturing_laborCategoryIndex'
import en_accounting_manufacturing_processIndex from './en/accounting/manufacturing_processIndex'
import en_accounting_manufacturing_machineIndex from './en/accounting/manufacturing_machineIndex'
import en_accounting_manufacturing_jobOrderIndex from './en/accounting/manufacturing_jobOrderIndex'
import en_accounting_manufacturing_varianceClosingIndex from './en/accounting/manufacturing_varianceClosingIndex'
import en_accounting_manufacturing_varianceClosingPreview from './en/accounting/manufacturing_varianceClosingPreview'
import en_accounting_manufacturing_jobOrderTemplateForm from './en/accounting/manufacturing_jobOrderTemplateForm'
import en_accounting_manufacturing_productionOrderShow from './en/accounting/manufacturing_productionOrderShow'
import en_accounting_manufacturing_overheadPoolForm from './en/accounting/manufacturing_overheadPoolForm'
import en_accounting_manufacturing_laborCategoryForm from './en/accounting/manufacturing_laborCategoryForm'
import en_accounting_manufacturing_processForm from './en/accounting/manufacturing_processForm'
import en_accounting_manufacturing_machineForm from './en/accounting/manufacturing_machineForm'
import en_accounting_manufacturing_jobOrderForm from './en/accounting/manufacturing_jobOrderForm'
import en_accounting_manufacturing_standardRateForm from './en/accounting/manufacturing_standardRateForm'
import en_accounting_manufacturing_jobOrderTemplateIndex from './en/accounting/manufacturing_jobOrderTemplateIndex'
import en_accounting_manufacturing_bomIndex from './en/accounting/manufacturing_bomIndex'
import en_accounting_manufacturing_productionOrderForm from './en/accounting/manufacturing_productionOrderForm'
import en_accounting_manufacturing_bomForm from './en/accounting/manufacturing_bomForm'
import en_accounting_manufacturing_kitRecipeIndex from './en/accounting/manufacturing_kitRecipeIndex'
import en_accounting_manufacturing_kitRecipeForm from './en/accounting/manufacturing_kitRecipeForm'
import en_accounting_manufacturing_kitAssemblyIndex from './en/accounting/manufacturing_kitAssemblyIndex'
import en_accounting_manufacturing_kitAssemblyForm from './en/accounting/manufacturing_kitAssemblyForm'
import en_accounting_manufacturing_jobOrderShow from './en/accounting/manufacturing_jobOrderShow'
import en_accounting_hr_employeeProfileIndex from './en/accounting/hr_employeeProfileIndex'
import en_accounting_hr_employeeProfileForm from './en/accounting/hr_employeeProfileForm'
import en_accounting_hr_deductionReasonIndex from './en/accounting/hr_deductionReasonIndex'
import en_accounting_hr_payrollRunIndex from './en/accounting/hr_payrollRunIndex'
import en_accounting_hr_dashboard from './en/accounting/hr_dashboard'
import en_accounting_hr_advancesSettlementsIndex from './en/accounting/hr_advancesSettlementsIndex'
import en_accounting_hr_advancesSettlementsShow from './en/accounting/hr_advancesSettlementsShow'
import en_accounting_hr_governmentRemittanceIndex from './en/accounting/hr_governmentRemittanceIndex'
import en_accounting_hr_eosbProvisionRunIndex from './en/accounting/hr_eosbProvisionRunIndex'
import en_accounting_hr_eosbProvisionRunCreate from './en/accounting/hr_eosbProvisionRunCreate'
import en_accounting_hr_eosbProvisionRunShow from './en/accounting/hr_eosbProvisionRunShow'
import en_accounting_hr_payrollRunCreate from './en/accounting/hr_payrollRunCreate'
import en_accounting_hr_payrollRunShow from './en/accounting/hr_payrollRunShow'
import en_accounting_manufacturing_productionOrderIndex from './en/accounting/manufacturing_productionOrderIndex'
import en_accounting_manufacturing_standardRateIndex from './en/accounting/manufacturing_standardRateIndex'
import en_accounting_customerPayments from './en/accounting/customerPayments'
import en_accounting_otherCollections from './en/accounting/otherCollections'
import en_accounting_otherPayments from './en/accounting/otherPayments'
import en_accounting_cashExpensePayments from './en/accounting/cashExpensePayments'
import en_accounting_customerInvoices from './en/accounting/customerInvoices'
import en_accounting_chequesReceivable from './en/accounting/chequesReceivable'
import en_accounting_accruals from './en/accounting/accruals'
import en_accounting_fxRevaluations from './en/accounting/fxRevaluations'
import en_accounting_incomeTax from './en/accounting/incomeTax'
import en_accounting_fixedAssetsManage from './en/accounting/fixedAssetsManage'
import en_accounting_revenueSchedules from './en/accounting/revenueSchedules'
import en_accounting_salesReturns from './en/accounting/salesReturns'
import en_accounting_purchaseReturns from './en/accounting/purchaseReturns'
import en_accounting_inventory_stockTransferForm from './en/accounting/inventory_stockTransferForm'
import en_accounting_inventory_stockBalanceIndex from './en/accounting/inventory_stockBalanceIndex'
import en_accounting_inventory_inventoryStatement from './en/accounting/inventory_inventoryStatement'
import en_accounting_inventory_stockAdjustmentForm from './en/accounting/inventory_stockAdjustmentForm'
import en_accounting_inventory_stockAdjustmentIndex from './en/accounting/inventory_stockAdjustmentIndex'
import en_accounting_inventory_categoryValueStatement from './en/accounting/inventory_categoryValueStatement'
import en_accounting_inventory_stockTransferIndex from './en/accounting/inventory_stockTransferIndex'
import en_accounting_inventory_stockReservations from './en/accounting/inventory_stockReservations'
import en_settings_currencies from './en/settings/currencies'
import en_settings_openingBalances from './en/settings/openingBalances'
import en_settings_taxes from './en/settings/taxes'
import en_settings_partners from './en/settings/partners'
import en_settings_productCategories from './en/settings/productCategories'
import en_settings_products from './en/settings/products'
import en_settings_fixedAssetTypes from './en/settings/fixedAssetTypes'
import en_settings_paymentTerms from './en/settings/paymentTerms'
import en_settings_costCenters from './en/settings/costCenters'
import en_settings_chartOfAccounts from './en/settings/chartOfAccounts'
import en_settings_inventoryLocations from './en/settings/inventoryLocations'
import en_settings_fiscalYears from './en/settings/fiscalYears'
import en_users_permissions from './en/users/permissions'
import en_users_form from './en/users/form'
import en_users_index from './en/users/index'
import en_layouts from './en/layouts'
import en_components from './en/components'
import en_financialInstitutions_banksFacilities_facilitySettlements from './en/financialInstitutions/banksFacilities_facilitySettlements'
import en_financialInstitutions_statements_bankStatementIndex from './en/financialInstitutions/statements_bankStatementIndex'
import en_financialInstitutions_statements_bankStatementResult from './en/financialInstitutions/statements_bankStatementResult'
import en_financialInstitutions_statements_safeStatementIndex from './en/financialInstitutions/statements_safeStatementIndex'
import en_financialInstitutions_statements_safeStatementResult from './en/financialInstitutions/statements_safeStatementResult'
import en_financialInstitutions_dashboard_common from './en/financialInstitutions/dashboard_common'
import en_financialInstitutions_dashboard_tabs from './en/financialInstitutions/dashboard_tabs'
import en_financialInstitutions_dashboard_cashStatus from './en/financialInstitutions/dashboard_cashStatus'
import en_financialInstitutions_dashboard_lgLcStatus from './en/financialInstitutions/dashboard_lgLcStatus'
import en_financialInstitutions_dashboard_forecast from './en/financialInstitutions/dashboard_forecast'
import en_financialInstitutions_statements_lgStatementIndex from './en/financialInstitutions/statements_lgStatementIndex'
import en_financialInstitutions_statements_lgStatementResult from './en/financialInstitutions/statements_lgStatementResult'
import en_financialInstitutions_statements_lcStatementIndex from './en/financialInstitutions/statements_lcStatementIndex'
import en_financialInstitutions_statements_lcStatementResult from './en/financialInstitutions/statements_lcStatementResult'
import en_financialInstitutions_statements_withdrawalStatementIndex from './en/financialInstitutions/statements_withdrawalStatementIndex'
import en_financialInstitutions_statements_withdrawalStatementResult from './en/financialInstitutions/statements_withdrawalStatementResult'
import en_planning_annualPlanIndex from './en/planning/annualPlanIndex'
import en_planning_annualPlanCreate from './en/planning/annualPlanCreate'
import en_planning_businessPlanIndex from './en/planning/businessPlanIndex'
import en_planning_businessPlanCreate from './en/planning/businessPlanCreate'
import en_planning_wizard from './en/planning/wizard'

import ar_analytics_salesAnalysis from './ar/analytics/salesAnalysis'
import ar_analytics_expenseAnalysis from './ar/analytics/expenseAnalysis'
import ar_dashboard from './ar/dashboard'
import ar_comingSoon from './ar/comingSoon'
import ar_projects_form from './ar/projects/form'
import ar_projects_index from './ar/projects/index'
import ar_projects_salesOrders from './ar/projects/salesOrders'
import ar_projects_dashboard from './ar/projects/dashboard'
import ar_projects_salesOrdersDashboard from './ar/projects/salesOrdersDashboard'
import ar_projects_inventory from './ar/projects/inventory'
import ar_messaging from './ar/messaging'
import ar_common from './ar/common'
import ar_financialInstitutions_factoring from './ar/financialInstitutions/factoring'
import ar_financialInstitutions_banks_accountsIndex from './ar/financialInstitutions/banks_accountsIndex'
import ar_financialInstitutions_banks_accountForm from './ar/financialInstitutions/banks_accountForm'
import ar_financialInstitutions_banks_form from './ar/financialInstitutions/banks_form'
import ar_financialInstitutions_banks_index from './ar/financialInstitutions/banks_index'
import ar_financialInstitutions_treasurySettings from './ar/financialInstitutions/treasurySettings'
import ar_financialInstitutions_treasury_buySellCurrencyIndex from './ar/financialInstitutions/treasury_buySellCurrencyIndex'
import ar_financialInstitutions_treasury_lcIssuanceIndex from './ar/financialInstitutions/treasury_lcIssuanceIndex'
import ar_financialInstitutions_treasury_internalMoneyTransferForm from './ar/financialInstitutions/treasury_internalMoneyTransferForm'
import ar_financialInstitutions_treasury_internalMoneyTransferIndex from './ar/financialInstitutions/treasury_internalMoneyTransferIndex'
import ar_financialInstitutions_treasury_buySellCurrencyForm from './ar/financialInstitutions/treasury_buySellCurrencyForm'
import ar_financialInstitutions_treasury_lgIssuanceForm from './ar/financialInstitutions/treasury_lgIssuanceForm'
import ar_financialInstitutions_treasury_factoringWithRecourseForm from './ar/financialInstitutions/treasury_factoringWithRecourseForm'
import ar_financialInstitutions_treasury_factoringWithRecourseIndex from './ar/financialInstitutions/treasury_factoringWithRecourseIndex'
import ar_financialInstitutions_treasury_factoringWithoutRecourseForm from './ar/financialInstitutions/treasury_factoringWithoutRecourseForm'
import ar_financialInstitutions_treasury_factoringWithoutRecourseIndex from './ar/financialInstitutions/treasury_factoringWithoutRecourseIndex'
import ar_financialInstitutions_treasury_reverseFactoringIndex from './ar/financialInstitutions/treasury_reverseFactoringIndex'
import ar_financialInstitutions_treasury_reverseFactoringForm from './ar/financialInstitutions/treasury_reverseFactoringForm'
import ar_financialInstitutions_reconciliation from './ar/financialInstitutions/reconciliation'
import ar_financialInstitutions_treasury_lcSettlementsIndex from './ar/financialInstitutions/treasury_lcSettlementsIndex'
import ar_financialInstitutions_treasury_lgIssuanceIndex from './ar/financialInstitutions/treasury_lgIssuanceIndex'
import ar_financialInstitutions_treasury_lcIssuanceForm from './ar/financialInstitutions/treasury_lcIssuanceForm'
import ar_financialInstitutions_leasing from './ar/financialInstitutions/leasing'
import ar_financialInstitutions_shared from './ar/financialInstitutions/shared'
import ar_financialInstitutions_banksFacilities_mtlForm from './ar/financialInstitutions/banksFacilities_mtlForm'
import ar_financialInstitutions_banksFacilities_letterOfCreditIndex from './ar/financialInstitutions/banksFacilities_letterOfCreditIndex'
import ar_financialInstitutions_banksFacilities_contractAssignmentOdIndex from './ar/financialInstitutions/banksFacilities_contractAssignmentOdIndex'
import ar_financialInstitutions_banksFacilities_letterOfGuaranteeForm from './ar/financialInstitutions/banksFacilities_letterOfGuaranteeForm'
import ar_financialInstitutions_banksFacilities_cleanOverdraftForm from './ar/financialInstitutions/banksFacilities_cleanOverdraftForm'
import ar_financialInstitutions_banksFacilities_fullySecuredOverdraftForm from './ar/financialInstitutions/banksFacilities_fullySecuredOverdraftForm'
import ar_financialInstitutions_banksFacilities_fullySecuredOverdraftIndex from './ar/financialInstitutions/banksFacilities_fullySecuredOverdraftIndex'
import ar_financialInstitutions_banksFacilities_commercialPaperOverdraftIndex from './ar/financialInstitutions/banksFacilities_commercialPaperOverdraftIndex'
import ar_financialInstitutions_banksFacilities_mtlScheduleIndex from './ar/financialInstitutions/banksFacilities_mtlScheduleIndex'
import ar_financialInstitutions_banksFacilities_contractAssignmentOdForm from './ar/financialInstitutions/banksFacilities_contractAssignmentOdForm'
import ar_financialInstitutions_banksFacilities_mtlIndex from './ar/financialInstitutions/banksFacilities_mtlIndex'
import ar_financialInstitutions_banksFacilities_cleanOverdraftIndex from './ar/financialInstitutions/banksFacilities_cleanOverdraftIndex'
import ar_financialInstitutions_banksFacilities_commercialPaperOverdraftForm from './ar/financialInstitutions/banksFacilities_commercialPaperOverdraftForm'
import ar_financialInstitutions_banksFacilities_letterOfCreditForm from './ar/financialInstitutions/banksFacilities_letterOfCreditForm'
import ar_financialInstitutions_banksFacilities_mtlSettlementForm from './ar/financialInstitutions/banksFacilities_mtlSettlementForm'
import ar_financialInstitutions_banksFacilities_letterOfGuaranteeIndex from './ar/financialInstitutions/banksFacilities_letterOfGuaranteeIndex'
import ar_companies from './ar/companies'
import ar_profile from './ar/profile'
import ar_auth from './ar/auth'
import ar_accounting_supplierBills from './ar/accounting/supplierBills'
import ar_accounting_supplierDebitNotes from './ar/accounting/supplierDebitNotes'
import ar_accounting_supplierCreditNotes from './ar/accounting/supplierCreditNotes'
import ar_accounting_chequesPayable from './ar/accounting/chequesPayable'
import ar_accounting_purchaseOrders from './ar/accounting/purchaseOrders'
import ar_accounting_creditNotes from './ar/accounting/creditNotes'
import ar_accounting_customerCredits from './ar/accounting/customerCredits'
import ar_accounting_customerBalances from './ar/accounting/customerBalances'
import ar_accounting_supplierBalances from './ar/accounting/supplierBalances'
import ar_accounting_internalSettlements from './ar/accounting/internalSettlements'
import ar_accounting_customerDebitNotes from './ar/accounting/customerDebitNotes'
import ar_accounting_supplierPayments from './ar/accounting/supplierPayments'
import ar_accounting_deliveryNotes from './ar/accounting/deliveryNotes'
import ar_accounting_goodsReceiptNotes from './ar/accounting/goodsReceiptNotes'
import ar_accounting_landingCosts from './ar/accounting/landingCosts'
import ar_accounting_fixedAssetReceipts from './ar/accounting/fixedAssetReceipts'
import ar_accounting_fixedAssets from './ar/accounting/fixedAssets'
import ar_accounting_journalEntries from './ar/accounting/journalEntries'
import ar_accounting_approvals from './ar/accounting/approvals'
import ar_accounting_generalLedger from './ar/accounting/generalLedger'
import ar_accounting_trialBalance from './ar/accounting/trialBalance'
import ar_accounting_incomeStatement from './ar/accounting/incomeStatement'
import ar_accounting_dashboard from './ar/accounting/dashboard'
import ar_accounting_balanceSheet from './ar/accounting/balanceSheet'
import ar_accounting_cashFlowStatement from './ar/accounting/cashFlowStatement'
import ar_accounting_manufacturing_overheadPoolIndex from './ar/accounting/manufacturing_overheadPoolIndex'
import ar_accounting_manufacturing_laborCategoryIndex from './ar/accounting/manufacturing_laborCategoryIndex'
import ar_accounting_manufacturing_processIndex from './ar/accounting/manufacturing_processIndex'
import ar_accounting_manufacturing_machineIndex from './ar/accounting/manufacturing_machineIndex'
import ar_accounting_manufacturing_jobOrderIndex from './ar/accounting/manufacturing_jobOrderIndex'
import ar_accounting_manufacturing_varianceClosingIndex from './ar/accounting/manufacturing_varianceClosingIndex'
import ar_accounting_manufacturing_varianceClosingPreview from './ar/accounting/manufacturing_varianceClosingPreview'
import ar_accounting_manufacturing_jobOrderTemplateForm from './ar/accounting/manufacturing_jobOrderTemplateForm'
import ar_accounting_manufacturing_productionOrderShow from './ar/accounting/manufacturing_productionOrderShow'
import ar_accounting_manufacturing_overheadPoolForm from './ar/accounting/manufacturing_overheadPoolForm'
import ar_accounting_manufacturing_laborCategoryForm from './ar/accounting/manufacturing_laborCategoryForm'
import ar_accounting_manufacturing_processForm from './ar/accounting/manufacturing_processForm'
import ar_accounting_manufacturing_machineForm from './ar/accounting/manufacturing_machineForm'
import ar_accounting_manufacturing_jobOrderForm from './ar/accounting/manufacturing_jobOrderForm'
import ar_accounting_manufacturing_standardRateForm from './ar/accounting/manufacturing_standardRateForm'
import ar_accounting_manufacturing_jobOrderTemplateIndex from './ar/accounting/manufacturing_jobOrderTemplateIndex'
import ar_accounting_manufacturing_bomIndex from './ar/accounting/manufacturing_bomIndex'
import ar_accounting_manufacturing_productionOrderForm from './ar/accounting/manufacturing_productionOrderForm'
import ar_accounting_manufacturing_bomForm from './ar/accounting/manufacturing_bomForm'
import ar_accounting_manufacturing_kitRecipeIndex from './ar/accounting/manufacturing_kitRecipeIndex'
import ar_accounting_manufacturing_kitRecipeForm from './ar/accounting/manufacturing_kitRecipeForm'
import ar_accounting_manufacturing_kitAssemblyIndex from './ar/accounting/manufacturing_kitAssemblyIndex'
import ar_accounting_manufacturing_kitAssemblyForm from './ar/accounting/manufacturing_kitAssemblyForm'
import ar_accounting_manufacturing_jobOrderShow from './ar/accounting/manufacturing_jobOrderShow'
import ar_accounting_hr_employeeProfileIndex from './ar/accounting/hr_employeeProfileIndex'
import ar_accounting_hr_employeeProfileForm from './ar/accounting/hr_employeeProfileForm'
import ar_accounting_hr_deductionReasonIndex from './ar/accounting/hr_deductionReasonIndex'
import ar_accounting_hr_payrollRunIndex from './ar/accounting/hr_payrollRunIndex'
import ar_accounting_hr_dashboard from './ar/accounting/hr_dashboard'
import ar_accounting_hr_advancesSettlementsIndex from './ar/accounting/hr_advancesSettlementsIndex'
import ar_accounting_hr_advancesSettlementsShow from './ar/accounting/hr_advancesSettlementsShow'
import ar_accounting_hr_governmentRemittanceIndex from './ar/accounting/hr_governmentRemittanceIndex'
import ar_accounting_hr_eosbProvisionRunIndex from './ar/accounting/hr_eosbProvisionRunIndex'
import ar_accounting_hr_eosbProvisionRunCreate from './ar/accounting/hr_eosbProvisionRunCreate'
import ar_accounting_hr_eosbProvisionRunShow from './ar/accounting/hr_eosbProvisionRunShow'
import ar_accounting_hr_payrollRunCreate from './ar/accounting/hr_payrollRunCreate'
import ar_accounting_hr_payrollRunShow from './ar/accounting/hr_payrollRunShow'
import ar_accounting_manufacturing_productionOrderIndex from './ar/accounting/manufacturing_productionOrderIndex'
import ar_accounting_manufacturing_standardRateIndex from './ar/accounting/manufacturing_standardRateIndex'
import ar_accounting_customerPayments from './ar/accounting/customerPayments'
import ar_accounting_otherCollections from './ar/accounting/otherCollections'
import ar_accounting_otherPayments from './ar/accounting/otherPayments'
import ar_accounting_cashExpensePayments from './ar/accounting/cashExpensePayments'
import ar_accounting_customerInvoices from './ar/accounting/customerInvoices'
import ar_accounting_chequesReceivable from './ar/accounting/chequesReceivable'
import ar_accounting_accruals from './ar/accounting/accruals'
import ar_accounting_fxRevaluations from './ar/accounting/fxRevaluations'
import ar_accounting_incomeTax from './ar/accounting/incomeTax'
import ar_accounting_fixedAssetsManage from './ar/accounting/fixedAssetsManage'
import ar_accounting_revenueSchedules from './ar/accounting/revenueSchedules'
import ar_accounting_salesReturns from './ar/accounting/salesReturns'
import ar_accounting_purchaseReturns from './ar/accounting/purchaseReturns'
import ar_accounting_inventory_stockTransferForm from './ar/accounting/inventory_stockTransferForm'
import ar_accounting_inventory_stockBalanceIndex from './ar/accounting/inventory_stockBalanceIndex'
import ar_accounting_inventory_inventoryStatement from './ar/accounting/inventory_inventoryStatement'
import ar_accounting_inventory_stockAdjustmentForm from './ar/accounting/inventory_stockAdjustmentForm'
import ar_accounting_inventory_stockAdjustmentIndex from './ar/accounting/inventory_stockAdjustmentIndex'
import ar_accounting_inventory_categoryValueStatement from './ar/accounting/inventory_categoryValueStatement'
import ar_accounting_inventory_stockTransferIndex from './ar/accounting/inventory_stockTransferIndex'
import ar_accounting_inventory_stockReservations from './ar/accounting/inventory_stockReservations'
import ar_settings_currencies from './ar/settings/currencies'
import ar_settings_openingBalances from './ar/settings/openingBalances'
import ar_settings_taxes from './ar/settings/taxes'
import ar_settings_partners from './ar/settings/partners'
import ar_settings_productCategories from './ar/settings/productCategories'
import ar_settings_products from './ar/settings/products'
import ar_settings_fixedAssetTypes from './ar/settings/fixedAssetTypes'
import ar_settings_paymentTerms from './ar/settings/paymentTerms'
import ar_settings_costCenters from './ar/settings/costCenters'
import ar_settings_chartOfAccounts from './ar/settings/chartOfAccounts'
import ar_settings_inventoryLocations from './ar/settings/inventoryLocations'
import ar_settings_fiscalYears from './ar/settings/fiscalYears'
import ar_users_permissions from './ar/users/permissions'
import ar_users_form from './ar/users/form'
import ar_users_index from './ar/users/index'
import ar_layouts from './ar/layouts'
import ar_components from './ar/components'
import ar_financialInstitutions_banksFacilities_facilitySettlements from './ar/financialInstitutions/banksFacilities_facilitySettlements'
import ar_financialInstitutions_statements_bankStatementIndex from './ar/financialInstitutions/statements_bankStatementIndex'
import ar_financialInstitutions_statements_bankStatementResult from './ar/financialInstitutions/statements_bankStatementResult'
import ar_financialInstitutions_statements_safeStatementIndex from './ar/financialInstitutions/statements_safeStatementIndex'
import ar_financialInstitutions_statements_safeStatementResult from './ar/financialInstitutions/statements_safeStatementResult'
import ar_financialInstitutions_dashboard_common from './ar/financialInstitutions/dashboard_common'
import ar_financialInstitutions_dashboard_tabs from './ar/financialInstitutions/dashboard_tabs'
import ar_financialInstitutions_dashboard_cashStatus from './ar/financialInstitutions/dashboard_cashStatus'
import ar_financialInstitutions_dashboard_lgLcStatus from './ar/financialInstitutions/dashboard_lgLcStatus'
import ar_financialInstitutions_dashboard_forecast from './ar/financialInstitutions/dashboard_forecast'
import ar_financialInstitutions_statements_lgStatementIndex from './ar/financialInstitutions/statements_lgStatementIndex'
import ar_financialInstitutions_statements_lgStatementResult from './ar/financialInstitutions/statements_lgStatementResult'
import ar_financialInstitutions_statements_lcStatementIndex from './ar/financialInstitutions/statements_lcStatementIndex'
import ar_financialInstitutions_statements_lcStatementResult from './ar/financialInstitutions/statements_lcStatementResult'
import ar_financialInstitutions_statements_withdrawalStatementIndex from './ar/financialInstitutions/statements_withdrawalStatementIndex'
import ar_financialInstitutions_statements_withdrawalStatementResult from './ar/financialInstitutions/statements_withdrawalStatementResult'
import ar_planning_annualPlanIndex from './ar/planning/annualPlanIndex'
import ar_planning_annualPlanCreate from './ar/planning/annualPlanCreate'
import ar_planning_businessPlanIndex from './ar/planning/businessPlanIndex'
import ar_planning_businessPlanCreate from './ar/planning/businessPlanCreate'
import ar_planning_wizard from './ar/planning/wizard'

// ── deepMerge — combines the per-page translation files into one tree ──
// Needed because several files under the same module (e.g. every page under
// financialInstitutions/banks/facilities/*) each contribute a different leaf
// of the same nested branch — a shallow merge would silently drop siblings.
function deepMerge(target, source) {
    for (const key of Object.keys(source)) {
        if (
            source[key] && typeof source[key] === 'object' && !Array.isArray(source[key]) &&
            target[key] && typeof target[key] === 'object' && !Array.isArray(target[key])
        ) {
            deepMerge(target[key], source[key])
        } else {
            target[key] = source[key]
        }
    }
    return target
}

const en = {}
const ar = {}
deepMerge(en, { analytics: en_analytics_salesAnalysis })
deepMerge(ar, { analytics: ar_analytics_salesAnalysis })
deepMerge(en, { analytics: en_analytics_expenseAnalysis })
deepMerge(ar, { analytics: ar_analytics_expenseAnalysis })
deepMerge(en, { dashboard: en_dashboard })
deepMerge(en, { comingSoon: en_comingSoon })
deepMerge(ar, { dashboard: ar_dashboard })
deepMerge(ar, { comingSoon: ar_comingSoon })
deepMerge(en, { projects: en_projects_form })
deepMerge(ar, { projects: ar_projects_form })
deepMerge(en, { projects: en_projects_index })
deepMerge(ar, { projects: ar_projects_index })
deepMerge(en, { projects: en_projects_salesOrders })
deepMerge(ar, { projects: ar_projects_salesOrders })
deepMerge(en, { projects: en_projects_dashboard })
deepMerge(ar, { projects: ar_projects_dashboard })
deepMerge(en, { projects: en_projects_salesOrdersDashboard })
deepMerge(ar, { projects: ar_projects_salesOrdersDashboard })
deepMerge(en, { projects: en_projects_inventory })
deepMerge(ar, { projects: ar_projects_inventory })
deepMerge(en, { messaging: en_messaging })
deepMerge(ar, { messaging: ar_messaging })
deepMerge(en, { common: en_common })
deepMerge(ar, { common: ar_common })
deepMerge(en, { financialInstitutions: en_financialInstitutions_factoring })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_factoring })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banks_accountsIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banks_accountsIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banks_accountForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banks_accountForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banks_form })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banks_form })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banks_index })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banks_index })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasurySettings })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasurySettings })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_buySellCurrencyIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_buySellCurrencyIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_lcIssuanceIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_lcIssuanceIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_internalMoneyTransferForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_internalMoneyTransferForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_internalMoneyTransferIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_internalMoneyTransferIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_buySellCurrencyForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_buySellCurrencyForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_lgIssuanceForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_lgIssuanceForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_factoringWithRecourseForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_factoringWithRecourseForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_factoringWithRecourseIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_factoringWithRecourseIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_factoringWithoutRecourseForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_factoringWithoutRecourseForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_factoringWithoutRecourseIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_factoringWithoutRecourseIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_reverseFactoringIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_reverseFactoringIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_reverseFactoringForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_reverseFactoringForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_reconciliation })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_reconciliation })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_lcSettlementsIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_lcSettlementsIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_lgIssuanceIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_lgIssuanceIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_treasury_lcIssuanceForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_treasury_lcIssuanceForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_leasing })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_leasing })
deepMerge(en, { financialInstitutions: en_financialInstitutions_shared })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_shared })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_mtlForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_mtlForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_letterOfCreditIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_letterOfCreditIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_contractAssignmentOdIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_contractAssignmentOdIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_letterOfGuaranteeForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_letterOfGuaranteeForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_cleanOverdraftForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_cleanOverdraftForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_fullySecuredOverdraftForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_fullySecuredOverdraftForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_fullySecuredOverdraftIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_fullySecuredOverdraftIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_commercialPaperOverdraftIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_commercialPaperOverdraftIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_mtlScheduleIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_mtlScheduleIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_contractAssignmentOdForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_contractAssignmentOdForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_mtlIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_mtlIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_cleanOverdraftIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_cleanOverdraftIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_commercialPaperOverdraftForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_commercialPaperOverdraftForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_letterOfCreditForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_letterOfCreditForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_mtlSettlementForm })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_mtlSettlementForm })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_letterOfGuaranteeIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_letterOfGuaranteeIndex })
deepMerge(en, { companies: en_companies })
deepMerge(ar, { companies: ar_companies })
deepMerge(en, { profile: en_profile })
deepMerge(ar, { profile: ar_profile })
deepMerge(en, { auth: en_auth })
deepMerge(ar, { auth: ar_auth })
deepMerge(en, { accounting: en_accounting_supplierBills })
deepMerge(ar, { accounting: ar_accounting_supplierBills })
deepMerge(en, { accounting: en_accounting_supplierDebitNotes })
deepMerge(ar, { accounting: ar_accounting_supplierDebitNotes })
deepMerge(en, { accounting: en_accounting_supplierCreditNotes })
deepMerge(ar, { accounting: ar_accounting_supplierCreditNotes })
deepMerge(en, { accounting: en_accounting_chequesPayable })
deepMerge(ar, { accounting: ar_accounting_chequesPayable })
deepMerge(en, { accounting: en_accounting_purchaseOrders })
deepMerge(ar, { accounting: ar_accounting_purchaseOrders })
deepMerge(en, { accounting: en_accounting_creditNotes })
deepMerge(ar, { accounting: ar_accounting_creditNotes })
deepMerge(en, { accounting: en_accounting_customerCredits })
deepMerge(ar, { accounting: ar_accounting_customerCredits })
deepMerge(en, { accounting: en_accounting_customerBalances })
deepMerge(ar, { accounting: ar_accounting_customerBalances })
deepMerge(en, { accounting: en_accounting_supplierBalances })
deepMerge(ar, { accounting: ar_accounting_supplierBalances })
deepMerge(en, { accounting: en_accounting_internalSettlements })
deepMerge(ar, { accounting: ar_accounting_internalSettlements })
deepMerge(en, { accounting: en_accounting_customerDebitNotes })
deepMerge(ar, { accounting: ar_accounting_customerDebitNotes })
deepMerge(en, { accounting: en_accounting_hrLeave })
deepMerge(ar, { accounting: ar_accounting_hrLeave })
deepMerge(en, { accounting: en_accounting_hrExpenseClaims })
deepMerge(ar, { accounting: ar_accounting_hrExpenseClaims })
deepMerge(en, { accounting: en_accounting_supplierPayments })
deepMerge(ar, { accounting: ar_accounting_supplierPayments })
deepMerge(en, { accounting: en_accounting_deliveryNotes })
deepMerge(ar, { accounting: ar_accounting_deliveryNotes })
deepMerge(en, { accounting: en_accounting_goodsReceiptNotes })
deepMerge(en, { accounting: en_accounting_landingCosts })
deepMerge(en, { accounting: en_accounting_fixedAssetReceipts })
deepMerge(en, { accounting: en_accounting_fixedAssets })
deepMerge(ar, { accounting: ar_accounting_goodsReceiptNotes })
deepMerge(ar, { accounting: ar_accounting_landingCosts })
deepMerge(ar, { accounting: ar_accounting_fixedAssetReceipts })
deepMerge(ar, { accounting: ar_accounting_fixedAssets })
deepMerge(en, { accounting: en_accounting_journalEntries })
deepMerge(ar, { accounting: ar_accounting_journalEntries })
deepMerge(en, { accounting: en_accounting_approvals })
deepMerge(ar, { accounting: ar_accounting_approvals })
deepMerge(en, { accounting: en_accounting_generalLedger })
deepMerge(ar, { accounting: ar_accounting_generalLedger })
deepMerge(en, { accounting: en_accounting_trialBalance })
deepMerge(ar, { accounting: ar_accounting_trialBalance })
deepMerge(en, { accounting: en_accounting_incomeStatement })
deepMerge(ar, { accounting: ar_accounting_incomeStatement })
deepMerge(en, { accounting: en_accounting_dashboard })
deepMerge(ar, { accounting: ar_accounting_dashboard })
deepMerge(en, { accounting: en_accounting_balanceSheet })
deepMerge(ar, { accounting: ar_accounting_balanceSheet })
deepMerge(en, { accounting: en_accounting_cashFlowStatement })
deepMerge(ar, { accounting: ar_accounting_cashFlowStatement })
deepMerge(en, { accounting: en_accounting_manufacturing_overheadPoolIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_overheadPoolIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_laborCategoryIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_laborCategoryIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_processIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_processIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_machineIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_machineIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_jobOrderIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_jobOrderIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_varianceClosingIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_varianceClosingIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_varianceClosingPreview })
deepMerge(ar, { accounting: ar_accounting_manufacturing_varianceClosingPreview })
deepMerge(en, { accounting: en_accounting_manufacturing_jobOrderTemplateForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_jobOrderTemplateForm })
deepMerge(en, { accounting: en_accounting_manufacturing_productionOrderShow })
deepMerge(ar, { accounting: ar_accounting_manufacturing_productionOrderShow })
deepMerge(en, { accounting: en_accounting_manufacturing_overheadPoolForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_overheadPoolForm })
deepMerge(en, { accounting: en_accounting_manufacturing_laborCategoryForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_laborCategoryForm })
deepMerge(en, { accounting: en_accounting_manufacturing_processForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_processForm })
deepMerge(en, { accounting: en_accounting_manufacturing_machineForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_machineForm })
deepMerge(en, { accounting: en_accounting_manufacturing_jobOrderForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_jobOrderForm })
deepMerge(en, { accounting: en_accounting_manufacturing_standardRateForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_standardRateForm })
deepMerge(en, { accounting: en_accounting_manufacturing_jobOrderTemplateIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_jobOrderTemplateIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_bomIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_bomIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_productionOrderForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_productionOrderForm })
deepMerge(en, { accounting: en_accounting_manufacturing_bomForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_bomForm })
deepMerge(en, { accounting: en_accounting_manufacturing_kitRecipeIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_kitRecipeIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_kitRecipeForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_kitRecipeForm })
deepMerge(en, { accounting: en_accounting_manufacturing_kitAssemblyIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_kitAssemblyIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_kitAssemblyForm })
deepMerge(ar, { accounting: ar_accounting_manufacturing_kitAssemblyForm })
deepMerge(en, { accounting: en_accounting_manufacturing_jobOrderShow })
deepMerge(ar, { accounting: ar_accounting_manufacturing_jobOrderShow })
deepMerge(en, { accounting: en_accounting_hr_employeeProfileIndex })
deepMerge(ar, { accounting: ar_accounting_hr_employeeProfileIndex })
deepMerge(en, { accounting: en_accounting_hr_employeeProfileForm })
deepMerge(ar, { accounting: ar_accounting_hr_employeeProfileForm })
deepMerge(en, { accounting: en_accounting_hr_deductionReasonIndex })
deepMerge(ar, { accounting: ar_accounting_hr_deductionReasonIndex })
deepMerge(en, { accounting: en_accounting_hr_payrollRunIndex })
deepMerge(ar, { accounting: ar_accounting_hr_payrollRunIndex })
deepMerge(en, { accounting: en_accounting_hr_dashboard })
deepMerge(ar, { accounting: ar_accounting_hr_dashboard })
deepMerge(en, { accounting: en_accounting_hr_advancesSettlementsIndex })
deepMerge(ar, { accounting: ar_accounting_hr_advancesSettlementsIndex })
deepMerge(en, { accounting: en_accounting_hr_advancesSettlementsShow })
deepMerge(ar, { accounting: ar_accounting_hr_advancesSettlementsShow })
deepMerge(en, { accounting: en_accounting_hr_governmentRemittanceIndex })
deepMerge(ar, { accounting: ar_accounting_hr_governmentRemittanceIndex })
deepMerge(en, { accounting: en_accounting_hr_eosbProvisionRunIndex })
deepMerge(ar, { accounting: ar_accounting_hr_eosbProvisionRunIndex })
deepMerge(en, { accounting: en_accounting_hr_eosbProvisionRunCreate })
deepMerge(ar, { accounting: ar_accounting_hr_eosbProvisionRunCreate })
deepMerge(en, { accounting: en_accounting_hr_eosbProvisionRunShow })
deepMerge(ar, { accounting: ar_accounting_hr_eosbProvisionRunShow })
deepMerge(en, { accounting: en_accounting_hr_payrollRunCreate })
deepMerge(ar, { accounting: ar_accounting_hr_payrollRunCreate })
deepMerge(en, { accounting: en_accounting_hr_payrollRunShow })
deepMerge(ar, { accounting: ar_accounting_hr_payrollRunShow })
deepMerge(en, { accounting: en_accounting_manufacturing_productionOrderIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_productionOrderIndex })
deepMerge(en, { accounting: en_accounting_manufacturing_standardRateIndex })
deepMerge(ar, { accounting: ar_accounting_manufacturing_standardRateIndex })
deepMerge(en, { accounting: en_accounting_customerPayments })
deepMerge(ar, { accounting: ar_accounting_customerPayments })
deepMerge(en, { accounting: en_accounting_otherCollections })
deepMerge(ar, { accounting: ar_accounting_otherCollections })
deepMerge(en, { accounting: en_accounting_otherPayments })
deepMerge(ar, { accounting: ar_accounting_otherPayments })
deepMerge(en, { accounting: en_accounting_cashExpensePayments })
deepMerge(ar, { accounting: ar_accounting_cashExpensePayments })
deepMerge(en, { accounting: en_accounting_customerInvoices })
deepMerge(ar, { accounting: ar_accounting_customerInvoices })
deepMerge(en, { accounting: en_accounting_chequesReceivable })
deepMerge(ar, { accounting: ar_accounting_chequesReceivable })
deepMerge(en, { accounting: en_accounting_accruals })
deepMerge(ar, { accounting: ar_accounting_accruals })
deepMerge(en, { accounting: en_accounting_fxRevaluations })
deepMerge(ar, { accounting: ar_accounting_fxRevaluations })
deepMerge(en, { accounting: en_accounting_incomeTax })
deepMerge(ar, { accounting: ar_accounting_incomeTax })
deepMerge(en, { accounting: en_accounting_fixedAssetsManage })
deepMerge(ar, { accounting: ar_accounting_fixedAssetsManage })
deepMerge(en, { accounting: en_accounting_revenueSchedules })
deepMerge(ar, { accounting: ar_accounting_revenueSchedules })
deepMerge(en, { accounting: en_accounting_salesReturns })
deepMerge(ar, { accounting: ar_accounting_salesReturns })
deepMerge(en, { accounting: en_accounting_purchaseReturns })
deepMerge(ar, { accounting: ar_accounting_purchaseReturns })
deepMerge(en, { accounting: en_accounting_inventory_stockTransferForm })
deepMerge(ar, { accounting: ar_accounting_inventory_stockTransferForm })
deepMerge(en, { accounting: en_accounting_inventory_stockBalanceIndex })
deepMerge(ar, { accounting: ar_accounting_inventory_stockBalanceIndex })
deepMerge(en, { accounting: en_accounting_inventory_inventoryStatement })
deepMerge(ar, { accounting: ar_accounting_inventory_inventoryStatement })
deepMerge(en, { accounting: en_accounting_inventory_stockAdjustmentForm })
deepMerge(ar, { accounting: ar_accounting_inventory_stockAdjustmentForm })
deepMerge(en, { accounting: en_accounting_inventory_stockAdjustmentIndex })
deepMerge(ar, { accounting: ar_accounting_inventory_stockAdjustmentIndex })
deepMerge(en, { accounting: en_accounting_inventory_categoryValueStatement })
deepMerge(ar, { accounting: ar_accounting_inventory_categoryValueStatement })
deepMerge(en, { accounting: en_accounting_inventory_stockTransferIndex })
deepMerge(ar, { accounting: ar_accounting_inventory_stockTransferIndex })
deepMerge(en, { accounting: en_accounting_inventory_stockReservations })
deepMerge(ar, { accounting: ar_accounting_inventory_stockReservations })
deepMerge(en, { settings: en_settings_currencies })
deepMerge(ar, { settings: ar_settings_currencies })
deepMerge(en, { settings: en_settings_openingBalances })
deepMerge(ar, { settings: ar_settings_openingBalances })
deepMerge(en, { settings: en_settings_taxes })
deepMerge(ar, { settings: ar_settings_taxes })
deepMerge(en, { settings: en_settings_partners })
deepMerge(ar, { settings: ar_settings_partners })
deepMerge(en, { settings: en_settings_productCategories })
deepMerge(ar, { settings: ar_settings_productCategories })
deepMerge(en, { settings: en_settings_products })
deepMerge(en, { settings: en_settings_fixedAssetTypes })
deepMerge(ar, { settings: ar_settings_products })
deepMerge(ar, { settings: ar_settings_fixedAssetTypes })
deepMerge(en, { settings: en_settings_paymentTerms })
deepMerge(ar, { settings: ar_settings_paymentTerms })
deepMerge(en, { settings: en_settings_costCenters })
deepMerge(ar, { settings: ar_settings_costCenters })
deepMerge(en, { settings: en_settings_chartOfAccounts })
deepMerge(ar, { settings: ar_settings_chartOfAccounts })
deepMerge(en, { settings: en_settings_inventoryLocations })
deepMerge(ar, { settings: ar_settings_inventoryLocations })
deepMerge(en, { settings: en_settings_fiscalYears })
deepMerge(ar, { settings: ar_settings_fiscalYears })
deepMerge(en, { users: en_users_permissions })
deepMerge(ar, { users: ar_users_permissions })
deepMerge(en, { users: en_users_form })
deepMerge(ar, { users: ar_users_form })
deepMerge(en, { users: en_users_index })
deepMerge(ar, { users: ar_users_index })
deepMerge(en, { layouts: en_layouts })
deepMerge(ar, { layouts: ar_layouts })
deepMerge(en, { components: en_components })
deepMerge(ar, { components: ar_components })
deepMerge(en, { financialInstitutions: en_financialInstitutions_banksFacilities_facilitySettlements })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_banksFacilities_facilitySettlements })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_bankStatementIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_bankStatementIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_bankStatementResult })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_bankStatementResult })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_safeStatementIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_safeStatementIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_safeStatementResult })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_safeStatementResult })
deepMerge(en, { financialInstitutions: en_financialInstitutions_dashboard_common })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_dashboard_common })
deepMerge(en, { financialInstitutions: en_financialInstitutions_dashboard_tabs })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_dashboard_tabs })
deepMerge(en, { financialInstitutions: en_financialInstitutions_dashboard_cashStatus })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_dashboard_cashStatus })
deepMerge(en, { financialInstitutions: en_financialInstitutions_dashboard_lgLcStatus })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_dashboard_lgLcStatus })
deepMerge(en, { financialInstitutions: en_financialInstitutions_dashboard_forecast })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_dashboard_forecast })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_lgStatementIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_lgStatementIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_lgStatementResult })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_lgStatementResult })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_lcStatementIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_lcStatementIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_lcStatementResult })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_lcStatementResult })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_withdrawalStatementIndex })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_withdrawalStatementIndex })
deepMerge(en, { financialInstitutions: en_financialInstitutions_statements_withdrawalStatementResult })
deepMerge(ar, { financialInstitutions: ar_financialInstitutions_statements_withdrawalStatementResult })
deepMerge(en, { planning: en_planning_annualPlanIndex })
deepMerge(ar, { planning: ar_planning_annualPlanIndex })
deepMerge(en, { planning: en_planning_annualPlanCreate })
deepMerge(ar, { planning: ar_planning_annualPlanCreate })
deepMerge(en, { planning: en_planning_businessPlanIndex })
deepMerge(ar, { planning: ar_planning_businessPlanIndex })
deepMerge(en, { planning: en_planning_businessPlanCreate })
deepMerge(ar, { planning: ar_planning_businessPlanCreate })
deepMerge(en, { planning: en_planning_wizard })
deepMerge(ar, { planning: ar_planning_wizard })

// ── Create i18n instance ──────────────────────────────────────
// Locale is driven by the backend (user.lang_pref via Inertia shared props).
// We read the HTML lang attribute as the initial value — set by AppLayout.
// Keys are namespaced (module.page.key), not raw English text — every locale
// including English is now an explicit, reviewable translation file.
const initialLocale = document.documentElement.getAttribute('lang') || 'en'

const i18n = createI18n({
    legacy:         false,
    locale:         initialLocale,
    fallbackLocale: 'en',
    messages:       { en, ar },
})

export default i18n

// ── setLocale — called when user clicks the language toggle ───
// Updates i18n + HTML attributes. The actual persistence is done
// via the backend route (lang.update) in the Vue components.
export function setLocale(locale) {
    i18n.global.locale.value = locale
    document.documentElement.setAttribute('dir',  locale === 'ar' ? 'rtl' : 'ltr')
    document.documentElement.setAttribute('lang', locale)
}
