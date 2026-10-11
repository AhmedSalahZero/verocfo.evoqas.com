<!--
══════════════════════════════════════════════════════════════════
  Settings/OpeningBalances/PartnerInvoicesTab.vue

  Reusable tab for Customer AR and Supplier AP opening balances.
  Shows invoice-by-invoice repeater grouped by partner.

  @props  partnerType: 'customer' | 'supplier'
          partners:    Partner[]
          invoices:    ObPartnerInvoice[]
          arApAccounts: ChartOfAccount[]
          currencies:  Currency[]
          isPosted:    boolean
  @emits  save(data), delete(id)
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
          {{ partnerType === 'customer' ? t('settings.openingBalances.partnerInvoicesTab.customerOpeningBalancesAR') : t('settings.openingBalances.partnerInvoicesTab.supplierOpeningBalancesAP') }}
        </h2>
        <div class="ms-auto font-mono text-sm font-semibold" style="color:var(--zv-blue-text)">
          {{ t('settings.openingBalances.partnerInvoicesTab.total') }} {{ formatMoney(totalNet) }} EGP
        </div>
      </div>

      <!-- Add invoice form -->
      <div v-if="!isPosted" class="p-4 border-b" style="border-color:var(--zv-border);background:var(--zv-bg-surface)">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
          <div>
            <label class="zv-label zv-label-required">{{ partnerType === 'customer' ? t('common.customer') : t('settings.openingBalances.partnerInvoicesTab.supplier') }}</label>
            <select v-model="form.partner_id" class="zv-select">
              <option :value="null">— {{ t('common.select') }} —</option>
              <option v-for="p in partners" :key="p.id" :value="p.id">{{ p.name_en }}</option>
            </select>
          </div>
          <div>
            <label class="zv-label zv-label-required">
              {{ partnerType === 'customer' ? t('settings.openingBalances.partnerInvoicesTab.aRAccount') : t('settings.openingBalances.partnerInvoicesTab.aPAccount') }}
            </label>
            <select v-model="form.account_id" class="zv-select">
              <option :value="null">— {{ t('settings.openingBalances.partnerInvoicesTab.selectAccount') }} —</option>
              <option v-for="a in arApAccounts" :key="a.id" :value="a.id"
                      :disabled="!a.is_postable">
                {{ a.code }} — {{ a.name_en }}
                {{ !a.is_postable ? t('settings.openingBalances.partnerInvoicesTab.group') : '' }}
              </option>
            </select>
          </div>
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.partnerInvoicesTab.invoiceRef') }}</label>
            <input v-model="form.invoice_ref" type="text" class="zv-input" placeholder="INV-2026-0001" />
          </div>
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.partnerInvoicesTab.invoiceDate') }}</label>
            <input v-model="form.invoice_date" type="date" class="zv-input" />
          </div>
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.partnerInvoicesTab.dueDate') }}</label>
            <input v-model="form.due_date" type="date" class="zv-input" />
          </div>
          <div>
            <label class="zv-label zv-label-required">{{ t('settings.openingBalances.partnerInvoicesTab.invoiceAmount') }}</label>
            <input v-model="form.invoice_amount" type="number" step="0.01" min="0"
                   class="zv-input font-mono text-end" placeholder="0.00"
                   @input="calcNet" />
          </div>
          <div>
            <label class="zv-label">{{ t('settings.openingBalances.partnerInvoicesTab.previousCollection') }}</label>
            <input v-model="form.previous_collection" type="number" step="0.01" min="0"
                   class="zv-input font-mono text-end" placeholder="0.00"
                   @input="calcNet" />
          </div>
          <div>
            <label class="zv-label">{{ t('settings.openingBalances.partnerInvoicesTab.netBalance') }}</label>
            <input :value="formatMoney(netBalance)" type="text" class="zv-input font-mono text-end zv-num-blue" readonly />
          </div>
          <!-- Currency row -->
          <div>
            <label class="zv-label">{{ t('settings.openingBalances.partnerInvoicesTab.currency') }}</label>
            <select v-model="form.currency_id" class="zv-select">
              <option :value="null">EGP ({{ t('settings.openingBalances.partnerInvoicesTab.default') }})</option>
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_en }}</option>
            </select>
          </div>
          <template v-if="form.currency_id">
            <div>
              <label class="zv-label">{{ t('settings.openingBalances.partnerInvoicesTab.foreignAmount') }}</label>
              <input v-model="form.amount_foreign" type="number" step="0.01" min="0"
                     class="zv-input font-mono text-end" placeholder="0.00" />
            </div>
            <div>
              <label class="zv-label">{{ t('settings.openingBalances.partnerInvoicesTab.exchangeRate') }}</label>
              <input v-model="form.exchange_rate" type="number" step="0.000001" min="0"
                     class="zv-input font-mono text-end" placeholder="0.000000" />
            </div>
          </template>
          <div class="sm:col-start-3 flex items-end">
            <button type="button" @click="save"
                    :disabled="!form.partner_id || !form.account_id || !form.invoice_ref || !form.invoice_date || !form.due_date || !form.invoice_amount"
                    class="zv-btn zv-btn-primary w-full">
              {{ t('settings.openingBalances.partnerInvoicesTab.addInvoice') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Invoices table grouped by partner -->
      <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ partnerType === 'customer' ? t('common.customer') : t('settings.openingBalances.partnerInvoicesTab.supplier') }}</th>
              <th>{{ t('settings.openingBalances.partnerInvoicesTab.invoiceRef') }}</th>
              <th>{{ t('settings.openingBalances.partnerInvoicesTab.account') }}</th>
              <th>{{ t('settings.openingBalances.partnerInvoicesTab.invoiceDate') }}</th>
              <th>{{ t('settings.openingBalances.partnerInvoicesTab.dueDate') }}</th>
              <th class="text-end">{{ t('settings.openingBalances.partnerInvoicesTab.invoiceAmount') }}</th>
              <th class="text-end">{{ t('settings.openingBalances.partnerInvoicesTab.previousCollection') }}</th>
              <th class="text-end">{{ t('settings.openingBalances.partnerInvoicesTab.netBalance') }}</th>
              <th v-if="!isPosted"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="invoices.length === 0">
              <td :colspan="isPosted ? 8 : 9">
                <div class="zv-empty py-8">
                  <div class="zv-empty-title">{{ t('settings.openingBalances.partnerInvoicesTab.noInvoicesEntered') }}</div>
                </div>
              </td>
            </tr>
            <tr v-for="inv in invoices" :key="inv.id">
              <td>
                <div class="font-medium text-sm">{{ inv.partner?.name_en }}</div>
                <div v-if="inv.currency_id" class="text-xs" style="color:var(--zv-text-muted)">
                  {{ inv.currency?.code }} {{ formatMoney(inv.amount_foreign) }} @ {{ inv.exchange_rate }}
                </div>
              </td>
              <td class="font-mono text-xs font-semibold" style="color:var(--zv-blue-text)">{{ inv.invoice_ref }}</td>
              <td class="text-xs" style="color:var(--zv-text-muted)">{{ inv.account?.code }}</td>
              <td class="text-sm">{{ formatDate(inv.invoice_date) }}</td>
              <td class="text-sm" :class="isOverdue(inv) ? 'zv-num-danger font-semibold' : ''">
                {{ formatDate(inv.due_date) }}
                <span v-if="isOverdue(inv)" class="zv-badge zv-badge-danger text-xs ms-1">{{ t('common.overdue') }}</span>
              </td>
              <td class="text-end font-mono zv-num">{{ formatMoney(inv.invoice_amount) }}</td>
              <td class="text-end font-mono" style="color:var(--zv-green-text)">
                {{ inv.previous_collection > 0 ? formatMoney(inv.previous_collection) : '—' }}
              </td>
              <td class="text-end font-mono zv-num-blue font-semibold">{{ formatMoney(inv.net_balance) }}</td>
              <td v-if="!isPosted">
                <button type="button" @click="$emit('delete', inv.id)" class="zv-btn zv-btn-danger zv-btn-sm">
                  {{ t('settings.openingBalances.partnerInvoicesTab.remove') }}
                </button>
              </td>
            </tr>
            <!-- Total row -->
            <tr v-if="invoices.length > 0" class="font-semibold" style="background:var(--zv-bg-surface)">
              <td colspan="7" class="text-end text-sm" style="color:var(--zv-text-muted)">{{ t('settings.openingBalances.partnerInvoicesTab.totalNetBalance') }}</td>
              <td class="text-end font-mono zv-num-blue">{{ formatMoney(totalNet) }}</td>
              <td v-if="!isPosted"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { sub as moneySub, sum as moneySum } from '@/Utils/money'

const { t } = useI18n()

const props = defineProps({
  partnerType:  String,
  partners:     Array,
  invoices:     Array,
  arApAccounts: Array,
  currencies:   Array,
  isPosted:     Boolean,
})

const emit = defineEmits(['save', 'delete'])

const form = reactive({
  partner_id:          null,
  partner_type:        props.partnerType,
  account_id:          null,
  invoice_ref:         '',
  invoice_date:        '',
  due_date:            '',
  invoice_amount:      '',
  previous_collection: '',
  currency_id:         null,
  amount_foreign:      '',
  exchange_rate:       '',
})

const netBalance = computed(() => {
  const net = moneySub(form.invoice_amount || 0, form.previous_collection || 0)
  return net.startsWith('-') ? '0.00' : net
})

function calcNet() {} // triggers computed

const totalNet = computed(() =>
  moneySum((props.invoices ?? []).map(i => i.net_balance || 0))
)

const page = usePage()
const formErrors = computed(() => Object.values(page.props.errors ?? {}).flat().filter(Boolean))

// Round 7: the form is cleared only after the parent confirms the save worked.
function resetForm() {
  Object.assign(form, {
    partner_id: null, account_id: null, invoice_ref: '', invoice_date: '',
    due_date: '', invoice_amount: '', previous_collection: '',
    currency_id: null, amount_foreign: '', exchange_rate: '',
  })
}
function save() {
  emit('save', { ...form, partner_type: props.partnerType }, resetForm)
}

function isOverdue(inv) {
  if (!inv.due_date) return false
  return new Date(inv.due_date) < new Date()
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