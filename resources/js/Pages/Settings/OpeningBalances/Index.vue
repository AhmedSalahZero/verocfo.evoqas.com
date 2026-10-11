<!--
══════════════════════════════════════════════════════════════════
  Settings/OpeningBalances/Index.vue

  6-tab Opening Balances page:
  1. Chart of Accounts — manual account balances
  2. Customers (AR)    — invoice-by-invoice repeater
  3. Suppliers (AP)    — invoice-by-invoice repeater
  4. Notes Receivable  — checks received
  5. Notes Payable     — checks issued
  6. Inventory Stock   — qty + value per product/location

  Bottom bar: Validate + Post button (irreversible).
  Once posted, all tabs become read-only.

  @layout    AppLayout
  @route     settings.opening-balances.index
══════════════════════════════════════════════════════════════════
-->
<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.openingBalances.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.openingBalances.index.openingBalances') }}</h1>
          <p class="zv-page-subtitle">
            {{ t('settings.openingBalances.index.asOf') }}
            <strong>{{ formatDate(session.as_of_date) }}</strong>
          </p>
        </div>

        <!-- Posted badge or Validate + Post button -->
        <div v-if="session.is_posted" class="flex items-center gap-2">
          <span class="zv-badge zv-badge-green text-sm px-3 py-1.5">
            <span class="zv-status-dot zv-status-dot-green"></span>
            {{ t('settings.openingBalances.index.posted') }} — {{ formatDate(session.posted_at) }}
          </span>
        </div>
        <div v-else class="flex items-center gap-3">
          <button type="button" @click="runValidate" :disabled="validating"
                  class="zv-btn zv-btn-secondary">
            <div v-if="validating" class="zv-spinner w-4 h-4 me-2"></div>
            {{ validating ? t('settings.openingBalances.index.validating') : t('settings.openingBalances.index.validate') }}
          </button>
          <button type="button" @click="confirmPost"
                  :disabled="!lastValidation?.can_post"
                  class="zv-btn zv-btn-primary">
            {{ t('settings.openingBalances.index.postOpeningBalances') }}
          </button>
        </div>
      </div>

      <!-- ── Flash ─────────────────────────────────────────────── -->
      <div v-if="$page.props.flash?.success" class="zv-alert zv-alert-success mb-4">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="zv-alert zv-alert-danger mb-4">{{ $page.props.flash.error }}</div>

      <!-- ── Validation Result ──────────────────────────────────── -->
      <Transition name="section-slide">
        <div v-if="lastValidation" class="zv-card mb-5">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.openingBalances.index.validationResult') }}</h2>
            <span class="ms-auto zv-badge" :class="lastValidation.can_post ? 'zv-badge-green' : 'zv-badge-danger'">
              {{ lastValidation.can_post ? t('settings.openingBalances.index.readyToPost') : t('settings.openingBalances.index.errorsFound') }}
            </span>
          </div>
          <div class="zv-card-body">
            <!-- Trial balance summary -->
            <div class="grid grid-cols-3 gap-4 mb-4">
              <div class="ob-summary-chip">
                <div class="ob-summary-label">{{ t('settings.openingBalances.index.totalDebits') }}</div>
                <div class="ob-summary-value zv-num-blue">{{ formatMoney(lastValidation.debits) }}</div>
              </div>
              <div class="ob-summary-chip">
                <div class="ob-summary-label">{{ t('settings.openingBalances.index.totalCredits') }}</div>
                <div class="ob-summary-value zv-num-blue">{{ formatMoney(lastValidation.credits) }}</div>
              </div>
              <div class="ob-summary-chip" :class="lastValidation.balanced ? 'ob-chip--ok' : 'ob-chip--err'">
                <div class="ob-summary-label">{{ t('settings.openingBalances.index.difference') }}</div>
                <div class="ob-summary-value" :class="lastValidation.balanced ? 'zv-num-green' : 'zv-num-danger'">
                  {{ formatMoney(Math.abs(lastValidation.debits - lastValidation.credits)) }}
                </div>
              </div>
            </div>
            <!-- Errors -->
            <div v-if="lastValidation.errors.length" class="space-y-2 mb-3">
              <div v-for="err in lastValidation.errors" :key="err"
                   class="flex items-start gap-2 text-sm" style="color:var(--zv-danger-text)">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                {{ err }}
              </div>
            </div>
            <!-- Warnings -->
            <div v-if="lastValidation.warnings.length" class="space-y-2">
              <div v-for="w in lastValidation.warnings" :key="w"
                   class="flex items-start gap-2 text-sm" style="color:var(--zv-amber-text)">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                {{ w }}
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- ── Tabs ──────────────────────────────────────────────── -->
      <div class="ob-tabs mb-5">
        <button v-for="tab in tabs" :key="tab.key"
                type="button" class="ob-tab"
                :class="{ 'ob-tab--active': activeTab === tab.key }"
                @click="activeTab = tab.key">
          <span v-html="tab.icon" class="w-4 h-4"></span>
          {{ t(tab.label) }}
          <span v-if="tab.count > 0" class="ob-tab-count">{{ tab.count }}</span>
        </button>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 1 — CHART OF ACCOUNTS
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'coa'">
        <div class="zv-card mb-4">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.openingBalances.index.accountOpeningBalances') }}</h2>
            <div class="ms-auto text-xs" style="color:var(--zv-text-muted)">
              {{ t('settings.openingBalances.index.aRAPAndInventoryAccountsAre') }}
            </div>
          </div>

          <!-- Add account form (hidden if posted) -->
          <div v-if="!session.is_posted" class="p-4 border-b" style="border-color:var(--zv-border);background:var(--zv-bg-surface)">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
              <div class="sm:col-span-2">
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.account') }}</label>
                <select v-model="coaForm.account_id" @change="onCoaAccountChange" class="zv-select">
                  <option :value="null">— {{ t('settings.openingBalances.index.selectAccount') }} —</option>
                  <optgroup v-for="grp in coaAccountGroups" :key="grp.label" :label="grp.label">
                    <option v-for="acc in grp.accounts" :key="acc.id" :value="acc.id">
                      {{ acc.code }} — {{ acc.name_en }}
                    </option>
                  </optgroup>
                </select>
              </div>
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.side') }}</label>
                <select v-model="coaForm.entry_side" class="zv-select">
                  <option value="debit">{{ t('settings.openingBalances.index.debit') }}</option>
                  <option value="credit">{{ t('settings.openingBalances.index.credit') }}</option>
                </select>
              </div>
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.currency') }}</label>
                <!-- Locked: account has a fixed currency (e.g. Petty Cash USD) -->
                <div v-if="coaForm.account_currency"
                     class="zv-input flex items-center gap-2"
                     style="background:var(--zv-blue-light);cursor:not-allowed">
                  <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--zv-blue-text)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                  <span class="text-sm font-semibold" style="color:var(--zv-blue-text)">
                    {{ currencies.find(c => c.id == coaForm.account_currency)?.code ?? 'Foreign' }}
                  </span>
                  <span class="text-xs ms-1" style="color:var(--zv-text-muted)">({{ t('settings.openingBalances.index.setByAccount') }})</span>
                </div>
                <!-- Free: user picks currency -->
                <select v-else v-model="coaForm.currency_id" class="zv-select" @change="calcCoaEgp">
                  <option :value="null">EGP ({{ t('settings.openingBalances.index.functional') }})</option>
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_en }}</option>
                </select>
              </div>

              <!-- EGP amount — shown when functional currency -->
              <div v-if="!coaForm.currency_id">
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.amountEGP') }}</label>
                <input v-model="coaForm.amount" type="number" step="0.01" min="0"
                       class="zv-input font-mono text-end" placeholder="0.00" />
              </div>

              <!-- Foreign currency fields — shown when non-EGP selected -->
              <template v-if="coaForm.currency_id">
                <div>
                  <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.amountInCurrency') }}</label>
                  <input v-model="coaForm.amount_foreign" type="number" step="0.01" min="0"
                         class="zv-input font-mono text-end" placeholder="0.00"
                         @input="calcCoaEgp" />
                </div>
                <div>
                  <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.exchangeRate1ForeignEGP') }}</label>
                  <input v-model="coaForm.exchange_rate" type="number" step="0.000001" min="0"
                         class="zv-input font-mono text-end" placeholder="0.000000"
                         @input="calcCoaEgp" />
                </div>
                <div>
                  <label class="zv-label">{{ t('settings.openingBalances.index.eGPEquivalent') }}</label>
                  <input :value="formatMoney(coaEgpEquivalent)"
                         type="text" class="zv-input font-mono text-end zv-num-blue" readonly
                         style="background:var(--zv-blue-light)" />
                </div>
              </template>

              <div class="flex items-end" :class="coaForm.currency_id ? 'sm:col-start-4' : ''">
                <button type="button" @click="saveCoaBalance"
                        :disabled="!coaForm.account_id || (!coaForm.amount && !coaForm.amount_foreign)"
                        class="zv-btn zv-btn-primary w-full">
                  {{ t('settings.openingBalances.index.save') }}
                </button>
              </div>
            </div>
          </div>

          <!-- Balances table -->
          <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('settings.openingBalances.index.account') }}</th>
                  <th>{{ t('common.type') }}</th>
                  <th class="text-center">{{ t('settings.openingBalances.index.side') }}</th>
                  <th class="text-end">{{ t('settings.openingBalances.index.amountEGP') }}</th>
                  <th class="text-end">{{ t('settings.openingBalances.index.foreignAmount') }}</th>
                  <th class="text-center">{{ t('settings.openingBalances.index.source') }}</th>
                  <th v-if="!session.is_posted"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="allCoaBalances.length === 0">
                  <td :colspan="session.is_posted ? 6 : 7">
                    <div class="zv-empty py-8">
                      <div class="zv-empty-title">{{ t('settings.openingBalances.index.noBalancesEnteredYet') }}</div>
                    </div>
                  </td>
                </tr>
                <tr v-for="bal in allCoaBalances" :key="bal.id"
                    :class="{ 'opacity-60': bal.auto_populated }">
                  <td>
                    <span class="font-mono text-xs font-semibold" style="color:var(--zv-blue-text)">{{ bal.account?.code }}</span>
                    <span class="ms-2 text-sm">{{ bal.account?.name_en }}</span>
                  </td>
                  <td class="text-xs" style="color:var(--zv-text-muted)">{{ bal.account?.account_type }}</td>
                  <td class="text-center">
                    <span class="zv-badge text-xs" :class="bal.entry_side === 'debit' ? 'zv-badge-blue' : 'zv-badge-amber'">
                      {{ bal.entry_side === 'debit' ? t('settings.openingBalances.index.debit') : t('settings.openingBalances.index.credit') }}
                    </span>
                  </td>
                  <td class="text-end font-mono zv-num font-semibold">{{ formatMoney(bal.amount) }}</td>
                  <td class="text-end font-mono text-sm" style="color:var(--zv-text-muted)">
                    <template v-if="bal.amount_foreign">
                      {{ bal.currency?.code }} {{ formatMoney(bal.amount_foreign) }}
                    </template>
                    <template v-else>—</template>
                  </td>
                  <td class="text-center">
                    <span class="zv-badge text-xs" :class="bal.auto_populated ? 'zv-badge-teal' : 'zv-badge-gray'">
                      {{ bal.auto_populated ? t('settings.openingBalances.index.auto') : t('settings.openingBalances.index.manual') }}
                    </span>
                  </td>
                  <td v-if="!session.is_posted">
                    <button v-if="!bal.auto_populated" type="button"
                            @click="deleteCoaBalance(bal.id)"
                            class="zv-btn zv-btn-danger zv-btn-sm">{{ t('settings.openingBalances.index.remove') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 2 — CUSTOMERS (AR)
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'ar'">
        <PartnerInvoicesTab
          partner-type="customer"
          :partners="customers"
          :invoices="customerInvoices"
          :ar-ap-accounts="arAccounts"
          :currencies="currencies"
          :is-posted="session.is_posted"
          @save="saveInvoice"
          @delete="deleteInvoice"
        />
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 3 — SUPPLIERS (AP)
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'ap'">
        <PartnerInvoicesTab
          partner-type="supplier"
          :partners="suppliers"
          :invoices="supplierInvoices"
          :ar-ap-accounts="apAccounts"
          :currencies="currencies"
          :is-posted="session.is_posted"
          @save="saveInvoice"
          @delete="deleteInvoice"
        />
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 4 — NOTES RECEIVABLE
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'nr'">
        <NotesTab
          note-type="receivable"
          :notes="notesReceivable"
          :partners="customers"
          :bank-accounts="bankAccounts"
          :currencies="currencies"
          :is-posted="session.is_posted"
          @save="saveNote"
          @delete="deleteNote"
        />
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 5 — NOTES PAYABLE
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'np'">
        <NotesTab
          note-type="payable"
          :notes="notesPayable"
          :partners="suppliers"
          :bank-accounts="bankAccounts"
          :currencies="currencies"
          :is-posted="session.is_posted"
          @save="saveNote"
          @delete="deleteNote"
        />
      </div>

      <!-- ══════════════════════════════════════════════════════
           TAB 6 — INVENTORY STOCK
      ══════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'stock'">
        <div class="zv-card">
          <div class="zv-card-header">
            <h2 class="zv-card-title">{{ t('settings.openingBalances.index.openingInventoryStock') }}</h2>
          </div>

          <!-- Add stock form -->
          <div v-if="!session.is_posted" class="p-4 border-b" style="border-color:var(--zv-border);background:var(--zv-bg-surface)">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">

              <!-- Category filter -->
              <div>
                <label class="zv-label">{{ t('settings.openingBalances.index.filterByCategory') }}</label>
                <select v-model="stockForm.category_id" class="zv-select" @change="stockForm.product_id = null">
                  <option :value="null">{{ t('settings.openingBalances.index.allCategories') }}</option>
                  <option v-for="cat in productCategories" :key="cat.id" :value="cat.id">
                    {{ cat.level === 2 ? '↳ ' : '' }}{{ cat.name_en }}
                  </option>
                </select>
              </div>

              <!-- Product selector (filtered by category) -->
              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.product') }}</label>
                <select v-model="stockForm.product_id" class="zv-select" @change="onProductChange">
                  <option :value="null">— {{ t('settings.openingBalances.index.selectProduct') }} —</option>
                  <option v-for="p in filteredStockedProducts" :key="p.id" :value="p.id">
                    {{ p.code }} — {{ p.name_en }}
                  </option>
                </select>
              </div>

              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.location') }}</label>
                <select v-model="stockForm.location_id" class="zv-select">
                  <option :value="null">— {{ t('settings.openingBalances.index.selectLocation') }} —</option>
                  <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name_en }}</option>
                </select>
              </div>

              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.quantity') }} <span class="text-xs font-normal">({{ stockUomCode }})</span></label>
                <input v-model="stockForm.quantity" type="number" step="0.0001" min="0.0001"
                       class="zv-input font-mono text-end" placeholder="0.0000" />
              </div>

              <div>
                <label class="zv-label zv-label-required">{{ t('settings.openingBalances.index.totalValueEGP') }}</label>
                <input v-model="stockForm.total_value" type="number" step="0.01" min="0"
                       class="zv-input font-mono text-end" placeholder="0.00" />
              </div>

              <div class="sm:col-span-2">
                <!-- WAC preview -->
                <div v-if="stockForm.quantity && stockForm.total_value" class="ob-wac-preview">
                  <span>{{ t('settings.openingBalances.index.wACPer') }} {{ stockUomCode }}:</span>
                  <strong class="font-mono zv-num-blue ms-2">
                    {{ formatMoney(calcWac()) }} EGP
                  </strong>
                </div>
              </div>

              <div class="flex items-end">
                <button type="button" @click="saveStock"
                        :disabled="!stockForm.product_id || !stockForm.location_id || !stockForm.quantity"
                        class="zv-btn zv-btn-primary w-full">
                  {{ t('settings.openingBalances.index.saveStock') }}
                </button>
              </div>
            </div>
          </div>

          <!-- Stock table -->
          <div class="zv-table-wrap" style="border:none;box-shadow:none;border-radius:0">
            <table class="zv-table">
              <thead>
                <tr>
                  <th>{{ t('settings.openingBalances.index.product') }}</th>
                  <th>{{ t('settings.openingBalances.index.location') }}</th>
                  <th class="text-end">{{ t('settings.openingBalances.index.quantity') }}</th>
                  <th>{{ t('settings.openingBalances.index.uOM') }}</th>
                  <th class="text-end">{{ t('settings.openingBalances.index.totalValue') }}</th>
                  <th class="text-end">{{ t('settings.openingBalances.index.wACUnit') }}</th>
                  <th v-if="!session.is_posted"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="inventoryStock.length === 0">
                  <td :colspan="session.is_posted ? 6 : 7">
                    <div class="zv-empty py-8">
                      <div class="zv-empty-title">{{ t('settings.openingBalances.index.noOpeningStockEntered') }}</div>
                      <div class="zv-empty-sub">{{ t('settings.openingBalances.index.addStockUsingTheFormAbove') }}</div>
                    </div>
                  </td>
                </tr>
                <tr v-for="s in inventoryStock" :key="s.id">
                  <td>
                    <div class="font-medium text-sm">{{ s.product?.name_en }}</div>
                    <div class="font-mono text-xs" style="color:var(--zv-text-muted)">{{ s.product?.code }}</div>
                  </td>
                  <td class="text-sm" style="color:var(--zv-text-secondary)">{{ s.location?.name_en }}</td>
                  <td class="text-end font-mono zv-num font-semibold">{{ formatQty(s.quantity) }}</td>
                  <td class="font-mono text-xs" style="color:var(--zv-text-muted)">{{ s.product?.primary_uom?.code }}</td>
                  <td class="text-end font-mono zv-num">{{ formatMoney(s.total_value) }}</td>
                  <td class="text-end font-mono zv-num-blue font-semibold">{{ formatMoney(s.unit_cost) }}</td>
                  <td v-if="!session.is_posted">
                    <button type="button" @click="deleteStockItem(s.id)" class="zv-btn zv-btn-danger zv-btn-sm">
                      {{ t('settings.openingBalances.index.remove') }}
                    </button>
                  </td>
                </tr>
                <!-- Totals -->
                <tr v-if="inventoryStock.length > 0" class="font-semibold" style="background:var(--zv-bg-surface)">
                  <td colspan="4" class="text-end text-sm" style="color:var(--zv-text-muted)">{{ t('settings.openingBalances.index.totalOpeningStockValue') }}</td>
                  <td class="text-end font-mono zv-num-blue">{{ formatMoney(totalStockValue) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

    <!-- ── Post Confirmation Modal ─────────────────────────────── -->
    <Teleport to="body">
      <Transition name="scale">
        <div v-if="showPostModal" class="zv-modal-backdrop" @click.self="showPostModal = false">
          <div class="zv-modal zv-modal-sm">
            <div class="zv-modal-header">
              <h3 class="zv-modal-title">{{ t('settings.openingBalances.index.postOpeningBalances') }}</h3>
              <button type="button" @click="showPostModal = false" class="zv-modal-close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>
            </div>
            <div class="zv-modal-body">
              <p class="text-sm" style="color:var(--zv-text-secondary)">
                {{ t('settings.openingBalances.index.youAreAboutToPostThe') }}
              </p>
              <div class="mt-3 p-3 rounded-lg" style="background:var(--zv-danger-light);border:1px solid var(--zv-danger-border)">
                <p class="text-xs font-semibold" style="color:var(--zv-danger-text)">
                  {{ t('settings.openingBalances.index.thisIsIrreversibleUseJournalEntries') }}
                </p>
              </div>
            </div>
            <div class="zv-modal-footer">
              <button type="button" @click="showPostModal = false" class="zv-btn zv-btn-ghost">{{ t('common.cancel') }}</button>
              <button type="button" @click="doPost" :disabled="posting" class="zv-btn zv-btn-danger">
                <div v-if="posting" class="zv-spinner w-4 h-4 me-2"></div>
                {{ posting ? t('settings.openingBalances.index.posting') : t('settings.openingBalances.index.postOpeningBalancesIConfirm') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

</template>

<script setup>
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PartnerInvoicesTab from './PartnerInvoicesTab.vue'
import NotesTab from './NotesTab.vue'
import { div as moneyDiv, mul as moneyMul, round as moneyRound, sum as moneySum } from '@/Utils/money'

const { t } = useI18n()
const page  = usePage()

const props = defineProps({
  session:           Object,
  coa_balances:      Array,
  ar_ap_summary:     Object,
  partner_invoices:  Array,
  notes_receivable:  Array,
  notes_payable:     Array,
  inventory_stock:   Array,
  postable_accounts: Array,
  ar_accounts:       Array,
  ap_accounts:       Array,
  bank_accounts:     Array,
  customers:         Array,
  suppliers:         Array,
  currencies:        Array,
  stocked_products:  Array,
  product_categories:Array,
  locations:         Array,
})

// ── Derived ───────────────────────────────────────────────────────
const customerInvoices = computed(() =>
  (props.partner_invoices ?? []).filter(i => i.partner_type === 'customer')
)
const supplierInvoices = computed(() =>
  (props.partner_invoices ?? []).filter(i => i.partner_type === 'supplier')
)
const notesReceivable = computed(() => props.notes_receivable ?? [])
const notesPayable    = computed(() => props.notes_payable ?? [])
const inventoryStock  = computed(() => props.inventory_stock ?? [])

const allCoaBalances  = computed(() => props.coa_balances ?? [])

// ── Account selectors — expose props as computed for child components ──
const arAccounts   = computed(() => props.ar_accounts   ?? [])
const apAccounts   = computed(() => props.ap_accounts   ?? [])
const bankAccounts = computed(() => props.bank_accounts ?? [])
const autoPopulatedIds = computed(() =>
  allCoaBalances.value.filter(b => b.auto_populated).map(b => b.account_id)
)
function isAutoPopulated(accountId) { return autoPopulatedIds.value.includes(accountId) }

const totalStockValue = computed(() =>
  Number(moneySum((inventoryStock.value ?? []).map(s => s.total_value || 0)))
)

// ── Tabs ──────────────────────────────────────────────────────────
const activeTab = ref('coa')

const tabs = computed(() => [
  { key: 'coa',   label: 'Chart of Accounts',  count: allCoaBalances.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>' },
  { key: 'ar',    label: 'Customers (AR)',      count: customerInvoices.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' },
  { key: 'ap',    label: 'Suppliers (AP)',      count: supplierInvoices.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 00-1-1h-2a1 1 0 00-1 1v5m4 0H9"/></svg>' },
  { key: 'nr',    label: 'Notes Receivable',    count: notesReceivable.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2m-3 7l2 2 4-4"/></svg>' },
  { key: 'np',    label: 'Notes Payable',       count: notesPayable.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 9a2 2 0 10-4 0v5a2 2 0 01-2 2h6m-6-4h4m8 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' },
  { key: 'stock', label: 'Inventory Stock',     count: inventoryStock.value.length,
    icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' },
])

// ── COA form ──────────────────────────────────────────────────────
const coaForm = reactive({
  account_id:       null,
  entry_side:       'debit',
  amount:           '',
  currency_id:      null,
  account_currency: null,  // locked when account has fixed currency
  amount_foreign:   '',
  exchange_rate:    '',
})

// Round 7: exact decimal maths, same rule as the server (foreign x rate,
// 8 decimals, then rounded half-up to 2) so the figure shown is the figure saved.
const coaEgpEquivalent = computed(() => {
  if (!coaForm.amount_foreign || !coaForm.exchange_rate) return '0.00'
  return moneyRound(moneyMul(coaForm.amount_foreign, coaForm.exchange_rate, 8), 2)
})

function onCoaAccountChange() {
  const acc = (props.postable_accounts ?? []).find(a => a.id == coaForm.account_id)
  if (acc) {
    // Auto-set debit/credit side from normal_balance
    coaForm.entry_side = acc.normal_balance === 'debit' ? 'debit' : 'credit'
    // Auto-set currency if account is currency-enabled (e.g. Petty Cash USD)
    if (acc.allow_currency && acc.currency_id) {
      coaForm.currency_id      = acc.currency_id
      coaForm.account_currency = acc.currency_id  // locked — cannot change
    } else {
      coaForm.currency_id      = null
      coaForm.account_currency = null
    }
  }
}

function calcCoaEgp() {
  if (coaForm.currency_id && coaForm.amount_foreign && coaForm.exchange_rate) {
    coaForm.amount = coaEgpEquivalent.value
  }
}

const coaAccountGroups = computed(() => {
  const groups = {}
  ;(props.postable_accounts ?? []).forEach(acc => {
    if (!groups[acc.account_type]) groups[acc.account_type] = { label: acc.account_type, accounts: [] }
    groups[acc.account_type].accounts.push(acc)
  })
  return Object.values(groups)
})

// A save counts as successful only when the server neither refused it
// (flash.error) nor returned validation errors. Only then is the form cleared.
function saved(page) {
  return !page?.props?.flash?.error && !Object.keys(page?.props?.errors ?? {}).length
}

function saveCoaBalance() {
  const foreign = !!coaForm.currency_id
  const payload = {
    ...coaForm,
    amount: foreign ? coaEgpEquivalent.value : String(coaForm.amount),
    // EGP entry: never send a stale foreign amount / rate left from a previous selection
    currency_id:    foreign ? coaForm.currency_id : null,
    amount_foreign: foreign ? coaForm.amount_foreign : null,
    exchange_rate:  foreign ? coaForm.exchange_rate : null,
  }
  router.post(route('settings.opening-balances.coa.save'), payload, {
    preserveScroll: true,
    onSuccess: (page) => {
      if (!saved(page)) return
      Object.assign(coaForm, {
        account_id: null, entry_side: 'debit', amount: '',
        currency_id: null, account_currency: null, amount_foreign: '', exchange_rate: '',
      })
    },
  })
}

function deleteCoaBalance(id) {
  router.delete(route('settings.opening-balances.coa.delete', id), { preserveScroll: true })
}

// ── Partner Invoices ──────────────────────────────────────────────
// The tab passes `reset` so its form is cleared only when the save succeeded.
function saveInvoice(data, reset) {
  router.post(route('settings.opening-balances.invoices.save'), data, {
    preserveScroll: true,
    onSuccess: (page) => { if (saved(page)) reset?.() },
  })
}
function deleteInvoice(id) {
  router.delete(route('settings.opening-balances.invoices.delete', id), { preserveScroll: true })
}

// ── Notes ─────────────────────────────────────────────────────────
function saveNote({ type, data }, reset) {
  const routeName = type === 'receivable'
    ? 'settings.opening-balances.nr.save'
    : 'settings.opening-balances.np.save'
  router.post(route(routeName), data, {
    preserveScroll: true,
    onSuccess: (page) => { if (saved(page)) reset?.() },
  })
}
function deleteNote({ type, id }) {
  const routeName = type === 'receivable'
    ? 'settings.opening-balances.nr.delete'
    : 'settings.opening-balances.np.delete'
  router.delete(route(routeName, id), { preserveScroll: true })
}

// ── Inventory Stock ───────────────────────────────────────────────
const stockForm = reactive({
  category_id: null,
  product_id:  null,
  location_id: null,
  uom_id:      null,
  quantity:    '',
  total_value: '',
})

const productCategories = computed(() => props.product_categories ?? [])

const filteredStockedProducts = computed(() => {
  const all = props.stocked_products ?? []
  if (!stockForm.category_id) return all
  return all.filter(p => p.category_id == stockForm.category_id)
})

const stockUomCode = computed(() => {
  const p = (props.stocked_products ?? []).find(p => p.id == stockForm.product_id)
  return p?.primary_uom?.code ?? ''
})

function onProductChange() {
  const p = (props.stocked_products ?? []).find(p => p.id == stockForm.product_id)
  stockForm.uom_id = p?.primary_uom_id ?? null
}

function calcWac() {
  if (!stockForm.quantity || !stockForm.total_value) return 0
  return Number(moneyDiv(stockForm.total_value, stockForm.quantity, 4))
}

function saveStock() {
  router.post(route('settings.opening-balances.stock.save'), {
    product_id:  stockForm.product_id,
    location_id: stockForm.location_id,
    uom_id:      stockForm.uom_id,
    quantity:    stockForm.quantity,
    total_value: stockForm.total_value,
  }, {
    preserveScroll: true,
    onSuccess: (page) => {
      if (!saved(page)) return
      Object.assign(stockForm, {
        category_id: null, product_id: null, location_id: null,
        uom_id: null, quantity: '', total_value: '',
      })
    },
  })
}
function deleteStockItem(id) {
  router.delete(route('settings.opening-balances.stock.delete', id), { preserveScroll: true })
}

// ── Validate + Post ───────────────────────────────────────────────
const validating     = ref(false)
const lastValidation = ref(null)
const showPostModal  = ref(false)
const posting        = ref(false)

async function runValidate() {
  validating.value = true
  try {
    const res = await window.axios.post(route('settings.opening-balances.validate'))
    lastValidation.value = res.data
  } catch { lastValidation.value = null }
  finally { validating.value = false }
}

// Any change to the data (add / remove / post) makes an earlier validation
// result out of date — clear it so "Ready to post" is never shown for
// numbers that have since changed.
let offSuccess = null
onMounted(() => { offSuccess = router.on('success', () => { lastValidation.value = null }) })
onUnmounted(() => { if (typeof offSuccess === 'function') offSuccess() })

function confirmPost() { showPostModal.value = true }

function doPost() {
  posting.value = true
  router.post(route('settings.opening-balances.post'), {}, {
    preserveScroll: true,
    onFinish: () => { posting.value = false; showPostModal.value = false },
  })
}

// ── Formatting ────────────────────────────────────────────────────
function formatDate(date) {
  if (!date) return '—'
  return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date))
}
function formatMoney(val) {
  if (!val) return '0.00'
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}
function formatQty(val) {
  if (!val) return '0.0000'
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 4 }).format(val)
}
</script>

<style scoped>
/* ── Tabs ───────────────────────────────────────────────────── */
.ob-tabs {
  display: flex;
  gap: 0.25rem;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.25rem;
  overflow-x: auto;
}
.ob-tab {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: var(--zv-radius);
  font-size: 0.8125rem; font-weight: 500;
  color: var(--zv-text-secondary);
  background: transparent; border: none; cursor: pointer;
  white-space: nowrap;
  transition: var(--zv-transition);
}
.ob-tab:hover { background: var(--zv-bg-hover); color: var(--zv-text-primary); }
.ob-tab--active { background: var(--zv-nav-bg); color: #fff; }
.ob-tab-count {
  background: rgba(255,255,255,0.2); color: inherit;
  font-size: 0.6875rem; font-weight: 700;
  padding: 0.125rem 0.4rem; border-radius: 9999px;
}

/* ── Summary chips ──────────────────────────────────────────── */
.ob-summary-chip {
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.875rem 1rem;
}
.ob-chip--ok   { border-color: var(--zv-green-border); background: var(--zv-green-light); }
.ob-chip--err  { border-color: var(--zv-danger-border); background: var(--zv-danger-light); }
.ob-summary-label { font-size: 0.6875rem; color: var(--zv-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.ob-summary-value { font-size: 1.125rem; font-weight: 700; font-family: monospace; margin-top: 0.25rem; }

/* ── WAC preview ────────────────────────────────────────────── */
.ob-wac-preview {
  display: flex; align-items: center;
  font-size: 0.875rem;
  color: var(--zv-text-secondary);
  padding: 0.5rem 0.75rem;
  background: var(--zv-blue-light);
  border: 1px solid var(--zv-blue-border);
  border-radius: var(--zv-radius-md);
}

/* ── teal badge ─────────────────────────────────────────────── */
:deep(.zv-badge-teal) {
  background: #E0F7FA; color: #006064; border: 1px solid #80DEEA;
}

.section-slide-enter-active { transition: all 0.25s ease; }
.section-slide-leave-active { transition: all 0.2s ease; }
.section-slide-enter-from, .section-slide-leave-to { opacity: 0; transform: translateY(-8px); }
</style>