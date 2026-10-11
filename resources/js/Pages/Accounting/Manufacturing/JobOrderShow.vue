<!--
══════════════════════════════════════════════════════════════════
  Accounting/Manufacturing/JobOrderShow.vue

  Mirrors ProductionOrderShow's structure (stepper, KPIs, two-column
  working area, Teleport action modals) — but "Estimated" replaces
  "Standard" throughout, reflecting the different costing philosophy
  (a one-off job has no repeatable rate to derive a standard from —
  see JobOrder migration header). Material List shows job.lines
  directly at face value — no batch-scaling needed here, unlike
  ProductionOrderShow's BOM reference, since job_order_lines already
  stores the TOTAL quantity for this specific job.

  All CSS is defined locally in this file rather than assumed
  available from ProductionOrderShow.vue — the two pages share no
  styles, same as they share no backend code.

  Pieces (Section 6, Sept 2026) — an OPTIONAL Routing breakdown.
  job.pieces is empty for a simple, flat job (nothing changes for
  those); when present, each Piece shows its own status and its own
  Operations (Process/Machine/Labor Category), each with a "Log
  Hours" action that computes cost automatically — no actual-cost
  field anywhere in this new UI, by design.

  @layout    AppLayout
  @route     accounting.job-orders.show
  @props     job, wipBalance, variance, overheadPools, costCenters,
             processes, machines, laborCategories
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">

    <PageHeader
      :breadcrumbs="[{ label: t('accounting.manufacturing.jobOrderShow.jobOrders'), href: route('accounting.job-orders.index') }, { label: job.job_code }]"
      :title="job.job_code"
    >
      <template #subtitle>
        <span class="font-semibold">{{ job.product?.name_en }}</span>
        <template v-if="job.sales_order">
          <span class="mx-2" style="color:var(--zv-border-md)">·</span>
          <span>{{ t('accounting.manufacturing.jobOrderShow.for') }} {{ job.sales_order.so_code }}</span>
        </template>
        <template v-if="job.template">
          <span class="mx-2" style="color:var(--zv-border-md)">·</span>
          <span>{{ t('accounting.manufacturing.jobOrderShow.fromTemplate') }}: {{ job.template.name }}</span>
        </template>
      </template>

      <template #actions>
        <button v-if="job.status === 'draft'" type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="start">
          {{ t('accounting.manufacturing.jobOrderShow.startProduction') }}
        </button>
        <template v-else-if="job.status === 'in_progress'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.manufacturing.jobOrderShow.setToDraft') }}</button>
          <button type="button" class="zv-btn zv-btn-primary zv-btn-lg" @click="openModal('complete')">
            {{ t('accounting.manufacturing.jobOrderShow.completeJob') }}
          </button>
        </template>
        <template v-else-if="job.status === 'completed'">
          <button type="button" class="zv-btn zv-btn-secondary" @click="confirmSetToDraft">{{ t('accounting.manufacturing.jobOrderShow.setToDraft') }}</button>
          <StatusBadge :status="job.status" :overrides="ORDER_STATUS_BADGES" />
        </template>
        <StatusBadge v-else :status="job.status" :overrides="ORDER_STATUS_BADGES" />
      </template>
    </PageHeader>

    <!-- ══════════════════════════════════════════════════════
         LIFECYCLE STEPPER
    ══════════════════════════════════════════════════════ -->
    <div class="zv-card jo-stepper-card">
      <div v-if="job.status === 'cancelled'" class="jo-cancelled-banner">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        {{ t('accounting.manufacturing.jobOrderShow.thisJobOrderWasCancelled') }}
      </div>
      <div v-else class="jo-stepper">
        <div v-for="(step, i) in steps" :key="step.key" class="jo-step" :class="stepClass(i)">
          <div class="jo-step-dot">
            <svg v-if="currentStepIndex > i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            <span v-else>{{ i + 1 }}</span>
          </div>
          <div class="jo-step-label">{{ t(step.label) }}</div>
          <div v-if="i < steps.length - 1" class="jo-step-line" :class="{ 'jo-step-line-done': currentStepIndex > i }"></div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         KPI ROW
    ══════════════════════════════════════════════════════ -->
    <div class="jo-kpi-grid">
      <KpiCard
        :label="t('accounting.manufacturing.jobOrderShow.quantityToProduce')"
        :value="fmtQty(job.quantity_to_produce)"
        tone="navy"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>'
      >
        <template #sub>{{ job.product?.name_en }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.manufacturing.jobOrderShow.wIPBalance')"
        :value="fmt(wipBalance)"
        tone="blue"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
      >
        <template #sub>{{ t('accounting.manufacturing.jobOrderShow.materialLaborOverheadPostedSoFar') }}</template>
      </KpiCard>

      <KpiCard
        :label="t('accounting.manufacturing.jobOrderShow.estimatedUnitCost')"
        :value="fmt(estimatedUnitCost)"
        tone="gold"
        icon='<path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-2m-2 5h10a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>'
      >
        <template #sub>{{ t('accounting.manufacturing.jobOrderShow.quotedAtOpening') }}</template>
      </KpiCard>
    </div>

    <!-- ══════════════════════════════════════════════════════
         PIECES (Section 6 — optional Routing breakdown)
    ══════════════════════════════════════════════════════ -->
    <div v-if="job.pieces?.length || job.status !== 'completed'" class="zv-card mb-5">
      <div class="zv-card-header flex items-center justify-between">
        <span>{{ t('accounting.manufacturing.jobOrderShow.pieces') }}</span>
        <button v-if="['draft','in_progress'].includes(job.status)" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openPieceModal">
          {{ t('accounting.manufacturing.jobOrderShow.addPiece') }}
        </button>
      </div>

      <EmptyState v-if="!job.pieces?.length" :title="t('accounting.manufacturing.jobOrderShow.noPiecesYet')" :message="t('accounting.manufacturing.jobOrderShow.optionalOnlyUseThisForAComplex')" icon-path="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>

      <div v-else class="jo-pieces">
        <div v-for="piece in job.pieces" :key="piece.id" class="jo-piece">
          <div class="jo-piece-header">
            <div class="jo-piece-title">
              <span>{{ piece.name }}</span>
              <span v-if="piece.quantity" class="jo-piece-qty">× {{ fmtQty(piece.quantity) }}</span>
            </div>
            <div class="flex items-center gap-2">
              <StatusBadge :status="piece.status" :overrides="PIECE_STATUS_BADGES" />
              <span class="jo-piece-cost">{{ fmt(pieceCost(piece)) }}</span>
              <button v-if="piece.status !== 'completed' && job.status === 'in_progress'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm" @click="markPieceComplete(piece)">
                {{ t('accounting.manufacturing.jobOrderShow.markComplete') }}
              </button>
            </div>
          </div>

          <div class="jo-operations">
            <div v-for="op in piece.operations" :key="op.id" class="jo-operation">
              <div class="jo-operation-info">
                <span class="jo-op-tag">{{ op.process?.name }}</span>
                <span class="jo-op-sep">·</span>
                <span>{{ op.machine?.code }} — {{ op.machine?.name }}</span>
                <span class="jo-op-sep">·</span>
                <span>{{ op.labor_category?.name }}</span>
              </div>
              <button v-if="piece.status !== 'completed' && job.status === 'in_progress'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openLogCostModal(piece, op)">
                {{ t('accounting.manufacturing.jobOrderShow.logHours') }}
              </button>
            </div>

            <button v-if="piece.status !== 'completed' && job.status === 'in_progress'" type="button" class="zv-btn zv-btn-ghost zv-btn-sm jo-add-operation" @click="openOperationModal(piece)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="w-3.5 h-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
              </svg>
              {{ t('accounting.manufacturing.jobOrderShow.addOperation') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         TWO-COLUMN WORKING AREA
    ══════════════════════════════════════════════════════ -->
    <div class="jo-columns">

      <!-- ── LEFT: material list + consumption log ───────────── -->
      <div class="jo-col">
        <div class="zv-card mb-5">
          <div class="zv-card-header">{{ t('accounting.manufacturing.jobOrderShow.materialList') }}</div>
          <div class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('accounting.manufacturing.jobOrderShow.component') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderShow.requiredQty') }}</th></tr></thead>
              <tbody>
                <tr v-for="line in job.lines" :key="line.id">
                  <td>{{ line.component_product?.name_en }}</td>
                  <td class="zv-table-num">{{ fmtQty(line.quantity) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="zv-card">
          <div class="zv-card-header flex items-center justify-between">
            <span>{{ t('accounting.manufacturing.jobOrderShow.materialConsumption') }}</span>
            <button v-if="job.status === 'in_progress'" type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('consume')">
              {{ t('accounting.manufacturing.jobOrderShow.consumeMaterial') }}
            </button>
          </div>

          <EmptyState v-if="!materialMovements.length" :title="t('accounting.manufacturing.jobOrderShow.noMaterialConsumedYet')" icon-path="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('accounting.manufacturing.jobOrderShow.date') }}</th><th>{{ t('accounting.manufacturing.jobOrderShow.component') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderShow.qty') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderShow.wACValue') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in materialMovements" :key="m.id">
                  <td>{{ fmtDate(m.movement_date) }}</td>
                  <td>{{ m.product?.name_en }}</td>
                  <td class="zv-table-num">{{ fmtQty(m.quantity) }}</td>
                  <td class="zv-table-num">{{ fmt(m.wac_total_value) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── RIGHT: cost log + variance ───────────────────────── -->
      <div class="jo-col">
        <div class="zv-card mb-5">
          <div class="zv-card-header flex items-center justify-between">
            <span>{{ t('accounting.manufacturing.jobOrderShow.laborOverhead') }}</span>
            <div v-if="job.status === 'in_progress'" class="flex gap-2">
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('labor')">{{ t('accounting.manufacturing.jobOrderShow.recordLabor') }}</button>
              <button type="button" class="zv-btn zv-btn-secondary zv-btn-sm" @click="openModal('overhead')">{{ t('accounting.manufacturing.jobOrderShow.recordOverhead') }}</button>
            </div>
          </div>

          <EmptyState v-if="!job.cost_entries?.length" :title="t('accounting.manufacturing.jobOrderShow.noLaborOrOverheadRecordedYet')" icon-path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>

          <div v-else class="zv-table-wrap">
            <table class="zv-table">
              <thead><tr><th>{{ t('common.type') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderShow.baseQty') }}</th><th class="zv-table-num">{{ t('common.rate') }}</th><th class="zv-table-num">{{ t('accounting.manufacturing.jobOrderShow.actual') }}</th><th>{{ t('accounting.manufacturing.jobOrderShow.costCenter') }}</th></tr></thead>
              <tbody>
                <tr v-for="e in job.cost_entries" :key="e.id">
                  <td>
                    <span class="zv-tag" :class="e.entry_type === 'labor' ? '' : 'zv-tag-gray'">
                      {{ e.entry_type === 'labor' ? t('accounting.manufacturing.jobOrderShow.labor') : (e.overhead_pool?.name ?? t('accounting.manufacturing.jobOrderShow.overhead')) }}
                    </span>
                  </td>
                  <td class="zv-table-num">{{ fmtQty(e.base_quantity) }}</td>
                  <td class="zv-table-num">{{ fmt(e.rate_applied) }}</td>
                  <td class="zv-table-num">{{ fmt(e.actual_amount) }}</td>
                  <td>{{ e.cost_center?.name_en ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="variance" class="zv-card">
          <div class="zv-card-header">{{ t('accounting.manufacturing.jobOrderShow.varianceEstimatedVsActual') }}</div>
          <div class="jo-variance-body">
            <div v-for="cat in varianceCategories" :key="cat.key" class="jo-variance-row">
              <div class="jo-variance-label">{{ t(cat.label) }}</div>
              <div class="jo-variance-figures">
                <span class="jo-variance-figure"><span class="jo-variance-tag">{{ t('accounting.manufacturing.jobOrderShow.est') }}</span>{{ fmt(variance[cat.key].estimated) }}</span>
                <span class="jo-variance-figure"><span class="jo-variance-tag">{{ t('accounting.manufacturing.jobOrderShow.actual') }}</span>{{ fmt(variance[cat.key].actual) }}</span>
                <span class="jo-variance-figure jo-variance-delta" :class="varianceClass(variance[cat.key].variance)">
                  {{ Number(variance[cat.key].variance) > 0 ? '+' : '' }}{{ fmt(variance[cat.key].variance) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════ Action Modals ══════════════════════════ -->
    <Teleport to="body">
      <div v-if="modal.type" class="jo-modal-overlay zv-acc" @click.self="closeModal">
        <div class="jo-modal">

          <template v-if="modal.type === 'consume'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.consumeMaterial') }}</h3>
            <p class="jo-modal-hint">{{ t('accounting.manufacturing.jobOrderShow.issuedFrom') }} {{ job.raw_material_location?.name_en }}, {{ t('accounting.manufacturing.jobOrderShow.relievedAtCurrentWAC') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.component') }}</label>
              <SelectBox v-model="modalForm.component_product_id" :options="job.lines" value-key="component_product_id" :label-fn="line => line.component_product?.name_en" :placeholder="t('accounting.manufacturing.jobOrderShow.select')" :clearable="false" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.quantity') }}</label>
              <input v-model="modalForm.quantity" type="number" step="0.0001" min="0.0001" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'labor'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.recordLaborCost') }}</h3>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.hours') }}</label>
              <input v-model="modalForm.hours" type="number" step="0.0001" min="0.0001" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.manufacturing.jobOrderShow.costCalculatedFromRate') }}</p>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.costCenter') }}</label>
              <SelectBox v-model="modalForm.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderShow.none')" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'overhead'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.recordOverheadCost') }}</h3>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.overheadPool') }}</label>
              <SelectBox v-model="modalForm.overhead_pool_id" :options="overheadPools" value-key="id" :label-fn="p => p.name" :placeholder="t('accounting.manufacturing.jobOrderShow.select')" :clearable="false" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.baseQuantity') }}</label>
              <input v-model="modalForm.base_quantity" type="number" step="0.0001" min="0.0001" class="zv-input"/>
              <p class="field-hint">{{ t('accounting.manufacturing.jobOrderShow.costCalculatedFromRate') }}</p>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.costCenter') }}</label>
              <SelectBox v-model="modalForm.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderShow.none')" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'add-piece'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.addPiece') }}</h3>
            <p class="jo-modal-hint">{{ t('accounting.manufacturing.jobOrderShow.aNamedComponentOrSubAssembly') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.pieceName') }}</label>
              <input v-model="modalForm.name" type="text" class="zv-input" :placeholder="t('accounting.manufacturing.jobOrderShow.eGUprightsBasePlatesCrossBraces')"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.quantity') }}</label>
              <input v-model="modalForm.quantity" type="number" step="0.0001" min="0.0001" class="zv-input"/>
            </div>
          </template>

          <template v-else-if="modal.type === 'add-operation'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.addOperation') }}</h3>
            <p class="jo-modal-hint">{{ modalContext.piece?.name }}</p>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.process') }}</label>
              <SelectBox v-model="modalForm.process_id" :options="processes" value-key="id" label-key="name" :placeholder="t('accounting.manufacturing.jobOrderShow.select')" :clearable="false" @change="modalForm.machine_id = ''" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.machineUsed') }}</label>
              <SelectBox v-model="modalForm.machine_id" :options="machinesForProcess(modalForm.process_id)" value-key="id" :label-fn="m => `${m.code} — ${m.name}`" :placeholder="t('accounting.manufacturing.jobOrderShow.select')" :clearable="false" :disabled="!modalForm.process_id" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label zv-label-required">{{ t('accounting.manufacturing.jobOrderShow.laborCategory') }}</label>
              <SelectBox v-model="modalForm.labor_category_id" :options="laborCategories" value-key="id" label-key="name" :placeholder="t('accounting.manufacturing.jobOrderShow.select')" :clearable="false" />
            </div>
          </template>

          <template v-else-if="modal.type === 'log-cost'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.logHours') }}</h3>
            <p class="jo-modal-hint">{{ modalContext.piece?.name }} — {{ modalContext.operation?.process?.name }}</p>
            <p class="jo-modal-hint">{{ t('accounting.manufacturing.jobOrderShow.costIsCalculatedAutomaticallyFrom') }}</p>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.laborHours') }}</label>
              <input v-model="modalForm.labor_hours" type="number" step="0.0001" min="0" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.machineHours') }}</label>
              <input v-model="modalForm.machine_hours" type="number" step="0.0001" min="0" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.entryDate') }}</label>
              <input v-model="modalForm.entry_date" type="date" class="zv-input"/>
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.costCenter') }}</label>
              <SelectBox v-model="modalForm.cost_center_id" :options="costCenters" value-key="id" :label-fn="c => `${c.code} — ${c.name_en}`" :placeholder="t('accounting.manufacturing.jobOrderShow.none')" />
            </div>
            <div class="field-group mb-3">
              <label class="zv-label">{{ t('accounting.manufacturing.jobOrderShow.notes') }}</label>
              <textarea v-model="modalForm.notes" class="zv-input" rows="2"></textarea>
            </div>
          </template>

          <template v-else-if="modal.type === 'complete'">
            <h3 class="jo-modal-title">{{ t('accounting.manufacturing.jobOrderShow.completeJobOrder') }}</h3>
            <p class="jo-modal-hint">
              {{ t('accounting.manufacturing.jobOrderShow.finishedGoodsWillBeReceivedAt') }}
              ({{ fmt(wipBalance) }} {{ t('accounting.manufacturing.jobOrderShow.total') }},
              {{ fmt(bcActualUnitCost) }} {{ t('accounting.manufacturing.jobOrderShow.perUnit') }}) — {{ t('accounting.manufacturing.jobOrderShow.canBeReopenedLaterViaSetToDraft') }}
            </p>
          </template>

          <div v-if="modalErrors.length" class="zv-field-error mb-3" role="alert">
            <p v-for="(msg, i) in modalErrors" :key="i">{{ msg }}</p>
          </div>

          <div class="jo-modal-actions">
            <button type="button" class="zv-btn zv-btn-ghost" @click="closeModal">{{ t('common.cancel') }}</button>
            <button type="button" class="zv-btn zv-btn-primary" :disabled="modalSubmitting" @click="submitModal">
              {{ modalSubmitting ? t('common.saving') : t('common.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { todayLocal } from '@/Utils/dates'
import { sum as sumMoney, div as divMoney } from '@/Utils/money'
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/Components/PageHeader.vue'
import KpiCard from '@/Components/KpiCard.vue'
import EmptyState from '@/Components/EmptyState.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SelectBox from '@/Components/SelectBox.vue'

const { t } = useI18n()

const props = defineProps({
  job:              { type: Object, required: true },
  wipBalance:       { type: [String, Number], required: true },
  variance:         { type: Object, default: null },
  overheadPools:    { type: Array,  default: () => [] },
  costCenters:      { type: Array,  default: () => [] },
  processes:        { type: Array,  default: () => [] },
  machines:         { type: Array,  default: () => [] },
  laborCategories:  { type: Array,  default: () => [] },
})

const ORDER_STATUS_BADGES = {
  in_progress: { label: 'In Progress', badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue'  },
  completed:   { label: 'Completed',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

const PIECE_STATUS_BADGES = {
  not_started: { label: 'Not Started', badge: 'zv-badge-gray',  dot: 'zv-status-dot-gray'  },
  in_progress: { label: 'In Progress', badge: 'zv-badge-blue',  dot: 'zv-status-dot-blue'  },
  completed:   { label: 'Completed',   badge: 'zv-badge-green', dot: 'zv-status-dot-green' },
}

// ── Lifecycle stepper ────────────────────────────────────────────

const steps = [
  { key: 'draft',       label: 'Draft' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'completed',   label: 'Completed' },
  { key: 'closed',      label: 'Closed' },
]

const currentStepIndex = computed(() => steps.findIndex(s => s.key === props.job.status))

function stepClass(i) {
  if (i < currentStepIndex.value) return 'jo-step-done'
  if (i === currentStepIndex.value) return 'jo-step-current'
  return 'jo-step-pending'
}

const materialMovements = computed(() =>
  (props.job.stock_movements ?? []).filter(m => m.movement_type === 'job_consumption')
)

const estimatedUnitCost = computed(() =>
  Number(props.job.estimated_material_cost_per_unit || 0)
  + Number(props.job.estimated_labor_cost_per_unit || 0)
  + Number(props.job.estimated_overhead_cost_per_unit || 0)
)

const bcActualUnitCost = computed(() => {
  const qty = Number(props.job.quantity_to_produce || 0)
  return qty > 0 ? divMoney(props.wipBalance, qty, 4) : 0
})

const varianceCategories = [
  { key: 'material', label: 'Material' },
  { key: 'labor',    label: 'Labor' },
  { key: 'overhead', label: 'Overhead' },
]

function varianceClass(v) {
  const n = Number(v)
  if (n > 0) return 'jo-variance-unfavorable'
  if (n < 0) return 'jo-variance-favorable'
  return ''
}

// ── Simple actions ────────────────────────────────────────────────

function start() {
  router.patch(route('accounting.job-orders.start', props.job.id), {}, { preserveScroll: true })
}

// 2026-09 — full reversal, same pattern as ProductionOrderShow.vue's
// confirmSetToDraft().
function confirmSetToDraft() {
  if (! confirm(t('accounting.manufacturing.jobOrderShow.reopenThisJobForEditingThisWillReverse'))) return

  router.patch(route('accounting.job-orders.set-to-draft', props.job.id), {}, {
    onSuccess: (page) => {
      if (page.props.flash?.error) return
      window.location.reload()
    },
  })
}

// ── Section 6 — Pieces / Operations ────────────────────────────────

function pieceCost(piece) {
  const opIds = (piece.operations ?? []).map(o => o.id)
  return (props.job.cost_entries ?? [])
    .filter(e => opIds.includes(e.job_order_operation_id))
    .map(e => e.actual_amount || 0)
    .reduce((sum, v) => sumMoney([sum, v]), '0.00')
}

// Same "narrow the Machine picker to the chosen Process" pattern as
// BomForm.vue — a job's Operation, like a BOM Operation, must use a
// Machine actually tagged to that Process.
function machinesForProcess(processId) {
  if (!processId) return props.machines
  return props.machines.filter(m => m.process_id === processId)
}

function markPieceComplete(piece) {
  if (! confirm(t('accounting.manufacturing.jobOrderShow.markThisPieceAsCompleteNoMore'))) return
  router.patch(route('accounting.job-orders.pieces.complete', [props.job.id, piece.id]), {}, { preserveScroll: true })
}

// ── Modal ─────────────────────────────────────────────────────────

const modal = ref({ type: null })
const modalForm = ref({})
const modalContext = ref({})
const modalSubmitting = ref(false)
const modalErrors = ref([])

// Shared result handling for every action window: close it only when the server
// accepted the entry; otherwise keep it open, keep what was typed, and show why.
function modalOptions() {
  return {
    preserveScroll: true,
    onStart: () => { modalErrors.value = [] },
    onSuccess: (page) => {
      const refusal = page.props.flash?.error
      if (refusal) { modalErrors.value = [refusal]; return }
      closeModal()
    },
    onError: (errors) => { modalErrors.value = Object.values(errors) },
    onFinish: () => { modalSubmitting.value = false },
  }
}
const today = todayLocal()

function openModal(type) {
  modal.value = { type }
  modalContext.value = {}
  modalForm.value = {
    component_product_id: '', quantity: '', notes: '',
    hours: '', entry_date: today,
    overhead_pool_id: '', base_quantity: '', cost_center_id: '',
  }
}

function openPieceModal() {
  modal.value = { type: 'add-piece' }
  modalContext.value = {}
  modalForm.value = { name: '', quantity: '' }
}

function openOperationModal(piece) {
  modal.value = { type: 'add-operation' }
  modalContext.value = { piece }
  modalForm.value = { process_id: '', machine_id: '', labor_category_id: '' }
}

function openLogCostModal(piece, operation) {
  modal.value = { type: 'log-cost' }
  modalContext.value = { piece, operation }
  modalForm.value = { labor_hours: '', machine_hours: '', entry_date: today, cost_center_id: '', notes: '' }
}

function closeModal() {
  modalErrors.value = []
  modal.value = { type: null }
  modalContext.value = {}
}

function submitModal() {
  modalSubmitting.value = true

  if (modal.value.type === 'complete') {
    router.patch(route('accounting.job-orders.complete', props.job.id), {}, {
      ...modalOptions(),
    })
    return
  }

  if (modal.value.type === 'add-piece') {
    router.post(route('accounting.job-orders.pieces.store', props.job.id), modalForm.value, {
      ...modalOptions(),
    })
    return
  }

  if (modal.value.type === 'add-operation') {
    router.post(route('accounting.job-orders.pieces.operations.store', [props.job.id, modalContext.value.piece.id]), modalForm.value, {
      ...modalOptions(),
    })
    return
  }

  if (modal.value.type === 'log-cost') {
    router.post(route('accounting.job-orders.pieces.operations.log', [props.job.id, modalContext.value.piece.id, modalContext.value.operation.id]), modalForm.value, {
      ...modalOptions(),
    })
    return
  }

  const routeName = {
    consume:  'accounting.job-orders.consume-material',
    labor:    'accounting.job-orders.record-labor',
    overhead: 'accounting.job-orders.record-overhead',
  }[modal.value.type]

  router.post(route(routeName, props.job.id), modalForm.value, {
    ...modalOptions(),
  })
}

// ── Formatting ────────────────────────────────────────────────────

function fmt(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(val) {
  return Number(val || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
}

function fmtDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<style>
/* ── Stepper ─────────────────────────────────────────────────── */
.jo-stepper-card { padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
.jo-stepper { display: flex; align-items: flex-start; }
.jo-step { flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; }
.jo-step-dot {
  width: 2rem; height: 2rem; border-radius: 999px; display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; border: 2px solid var(--zv-border, #e2e8f0); background: var(--zv-bg-card, #fff);
  color: var(--zv-text-muted, #94a3b8); z-index: 1; margin-bottom: 0.5rem;
}
.jo-step-label { font-size: 0.8rem; color: var(--zv-text-muted, #94a3b8); font-weight: 600; }
.jo-step-line { position: absolute; top: 1rem; left: 50%; width: 100%; height: 2px; background: var(--zv-border, #e2e8f0); z-index: 0; }
.jo-step-line-done { background: #059669; }
.jo-step-current .jo-step-dot { border-color: #2563EB; color: #2563EB; box-shadow: 0 0 0 4px rgba(37,99,235,0.12); }
.jo-step-current .jo-step-label { color: #2563EB; }
.jo-step-done .jo-step-dot { border-color: #059669; background: #059669; color: #fff; }
.jo-step-done .jo-step-label { color: var(--zv-text-primary, #0f172a); }
.jo-cancelled-banner { display: flex; align-items: center; gap: 0.5rem; color: #DC2626; font-weight: 600; font-size: 0.9rem; }

/* ── KPIs ────────────────────────────────────────────────────── */
.jo-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
@media (max-width: 900px) { .jo-kpi-grid { grid-template-columns: 1fr; } }

/* ── Two-column layout ──────────────────────────────────────── */
.jo-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 1000px) { .jo-columns { grid-template-columns: 1fr; } }
.jo-col { display: flex; flex-direction: column; }

/* ── Variance card ──────────────────────────────────────────── */
.jo-variance-body { padding: 0.25rem 1.25rem 1.25rem; }
.jo-variance-row { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid var(--zv-border, #e2e8f0); }
.jo-variance-row:last-child { border-bottom: none; }
.jo-variance-label { font-weight: 600; font-size: 0.85rem; color: var(--zv-text-secondary, #475569); }
.jo-variance-figures { display: flex; gap: 1rem; align-items: center; }
.jo-variance-figure { font-size: 0.82rem; font-variant-numeric: tabular-nums; }
.jo-variance-tag { font-size: 0.68rem; color: var(--zv-text-muted, #94a3b8); text-transform: uppercase; letter-spacing: 0.03em; margin-inline-end: 0.35rem; }
.jo-variance-delta { font-weight: 700; min-width: 5rem; text-align: end; }
.jo-variance-unfavorable { color: #DC2626; }
.jo-variance-favorable { color: #059669; }

/* ── Pieces (Section 6) ─────────────────────────────────────── */
.jo-pieces { display: flex; flex-direction: column; }
.jo-piece { border-bottom: 1px solid var(--zv-border, #e2e8f0); padding: 0.9rem 1.25rem; }
.jo-piece:last-child { border-bottom: none; }
.jo-piece-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; }
.jo-piece-title { font-weight: 700; font-size: 0.9rem; color: var(--zv-text-primary, #0f172a); display: flex; align-items: center; gap: 0.4rem; }
.jo-piece-qty { font-weight: 500; font-size: 0.8rem; color: var(--zv-text-muted, #94a3b8); }
.jo-piece-cost { font-weight: 700; font-size: 0.85rem; font-variant-numeric: tabular-nums; color: var(--zv-text-secondary, #475569); }
.jo-operations { display: flex; flex-direction: column; gap: 0.4rem; padding-inline-start: 0.5rem; }
.jo-operation { display: flex; align-items: center; justify-content: space-between; padding: 0.4rem 0.6rem; background: var(--zv-subtle-bg, #f8fafc); border-radius: var(--zv-radius, 8px); font-size: 0.82rem; }
.jo-operation-info { display: flex; align-items: center; gap: 0.4rem; color: var(--zv-text-secondary, #475569); }
.jo-op-tag { font-weight: 700; color: var(--zv-text-primary, #0f172a); }
.jo-op-sep { color: var(--zv-text-muted, #94a3b8); }
.jo-add-operation { align-self: flex-start; }

/* ── Action modals ─────────────────────────────────────────── */
.jo-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400; display: flex; align-items: center; justify-content: center; }
.jo-modal { width: 440px; max-width: 92vw; background: var(--zv-bg-card, #fff); border-radius: var(--zv-radius-lg, 14px); border: 1px solid var(--zv-border, #e2e8f0); box-shadow: var(--zv-shadow-md); padding: 20px; }
.jo-modal-title { font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--zv-text-primary, #0f172a); }
.jo-modal-hint { font-size: 12.5px; color: var(--zv-text-muted, #94a3b8); margin-bottom: 14px; line-height: 1.5; }
.jo-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
</style>
