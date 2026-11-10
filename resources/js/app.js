import { applyTheme } from '@/config/theme.config';
applyTheme();
import '../css/app.css';



import { createPinia } from "pinia";
import { useStyleStore } from "@/stores/style.js";
import { darkModeKey, styleKey } from "@/config.js";
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName =
  window.document.getElementsByTagName("title")[0]?.innerText || "Laravel";

const pinia = createPinia();
// Revalidate the session at the actual history destination. Never replace
// browser history destinations with a forced home or external redirect.
window.addEventListener('popstate', (event) => {
  event.stopImmediatePropagation();
  window.location.reload();
}, { capture: true });
window.addEventListener('pageshow', (event) => {
  if (event.persisted) window.location.reload();
});

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true });
    const path = `./pages/${name}.vue`;

    if (pages[path]) {
      return pages[path].default || pages[path];
    }

    // Fallback for Linux case-sensitive filesystems
    const matchedKey = Object.keys(pages).find((key) => key.toLowerCase() === path.toLowerCase());
    if (matchedKey) {
      return pages[matchedKey].default || pages[matchedKey];
    }

    throw new Error(`Page not found: ${path}`);
  },
  setup({ el, App, props, plugin }) {
    return createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(pinia)
      .use(ZiggyVue)
      .mount(el);
  },
  progress: {
    color: '#4B5563',
  },
});

const styleStore = useStyleStore(pinia);

/* App style */
styleStore.setStyle(localStorage[styleKey] ?? "basic");

/* Dark mode */
if (
  (!localStorage[darkModeKey] &&
    window.matchMedia("(prefers-color-scheme: dark)").matches) ||
  localStorage[darkModeKey] === "1"
) {
  styleStore.setDarkMode(true);
}
