import { createRouter, createWebHistory } from 'vue-router'
const EmptyRoute = { render: () => null }
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/playing' },
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
