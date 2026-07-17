import { createRouter, createWebHistory } from 'vue-router';
import WikiPage from './pages/WikiPage.vue';

const routes = [
    { path: '/', redirect: '/wiki' },
    { path: '/wiki', name: 'wiki', component: WikiPage },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
