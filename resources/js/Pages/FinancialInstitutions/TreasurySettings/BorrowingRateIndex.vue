<!--
══════════════════════════════════════════════════════════════════
  FinancialInstitutions/TreasurySettings/BorrowingRateIndex.vue

  CBE Corridor Rate (Borrowing Rate) management page.
  Sits under Financial Institutions → Treasury Settings in sidebar.

  Layout:
    Left  — Current rate display + New rate form
    Right — Rate history table

  Behaviours:
    - Current active rate shown prominently in navy card
    - Direction indicator (▲ increase / ▼ decrease) with colour
    - Form: new rate % + effective date + notes
    - Warning if effective_date is in the past (back-dating)
    - Shows count of facilities that will be affected
    - History table: all past rates, newest first, with direction badges
    - Confirm modal before posting (irreversible — all linked facilities update)

  @layout    AppLayout
  @route     fi.borrowing-rate.index
  @props     currentRate, history, affectedCount
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <span class="zv-text-blue">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.financialInstitutions') }}</span>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.borrowingRateUpdate') }}</span>
          </div>
          <h1 class="zv-page-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.borrowingRateUpdate') }}</h1>
          <p class="zv-page-subtitle">
            {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.cBECorridorRateUpdatesTheBorrowing') }}
          </p>
        </div>
      </div>

      <div class="br-layout">

        <!-- ════════════════════════════════════════════════════
             LEFT COLUMN — Current rate + New rate form
        ════════════════════════════════════════════════════ -->
        <div class="br-left">

          <!-- Current Rate Card -->
          <div class="form-section mb-5">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:#0F2554">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.currentCBECorridorRate') }}</div>
                <div class="form-section-sub">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.theRateCurrentlyAppliedToAll') }}</div>
              </div>
            </div>
            <div class="form-section-body">
              <div v-if="currentRate" class="br-current-display">
                <div class="br-current-rate">
                  {{ currentRate.rate }}<span class="br-current-pct">%</span>
                </div>
                <div class="br-current-meta">
                  <div class="br-meta-item">
                    <span class="br-meta-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effectiveSince') }}</span>
                    <span class="br-meta-val">{{ fmtDate(currentRate.effective_date) }}</span>
                  </div>
                  <div v-if="currentRate.previous_rate" class="br-meta-item">
                    <span class="br-meta-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.previousRate') }}</span>
                    <span class="br-meta-val">{{ currentRate.previous_rate }}%</span>
                  </div>
                  <div class="br-meta-item">
                    <span class="br-meta-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.lastChange') }}</span>
                    <span class="br-direction-badge" :class="directionClass(currentRate.direction)">
                      {{ directionLabel(currentRate.direction) }}
                    </span>
                  </div>
                </div>
              </div>
              <div v-else class="br-no-rate">
                <svg class="w-10 h-10 mb-3 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                <p class="text-sm" style="color:var(--zv-text-muted)">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.noRateRecordedYetAddThe') }}</p>
              </div>

              <!-- Affected facilities badge -->
              <div class="br-affected-badge">
                <div class="br-affected-icon">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"/>
                  </svg>
                </div>
                <div>
                  <div class="br-affected-count">{{ affectedCount }}</div>
                  <div class="br-affected-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.facilitiesLinkedToCorridorRate') }}</div>
                </div>
                <div class="br-affected-note">
                  {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.allWillUpdateAutomaticallyWhenYou') }}
                </div>
              </div>
            </div>
          </div>

          <!-- New Rate Form -->
          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:#1490A8">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.postNewCorridorRate') }}</div>
                <div class="form-section-sub">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effectiveDateDeterminesWhenDailyInterest') }}</div>
              </div>
            </div>
            <div class="form-section-body">

              <form @submit.prevent="openConfirm" novalidate>

                <!-- New Rate -->
                <div class="field-group mb-4">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.newBorrowingRate') }}</label>
                  <div class="zv-input-group">
                    <input v-model="form.rate" type="number" step="0.0001" min="0" max="100"
                           class="zv-input zv-input-has-suffix text-end"
                           :class="{ 'zv-input-error': form.errors.rate }"
                           placeholder="0.0000"/>
                    <span class="zv-input-suffix text-xs font-semibold">%</span>
                  </div>
                  <p v-if="form.errors.rate" class="zv-field-error">{{ form.errors.rate }}</p>

                  <!-- Live direction indicator -->
                  <div v-if="form.rate && currentRate" class="br-live-direction" :class="liveDirectionClass">
                    <span>{{ liveDirectionLabel }}</span>
                    <span class="font-bold">
                      {{ parseFloat(form.rate) > parseFloat(currentRate.rate) ? '+' : '' }}{{ rateDiff }}%
                    </span>
                    {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.vsCurrent') }} {{ currentRate.rate }}%
                  </div>
                </div>

                <!-- Effective Date -->
                <div class="field-group mb-4">
                  <label class="zv-label zv-label-required">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effectiveDate') }}</label>
                  <input v-model="form.effective_date" type="date" class="zv-input"
                         :class="{ 'zv-input-error': form.errors.effective_date }"/>
                  <p v-if="form.errors.effective_date" class="zv-field-error">{{ form.errors.effective_date }}</p>

                  <!-- Past-date warning -->
                  <div v-if="isBackDated" class="br-backdate-warn">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <span class="text-sm">
                      {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.backDatedRateChangeDailyInterest') }}
                    </span>
                  </div>
                </div>

                <!-- Notes -->
                <div class="field-group mb-5">
                  <label class="zv-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.notes') }}</label>
                  <input v-model="form.notes" type="text" class="zv-input"
                         :placeholder="t('financialInstitutions.treasurySettings.borrowingRateIndex.eGCBEMPCDecisionMay')"/>
                  <p class="field-hint">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.cBEDecisionReferenceOrAnyRelevant') }}</p>
                </div>

                <!-- Action preview box -->
                <div v-if="form.rate && form.effective_date" class="br-action-preview">
                  <div class="br-preview-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.whatWillHappen') }}</div>
                  <ul class="br-preview-list">
                    <li>
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                      </svg>
                      {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.corridorRateRecordedAs') }} <strong>{{ form.rate }}%</strong> {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effective') }} <strong>{{ fmtDate(form.effective_date) }}</strong>
                    </li>
                    <li>
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                      </svg>
                      <strong>{{ affectedCount }}</strong> {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.linkedFacilitiesWillHaveTheirBorrowing') }}
                    </li>
                    <li>
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                      </svg>
                      {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.dailyInterestFrom') }} <strong>{{ fmtDate(form.effective_date) }}</strong> {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.onwardsWillUseNewRate360') }}
                    </li>
                    <li>
                      <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                      </svg>
                      {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.daysBeforeEffectiveDateKeepThe') }}
                    </li>
                  </ul>
                </div>

                <button type="submit" class="zv-btn zv-btn-primary w-full mt-4"
                        :disabled="!form.rate || !form.effective_date || form.processing">
                  <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                  {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.postNewRate') }}
                </button>

              </form>
            </div>
          </div>

        </div>

        <!-- ════════════════════════════════════════════════════
             RIGHT COLUMN — Rate history
        ════════════════════════════════════════════════════ -->
        <div class="br-right">
          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-icon" style="background:#b8860b">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
              <div>
                <div class="form-section-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.rateHistory') }}</div>
                <div class="form-section-sub">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.allCBECorridorRateChangesNewest') }}</div>
              </div>
            </div>

            <div class="form-section-body p-0">

              <!-- Empty state -->
              <div v-if="history.data.length === 0" class="br-history-empty">
                <svg class="w-8 h-8 mb-2 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm" style="color:var(--zv-text-muted)">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.noRateHistoryYet') }}</p>
              </div>

              <!-- History rows -->
              <div class="br-history-table">
                <div class="br-history-header">
                  <div>{{ t('common.rate') }}</div>
                  <div>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effectiveDate') }}</div>
                  <div>{{ t('common.change') }}</div>
                  <div>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.notes') }}</div>
                  <div>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.postedBy') }}</div>
                </div>

                <div v-for="(rec, idx) in history.data" :key="rec.id"
                     class="br-history-row" :class="{ 'br-history-row-current': idx === 0 }">

                  <!-- Rate -->
                  <div class="br-hist-rate">
                    {{ rec.rate }}<span class="br-hist-pct">%</span>
                    <span v-if="idx === 0" class="br-current-tag">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.current') }}</span>
                  </div>

                  <!-- Effective Date -->
                  <div class="br-hist-date">{{ fmtDate(rec.effective_date) }}</div>

                  <!-- Direction -->
                  <div>
                    <span class="br-direction-badge" :class="directionClass(rec.direction)">
                      {{ directionLabel(rec.direction) }}
                    </span>
                    <div v-if="rec.previous_rate" class="br-hist-prev">
                      {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.from') }} {{ rec.previous_rate }}%
                    </div>
                  </div>

                  <!-- Notes -->
                  <div class="br-hist-notes">{{ rec.notes || '—' }}</div>

                  <!-- Posted by -->
                  <div class="br-hist-by">
                    <div>{{ rec.created_by_name ?? '—' }}</div>
                    <div class="br-hist-ts">{{ fmtDateTime(rec.created_at) }}</div>
                  </div>

                </div>
              </div>

              <!-- Pagination -->
              <Pagination :paginator="history" />

            </div>
          </div>
        </div>

      </div>

      <!-- ── Confirm Modal ─────────────────────────────────────── -->
      <Teleport to="body">
        <Transition name="scale">
          <div v-if="showConfirm" class="zv-modal-backdrop" @click.self="showConfirm = false">
            <div class="zv-modal">
              <div class="zv-modal-header">
                <h3 class="zv-modal-title">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.confirmRateUpdate') }}</h3>
                <button type="button" @click="showConfirm = false" class="zv-modal-close">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
              <div class="zv-modal-body">
                <div class="br-confirm-rate">
                  <div class="br-confirm-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.newCorridorRate') }}</div>
                  <div class="br-confirm-value">{{ form.rate }}%</div>
                  <div class="br-confirm-label">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.effective2') }}</div>
                  <div class="br-confirm-date">{{ fmtDate(form.effective_date) }}</div>
                </div>

                <div class="br-confirm-warning">
                  <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                  </svg>
                  <div>
                    <p class="font-semibold mb-1">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.thisWillImmediatelyUpdate') }}</p>
                    <ul class="br-confirm-list">
                      <li>{{ affectedCount }} {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.activeFacilitiesLinkedToTheCorridor') }}</li>
                      <li>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.allDailyInterestFromTheEffective') }}</li>
                      <li>{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.monthlyInterestEstimatesForLinkedFacilities') }}</li>
                    </ul>
                    <p class="mt-2 text-sm font-semibold" style="color:#dc2626">{{ t('financialInstitutions.treasurySettings.borrowingRateIndex.thisActionCannotBeUndone') }}</p>
                  </div>
                </div>
              </div>
              <div class="zv-modal-footer">
                <button type="button" class="zv-btn zv-btn-ghost" @click="showConfirm = false">
                  {{ t('common.cancel') }}
                </button>
                <button type="button" class="zv-btn zv-btn-primary" @click="confirmSubmit" :disabled="form.processing">
                  <div v-if="form.processing" class="zv-spinner w-4 h-4 me-2"></div>
                  {{ t('financialInstitutions.treasurySettings.borrowingRateIndex.confirmPostRate') }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>

    </div>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue'
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  currentRate:   Object,
  history:       Object,
  affectedCount: Number,
})

// ── Form ──────────────────────────────────────────────────────────
const form = useForm({
  rate:           '',
  effective_date: '',
  notes:          '',
})

const showConfirm = ref(false)

function openConfirm() {
  if (!form.rate || !form.effective_date) return
  showConfirm.value = true
}

function confirmSubmit() {
  form.post(route('fi.borrowing-rate.store'), {
    onSuccess: () => { showConfirm.value = false },
  })
}

// ── Computed ──────────────────────────────────────────────────────

const isBackDated = computed(() => {
  if (!form.effective_date) return false
  return new Date(form.effective_date) < new Date(new Date().toDateString())
})

const rateDiff = computed(() => {
  if (!form.rate || !props.currentRate) return '0'
  return Math.abs(parseFloat(form.rate) - parseFloat(props.currentRate.rate)).toFixed(4)
})

const liveDirectionClass = computed(() => {
  if (!form.rate || !props.currentRate) return ''
  const diff = parseFloat(form.rate) - parseFloat(props.currentRate.rate)
  if (diff > 0) return 'br-live-increase'
  if (diff < 0) return 'br-live-decrease'
  return 'br-live-unchanged'
})

const liveDirectionLabel = computed(() => {
  if (!form.rate || !props.currentRate) return ''
  const diff = parseFloat(form.rate) - parseFloat(props.currentRate.rate)
  if (diff > 0) return '▲'
  if (diff < 0) return '▼'
  return '—'
})

// ── Helpers ───────────────────────────────────────────────────────

function directionLabel(dir) {
  if (dir === 'increase') return '▲ ' + t('financialInstitutions.treasurySettings.borrowingRateIndex.increase')
  if (dir === 'decrease') return '▼ ' + t('financialInstitutions.treasurySettings.borrowingRateIndex.decrease')
  return '— ' + t('financialInstitutions.treasurySettings.borrowingRateIndex.unchanged')
}

function directionClass(dir) {
  if (dir === 'increase') return 'br-dir-up'
  if (dir === 'decrease') return 'br-dir-down'
  return 'br-dir-flat'
}

function fmtDate(val) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

function fmtDateTime(val) {
  if (!val) return '—'
  return new Date(val).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

</script>

<style scoped>
/* ── Layout ───────────────────────────────────────────────────── */
.br-layout { display: grid; grid-template-columns: 420px 1fr; gap: 20px; align-items: start; }
.br-left  { display: flex; flex-direction: column; }
.br-right { display: flex; flex-direction: column; }

/* ── Form section ─────────────────────────────────────────────── */
.form-section {
  background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-lg, 12px); box-shadow: var(--zv-shadow-sm); overflow: hidden;
}
.form-section-header {
  display: flex; align-items: center; gap: 1rem;
  padding: 1.125rem 1.375rem;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
}
.form-section-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.form-section-title { font-size: 0.9375rem; font-weight: 600; color: var(--zv-text-primary); }
.form-section-sub   { font-size: 0.75rem; color: var(--zv-text-muted); margin-top: 2px; }
.form-section-body  { padding: 1.375rem; }
.p-0 { padding: 0 !important; }

/* ── Current rate display ─────────────────────────────────────── */
.br-current-display { margin-bottom: 20px; }
.br-current-rate {
  font-size: 52px; font-weight: 900; color: #0F2554;
  line-height: 1; margin-bottom: 14px; font-variant-numeric: tabular-nums;
}
[data-theme="dark"] .br-current-rate { color: #90a4c4; }
.br-current-pct { font-size: 28px; font-weight: 600; opacity: 0.6; }
.br-current-meta { display: flex; flex-direction: column; gap: 8px; }
.br-meta-item { display: flex; align-items: center; gap: 10px; }
.br-meta-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); min-width: 100px; }
.br-meta-val   { font-size: 13px; font-weight: 600; color: var(--zv-text-primary); }
.br-no-rate { display: flex; flex-direction: column; align-items: center; padding: 24px 0; }

/* ── Affected count badge ─────────────────────────────────────── */
.br-affected-badge {
  display: flex; align-items: center; gap: 14px; margin-top: 20px;
  padding: 14px 16px; background: rgba(15,37,84,0.04);
  border: 1px solid rgba(15,37,84,0.1); border-radius: 10px;
}
[data-theme="dark"] .br-affected-badge { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.08); }
.br-affected-icon {
  width: 36px; height: 36px; border-radius: 8px; background: rgba(15,37,84,0.1);
  display: flex; align-items: center; justify-content: center; color: #0F2554; flex-shrink: 0;
}
[data-theme="dark"] .br-affected-icon { background: rgba(255,255,255,0.07); color: #90a4c4; }
.br-affected-count { font-size: 22px; font-weight: 800; color: #0F2554; line-height: 1; }
[data-theme="dark"] .br-affected-count { color: #90a4c4; }
.br-affected-label { font-size: 11.5px; color: var(--zv-text-muted); margin-top: 2px; }
.br-affected-note  { font-size: 11px; color: var(--zv-text-muted); font-style: italic; margin-left: auto; text-align: end; max-width: 140px; }

/* ── Direction badge ──────────────────────────────────────────── */
.br-direction-badge {
  display: inline-flex; align-items: center; padding: 3px 10px;
  border-radius: 20px; font-size: 12px; font-weight: 700;
}
.br-dir-up   { background: rgba(239,68,68,0.1); color: #dc2626; }
.br-dir-down { background: rgba(16,185,129,0.1); color: #10b981; }
.br-dir-flat { background: rgba(100,116,139,0.1); color: #64748b; }

/* ── Live direction indicator in form ────────────────────────── */
.br-live-direction {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 12px; border-radius: 20px; font-size: 12.5px; font-weight: 700;
  margin-top: 6px;
}
.br-live-increase  { background: rgba(239,68,68,0.08); color: #dc2626; }
.br-live-decrease  { background: rgba(16,185,129,0.08); color: #10b981; }
.br-live-unchanged { background: rgba(100,116,139,0.08); color: #64748b; }

/* ── Back-date warning ────────────────────────────────────────── */
.br-backdate-warn {
  display: flex; align-items: flex-start; gap: 8px; margin-top: 8px;
  padding: 10px 12px; background: rgba(245,158,11,0.07);
  border: 1px solid rgba(245,158,11,0.2); border-radius: 8px; color: #b45309;
}

/* ── Action preview box ───────────────────────────────────────── */
.br-action-preview {
  padding: 14px 16px; background: rgba(20,144,168,0.05);
  border: 1px solid rgba(20,144,168,0.15); border-radius: 10px;
}
.br-preview-title { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #1490A8; margin-bottom: 10px; }
.br-preview-list { display: flex; flex-direction: column; gap: 7px; list-style: none; padding: 0; margin: 0; }
.br-preview-list li { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--zv-text-primary); }
.br-preview-list li svg { color: #10b981; margin-top: 1px; }

/* ── History table ────────────────────────────────────────────── */
.br-history-empty { display: flex; flex-direction: column; align-items: center; padding: 40px 20px; }
.br-history-table { width: 100%; }
.br-history-header {
  display: grid; grid-template-columns: 80px 120px 130px 1fr 140px;
  padding: 9px 18px; gap: 12px;
  background: var(--zv-bg-surface); border-bottom: 1px solid var(--zv-border);
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--zv-text-muted);
}
.br-history-row {
  display: grid; grid-template-columns: 80px 120px 130px 1fr 140px;
  padding: 12px 18px; gap: 12px; align-items: center;
  border-bottom: 1px solid var(--zv-border); transition: background 0.1s;
}
.br-history-row:last-child { border-bottom: none; }
.br-history-row:hover { background: var(--zv-bg-surface-alt); }
.br-history-row-current { background: rgba(15,37,84,0.03); }
[data-theme="dark"] .br-history-row-current { background: rgba(255,255,255,0.02); }

.br-hist-rate {
  font-size: 18px; font-weight: 800; color: var(--zv-text-primary);
  font-variant-numeric: tabular-nums; display: flex; align-items: baseline; gap: 2px;
}
.br-hist-pct { font-size: 12px; font-weight: 600; opacity: 0.5; }
.br-current-tag {
  font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 2px 6px; border-radius: 8px; background: #0F2554; color: #FFBF00;
  margin-left: 6px; align-self: center;
}
.br-hist-date  { font-size: 13px; font-weight: 500; color: var(--zv-text-primary); }
.br-hist-prev  { font-size: 11px; color: var(--zv-text-muted); margin-top: 3px; }
.br-hist-notes { font-size: 12px; color: var(--zv-text-muted); }
.br-hist-by    { font-size: 12px; color: var(--zv-text-primary); }
.br-hist-ts    { font-size: 11px; color: var(--zv-text-muted); margin-top: 2px; }

/* ── Pagination ───────────────────────────────────────────────── */
.br-pagination {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 12px; border-top: 1px solid var(--zv-border);
}
.br-page-btn {
  min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px;
  border: 1px solid var(--zv-border); background: var(--zv-bg-card);
  color: var(--zv-text-primary); font-size: 13px; cursor: pointer;
}
.br-page-btn:hover:not(:disabled) { border-color: #1490A8; color: #1490A8; }
.br-page-btn:disabled { opacity: 0.4; cursor: default; }
.br-page-active { background: #0F2554 !important; color: #fff !important; border-color: #0F2554 !important; }

/* ── Confirm modal ────────────────────────────────────────────── */
.zv-modal-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; z-index: 500;
}
.zv-modal {
  background: var(--zv-bg-card); border: 1px solid var(--zv-border);
  border-radius: 16px; width: 480px; max-width: 95vw;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.zv-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 20px; border-bottom: 1px solid var(--zv-border);
}
.zv-modal-title { font-size: 16px; font-weight: 700; color: var(--zv-text-primary); }
.zv-modal-close {
  width: 28px; height: 28px; border-radius: 6px; border: none;
  background: transparent; color: var(--zv-text-muted); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.zv-modal-close:hover { background: var(--zv-bg-surface); }
.zv-modal-body   { padding: 20px; }
.zv-modal-footer {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 14px 20px; border-top: 1px solid var(--zv-border);
}

.br-confirm-rate {
  display: grid; grid-template-columns: auto 1fr; gap: 6px 16px;
  align-items: baseline; margin-bottom: 20px;
  padding: 16px; background: rgba(15,37,84,0.04);
  border-radius: 10px;
}
.br-confirm-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--zv-text-muted); }
.br-confirm-value { font-size: 28px; font-weight: 900; color: #0F2554; }
[data-theme="dark"] .br-confirm-value { color: #90a4c4; }
.br-confirm-date  { font-size: 16px; font-weight: 700; color: var(--zv-text-primary); }

.br-confirm-warning {
  display: flex; gap: 12px; padding: 14px 16px;
  background: rgba(245,158,11,0.07); border: 1px solid rgba(245,158,11,0.2);
  border-radius: 10px; color: #b45309;
}
.br-confirm-list { list-style: disc; padding-left: 1rem; margin: 4px 0 0; font-size: 12.5px; }
.br-confirm-list li { margin-bottom: 4px; }

/* ── Shared inputs (mirror Partners form tokens) ─────────────── */
.field-group { display: flex; flex-direction: column; gap: 5px; }
.field-hint  { font-size: 0.6875rem; color: var(--zv-text-muted); font-style: italic; }
.w-full { width: 100%; }
.mt-4 { margin-top: 1rem; }

/* ── Scale transition for modal ──────────────────────────────── */
.scale-enter-active, .scale-leave-active { transition: all 0.2s ease; }
.scale-enter-from, .scale-leave-to { opacity: 0; transform: scale(0.95); }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 900px) {
  .br-layout { grid-template-columns: 1fr; }
  .br-history-header,
  .br-history-row { grid-template-columns: 70px 110px 120px 1fr; }
  .br-history-row > :last-child,
  .br-history-header > :last-child { display: none; }
}
</style>
