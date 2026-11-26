import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import 'quasar/dist/quasar.prod.css';
import '@quasar/extras/material-icons-round/material-icons-round.css';
import '../../../packages/renderer-openlayers/src/viewer.css';
import './styles/app.css';

import App from './App.vue';
import { getConfig, resolveUiLocale } from './config';
import { createAppI18n } from './i18n';
import quasarLang from './i18n/quasarLang';
import { createAppRouter } from './router';

const MOUNT_ID = 'sw-maps-app';

function showFatal(el: HTMLElement, message: string): void {
  el.classList.add('swmap-root');
  const p = document.createElement('p');
  p.setAttribute('role', 'alert');
  p.textContent = message; // textContent: never inject markup
  el.replaceChildren(p);
}

export function bootstrap(): void {
  const el = document.getElementById(MOUNT_ID);
  if (!el) {
    console.error(`sw-maps: mount node #${MOUNT_ID} not found`);
    return;
  }
  let config;
  try {
    config = getConfig();
  } catch (error) {
    console.error(error);
    showFatal(el, 'SW Maps: configuration missing (window.swmapsConfig).');
    return;
  }
  el.classList.add('swmap-root');
  const app = createApp(App);
  app.use(createPinia());
  app.use(createAppRouter());
  app.use(createAppI18n(config.locale));
  app.use(Quasar, {
    plugins: { Notify },
    iconSet,
    lang: quasarLang(resolveUiLocale(config.locale)),
    // Quasar portals (dialogs, menus, notifications) are appended to <body>; give them the root class.
    config: { globalNodes: { class: 'swmap-root' } },
  });
  app.mount(el);
}

bootstrap();
