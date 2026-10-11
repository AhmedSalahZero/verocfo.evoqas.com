<!--
══════════════════════════════════════════════════════════════════
  Settings/OpeningBalances/NotesTab.vue

  Reusable tab for Notes Receivable and Notes Payable.
  Handles check details, status, bank account assignment.

  @props  noteType: 'receivable' | 'payable'
  @emits  save({ type, data }), delete({ type, id })
══════════════════════════════════════════════════════════════════
-->
<template>
  <div>
    <div class="zv-card mb-4">
      <div v-if="formErrors.length" class="zv-alert zv-alert-danger m-4 mb-0">
        <div v-for="e in formErrors" :key="e">{{ e }}</div>
      </div>
      <div class="zv-card-header">
        <h2 class="zv-card-title">
          {{ noteType === 'receivable' ? t('settings.openingBalances.notesTab.notesReceivableChecksReceived') : t('settings.openingBalances.notesTab.notesPayableChecksIssued') }}
        </h2>
        <div class="ms-auto font-mono text-sm font-semibold" style="color:var(--zv-blue-text)">
          {{ t('settings.openingBalances.notesTab.total') }} {{ formatMoney(totalAmount) }} EGP
        </div>
      </div>

      <!-- Add form -->
      <div v-if="!isPosted" class="p-4 border-b" style="border-color:var(--zv-border);background:var(--zv-bg-surface)">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

          <!-- Partner -->
          <div>
            <label class="zv-label zv-label-required">{{ noteType === 'receivable' ? t('common.customer') : t('settings.openingBalances.notesTab.supplier') }}</label>
            <select v-model="form.partner_id" class="zv-select">
              <option :value="null">— {{ t('common.select') }} —</option>
              <option v-for="p in partners" :key="p.id" :value="p.id">{{ p.name_en }}</option>
            </select>
          </div>

          <!-- Check number -->
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.notesTab.checkNumber') }}</label>
            <input v-model="form.check_number" type="text" class="zv-input font-mono" placeholder="CHK-001" />
          </div>

          <!-- Bank -->
          <div>
            <label class="zv-label zv-label-required">
              {{ noteType === 'receivable' ? t('settings.openingBalances.notesTab.draweeBankCustomerSBank') : t('settings.openingBalances.notesTab.drawlBankCompanySBank') }}
            </label>
            <input v-model="form.bank_name" type="text" class="zv-input"
                   :placeholder="noteType === 'receivable' ? t('settings.openingBalances.notesTab.eGCIBBank') : t('settings.openingBalances.notesTab.eGNBE')" />
          </div>

          <!-- Check date -->
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.notesTab.checkDate') }}</label>
            <input v-model="form.check_date" type="date" class="zv-input" />
          </div>

          <!-- Due date -->
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.notesTab.dueDateMaturity') }}</label>
            <input v-model="form.due_date" type="date" class="zv-input" />
          </div>

          <!-- Post-dated -->
          <div class="flex items-center gap-2 mt-6">
            <input type="checkbox" v-model="form.is_post_dated" class="zv-checkbox" id="is_post_dated" />
            <label for="is_post_dated" class="text-sm cursor-pointer" style="color:var(--zv-text-secondary)">
              {{ t('settings.openingBalances.notesTab.postDatedCheck') }}
            </label>
          </div>

          <!-- Amount -->
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.notesTab.amountEGP') }}</label>
            <input v-model="form.amount" type="number" step="0.01" min="0.01"
                   class="zv-input font-mono text-end" placeholder="0.00" />
          </div>

          <!-- Currency -->
          <div>
            <label class="zv-label">{{ t('settings.openingBalances.notesTab.currency') }}</label>
            <select v-model="form.currency_id" class="zv-select">
              <option :value="null">EGP ({{ t('settings.openingBalances.notesTab.default') }})</option>
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
            </select>
          </div>

          <template v-if="form.currency_id">
            <div>
              <label class="zv-label">{{ t('settings.openingBalances.notesTab.foreignAmount') }}</label>
              <input v-model="form.amount_foreign" type="number" step="0.01"
                     class="zv-input font-mono text-end" placeholder="0.00" />
            </div>
            <div>
              <label class="zv-label">{{ t('settings.openingBalances.notesTab.exchangeRate') }}</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001"
                     class="zv-input font-mono text-end" placeholder="0.000000" />
            </div>
          </template>

          <!-- Status -->
          <div>
            <label class="zv-label zv-label-required">{{ t('common.status') }}</label>
            <select v-model="form.status" class="zv-select">
              <template v-if="noteType === 'receivable'">
                <option value="in_safe">{{ t('settings.openingBalances.notesTab.inSafe') }}</option>
                <option value="under_collection">{{ t('settings.openingBalances.notesTab.underCollectionInBank') }}</option>
              </template>
              <template v-else>
                <option value="issued">{{ t('settings.openingBalances.notesTab.issuedToSupplier') }}</option>
                <option value="received_by_supplier">{{ t('settings.openingBalances.notesTab.receivedBySupplier') }}</option>
              </template>
            </select>
          </div>

          <!-- Bank account — required for a cheque under collection, and
               (Audit C7) for EVERY cheque we issued: it is the account the
               cheque is drawn on, needed later to mark it paid. -->
          <Transition name="section-slide">
            <div v-if="noteType === 'payable' || form.status === 'under_collection'">
              <label class="zv-label zv-label-required">{{ t('settings.openingBalances.notesTab.bankAccountCOA') }}</label>
              <select v-model="form.bank_account_id" class="zv-select">
                <option :value="null">— {{ t('settings.openingBalances.notesTab.selectBankAccount') }} —</option>
                <option v-for="b in bankAccounts" :key="b.id" :value="b.id">{{ b.code }} — {{ b.name_en }}</option>
              </select>
            </div>
          </Transition>

          <div class="flex items-end">
            <button type="button" @click="save"
                    :disabled="!canAdd"
                    class="zv-btn zv-btn-primary w-full">
              {{ t('settings.openingBalances.notesTab.addCheck') }}
            </button>
          </div>

        </div>
      </div>

      <!-- Notes table -->
      <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ noteType === 'receivable' ? t('common.customer') : t('settings.openingBalances.notesTab.supplier') }}</th>
              <th>{{ t('settings.openingBalances.notesTab.checkNo') }}</th>
              <th>{{ noteType === 'receivable' ? t('settings.openingBalances.notesTab.draweeBank') : t('settings.openingBalances.notesTab.drawlBank') }}</th>
              <th>{{ t('settings.openingBalances.notesTab.checkDate') }}</th>
              <th>{{ t('settings.openingBalances.notesTab.dueDate') }}</th>
              <th class="text-end">{{ t('common.amount') }}</th>
              <th>{{ t('common.status') }}</th>
              <th v-if="!isPosted"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="notes.length === 0">
              <td :colspan="isPosted ? 7 : 8">
                <div class="zv-empty py-8">
                  <div class="zv-empty-title">{{ t('settings.openingBalances.notesTab.noChecksEntered') }}</div>
                </div>
              </td>
            </tr>
            <tr v-for="note in notes" :key="note.id">
              <td class="font-medium text-sm">{{ note.partner?.name_en }}</td>
              <td class="font-mono text-xs font-semibold" style="color:var(--zv-blue-text)">
                {{ note.check_number }}
                <span v-if="note.is_post_dated" class="zv-badge zv-badge-amber text-xs ms-1">{{ t('settings.openingBalances.notesTab.pDC') }}</span>
              </td>
              <td class="text-sm" style="color:var(--zv-text-secondary)">
                {{ noteType === 'receivable' ? note.drawee_bank : note.drawl_bank }}
              </td>
              <td class="text-sm">{{ formatDate(note.check_date) }}</td>
              <td class="text-sm">{{ formatDate(note.due_date) }}</td>
              <td class="text-end font-mono zv-num font-semibold">{{ formatMoney(note.amount) }}</td>
              <td>
                <span class="zv-badge text-xs"
                      :class="(note.status === 'in_safe' || note.status === 'issued') ? 'zv-badge-blue' : 'zv-badge-green'">
                  {{ statusLabel(note.status) }}
                </span>
                <div v-if="note.bank_account" class="text-xs mt-0.5 font-mono" style="color:var(--zv-text-muted)">
                  {{ note.bank_account?.code }}
                </div>
              </td>
              <td v-if="!isPosted">
                <button type="button" @click="$emit('delete', { type: noteType, id: note.id })"
                        class="zv-btn zv-btn-danger zv-btn-sm">{{ t('settings.openingBalances.notesTab.remove') }}</button>
              </td>
            </tr>
            <tr v-if="notes.length > 0" class="font-semibold" style="background:var(--zv-bg-surface)">
              <td colspan="5" class="text-end text-sm" style="color:var(--zv-text-muted)">{{ t('settings.openingBalances.notesTab.total') }}</td>
              <td class="text-end font-mono zv-num-blue">{{ formatMoney(totalAmount) }}</td>
              <td :colspan="isPosted ? 1 : 2"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { sum as sumMoney } from '@/Utils/money'
import { reactive, computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  noteType:     String,
  notes:        Array,
  partners:     Array,
  bankAccounts: Array,
  currencies:   Array,
  isPosted:     Boolean,
})

const emit = defineEmits(['save', 'delete'])

const form = reactive({
  partner_id:      null,
  check_number:    '',
  bank_name:       '',
  check_date:      '',
  due_date:        '',
  is_post_dated:   false,
  amount:          '',
  currency_id:     null,
  amount_foreign:  '',
  exchange_rate:   '',
  status:          props.noteType === 'receivable' ? 'in_safe' : 'issued',
  bank_account_id: null,
})

const totalAmount = computed(() =>
  Number(sumMoney((props.notes ?? []).map(n => n.amount))) // Audit L6
)

const page = usePage()
const formErrors = computed(() => Object.values(page.props.errors ?? {}).flat().filter(Boolean))

// The bank-account field only exists for payable notes and for receivable
// notes under collection. Switching away must not leave a hidden stale value.
const needsBankAccount = computed(() => props.noteType === 'payable' || form.status === 'under_collection')
watch(needsBankAccount, (v) => { if (!v) form.bank_account_id = null })

// Add stays disabled until everything the server requires is filled in.
const canAdd = computed(() =>
  !!form.partner_id && !!form.check_number && !!form.bank_name && !!form.check_date &&
  !!form.due_date && !!form.amount && (!needsBankAccount.value || !!form.bank_account_id)
)

function resetForm() {
  Object.assign(form, {
    partner_id: null, check_number: '', bank_name: '', check_date: '', due_date: '',
    is_post_dated: false, amount: '', currency_id: null, amount_foreign: '', exchange_rate: '',
    status: props.noteType === 'receivable' ? 'in_safe' : 'issued', bank_account_id: null,
  })
}
// Round 7: cleared only after the parent confirms the save worked.
function save() {
  const payload = {
    ...form,
    [props.noteType === 'receivable' ? 'drawee_bank' : 'drawl_bank']: form.bank_name,
  }
  emit('save', { type: props.noteType, data: payload }, resetForm)
}

function statusLabel(status) {
  return {
    in_safe:              t('settings.openingBalances.notesTab.inSafe'),
    under_collection:     t('settings.openingBalances.notesTab.underCollection'),
    issued:               t('settings.openingBalances.notesTab.issuedToSupplier'),
    received_by_supplier: t('settings.openingBalances.notesTab.receivedBySupplier'),
  }[status] ?? status
}

function formatDate(date) {
  if (!date) return '—'
  return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date))
}
function formatMoney(val) {
  if (!val && val !== 0) return '—'
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}
</script>

<style scoped>
.section-slide-enter-active { transition: all 0.2s ease; }
.section-slide-leave-active { transition: all 0.15s ease; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-6px); }
</style>