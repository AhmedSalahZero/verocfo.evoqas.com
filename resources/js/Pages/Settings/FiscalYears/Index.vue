<!--
══════════════════════════════════════════════════════════════════
  Settings/FiscalYears/Index.vue

  Fiscal Year and Accounting Period management page.

  — No date entry form. First fiscal year is auto-created at
    company setup. Subsequent years created by one button.
  — "Create Next Fiscal Year" visible to Company Admin only.
  — Multiple open fiscal years allowed simultaneously (Rule 26.1).
  — Period closing: forward sequence only.
  — Period reopening: reverse sequence only.
  — Fiscal Year closing: all periods must be closed first.

  @layout    AppLayout
  @route     settings.fiscal-years.index
  @props     fiscal_years: FiscalYear[] with periods loaded
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.fiscalYears.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.fiscalYears.index.fiscalYearsPeriods') }}</h1>
          <p class="zv-page-subtitle">
            {{ t('settings.fiscalYears.index.manageYourFiscalYearsAndMonthly') }}
          </p>
        </div>

        <!-- Create Next FY button — Company Admin only -->
        <button v-if="isCompanyAdmin"
                type="button"
                class="zv-btn zv-btn-primary"
                :disabled="creating"
                @click="confirmCreateNext">
          <div v-if="creating" class="zv-spinner w-4 h-4 me-2"></div>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.fiscalYears.index.createNextFiscalYear') }}
        </button>
      </div>

      <!-- ── Flash Messages ────────────────────────────────────── -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="zv-alert zv-alert-danger mb-4">
        {{ $page.props.flash.error }}
      </div>

      <!-- ── Rules Banner ──────────────────────────────────────── -->
      <div class="zv-alert zv-alert-info mb-5 flex items-start gap-3">
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color:var(--zv-blue-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span class="text-sm" style="color:var(--zv-blue-text)">
          {{ t('settings.fiscalYears.index.periodsCloseInSequenceJanuaryFirst') }}
        </span>
      </div>

      <!-- ── Empty State ───────────────────────────────────────── -->
      <div v-if="fiscalYears.length === 0" class="zv-card">
        <div class="zv-empty py-12">
          <svg class="w-12 h-12 zv-empty-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
          </svg>
          <div class="zv-empty-title">{{ t('settings.fiscalYears.index.noFiscalYearsYet') }}</div>
          <div class="zv-empty-sub">{{ t('settings.fiscalYears.index.theFirstFiscalYearIsCreated') }}</div>
        </div>
      </div>

      <!-- ── Fiscal Year Cards ──────────────────────────────────── -->
      <div v-for="fy in fiscalYears" :key="fy.id"
           class="fy-card mb-4"
           :class="fy.status === 'open' ? 'fy-card--open' : 'fy-card--closed'">

        <!-- ── FY Header ─────────────────────────────────────── -->
        <div class="fy-header">

          <!-- Expand button -->
          <button type="button" class="fy-expand-btn" @click="toggleFY(fy.id)">
            <svg class="w-4 h-4 transition-transform duration-200"
                 :class="{ 'rotate-90': expandedFY[fy.id] }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </button>

          <!-- FY icon -->
          <div class="fy-icon" :class="fy.status === 'open' ? 'fy-icon--open' : 'fy-icon--closed'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
          </div>

          <!-- Name + dates -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-mono font-bold text-base" style="color:var(--zv-text-primary)">
                {{ fy.name }}
              </span>
              <span class="zv-badge" :class="fy.status === 'open' ? 'zv-badge-blue' : 'zv-badge-gray'">
                <span class="zv-status-dot" :class="fy.status === 'open' ? 'zv-status-dot-green' : ''"></span>
                {{ fy.status === 'open' ? t('settings.fiscalYears.index.open') : t('common.closed') }}
              </span>
              <span v-if="fy.status === 'closed' && fy.closed_by"
                    class="text-xs" style="color:var(--zv-text-muted)">
                {{ t('settings.fiscalYears.index.closedBy') }} {{ fy.closed_by?.name }} · {{ formatDate(fy.closed_at) }}
              </span>
            </div>
            <div class="text-xs mt-0.5" style="color:var(--zv-text-muted)">
              {{ formatDate(fy.start_date) }} → {{ formatDate(fy.end_date) }}
              <span class="ms-2">
                ({{ fy.periods?.length }} {{ t('settings.fiscalYears.index.periods') }})
              </span>
            </div>
          </div>

          <!-- Progress bar -->
          <div class="fy-progress-wrap">
            <div class="fy-progress-label">
              {{ closedCount(fy) }}/{{ fy.periods?.length ?? 12 }} {{ t('settings.fiscalYears.index.periodsClosed') }}
            </div>
            <div class="fy-progress-bar">
              <div class="fy-progress-fill"
                   :style="{ width: progressPct(fy) + '%' }"
                   :class="progressPct(fy) === 100 ? 'fy-progress-fill--complete' : ''">
              </div>
            </div>
          </div>

          <!-- Close FY button -->
          <div class="fy-actions">
            <button v-if="fy.status === 'open' && closedCount(fy) === (fy.periods?.length ?? 12)"
                    type="button" class="zv-btn zv-btn-warning zv-btn-sm"
                    @click="confirmCloseFY(fy)">
              {{ t('settings.fiscalYears.index.closeFiscalYear') }}
            </button>
            <span v-else-if="fy.status === 'open'"
                  class="text-xs" style="color:var(--zv-text-muted)">
              {{ t('settings.fiscalYears.index.closeAllPeriodsFirst') }}
            </span>
            <svg v-else class="w-4 h-4" style="color:var(--zv-text-muted)"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>

        </div>

        <!-- ── Periods List ───────────────────────────────────── -->
        <Transition name="fy-expand">
          <div v-if="expandedFY[fy.id]" class="fy-periods">
            <div v-for="period in fy.periods" :key="period.id"
                 class="period-row"
                 :class="period.status === 'closed' ? 'period-row--closed' : 'period-row--open'">

              <!-- Period number -->
              <div class="period-num"
                   :class="period.status === 'closed' ? 'period-num--closed' : 'period-num--open'">
                {{ period.period_number }}
              </div>

              <!-- Name + dates -->
              <div class="flex-1 min-w-0">
                <div class="font-medium text-sm" style="color:var(--zv-text-primary)">
                  {{ period.name }}
                </div>
                <div class="text-xs" style="color:var(--zv-text-muted)">
                  {{ formatDate(period.start_date) }} → {{ formatDate(period.end_date) }}
                </div>
              </div>

              <!-- Status + audit -->
              <div class="period-status">
                <span class="zv-badge" :class="period.status === 'open' ? 'zv-badge-green' : 'zv-badge-gray'">
                  <span v-if="period.status === 'open'" class="zv-status-dot zv-status-dot-green"></span>
                  <svg v-else class="w-3 h-3 me-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                  {{ period.status === 'open' ? t('settings.fiscalYears.index.open') : t('common.closed') }}
                </span>
                <span v-if="period.was_reopened" class="zv-badge zv-badge-amber" :title="period.reopen_reason || ''">
                  {{ t('settings.fiscalYears.index.reopened') }}
                </span>
                <span v-if="period.status === 'closed' && period.closed_by"
                      class="text-xs" style="color:var(--zv-text-muted)">
                  {{ period.closed_by?.name }} · {{ formatDate(period.closed_at) }}
                </span>
              </div>

              <!-- Actions -->
              <div class="period-actions">
                <button v-if="period.status === 'open' && fy.status === 'open' && isEarliestOpen(fy, period)"
                        type="button" class="zv-btn zv-btn-sm zv-btn-danger"
                        @click="confirmClosePeriod(period)">
                  {{ t('settings.fiscalYears.index.closePeriod') }}
                </button>
                <button v-if="period.status === 'closed' && fy.status === 'open' && isMostRecentlyClosed(fy, period) && isAdmin"
                        type="button" class="zv-btn zv-btn-sm zv-btn-secondary"
                        @click="doReopenPeriod(period)">
                  {{ t('settings.fiscalYears.index.reopen') }}
                </button>
              </div>

            </div>
          </div>
        </Transition>

      </div>

    </div>

    <!-- ── Confirm Modal ──────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="scale">
        <div v-if="modal.show" class="zv-modal-backdrop" @click.self="modal.show = false">
          <div class="zv-modal zv-modal-sm">
            <div class="zv-modal-header">
              <h3 class="zv-modal-title">{{ modal.title }}</h3>
              <button type="button" @click="modal.show = false" class="zv-modal-close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <div class="zv-modal-body">
              <p class="text-sm" style="color:var(--zv-text-secondary)">{{ modal.message }}</p>

              <!-- Checklist for final period or FY close -->
              <div v-if="modal.showChecklist"
                   class="mt-3 p-3 rounded-lg"
                   style="background:var(--zv-amber-light);border:1px solid var(--zv-amber-border)">
                <p class="text-xs font-semibold mb-2" style="color:var(--zv-amber-text)">
                  {{ t('settings.fiscalYears.index.recommendedChecklistBeforeProceeding') }}
                </p>
                <ul class="text-xs space-y-1" style="color:var(--zv-amber-text)">
                  <li>✓ {{ t('settings.fiscalYears.index.trialBalanceVerifiedAndBalanced') }}</li>
                  <li>✓ {{ t('settings.fiscalYears.index.allAuditAdjustmentsPosted') }}</li>
                  <li>✓ {{ t('settings.fiscalYears.index.financialStatementsReviewed') }}</li>
                  <li>✓ {{ t('settings.fiscalYears.index.shareholdersApprovalObtained') }}</li>
                </ul>
              </div>

              <div class="mt-3 p-3 rounded-lg"
                   style="background:var(--zv-danger-light);border:1px solid var(--zv-danger-border)">
                <p class="text-xs font-semibold" style="color:var(--zv-danger-text)">
                  {{ t('settings.fiscalYears.index.thisActionCannotBeUndone') }}
                </p>
              </div>
            </div>
            <div class="zv-modal-footer">
              <button type="button" @click="modal.show = false" class="zv-btn zv-btn-ghost">
                {{ t('common.cancel') }}
              </button>
              <button type="button" @click="modal.action()" :disabled="modal.loading"
                      class="zv-btn zv-btn-danger">
                <div v-if="modal.loading" class="zv-spinner w-4 h-4 me-2"></div>
                {{ modal.loading ? t('settings.fiscalYears.index.processing') : modal.confirmLabel }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const page  = usePage()

const props = defineProps({
  fiscal_years: Array,
})

const fiscalYears   = computed(() => props.fiscal_years ?? [])
const isCompanyAdmin = computed(() => page.props.auth?.user?.role === 'company_admin')
const creating       = ref(false)

// ── Expand — default first FY expanded ───────────────────────────
const expandedFY = reactive(
  Object.fromEntries((props.fiscal_years ?? []).map((fy, i) => [fy.id, i === 0]))
)
function toggleFY(id) { expandedFY[id] = !expandedFY[id] }

// ── Helpers ───────────────────────────────────────────────────────
function formatDate(date) {
  if (!date) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
  }).format(new Date(date))
}

function closedCount(fy) {
  return (fy.periods ?? []).filter(p => p.status === 'closed').length
}

function progressPct(fy) {
  const total  = fy.periods?.length ?? 12
  const closed = closedCount(fy)
  return total > 0 ? Math.round((closed / total) * 100) : 0
}

function isEarliestOpen(fy, period) {
  const open = (fy.periods ?? []).filter(p => p.status === 'open')
  if (!open.length) return false
  return Math.min(...open.map(p => p.period_number)) === period.period_number
}

function isMostRecentlyClosed(fy, period) {
  const laterOpen = (fy.periods ?? []).filter(
    p => p.status === 'open' && p.period_number > period.period_number
  )
  if (laterOpen.length > 0) return false
  const closed = (fy.periods ?? []).filter(p => p.status === 'closed')
  if (!closed.length) return false
  return Math.max(...closed.map(p => p.period_number)) === period.period_number
}

// ── Confirm Modal ─────────────────────────────────────────────────
const modal = reactive({
  show:          false,
  title:         '',
  message:       '',
  confirmLabel:  '',
  showChecklist: false,
  loading:       false,
  action:        () => {},
})

function confirmCreateNext() {
  modal.title         = t('settings.fiscalYears.index.createNextFiscalYear')
  modal.message       = t('settings.fiscalYears.index.theNextFiscalYearWillBe')
  modal.confirmLabel  = t('settings.fiscalYears.index.createFiscalYear')
  modal.showChecklist = false
  modal.show          = true
  modal.action        = () => {
    modal.loading = true
    creating.value = true
    router.post(route('settings.fiscal-years.next'), {}, {
      preserveScroll: true,
      onFinish: () => { modal.loading = false; modal.show = false; creating.value = false },
    })
  }
}

function confirmClosePeriod(period) {
  const isFinal       = period.period_number === (period.fiscal_year?.periods?.length ?? 12)
  modal.title         = t('settings.fiscalYears.index.closePeriod')
  modal.confirmLabel  = t('settings.fiscalYears.index.closePeriod')
  modal.showChecklist = isFinal
  modal.show          = true
  modal.message       = isFinal
    ? t('settings.fiscalYears.index.youAreAboutToCloseThe')
    : t('settings.fiscalYears.index.closeNameNoTransactionsCanBe').replace('{name}', period.name)
  modal.action        = () => {
    modal.loading = true
    router.post(route('settings.fiscal-years.periods.close', period.id), {}, {
      preserveScroll: true,
      onFinish: () => { modal.loading = false; modal.show = false },
    })
  }
}

function confirmCloseFY(fy) {
  modal.title         = t('settings.fiscalYears.index.closeFiscalYear')
  modal.confirmLabel  = t('settings.fiscalYears.index.closeFiscalYearIConfirm')
  modal.showChecklist = true
  modal.show          = true
  modal.message       = t('settings.fiscalYears.index.closingNameIsPermanentAndIrreversible').replace('{name}', fy.name)
  modal.action        = () => {
    modal.loading = true
    router.post(route('settings.fiscal-years.close', fy.id), {}, {
      preserveScroll: true,
      onFinish: () => { modal.loading = false; modal.show = false },
    })
  }
}

// Audit H4: only a Company Admin (or Super Admin) may reopen a closed
// month, and must give a written reason (stored and shown on the badge).
const isAdmin = computed(() => ['super_admin', 'company_admin'].includes(page.props.auth?.user?.role))

function doReopenPeriod(period) {
  const reason = window.prompt(t('settings.fiscalYears.index.reopenReasonPrompt'))
  if (reason === null) return
  if (reason.trim().length < 10) {
    window.alert(t('settings.fiscalYears.index.reopenReasonTooShort'))
    return
  }
  router.post(route('settings.fiscal-years.periods.reopen', period.id), { reason: reason.trim() }, {
    preserveScroll: true,
  })
}
</script>

<style scoped>
.fy-card {
  background: var(--zv-bg-card);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg);
  overflow: hidden;
  box-shadow: var(--zv-shadow-sm);
}
.fy-card--open   { border-inline-start: 4px solid var(--zv-blue); }
.fy-card--closed { border-inline-start: 4px solid var(--zv-border-md); }

.fy-header {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 1rem 1.25rem;
  background: var(--zv-bg-surface);
  border-bottom: 1px solid var(--zv-border);
}

.fy-expand-btn {
  width: 28px; height: 28px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--zv-radius-sm);
  border: none; background: transparent;
  cursor: pointer; color: var(--zv-text-muted);
  flex-shrink: 0;
  transition: background 0.12s ease;
}
.fy-expand-btn:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }

.fy-icon {
  width: 36px; height: 36px;
  border-radius: var(--zv-radius-md);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.fy-icon--open   { background: var(--zv-blue); color: #fff; }
.fy-icon--closed { background: var(--zv-bg-surface-alt); color: var(--zv-text-muted); }

.fy-progress-wrap { min-width: 140px; flex-shrink: 0; }
.fy-progress-label {
  font-size: 0.6875rem;
  color: var(--zv-text-muted);
  margin-bottom: 0.25rem;
  text-align: end;
}
.fy-progress-bar {
  height: 6px;
  background: var(--zv-border);
  border-radius: 9999px;
  overflow: hidden;
}
.fy-progress-fill {
  height: 100%;
  background: var(--zv-blue);
  border-radius: 9999px;
  transition: width 0.3s ease;
}
.fy-progress-fill--complete { background: var(--zv-green); }
.fy-actions { flex-shrink: 0; margin-inline-start: 0.5rem; }

.fy-periods { background: var(--zv-bg-card); }

.period-row {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 0.625rem 1.25rem 0.625rem 1.75rem;
  border-bottom: 1px solid var(--zv-border);
  transition: background 0.1s ease;
  min-height: 52px;
}
.period-row:last-child { border-bottom: none; }
.period-row:hover { background: var(--zv-bg-hover); }
.period-row--closed { opacity: 0.75; }

.period-num {
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; font-weight: 700;
  flex-shrink: 0;
}
.period-num--open   { background: var(--zv-green-light); color: var(--zv-green-text); }
.period-num--closed { background: var(--zv-bg-surface-alt); color: var(--zv-text-muted); }

.period-status {
  display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0;
}
.period-actions {
  flex-shrink: 0; min-width: 110px; display: flex; justify-content: flex-end;
}

.fy-expand-enter-active { transition: all 0.22s ease; overflow: hidden; }
.fy-expand-leave-active { transition: all 0.16s ease; overflow: hidden; }
.fy-expand-enter-from, .fy-expand-leave-to { opacity: 0; max-height: 0; }
.fy-expand-enter-to, .fy-expand-leave-from { opacity: 1; max-height: 900px; }
</style>