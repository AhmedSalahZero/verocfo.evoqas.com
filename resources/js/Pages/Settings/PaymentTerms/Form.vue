<!--
══════════════════════════════════════════════════════════════════
  Settings/PaymentTerms/Form.vue

  Create and Edit payment terms — one page.
  Mode detected by whether props.term exists.

  Uses the Vero CFO design system (app.css) throughout:
  zv-page, zv-page-header, zv-card, zv-card-header, zv-card-body,
  zv-card-footer, zv-label, zv-label-required, zv-input, zv-select,
  zv-btn, zv-field-error, zv-alert, zv-cell-input, zv-cell-select.

  The lines repeater lets the user add/remove installment lines.
  A live percentage total indicator turns green at 100% exactly.
  The Save button is disabled until lines sum to exactly 100%.

  @layout    AppLayout
  @route     settings.payment-terms.create
             settings.payment-terms.edit
  @props     term: PaymentTerm|null
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-breadcrumb">
            <Link :href="route('settings.payment-terms.index')" class="zv-text-blue">
              {{ t('settings.paymentTerms.form.paymentTerms') }}
            </Link>
            <span class="zv-breadcrumb-sep">/</span>
            <span>{{ isEdit ? t('settings.paymentTerms.form.editPaymentTerm') : t('settings.paymentTerms.form.newPaymentTerm') }}</span>
          </div>
          <h1 class="zv-page-title">
            {{ isEdit ? t('settings.paymentTerms.form.editPaymentTerm') : t('settings.paymentTerms.form.newPaymentTerm') }}
          </h1>
        </div>
      </div>

      <form @submit.prevent="submit">

        <!-- ── Term Identity ────────────────────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.paymentTerms.form.termDetails') }}</h2>
          </div>
          <div class="zv-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

              <!-- Code -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.paymentTerms.form.code') }}</label>
                <input v-model="form.code"
                       type="text"
                       maxlength="20"
                       class="zv-input uppercase"
                       :class="{ 'zv-input-error': errors.code }"
                       placeholder="e.g. NET30" />
                <p v-if="errors.code" class="zv-field-error">{{ errors.code }}</p>
              </div>

              <!-- Name EN -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.paymentTerms.form.nameEN') }}</label>
                <input v-model="form.name_en"
                       type="text"
                       class="zv-input"
                       :class="{ 'zv-input-error': errors.name_en }"
                       placeholder="e.g. Net 30 Days" />
                <p v-if="errors.name_en" class="zv-field-error">{{ errors.name_en }}</p>
              </div>

              <!-- Name AR -->
              <div>
                <label class="zv-label">{{ t('settings.paymentTerms.form.nameAR') }}</label>
                <input v-model="form.name_ar"
                       type="text"
                       class="zv-input zv-input-ar"
                       placeholder="مثال: صافي 30 يوم" />
              </div>

            </div>
          </div>
        </div>

        <!-- ── Payment Schedule ─────────────────────────────────── -->
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.paymentTerms.form.paymentSchedule') }}</h2>
            <button type="button"
                    @click="addLine"
                    class="zv-btn zv-btn-secondary zv-btn-sm">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('settings.paymentTerms.form.addLine') }}
            </button>
          </div>

          <div class="zv-card-body">

            <!-- Lines table header -->
            <div class="grid grid-cols-12 gap-2 mb-2 px-1">
              <div class="col-span-2 zv-label mb-0">{{ t('settings.paymentTerms.form.text') }}</div>
              <div class="col-span-2 zv-label mb-0">{{ t('settings.paymentTerms.form.days') }}</div>
              <div class="col-span-4 zv-label mb-0">{{ t('settings.paymentTerms.form.referencePoint') }}</div>
              <div class="col-span-3 zv-label mb-0">{{ t('settings.paymentTerms.form.label') }}</div>
              <div class="col-span-1"></div>
            </div>

            <!-- Line rows -->
            <div v-for="(line, index) in form.lines" :key="index"
                 class="grid grid-cols-12 gap-2 mb-2 items-center">

              <!-- Percentage -->
              <div class="col-span-2">
                <input v-model="line.percentage"
                       type="number" min="0.01" max="100" step="0.01"
                       class="zv-cell-input w-full text-end"
                       style="width:100% !important;"
                       placeholder="50" />
              </div>

              <!-- Due Days -->
              <div class="col-span-2">
                <input v-model="line.due_days"
                       type="number" min="0" max="365"
                       class="zv-cell-input w-full text-end"
                       style="width:100% !important;"
                       placeholder="0" />
              </div>

              <!-- Reference Point -->
              <div class="col-span-4">
                <select v-model="line.reference_point" class="zv-cell-select w-full">
                  <option value="invoice_date">{{ t('settings.paymentTerms.form.invoiceDate') }}</option>
                  <option value="so_date">{{ t('settings.paymentTerms.form.sODate') }}</option>
                  <option value="po_date">{{ t('settings.paymentTerms.form.pODate') }}</option>
                  <option value="contract_date">{{ t('settings.paymentTerms.form.contractDate') }}</option>
                </select>
              </div>

              <!-- Label -->
              <div class="col-span-3">
                <input v-model="line.label"
                       type="text" maxlength="80"
                       class="zv-cell-input w-full"
                       :placeholder="t('settings.paymentTerms.form.eGDownPayment')" />
              </div>

              <!-- Remove -->
              <div class="col-span-1 flex justify-center">
                <button type="button"
                        @click="removeLine(index)"
                        :disabled="form.lines.length === 1"
                        class="zv-btn zv-btn-icon zv-btn-ghost text-[var(--zv-danger-text)] hover:bg-[var(--zv-danger-light)] disabled:opacity-30">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Percentage total -->
            <div class="mt-4 pt-4 border-t border-[var(--zv-border)] flex items-center gap-3">
              <span class="zv-label mb-0">{{ t('settings.paymentTerms.form.total') }}</span>
              <span class="font-semibold text-sm font-variant-numeric: tabular-nums"
                    :class="percentageTotal === 100
                      ? 'text-[var(--zv-num-green)]'
                      : 'text-[var(--zv-num-danger)]'">
                {{ percentageTotal.toFixed(2) }}%
              </span>
              <div v-if="percentageTotal !== 100"
                   class="zv-alert zv-alert-warning py-1.5 px-3 text-xs">
                {{ t('settings.paymentTerms.form.mustEqual100') }}
              </div>
              <div v-else class="zv-badge zv-badge-green text-xs">✓ 100%</div>
            </div>

          </div>
        </div>

        <!-- ── Form Actions ──────────────────────────────────────── -->
        <div class="zv-card">
          <div class="zv-card-footer flex items-center justify-end gap-3" style="border-radius: var(--zv-radius-lg);">
            <Link :href="route('settings.payment-terms.index')"
                  class="zv-btn zv-btn-ghost">
              {{ t('common.cancel') }}
            </Link>
            <button type="submit"
                    :disabled="form.processing || percentageTotal !== 100"
                    class="zv-btn zv-btn-primary">
              <svg v-if="form.processing" class="zv-spinner w-4 h-4" viewBox="0 0 24 24"></svg>
              {{ form.processing ? t('common.saving') : t('common.saveChanges') }}
            </button>
          </div>
        </div>

      </form>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  term: Object,
})

const isEdit = computed(() => !!props.term?.id)

// ── Form ──────────────────────────────────────────────────────────

const form = useForm({
  code:    props.term?.code    ?? '',
  name_en: props.term?.name_en ?? '',
  name_ar: props.term?.name_ar ?? '',
  lines:   props.term?.lines?.length
    ? props.term.lines.map(l => ({
        percentage:      l.percentage,
        due_days:        l.due_days,
        reference_point: l.reference_point,
        label:           l.label ?? '',
      }))
    : [{ percentage: '', due_days: 0, reference_point: 'invoice_date', label: '' }],
})

const errors = computed(() => form.errors)

// ── Lines ─────────────────────────────────────────────────────────

function addLine() {
  form.lines.push({ percentage: '', due_days: 0, reference_point: 'invoice_date', label: '' })
}

function removeLine(index) {
  if (form.lines.length > 1) form.lines.splice(index, 1)
}

const percentageTotal = computed(() => {
  return form.lines.reduce((sum, line) => {
    const val = parseFloat(line.percentage) || 0
    return Math.round((sum + val) * 100) / 100
  }, 0)
})

// ── Submit ────────────────────────────────────────────────────────

function submit() {
  if (isEdit.value) {
    form.put(route('settings.payment-terms.update', props.term.id))
  } else {
    form.post(route('settings.payment-terms.store'))
  }
}
</script>

<style scoped>
/* Override zv-cell-input-num expanding behaviour inside the repeater.
   The CSS class expands from 88px to 130px on focus — that breaks the
   grid layout. Here we lock all number inputs to 100% of their column. */
input[type="number"] {
  width: 100% !important;
  transition: border-color 0.12s ease, box-shadow 0.12s ease !important;
}

/* Hide browser number spinners — cleaner look in the grid */
input[type="number"]::-webkit-outer-spin-button,
input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type="number"] { -moz-appearance: textfield; }
</style>