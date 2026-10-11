<!--
══════════════════════════════════════════════════════════════════
  Accounting/FixedAssets/Manage.vue

  One fixed asset's page (Audit H17): its register figures and
  history, and the three events after purchase —
    • Sell / scrap — a full month of depreciation is charged in the
      disposal month (the preview shows it), then the asset leaves the
      books with its gain (424) or loss (545).
    • Impair — IAS 36 write-down (546), later depreciation spread over
      what is left.
    • Move — change of location, no ledger effect.
  Disposals and impairments can be cancelled (journal entries
  reversed), never deleted.

  @layout    AppLayout
  @route     accounting.fixed-assets.manage
  @props     asset: FixedAsset with history
             proceedsAccounts: Array — postable asset accounts
             buyers: Array — partners
             locations: Array — inventory locations
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader
      eyebrow="Fixed Assets"
      :title="`${asset.asset_code} — ${asset.name}`"
      :subtitle="t('accounting.fixedAssetsManage.subtitle')"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5"/>'
    >
      <template #actions>
        <Link :href="route('accounting.fixed-assets.index')" class="zv-btn zv-btn-ghost">{{ t('accounting.fixedAssetsManage.backToRegister') }}</Link>
      </template>
    </PageHeader>

    <!-- Register figures -->
    <div class="zv-card mb-5">
      <div class="zv-card-body fam-cards">
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.status') }}</div><div class="fam-big">{{ statusLabel(asset.status) }}</div></div>
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.cost') }}</div><div class="fam-big">{{ fmt(asset.cost_basis) }}</div></div>
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.accumulatedDepreciation') }}</div><div class="fam-big">{{ fmt(asset.accumulated_depreciation) }}</div></div>
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.accumulatedImpairment') }}</div><div class="fam-big">{{ fmt(asset.accumulated_impairment) }}</div></div>
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.netBookValue') }}</div><div class="fam-big">{{ fmt(nbv) }}</div></div>
        <div><div class="fam-label">{{ t('accounting.fixedAssetsManage.location') }}</div><div class="fam-big">{{ asset.inventory_location?.name_en ?? '—' }}</div></div>
      </div>
    </div>

    <template v-if="asset.status !== 'disposed'">
      <div class="fam-tabs mb-4">
        <button v-for="tab in tabs" :key="tab" type="button" class="zv-btn zv-btn-sm" :class="activeTab === tab ? 'zv-btn-primary' : 'zv-btn-ghost'" @click="activeTab = tab">{{ t('accounting.fixedAssetsManage.tab_' + tab) }}</button>
      </div>

      <!-- Sell / scrap -->
      <div v-if="activeTab === 'dispose'" class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.disposalType') }}</label>
              <select v-model="disposal.disposal_type" class="zv-input">
                <option value="sale">{{ t('accounting.fixedAssetsManage.sale') }}</option>
                <option value="scrap">{{ t('accounting.fixedAssetsManage.scrap') }}</option>
              </select>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.date') }}</label>
              <input v-model="disposal.disposal_date" type="date" class="zv-input" @change="loadPreview"/>
            </div>
            <template v-if="disposal.disposal_type === 'sale'">
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.fixedAssetsManage.buyer') }}</label>
                <select v-model="disposal.partner_id" class="zv-input">
                  <option :value="null">—</option>
                  <option v-for="b in buyers" :key="b.id" :value="b.id">{{ b.name_en }} ({{ b.code }})</option>
                </select>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.proceeds') }}</label>
                <input v-model="disposal.proceeds" type="number" step="0.01" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label">{{ t('accounting.fixedAssetsManage.vat') }}</label>
                <input v-model="disposal.vat_amount" type="number" step="0.01" min="0" class="zv-input"/>
              </div>
              <div class="field-group">
                <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.receivedInto') }}</label>
                <select v-model="disposal.proceeds_account_id" class="zv-input">
                  <option :value="null">—</option>
                  <option v-for="a in proceedsAccounts" :key="a.id" :value="a.id">{{ a.code }} {{ a.name_en }}</option>
                </select>
              </div>
            </template>
            <div class="field-group field-group-span-3">
              <label class="zv-label">{{ t('accounting.fixedAssetsManage.notes') }}</label>
              <input v-model="disposal.notes" type="text" class="zv-input"/>
            </div>
          </div>

          <div v-if="previewError" class="zv-alert zv-alert-danger mt-4"><span class="zv-alert-icon">⚠</span><div>{{ previewError }}</div></div>
          <div v-else-if="preview" class="fam-preview mt-4">
            <div><span>{{ t('accounting.fixedAssetsManage.monthDepreciation') }}</span><strong>{{ fmt(preview.month_depreciation) }}</strong></div>
            <div><span>{{ t('accounting.fixedAssetsManage.netBookValueAtDisposal') }}</span><strong>{{ fmt(preview.net_book_value) }}</strong></div>
            <div><span>{{ gainLoss >= 0 ? t('accounting.fixedAssetsManage.gain') : t('accounting.fixedAssetsManage.loss') }}</span><strong :class="gainLoss < 0 ? 'zv-text-danger' : ''">{{ fmt(Math.abs(gainLoss)) }}</strong></div>
          </div>
        </div>
        <div class="zv-card-footer flex justify-end">
          <button type="button" class="zv-btn zv-btn-danger" :disabled="disposal.processing || !!previewError" @click="submitDisposal">{{ t('accounting.fixedAssetsManage.postDisposal') }}</button>
        </div>
      </div>

      <!-- Impair -->
      <div v-if="activeTab === 'impair'" class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.date') }}</label>
              <input v-model="impairment.impairment_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.impairmentAmount') }}</label>
              <input v-model="impairment.amount" type="number" step="0.01" min="0" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.fixedAssetsManage.impairmentHint', { max: fmt(nbv) }) }}</p>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.reason') }}</label>
              <input v-model="impairment.reason" type="text" class="zv-input"/>
            </div>
          </div>
        </div>
        <div class="zv-card-footer flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="impairment.processing" @click="impairment.post(route('accounting.fixed-assets.impair', asset.id), { preserveScroll: true })">{{ t('accounting.fixedAssetsManage.postImpairment') }}</button>
        </div>
      </div>

      <!-- Move -->
      <div v-if="activeTab === 'transfer'" class="form-section mb-5">
        <div class="form-section-body">
          <div class="field-grid field-grid-3">
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.date') }}</label>
              <input v-model="transfer.transfer_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group">
              <label class="zv-label zv-label-required">{{ t('accounting.fixedAssetsManage.toLocation') }}</label>
              <select v-model="transfer.to_location_id" class="zv-input">
                <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name_en }}</option>
              </select>
            </div>
            <div class="field-group">
              <label class="zv-label">{{ t('accounting.fixedAssetsManage.notes') }}</label>
              <input v-model="transfer.notes" type="text" class="zv-input"/>
            </div>
          </div>
        </div>
        <div class="zv-card-footer flex justify-end">
          <button type="button" class="zv-btn zv-btn-primary" :disabled="transfer.processing || !transfer.to_location_id" @click="transfer.post(route('accounting.fixed-assets.transfer', asset.id), { preserveScroll: true })">{{ t('accounting.fixedAssetsManage.postTransfer') }}</button>
        </div>
      </div>
    </template>

    <!-- History -->
    <div class="zv-card mb-5">
      <div class="zv-card-header">{{ t('accounting.fixedAssetsManage.history') }}</div>
      <div class="zv-table-wrap">
        <table class="zv-table">
          <thead>
            <tr>
              <th>{{ t('accounting.fixedAssetsManage.date') }}</th>
              <th>{{ t('accounting.fixedAssetsManage.event') }}</th>
              <th>{{ t('accounting.fixedAssetsManage.details') }}</th>
              <th class="zv-table-num">{{ t('accounting.fixedAssetsManage.amount') }}</th>
              <th>{{ t('accounting.fixedAssetsManage.journalEntry') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in asset.disposals" :key="'d'+d.id" :class="d.status === 'cancelled' ? 'opacity-50' : ''">
              <td>{{ day(d.disposal_date) }}</td>
              <td>{{ d.disposal_type === 'sale' ? t('accounting.fixedAssetsManage.sale') : t('accounting.fixedAssetsManage.scrap') }}<span v-if="d.status === 'cancelled'"> ({{ t('accounting.fixedAssetsManage.cancelled') }})</span></td>
              <td>{{ t('accounting.fixedAssetsManage.nbvLabel') }} {{ fmt(d.net_book_value) }} · {{ t('accounting.fixedAssetsManage.proceeds') }} {{ fmt(d.proceeds) }}</td>
              <td class="zv-table-num" :class="Number(d.gain_loss) < 0 ? 'zv-text-danger' : ''">{{ fmt(d.gain_loss) }}</td>
              <td>{{ d.journal_entry?.entry_number ?? '—' }}</td>
              <td class="text-end"><button v-if="d.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="cancel('accounting.fixed-assets.disposals.cancel', d.id)">{{ t('accounting.fixedAssetsManage.cancel') }}</button></td>
            </tr>
            <tr v-for="i in asset.impairments" :key="'i'+i.id" :class="i.status === 'cancelled' ? 'opacity-50' : ''">
              <td>{{ day(i.impairment_date) }}</td>
              <td>{{ t('accounting.fixedAssetsManage.impairment') }}<span v-if="i.status === 'cancelled'"> ({{ t('accounting.fixedAssetsManage.cancelled') }})</span></td>
              <td>{{ i.reason }}</td>
              <td class="zv-table-num zv-text-danger">{{ fmt(-i.amount) }}</td>
              <td>{{ i.journal_entry?.entry_number ?? '—' }}</td>
              <td class="text-end"><button v-if="i.status === 'posted'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="cancel('accounting.fixed-assets.impairments.cancel', i.id)">{{ t('accounting.fixedAssetsManage.cancel') }}</button></td>
            </tr>
            <tr v-for="tr in asset.transfers" :key="'t'+tr.id">
              <td>{{ day(tr.transfer_date) }}</td>
              <td>{{ t('accounting.fixedAssetsManage.move') }}</td>
              <td>{{ tr.from_location?.name_en ?? '—' }} → {{ tr.to_location?.name_en ?? '—' }}</td>
              <td></td><td>—</td><td></td>
            </tr>
            <tr v-if="!asset.disposals.length && !asset.impairments.length && !asset.transfers.length">
              <td colspan="6" class="text-center opacity-60">{{ t('accounting.fixedAssetsManage.noHistory') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'

const { t } = useI18n()

const props = defineProps({
  asset: { type: Object, required: true },
  proceedsAccounts: { type: Array, default: () => [] },
  buyers: { type: Array, default: () => [] },
  locations: { type: Array, default: () => [] },
})

const tabs = ['dispose', 'impair', 'transfer']
const activeTab = ref('dispose')
const today = todayLocal()

const nbv = computed(() => Number(props.asset.cost_basis || 0) - Number(props.asset.accumulated_depreciation || 0) - Number(props.asset.accumulated_impairment || 0))

const disposal = useForm({ disposal_type: 'sale', disposal_date: today, partner_id: null, proceeds: '', vat_amount: '', proceeds_account_id: null, notes: '' })
const impairment = useForm({ impairment_date: today, amount: '', reason: '' })
const transfer = useForm({ transfer_date: today, to_location_id: null, notes: '' })

// Preview of the full-month depreciation and the net book value on the chosen date.
const preview = ref(null)
const previewError = ref('')
async function loadPreview() {
  previewError.value = ''
  preview.value = null
  if (!disposal.disposal_date || props.asset.status === 'disposed') return
  const res = await fetch(route('accounting.fixed-assets.disposal-preview', props.asset.id) + '?disposal_date=' + disposal.disposal_date, { headers: { Accept: 'application/json' } })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) { previewError.value = body.message || 'Error'; return }
  preview.value = body
}
onMounted(loadPreview)

const gainLoss = computed(() => {
  if (!preview.value) return 0
  const proceeds = disposal.disposal_type === 'sale' ? Number(disposal.proceeds || 0) : 0
  return proceeds - Number(preview.value.net_book_value || 0)
})

function submitDisposal() {
  if (!confirm(t('accounting.fixedAssetsManage.confirmDisposal'))) return
  disposal.post(route('accounting.fixed-assets.dispose', props.asset.id), { preserveScroll: true, onSuccess: loadPreview })
}

function cancel(routeName, id) {
  const reason = window.prompt(t('accounting.fixedAssetsManage.cancelPrompt'))
  if (!reason) return
  router.patch(route(routeName, id), { cancel_reason: reason }, { preserveScroll: true, onSuccess: loadPreview })
}

function statusLabel(s) { return t('accounting.fixedAssetsManage.status_' + s) }
function day(d) { return d ? String(d).slice(0, 10) : '—' }
function fmt(n) { return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<style scoped>
.fam-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; }
.fam-label { font-size: 12px; opacity: .7; margin-bottom: 4px; }
.fam-big { font-size: 1.125rem; font-weight: 600; }
.fam-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.fam-preview { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; padding: 12px; border: 1px solid var(--zv-border); border-radius: 8px; }
.fam-preview div { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
</style>
