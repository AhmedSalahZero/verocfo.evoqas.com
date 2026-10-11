<!--
══════════════════════════════════════════════════════════════════
  Settings/ChartOfAccounts/Index.vue

  Displays the full Chart of Accounts in a two-section tree:
  Balance Sheet (Assets / Liabilities / Equity) and
  Income Statement (Revenue / Expenses).

  Each class is shown as an expandable section.
  System accounts show a lock icon — no edit/toggle actions.
  User accounts show Edit and Toggle actions.

  Search filters the visible accounts in real time (client-side).

  Design: premium tree layout using app.css design tokens.
  Class headers use full-width colored banners.
  Account rows are indented by level with connecting lines.

  @layout    AppLayout
  @route     settings.coa.index
  @props     tree: { balance_sheet: {...}, income_statement: {...} }
             filters: { search, section, class }
══════════════════════════════════════════════════════════════════
-->

<template>
    <div class="zv-page">

      <!-- ── Page Header ──────────────────────────────────────── -->
      <div class="zv-page-header">
        <div>
          <div class="zv-section-eyebrow">{{ t('settings.chartOfAccounts.index.settings') }}</div>
          <h1 class="zv-page-title">{{ t('settings.chartOfAccounts.index.chartOfAccounts') }}</h1>
          <p class="zv-page-subtitle">{{ t('settings.chartOfAccounts.index.yourIFRSAlignedAccountStructureSystem') }}</p>
        </div>
        <Link :href="route('settings.coa.create')" class="zv-btn zv-btn-primary">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          {{ t('settings.chartOfAccounts.index.newAccount') }}
        </Link>
      </div>

      <!-- ── Search + Section Tabs ────────────────────────────── -->
      <div class="coa-toolbar">
        <div class="zv-search-input" style="max-width:320px">
          <svg class="w-4 h-4 flex-shrink-0" style="color:var(--zv-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input v-model="search" type="text" :placeholder="t('settings.chartOfAccounts.index.searchByCodeOrName')" />
        </div>

        <div class="coa-tabs">
          <button v-for="tab in tabs" :key="tab.value"
                  type="button"
                  class="coa-tab"
                  :class="{ 'coa-tab--active': activeSection === tab.value }"
                  @click="activeSection = tab.value">
            {{ t(tab.label) }}
          </button>
        </div>

        <div class="ms-auto flex gap-2">
          <button type="button" @click="expandAll" class="zv-btn zv-btn-secondary zv-btn-sm">
            {{ t('settings.chartOfAccounts.index.expandAll') }}
          </button>
          <button type="button" @click="collapseAll" class="zv-btn zv-btn-ghost zv-btn-sm">
            {{ t('settings.chartOfAccounts.index.collapseAll') }}
          </button>
        </div>
      </div>

      <!-- ── Balance Sheet Section ─────────────────────────────── -->
      <div v-show="activeSection === 'all' || activeSection === 'balance_sheet'" class="mb-6">
        <div class="coa-section-banner coa-section-banner--bs">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/>
          </svg>
          {{ t('settings.chartOfAccounts.index.balanceSheet') }}
        </div>

        <CoaClassBlock
          v-for="(classKey, index) in ['assets','liabilities','equity']"
          :key="classKey"
          :accounts="filteredClass('balance_sheet', classKey)"
          :class-key="classKey"
          :expanded="expandedClasses[classKey]"
          :search="search"
          @toggle="toggleClass(classKey)"
          @edit="editAccount"
          @toggle-active="toggleActive"
        />
      </div>

      <!-- ── Income Statement Section ──────────────────────────── -->
      <div v-show="activeSection === 'all' || activeSection === 'income_statement'" class="mb-6">
        <div class="coa-section-banner coa-section-banner--is">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
          {{ t('settings.chartOfAccounts.index.incomeStatement') }}
        </div>

        <CoaClassBlock
          v-for="classKey in ['revenue','expenses']"
          :key="classKey"
          :accounts="filteredClass('income_statement', classKey)"
          :class-key="classKey"
          :expanded="expandedClasses[classKey]"
          :search="search"
          @toggle="toggleClass(classKey)"
          @edit="editAccount"
          @toggle-active="toggleActive"
        />
      </div>

    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import CoaClassBlock from './CoaClassBlock.vue'

const { t } = useI18n()

const props = defineProps({
  tree:    Object,
  filters: Object,
})

const search        = ref(props.filters?.search ?? '')
const activeSection = ref('all')

const tabs = [
  { value: 'all',              label: 'All' },
  { value: 'balance_sheet',    label: 'Balance Sheet' },
  { value: 'income_statement', label: 'Income Statement' },
]

const expandedClasses = reactive({
  assets: true, liabilities: true, equity: true,
  revenue: true, expenses: true,
})

function toggleClass(key) { expandedClasses[key] = !expandedClasses[key] }
function expandAll()  { Object.keys(expandedClasses).forEach(k => expandedClasses[k] = true) }
function collapseAll() { Object.keys(expandedClasses).forEach(k => expandedClasses[k] = false) }

function filteredClass(section, classKey) {
  const accounts = props.tree?.[section]?.[classKey] ?? []
  if (!search.value.trim()) return accounts
  const q = search.value.toLowerCase()
  // Flatten and filter — return matching accounts with their ancestors
  return filterTree(accounts, q)
}

function filterTree(accounts, q) {
  if (!accounts?.length) return []
  return accounts.filter(a =>
    a.code?.toLowerCase().includes(q) ||
    a.name_en?.toLowerCase().includes(q) ||
    a.name_ar?.toLowerCase().includes(q)
  )
}

function editAccount(account) {
  router.get(route('settings.coa.edit', account.id))
}

function toggleActive(account) {
  router.patch(route('settings.coa.toggle', account.id))
}
</script>

<style scoped>
.coa-toolbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}

.coa-tabs {
  display: flex;
  gap: 0.25rem;
  background: var(--zv-bg-surface);
  border: 1px solid var(--zv-border);
  border-radius: var(--zv-radius-md);
  padding: 0.25rem;
}

.coa-tab {
  padding: 0.375rem 1rem;
  border-radius: var(--zv-radius);
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--zv-text-secondary);
  transition: var(--zv-transition);
  border: none;
  background: transparent;
  cursor: pointer;
}
.coa-tab:hover { color: var(--zv-text-primary); background: var(--zv-bg-hover); }
.coa-tab--active {
  background: var(--zv-nav-bg);
  color: #fff;
}

.coa-section-banner {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  border-radius: var(--zv-radius-md);
  font-size: 0.9375rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  margin-bottom: 0.75rem;
}
.coa-section-banner--bs {
  background: linear-gradient(135deg, #0F2554 0%, #1E4DB7 100%);
  color: #fff;
}
.coa-section-banner--is {
  background: linear-gradient(135deg, #047857 0%, #059669 100%);
  color: #fff;
}
</style>
