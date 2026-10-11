<!--
══════════════════════════════════════════════════════════════════
  Accounting/Leave/Holidays.vue   (audit M-35)

  The company's weekly rest days and public holidays. Leave types set
  to "skip non-working days" (e.g. annual leave) do not count these
  dates when the days of a request are calculated.

  @layout    AppLayout
  @route     hr.leave-holidays.index
  @props     holidays: Array, weekendDays: Array — ISO weekdays (1=Mon … 7=Sun)
══════════════════════════════════════════════════════════════════
-->
<template>
  <div class="zv-page zv-acc">
    <PageHeader eyebrow="HR" title="Weekly rest days & holidays" subtitle="Not counted in leave types that skip non-working days"
      icon='<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>' />

    <div class="form-section mb-5">
      <div class="form-section-body">
        <p class="zv-label">Weekly rest days</p>
        <div class="flex gap-3 flex-wrap">
          <label v-for="d in days" :key="d.n" class="flex items-center gap-1">
            <input type="checkbox" :value="d.n" v-model="weekend"/> {{ d.name }}
          </label>
        </div>
        <button type="button" class="zv-btn zv-btn-secondary mt-3" @click="saveWeekend">Save rest days</button>
        <p v-if="weekendError" class="zv-field-error mt-2">{{ weekendError }}</p>
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-body">
        <div class="field-grid field-grid-3">
          <div class="field-group"><label class="zv-label">Date</label><input v-model="form.holiday_date" type="date" class="zv-input"/></div>
          <div class="field-group"><label class="zv-label">Holiday name</label><input v-model="form.name" type="text" class="zv-input"/></div>
          <div class="field-group" style="align-self:end"><button type="button" class="zv-btn zv-btn-primary" :disabled="form.processing" @click="add">Add holiday</button></div>
        </div>
        <p v-if="form.errors.holiday_date" class="zv-field-error">{{ form.errors.holiday_date }}</p>
        <p v-if="form.errors.name" class="zv-field-error">{{ form.errors.name }}</p>

        <table class="zv-table mt-4">
          <thead><tr><th>Date</th><th>Holiday</th><th></th></tr></thead>
          <tbody>
            <tr v-for="h in holidays" :key="h.id">
              <td>{{ String(h.holiday_date).slice(0, 10) }}</td>
              <td>{{ h.name }}</td>
              <td><button type="button" class="zv-action-btn zv-action-btn-danger" @click="remove(h)">Remove</button></td>
            </tr>
            <tr v-if="!holidays.length"><td colspan="3" class="opacity-70">No holidays entered yet.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PageHeader from '@/Components/PageHeader.vue'

const props = defineProps({ holidays: { type: Array, default: () => [] }, weekendDays: { type: Array, default: () => [5, 6] } })

const days = [
  { n: 1, name: 'Monday' }, { n: 2, name: 'Tuesday' }, { n: 3, name: 'Wednesday' }, { n: 4, name: 'Thursday' },
  { n: 5, name: 'Friday' }, { n: 6, name: 'Saturday' }, { n: 7, name: 'Sunday' },
]
const weekend = ref([...props.weekendDays])
const form = useForm({ holiday_date: '', name: '' })

const weekendError = ref('')

function saveWeekend() {
  weekendError.value = ''
  router.put(route('hr.leave-holidays.weekend'), { weekend_days: weekend.value }, {
    preserveScroll: true,
    onError: (errors) => {
      // Round 8: a refused save (none ticked, more than three) used to say nothing.
      weekendError.value = errors.weekend_days ?? Object.values(errors)[0] ?? ''
    },
  })
}
function add() {
  form.post(route('hr.leave-holidays.store'), { preserveScroll: true, onSuccess: () => form.reset() })
}
function remove(h) {
  if (!confirm('Remove ' + h.name + '?')) return
  router.delete(route('hr.leave-holidays.destroy', h.id), { preserveScroll: true })
}
</script>
