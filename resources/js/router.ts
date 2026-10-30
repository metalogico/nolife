import { createRouter, createWebHistory } from 'vue-router'
const EmptyRoute = { render: () => null }
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/playing' },
    { path: '/settings', component: EmptyRoute, meta: { settings: true } },
    { path: '/statistics', component: EmptyRoute, meta: { statistics: true } },
    {
      path: '/statistics/games/:id(\\d+)',
      component: EmptyRoute,
      meta: { statistics: true },
    },
    {
      path: '/:view(playing|planned|completed|tried|dropped|collection)/games/:id(\\d+)',
      component: EmptyRoute,
    },
    {
      path: '/:view(playing|planned|completed|tried|dropped|collection)',
      component: EmptyRoute,
    },
    { path: '/:pathMatch(.*)*', redirect: '/playing' },
  ],
})
