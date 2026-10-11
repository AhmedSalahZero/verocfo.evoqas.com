<!--
══════════════════════════════════════════════════════════════════
  Accounting/AdvancesSettlements/Index.vue

  Advances & Settlements (2026-09) — one row per employee with a
  non-zero Custody or Advance-from-Salary balance. Per Diem is
  deliberately absent here: it's an outright expense with no balance
  to track (see OtherPayment class header / AdvancesSettlementsService
  header for the full reasoning).

  Two balances are shown side by side rather than netted together —
  Custody and Advance from Salary are different accounts settled in
  different ways, so combining them into one number would hide which
  kind of balance an employee actually carries.

  @layout    AppLayout
  @route     hr.advances.index
  @props     rows (paginator)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      eyebrow="HR"
      :title="t('accounting.hr.advancesSettlementsIndex.advancesSettlements')"
      :subtitle="t('accounting.hr.advancesSettlementsIndex.whatEachEmployeeCurrentlyOwesBack')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/>'
    />

    <div class="zv-card">
      <EmptyState
        v-if="!rows.data.length"
        :title="t('accounting.hr.advancesSettlementsIndex.noOutstandingBalances')"
        :message="t('accounting.hr.advancesSettlementsIndex.everyEmployeeIsSquaredUpAdvances')"
        icon-path="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75"
      />

      <div v-else class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.hr.advancesSettlementsIndex.employee') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.advancesSettlementsIndex.custodyBalance') }}</th>
              <th class="zv-table-num">{{ t('accounting.hr.advancesSettlementsIndex.advanceFromSalaryBalance') }}</th>
              <th class="zv-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.partner_id">
              <td><PartnerCell :name="row.partner ? `${row.partner.code} — ${row.partner.name_en}` : '—'" /></td>
              <td class="zv-table-num">{{ row.custody_balance !== '0.00' ? fmt(row.custody_balance) : '—' }}</td>
              <td class="zv-table-num"><strong>{{ row.advance_balance !== '0.00' ? fmt(row.advance_balance) : '—' }}</strong></td>
              <td class="zv-col-actions">
                <Link :href="route('hr.advances.show', row.partner_id)" class="zv-btn zv-btn-secondary zv-btn-sm">
                  {{ t('accounting.hr.advancesSettlementsIndex.viewHistory') }}
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :paginator="rows" />
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { Link } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/EmptyState.vue'
import PartnerCell from '@/Components/PartnerCell.vue'
import Pagination from '@/Components/Pagination.vue'

const { t } = useI18n()

defineProps({
  rows: { type: Object, required: true },
})

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>
