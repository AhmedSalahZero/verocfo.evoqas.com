<template>
  <Head title="Confirm Password — ZAVERO ERP" />
  <div class="zav-auth-shell">
    <div class="zav-auth-card">
      <div class="zav-auth-logo-mark">Z</div>
      <h1 class="zav-auth-title">Confirm Password</h1>
      <p class="zav-auth-sub">
        This is a secure area. Please confirm your password before continuing.
      </p>
      <form @submit.prevent="submit" class="zav-auth-form">
        <div class="zav-auth-field">
          <label class="zav-auth-label">Password</label>
          <input
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            class="zav-auth-input"
            :class="{ 'zav-auth-input--error': form.errors.password }"
            placeholder="••••••••"
            autofocus
          />
          <p v-if="form.errors.password" class="zav-auth-error">{{ form.errors.password }}</p>
        </div>
        <button type="submit" :disabled="form.processing" class="zav-auth-btn">
          <svg v-if="form.processing" class="zav-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
          </svg>
          {{ form.processing ? 'Confirming…' : 'Confirm Password' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3'
const form = useForm({ password: '' })
function submit() {
  form.post(route('password.confirm'), { onFinish: () => form.reset('password') })
}
</script>

<style scoped>
/* ── Auth shell ────────────────────────────────────────────── */
.zav-auth-shell {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #0C1E3A;
  background-image: radial-gradient(circle, rgba(34,211,238,0.08) 1px, transparent 1px);
  background-size: 28px 28px;
  padding: 2rem 1rem;
}
.zav-auth-card {
  width: 100%;
  max-width: 400px;
  background: #0F2448;
  border: 1px solid rgba(99,144,210,0.18);
  border-radius: 14px;
  padding: 2.25rem 2rem;
  box-shadow: 0 8px 28px rgba(4,12,28,0.55);
}
.zav-auth-logo-mark {
  width: 2.5rem; height: 2.5rem;
  background: #E8960A;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; font-weight: 800;
  color: #071428;
  box-shadow: 0 0 14px rgba(232,150,10,0.28);
  margin-bottom: 1.25rem;
}
.zav-auth-title {
  font-size: 1.25rem; font-weight: 700;
  color: #E4EEF8; margin-bottom: 0.375rem;
  letter-spacing: -0.01em;
}
.zav-auth-sub {
  font-size: 0.8125rem; color: #3D6080;
  line-height: 1.6; margin-bottom: 1.5rem;
}
.zav-auth-form { display: flex; flex-direction: column; }
.zav-auth-field { margin-bottom: 1rem; }
.zav-auth-label {
  display: block; font-size: 0.625rem; font-weight: 700;
  letter-spacing: 0.13em; text-transform: uppercase;
  color: #22D3EE; margin-bottom: 0.375rem;
}
.zav-auth-input {
  width: 100%;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(34,211,238,0.20);
  border-radius: 8px;
  padding: 0.625rem 0.8125rem;
  font-size: 0.8125rem; color: #E4EEF8;
  outline: none; font-family: inherit;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.zav-auth-input::placeholder { color: rgba(255,255,255,0.15); }
.zav-auth-input:focus { border-color: #22D3EE; box-shadow: 0 0 0 3px rgba(34,211,238,0.12); }
.zav-auth-input--error { border-color: rgba(192,57,43,0.70) !important; background: rgba(192,57,43,0.06); }
.zav-auth-error { font-size: 0.6875rem; color: #F09595; margin-top: 4px; }
.zav-auth-btn {
  width: 100%;
  background: #E8960A; border: 1px solid #E8960A;
  border-radius: 8px; padding: 0.6875rem;
  font-size: 0.875rem; font-weight: 700;
  color: #071428; cursor: pointer;
  box-shadow: 0 0 14px rgba(232,150,10,0.28);
  transition: background 0.18s ease, box-shadow 0.18s ease, transform 0.15s ease;
  display: flex; align-items: center; justify-content: center; gap: 0.5rem;
  font-family: inherit; margin-top: 0.5rem;
}
.zav-auth-btn:hover:not(:disabled) {
  background: #F5A820; border-color: #F5A820;
  box-shadow: 0 6px 26px rgba(232,150,10,0.40);
  transform: translateY(-1px);
}
.zav-auth-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.zav-auth-link {
  font-size: 0.75rem; color: #7DA4C8;
  text-decoration: none;
  transition: color 0.15s ease;
}
.zav-auth-link:hover { color: #22D3EE; }
.zav-auth-footer {
  margin-top: 1.25rem; text-align: center;
  font-size: 0.75rem; color: #3D6080;
}
.zav-auth-divider {
  height: 1px; background: rgba(99,144,210,0.14);
  margin: 1.25rem 0;
}
.zav-auth-alert {
  padding: 0.75rem 1rem; border-radius: 8px;
  font-size: 0.75rem; line-height: 1.6;
  margin-bottom: 1rem;
}
.zav-auth-alert-success {
  background: rgba(29,158,117,0.08);
  border: 1px solid rgba(29,158,117,0.28);
  color: #5DCAA5;
}
.zav-spin {
  width: 1rem; height: 1rem;
  animation: spin 1s linear infinite;
}
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>