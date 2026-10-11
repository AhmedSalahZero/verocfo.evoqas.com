<!--
══════════════════════════════════════════════════════════════════
  Projects/Dashboard/ContractStatus.vue

  Served by ContractDashboardController@index. Projects & Contracts
  > Contract Dashboard — full port of CashVero's own
  Dashboard/ContractStatus.vue: Contract Counts, Company Total,
  per-currency Contract Billing / Collections / Receivables Aging /
  Monthly Trend, Alerts, 4 Top-10 leaderboards, and Data Quality.

  No charts on this page — CashVero's own current design for this
  dashboard is entirely KPI-card-plus-table, confirmed by reading
  its actual Vue file, so nothing was simplified away here.

  "Contract" = VeroCFO's Project (contract_code / contract_value),
  rolled up through its Sales Orders' Customer Invoices — see
  ContractDashboardService's header for the full field mapping.

  Audit H14 (Sept 2026): posted invoices only; every per-currency
  figure is in the contract's own currency; the Data Quality
  "unbalanced invoices" table is in the main currency (labelled).

  @layout    AppLayout
  @route     projects.dashboard
  @props     see ContractDashboardService::build()
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">
      <PageHeader
        :title="t('projects.dashboard.dashboard')"
        :subtitle="t('projects.dashboard.subtitle')"
      />

      <div class="zv-card fid-filter-bar">
        <div class="field-group">
          <label class="zv-label">{{ t('common.startDate') }}</label>
          <input v-model="startDateModel" type="date" class="zv-input" style="width:160px;"/>
        </div>
        <div class="field-group">
          <label class="zv-label">{{ t('common.endDate') }}</label>
          <input v-model="endDateModel" type="date" class="zv-input" style="width:160px;"/>
        </div>
        <button class="zv-btn zv-btn-primary" @click="applyFilter">{{ t('common.apply') }}</button>
        <a :href="exportUrl" class="zv-btn zv-btn-secondary" style="margin-inline-start:auto;">{{ t('projects.dashboard.exportToExcel') }}</a>
      </div>

      <!-- Contract Counts -->
      <div class="fid-section-heading">{{ t('projects.dashboard.contractCounts') }}</div>
      <div class="fid-kpi-row-4">
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">Σ</div><div><p class="fid-kpi-label">{{ t('common.total') }}</p><p class="fid-kpi-value">{{ counts?.total ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">▶</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.active') }}</p><p class="fid-kpi-value fid-num-green">{{ counts?.active ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">⏸</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.onHold') }}</p><p class="fid-kpi-value fid-num-amber">{{ counts?.on_hold ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">⚠</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.expired') }}</p><p class="fid-kpi-value fid-num-amber">{{ counts?.expired ?? 0 }}</p></div></div>
      </div>
      <div class="fid-kpi-row-4">
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">■</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.closed') }}</p><p class="fid-kpi-value">{{ counts?.closed ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-red">✕</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.cancelled') }}</p><p class="fid-kpi-value fid-num-red">{{ counts?.cancelled ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">◌</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.notInvoiced') }}</p><p class="fid-kpi-value fid-num-amber">{{ counts?.not_invoiced ?? 0 }}</p></div></div>
        <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-red">⚠</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.overBilled') }}</p><p class="fid-kpi-value fid-num-red">{{ counts?.over_billed ?? 0 }}</p></div></div>
      </div>

      <!-- Company Total -->
      <template v-if="currencies?.length">
        <div class="fid-section-heading">{{ t('projects.dashboard.companyTotal') }} [{{ mainCurrency }}]</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">Σ</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.contractValue') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(mainCurrencyTotals?.value) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">⬆</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.invoiced') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(mainCurrencyTotals?.invoiced) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">✅</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.collected') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(mainCurrencyTotals?.collected) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">💳</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.uncollected') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(mainCurrencyTotals?.uncollected) }}</p></div></div>
        </div>
        <p class="fid-muted" style="margin-bottom:1rem;">
          {{ t('projects.dashboard.invoiceFiguresNote') }}
          <span v-if="mainCurrencyTotals?.value_unconvertible_count" class="fid-num-amber">
            ⚠ {{ t('projects.dashboard.excludesContracts', { count: mainCurrencyTotals.value_unconvertible_count }) }}
          </span>
        </p>

        <div class="fid-pill-row">
          <button v-for="currency in currencies" :key="currency" class="fid-pill" :class="{ 'fid-pill-active': activeCurrency === currency }" @click="activeCurrency = currency">{{ currency }}</button>
        </div>
      </template>
      <p v-else class="fid-muted">{{ t('projects.dashboard.noContractsYet') }}</p>

      <template v-if="activeCurrency">
        <!-- Contract Billing -->
        <div class="fid-section-heading">{{ t('projects.dashboard.contractBilling') }} [{{ activeCurrency }}]</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleDetail('value')"><div class="fid-kpi-icon fid-icon-blue">📄</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.contractValue') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(currencyKpis.value) }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleDetail('invoiced')"><div class="fid-kpi-icon fid-icon-green">⬆</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.invoiced') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(currencyKpis.invoiced) }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleDetail('remaining')"><div class="fid-kpi-icon fid-icon-amber">⏳</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.remainingToInvoice') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(currencyKpis.remaining) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">%</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.utilization') }}</p><p class="fid-kpi-value">{{ fmtPct(currencyKpis.utilization) }}</p></div></div>
        </div>

        <div v-if="['value','invoiced','remaining'].includes(openDetail)" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ detailTitle }}</h3><button class="fid-close-btn" @click="openDetail = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.customer') }}</th><th>{{ t('projects.dashboard.code') }}</th><th>{{ t('projects.dashboard.name') }}</th><th class="fid-th-num">{{ t('projects.dashboard.value') }}</th><th class="fid-th-num">{{ t('projects.dashboard.invoiced') }}</th><th class="fid-th-num">{{ t('projects.dashboard.remaining') }}</th><th>{{ t('projects.dashboard.endDate') }}</th><th>{{ t('projects.dashboard.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in detailRows" :key="'ccy-'+row.id">
                <td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-muted">{{ row.name }}</td>
                <td class="fid-num">{{ fmt(row.amount) }}</td><td class="fid-num">{{ fmt(row.invoiced) }}</td><td class="fid-num">{{ fmt(row.remaining) }}</td>
                <td class="fid-muted" style="text-align:center;">{{ row.end_date || '—' }}</td><td class="fid-muted" style="text-align:center;">{{ row.status_label }}</td>
              </tr>
              <tr v-if="!detailRows.length"><td colspan="8" class="fid-muted" style="text-align:center;">{{ t('projects.dashboard.noContractsInCurrency') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Collections -->
        <div class="fid-section-heading">{{ t('projects.dashboard.collections') }} [{{ activeCurrency }}{{ t('projects.dashboard.includingTax') }}</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">🧾</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.billedInclTax') }}</p><p class="fid-kpi-value fid-num-blue">{{ fmt(currencyKpis.billed) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-green">✅</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.collected') }}</p><p class="fid-kpi-value fid-num-green">{{ fmt(currencyKpis.collected) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-amber">💳</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.uncollectedAr') }}</p><p class="fid-kpi-value fid-num-amber">{{ fmt(currencyKpis.uncollected) }}</p></div></div>
          <div class="fid-kpi-card"><div class="fid-kpi-icon fid-icon-blue">%</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.collectionRate') }}</p><p class="fid-kpi-value">{{ fmtPct(currencyKpis.collection_rate) }}</p></div></div>
        </div>
        <p class="fid-muted" style="margin-bottom:1rem;">
          {{ t('projects.dashboard.reconciliationNote', { withheld: fmt(currencyKpis.withheld), deductions: fmt(currencyKpis.deductions) }) }}
          <span v-if="Math.abs(Number(currencyKpis.reconciliation_gap || 0)) > 0.01" class="fid-num-amber">
            ⚠ {{ t('projects.dashboard.offBy', { amount: fmt(currencyKpis.reconciliation_gap) }) }}
          </span>
        </p>

        <!-- Alerts -->
        <div class="fid-section-heading">{{ t('projects.dashboard.alerts') }}</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleAlert('past_end_date')"><div class="fid-kpi-icon fid-icon-amber">⚠</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.pastEndDate') }}</p><p class="fid-kpi-value fid-num-amber">{{ alerts?.past_end_date_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleAlert('ending_soon')"><div class="fid-kpi-icon fid-icon-blue">📅</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.endingWithin', { days: nearExpiryDays }) }}</p><p class="fid-kpi-value fid-num-blue">{{ alerts?.ending_soon_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleAlert('not_invoiced')"><div class="fid-kpi-icon fid-icon-blue">◌</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.noInvoicesRaised') }}</p><p class="fid-kpi-value">{{ alerts?.not_invoiced_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleAlert('over_billed')"><div class="fid-kpi-icon fid-icon-amber">⚠</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.overBilled') }}</p><p class="fid-kpi-value fid-num-amber">{{ alerts?.over_billed_count ?? 0 }}</p></div></div>
        </div>

        <div v-if="openAlert" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ alertTitle }}</h3><button class="fid-close-btn" @click="openAlert = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.customer') }}</th><th>{{ t('projects.dashboard.code') }}</th><th>{{ t('common.currency') }}</th><th class="fid-th-num">{{ t('projects.dashboard.value') }}</th><th class="fid-th-num">{{ t('projects.dashboard.remaining') }}</th><th>{{ t('projects.dashboard.invoices') }}</th><th>{{ t('projects.dashboard.endDate') }}</th><th>{{ t('projects.dashboard.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in alertRows" :key="'alert-'+row.id">
                <td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-muted" style="text-align:center;">{{ row.currency }}</td>
                <td class="fid-num">{{ fmt(row.amount) }}</td><td class="fid-num">{{ fmt(row.remaining) }}</td>
                <td class="fid-muted" style="text-align:center;">{{ row.invoice_count }}</td><td class="fid-muted" style="text-align:center;">{{ row.end_date || '—' }}</td><td class="fid-muted" style="text-align:center;">{{ row.status_label }}</td>
              </tr>
              <tr v-if="!alertRows.length"><td colspan="8" class="fid-muted" style="text-align:center;">{{ t('projects.dashboard.noContractsInAlert') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Top 10 (row 1) -->
        <div class="fid-chart-grid-2">
          <div class="zv-card fid-table-card">
            <div class="fid-pastdue-header">{{ t('projects.dashboard.top10ByValue') }} [{{ activeCurrency }}]</div>
            <table class="zv-table zv-table-sm">
              <tbody>
                <tr v-for="(row, i) in (topByValue?.[activeCurrency] || [])" :key="'v-'+row.id">
                  <td class="fid-muted">{{ i+1 }}</td><td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-num fid-num-blue">{{ fmt(row.amount) }}</td>
                </tr>
                <tr v-if="!(topByValue?.[activeCurrency] || []).length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div class="zv-card fid-table-card">
            <div class="fid-pastdue-header">{{ t('projects.dashboard.top10ByRemaining') }} [{{ activeCurrency }}]</div>
            <table class="zv-table zv-table-sm">
              <tbody>
                <tr v-for="(row, i) in (topByRemaining?.[activeCurrency] || [])" :key="'r-'+row.id">
                  <td class="fid-muted">{{ i+1 }}</td><td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-num fid-num-amber">{{ fmt(row.remaining) }}</td>
                </tr>
                <tr v-if="!(topByRemaining?.[activeCurrency] || []).length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Receivables Aging -->
        <div class="fid-section-heading">{{ t('projects.dashboard.receivablesAging') }} [{{ activeCurrency }}] — {{ t('projects.dashboard.asOf') }} {{ endDateFormatted }}</div>
        <div class="zv-card fid-table-card">
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.bucket') }}</th><th style="text-align:center;">{{ t('projects.dashboard.invoices') }}</th><th class="fid-th-num">{{ t('projects.dashboard.uncollected') }}</th><th style="width:35%;">{{ t('projects.dashboard.share') }}</th></tr></thead>
            <tbody>
              <tr v-for="bucket in agingBucketKeys" :key="'ag-'+bucket">
                <td>{{ agingBuckets[bucket] }}</td>
                <td class="fid-muted" style="text-align:center;">{{ agingRow?.[bucket]?.invoice_count ?? 0 }}</td>
                <td class="fid-num" :class="bucket === 'not_due' ? 'fid-num-green' : 'fid-num-amber'">{{ fmt(agingRow?.[bucket]?.amount) }}</td>
                <td><div class="fid-bar-track"><div class="fid-bar-fill" :class="bucket === 'not_due' ? 'fid-bar-green' : 'fid-bar-amber'" :style="{ width: agingShare(bucket) + '%' }"></div></div></td>
              </tr>
              <tr class="fid-summary-row">
                <td>{{ t('projects.dashboard.totalUncollected') }}</td>
                <td style="text-align:center;">{{ agingRow?.total?.invoice_count ?? 0 }}</td>
                <td class="fid-num">{{ fmt(agingRow?.total?.amount) }}</td>
                <td class="fid-muted" style="font-size:11px;">{{ t('projects.dashboard.overdue') }}: {{ fmt(agingRow?.overdue_total?.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Monthly Trend -->
        <div class="fid-section-heading">{{ t('projects.dashboard.monthlyTrend') }} [{{ activeCurrency }}] — {{ startDateFormatted }} → {{ endDateFormatted }}</div>
        <div class="zv-card fid-table-card">
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.month') }}</th><th class="fid-th-num">{{ t('projects.dashboard.invoiced') }}</th><th class="fid-th-num">{{ t('projects.dashboard.collected') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in (trend?.[activeCurrency] || [])" :key="row.month">
                <td>{{ row.month }}</td><td class="fid-num fid-num-blue">{{ fmt(row.invoiced) }}</td><td class="fid-num fid-num-green">{{ fmt(row.collected) }}</td>
              </tr>
              <tr v-if="!(trend?.[activeCurrency] || []).length"><td colspan="3" class="fid-muted" style="text-align:center;">{{ t('projects.dashboard.noActivityInPeriod') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Top 10 (row 2) -->
        <div class="fid-chart-grid-2">
          <div class="zv-card fid-table-card">
            <div class="fid-pastdue-header">{{ t('projects.dashboard.top10ByCount') }} [{{ activeCurrency }}]</div>
            <table class="zv-table zv-table-sm">
              <tbody>
                <tr v-for="(row, i) in (topByCount?.[activeCurrency] || [])" :key="'c-'+i">
                  <td class="fid-muted">{{ i+1 }}</td><td>{{ row.customer_name }}</td><td class="fid-num">{{ row.contract_count }}</td>
                </tr>
                <tr v-if="!(topByCount?.[activeCurrency] || []).length"><td colspan="3" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div class="zv-card fid-table-card">
            <div class="fid-pastdue-header">{{ t('projects.dashboard.top10ByUncollected') }} [{{ activeCurrency }}]</div>
            <table class="zv-table zv-table-sm">
              <tbody>
                <tr v-for="(row, i) in (topByUncollected?.[activeCurrency] || [])" :key="'u-'+row.id">
                  <td class="fid-muted">{{ i+1 }}</td><td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-num fid-num-amber">{{ fmt(row.uncollected) }}</td>
                </tr>
                <tr v-if="!(topByUncollected?.[activeCurrency] || []).length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Data Quality -->
        <div class="fid-section-heading">{{ t('projects.dashboard.dataQuality') }}</div>
        <div class="fid-kpi-row-4">
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleQuality('mismatch')"><div class="fid-kpi-icon fid-icon-amber">⇄</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.invoicesAnotherCurrency') }}</p><p class="fid-kpi-value fid-num-amber">{{ dataQuality?.mismatched_currency_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleQuality('rate')"><div class="fid-kpi-icon fid-icon-amber">💱</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.noUsableRate') }}</p><p class="fid-kpi-value fid-num-amber">{{ dataQuality?.exchange_rate_issue_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleQuality('unbalanced')"><div class="fid-kpi-icon fid-icon-amber">≠</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.invoicesDontAddUp') }}</p><p class="fid-kpi-value fid-num-amber">{{ dataQuality?.unbalanced_invoice_count ?? 0 }}</p></div></div>
          <div class="fid-kpi-card fid-kpi-card-clickable" @click="toggleQuality('nocode')"><div class="fid-kpi-icon fid-icon-amber">#</div><div><p class="fid-kpi-label">{{ t('projects.dashboard.contractsWithoutCode') }}</p><p class="fid-kpi-value fid-num-amber">{{ dataQuality?.contracts_without_code_count ?? 0 }}</p></div></div>
        </div>

        <div v-if="openQuality === 'mismatch'" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ t('projects.dashboard.mismatchTableTitle') }}</h3><button class="fid-close-btn" @click="openQuality = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.contract') }}</th><th>{{ t('projects.dashboard.contractCurrency') }}</th><th>{{ t('projects.dashboard.invoiceNumber') }}</th><th>{{ t('projects.dashboard.invoiceCurrency') }}</th><th class="fid-th-num">{{ t('projects.dashboard.invoiceAmount') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in (dataQuality?.mismatched_currency_invoices || [])" :key="'mm-'+row.id">
                <td>{{ row.contract_code }} — {{ row.contract_name }}</td><td class="fid-muted" style="text-align:center;">{{ row.contract_currency }}</td>
                <td class="fid-muted">{{ row.invoice_number }}</td><td class="fid-num-amber" style="text-align:center;">{{ row.invoice_currency }}</td><td class="fid-num">{{ fmt(row.invoice_amount) }}</td>
              </tr>
              <tr v-if="!(dataQuality?.mismatched_currency_invoices || []).length"><td colspan="5" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
            </tbody>
          </table>
        </div>

        <div v-if="openQuality === 'rate'" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ t('projects.dashboard.excludedTableTitle', { currency: mainCurrency }) }}</h3><button class="fid-close-btn" @click="openQuality = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.customer') }}</th><th>{{ t('projects.dashboard.code') }}</th><th>{{ t('common.currency') }}</th><th class="fid-th-num">{{ t('projects.dashboard.value') }}</th><th class="fid-th-num">{{ t('projects.dashboard.exchangeRate') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in (dataQuality?.exchange_rate_issues || [])" :key="'er-'+row.id">
                <td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.code }}</td><td class="fid-muted" style="text-align:center;">{{ row.currency }}</td>
                <td class="fid-num">{{ fmt(row.amount) }}</td><td class="fid-num fid-num-amber">{{ fmt(row.exchange_rate) }}</td>
              </tr>
              <tr v-if="!(dataQuality?.exchange_rate_issues || []).length"><td colspan="5" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
            </tbody>
          </table>
        </div>

        <div v-if="openQuality === 'unbalanced'" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ t('projects.dashboard.unbalancedTableTitle') }} [{{ mainCurrency }}]</h3><button class="fid-close-btn" @click="openQuality = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.contract') }}</th><th>{{ t('projects.dashboard.invoiceNumber') }}</th><th class="fid-th-num">{{ t('projects.dashboard.billed') }}</th><th class="fid-th-num">{{ t('projects.dashboard.collected') }}</th><th class="fid-th-num">{{ t('projects.dashboard.deductions') }}</th><th class="fid-th-num">{{ t('projects.dashboard.uncollected') }}</th><th class="fid-th-num">{{ t('projects.dashboard.gap') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in (dataQuality?.unbalanced_invoices || [])" :key="'ub-'+row.id">
                <td>{{ row.contract_code }}</td><td class="fid-muted">{{ row.invoice_number }}</td>
                <td class="fid-num">{{ fmt(row.net_invoice_amount) }}</td><td class="fid-num">{{ fmt(row.total_collected_amount) }}</td>
                <td class="fid-num">{{ fmt(row.total_deductions) }}</td><td class="fid-num">{{ fmt(row.net_balance) }}</td>
                <td class="fid-num fid-num-amber">{{ fmt(Number(row.net_invoice_amount) - Number(row.total_collected_amount) - Number(row.total_deductions) - Number(row.net_balance)) }}</td>
              </tr>
              <tr v-if="!(dataQuality?.unbalanced_invoices || []).length"><td colspan="7" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
            </tbody>
          </table>
        </div>

        <div v-if="openQuality === 'nocode'" class="zv-card fid-detail-card">
          <div class="fid-detail-header"><h3>{{ t('projects.dashboard.noCodeTableTitle') }}</h3><button class="fid-close-btn" @click="openQuality = null">{{ t('common.close') }}</button></div>
          <table class="zv-table">
            <thead><tr><th>{{ t('projects.dashboard.customer') }}</th><th>{{ t('projects.dashboard.name') }}</th><th>{{ t('common.currency') }}</th><th class="fid-th-num">{{ t('projects.dashboard.value') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in (dataQuality?.contracts_without_code || [])" :key="'nc-'+row.id">
                <td>{{ row.customer_name }}</td><td class="fid-muted">{{ row.name }}</td><td class="fid-muted" style="text-align:center;">{{ row.currency }}</td><td class="fid-num">{{ fmt(row.amount) }}</td>
              </tr>
              <tr v-if="!(dataQuality?.contracts_without_code || []).length"><td colspan="4" class="fid-muted" style="text-align:center;">{{ t('common.none') }}</td></tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  counts: { type: Object, default: () => ({}) },
  currencies: { type: Array, default: () => [] },
  byCurrency: { type: Object, default: () => ({}) },
  mainCurrency: { type: String, default: 'EGP' },
  mainCurrencyTotals: { type: Object, default: () => ({}) },
  alerts: { type: Object, default: () => ({}) },
  topByValue: { type: Object, default: () => ({}) },
  topByRemaining: { type: Object, default: () => ({}) },
  topByCount: { type: Object, default: () => ({}) },
  topByUncollected: { type: Object, default: () => ({}) },
  aging: { type: Object, default: () => ({}) },
  agingBuckets: { type: Object, default: () => ({}) },
  trend: { type: Object, default: () => ({}) },
  dataQuality: { type: Object, default: () => ({}) },
  details: { type: Object, default: () => ({}) },
  startDate: { type: String, default: '' },
  endDate: { type: String, default: '' },
  startDateFormatted: { type: String, default: '' },
  endDateFormatted: { type: String, default: '' },
  nearExpiryDays: { type: Number, default: 30 },
  filterUrl: { type: String, default: '' },
  exportUrl: { type: String, default: '' },
})

const activeCurrency = ref(props.currencies[0] || '')
const startDateModel = ref(props.startDate)
const endDateModel = ref(props.endDate)
const openDetail = ref(null)
const openAlert = ref(null)
const openQuality = ref(null)

function applyFilter() {
  router.get(props.filterUrl, { start_date: startDateModel.value, end_date: endDateModel.value }, { preserveScroll: true, preserveState: true })
}

function toggleDetail(key) { openDetail.value = openDetail.value === key ? null : key }
function toggleAlert(key) { openAlert.value = openAlert.value === key ? null : key }
function toggleQuality(key) { openQuality.value = openQuality.value === key ? null : key }

const currencyKpis = computed(() => {
  const c = props.byCurrency?.[activeCurrency.value] || {}
  const value = Number(c.value || 0)
  const invoiced = Number(c.invoiced || 0)
  const billed = Number(c.billed || 0)
  const collected = Number(c.collected || 0)
  const withheld = Number(c.withheld || 0)
  const deductions = Number(c.deductions || 0)
  const uncollected = Number(c.uncollected || 0)
  return {
    value, invoiced, remaining: Number(c.remaining || 0),
    utilization: value > 0 ? (invoiced / value) * 100 : 0,
    billed, collected, uncollected, withheld, deductions,
    collection_rate: billed > 0 ? (collected / billed) * 100 : 0,
    reconciliation_gap: (billed - collected - withheld - deductions) - uncollected,
  }
})

const detailTitle = computed(() => {
  const labels = {
    value: t('projects.dashboard.contractValue'),
    invoiced: t('projects.dashboard.invoiced'),
    remaining: t('projects.dashboard.remainingToInvoice'),
  }
  return labels[openDetail.value] || ''
})
const detailRows = computed(() => props.details?.byCurrency?.[activeCurrency.value] || [])

const alertTitle = computed(() => {
  const labels = {
    past_end_date: t('projects.dashboard.pastEndDate'),
    ending_soon: t('projects.dashboard.endingWithin', { days: props.nearExpiryDays }),
    not_invoiced: t('projects.dashboard.noInvoicesRaised'),
    over_billed: t('projects.dashboard.overBilled'),
  }
  return labels[openAlert.value] || ''
})
const alertRows = computed(() => props.alerts?.[openAlert.value + '_rows'] || [])

const agingBucketKeys = computed(() => Object.keys(props.agingBuckets || {}))
const agingRow = computed(() => props.aging?.[activeCurrency.value] || {})
function agingShare(bucket) {
  const total = Number(agingRow.value?.total?.amount || 0)
  if (!total) return 0
  return Math.min(100, (Number(agingRow.value?.[bucket]?.amount || 0) / total) * 100)
}

function fmt(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }
function fmtPct(v) { return Number(v || 0).toFixed(1) + '%' }
</script>

<style>
.fid-filter-bar { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 1.5rem; padding: 12px; }
.fid-pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.fid-pill { padding: 6px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; background: var(--zv-bg-surface-alt, #f3f4f6); color: var(--zv-text-secondary, #4b5563); }
.fid-pill-active { background: var(--zv-navy, #0F2554); color: #fff; }

.fid-section-heading { font-weight: 700; font-size: 15px; color: var(--zv-navy, #0F2554); margin: 1.5rem 0 0.75rem; }

.fid-kpi-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 1000px) { .fid-kpi-row-4 { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .fid-kpi-row-4 { grid-template-columns: 1fr; } }

.fid-kpi-card { display: flex; gap: 12px; align-items: flex-start; padding: 14px; border-radius: 10px; background: var(--zv-bg-card, #fff); border: 1px solid var(--zv-border, #e5e7eb); }
.fid-kpi-card-clickable { cursor: pointer; }
.fid-kpi-card-clickable:hover { border-color: var(--zv-blue, #1490A8); }
.fid-kpi-icon { font-size: 18px; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; flex-shrink: 0; }
.fid-icon-blue { background: rgba(20,144,168,0.12); }
.fid-icon-amber { background: rgba(180,83,9,0.12); }
.fid-icon-green { background: rgba(22,163,74,0.12); }
.fid-icon-red { background: rgba(220,38,38,0.12); }
.fid-kpi-label { font-size: 11.5px; color: var(--zv-text-muted, #6b7280); margin-bottom: 2px; }
.fid-kpi-value { font-size: 17px; font-weight: 700; color: var(--zv-navy, #0F2554); }
.fid-num-blue { color: #1490A8; }
.fid-num-amber { color: #b45309; }
.fid-num-green { color: #16a34a; }
.fid-num-red { color: #dc2626; }

.fid-table-card, .fid-detail-card { overflow-x: auto; margin-bottom: 1rem; }
.fid-th-num { text-align: end; }
.fid-num { text-align: end; font-variant-numeric: tabular-nums; }
.fid-muted { color: var(--zv-text-muted, #6b7280); font-size: 12px; }

.fid-detail-card { margin-bottom: 1rem; overflow: hidden; }
.fid-detail-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.fid-detail-header h3 { font-size: 13px; font-weight: 600; color: var(--zv-navy, #0F2554); }
.fid-close-btn { font-size: 11px; color: var(--zv-text-muted, #6b7280); }

.fid-chart-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (max-width: 900px) { .fid-chart-grid-2 { grid-template-columns: 1fr; } }

.fid-pastdue-header { padding: 8px 14px; font-size: 12px; font-weight: 600; color: var(--zv-navy, #0F2554); background: var(--zv-bg-surface-alt, #f9fafb); border-bottom: 1px solid var(--zv-border, #e5e7eb); }
.zv-table-sm td { font-size: 12px; padding: 6px 12px; }
.fid-summary-row { font-weight: 700; background: var(--zv-bg-surface-alt, #f9fafb); }

.fid-bar-track { width: 100%; height: 8px; border-radius: 4px; background: var(--zv-bg-surface-alt, #f3f4f6); overflow: hidden; }
.fid-bar-fill { height: 100%; }
.fid-bar-green { background: #16a34a; }
.fid-bar-amber { background: #b45309; }
</style>
