import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';
import { twemojiDirective } from './twemoji';
import '../scss/app.scss';

createApp(App).use(createPinia()).use(router).directive('twemoji', twemojiDirective).mount('#app');
