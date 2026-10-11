import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import i18n, { setLocale } from './i18n/index.js';
import AppLayout from './Layouts/AppLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Vero CFO';

// Read locale from the HTML lang attribute set by the backend (user.lang_pref)
// This is the source of truth — not localStorage
const savedLocale = document.documentElement.getAttribute('lang') || 'en';
setLocale(savedLocale);

createInertiaApp({
    title: (title) => `${title} — ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ).then((page) => {
            // ── Persistent layout (Building Bible Tier 1, Item 3) ──
            // Every page gets AppLayout automatically unless it already
            // sets its own `layout`, OR it's an Auth/* page — those are
            // intentionally standalone, full-screen designs (Login,
            // Register, password reset, etc.) with no shared topbar/
            // sidebar shell at all (confirmed: GuestLayout.vue exists
            // in the codebase but nothing actually uses it — Auth
            // pages are fully self-contained). Wrapping them in
            // AppLayout would incorrectly show the authenticated
            // navigation chrome on pre-login screens.
            //
            // This is what lets Inertia keep AppLayout's component
            // instance alive across navigations instead of destroying
            // and rebuilding the whole topbar/sidebar/notification-poll
            // shell on every single click. Pages themselves no longer
            // wrap their content in <AppLayout>...</AppLayout> — that
            // wrapping is exactly what defeated this before.
            const isAuthPage = name.startsWith('Auth/');
            if (!isAuthPage) {
                page.default.layout = page.default.layout || AppLayout;
            }
            return page;
        }),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#E8960A',
    },
});