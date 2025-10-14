import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import ChildAccounts from '../pages/ChildAccounts.vue'
import SyncSettings from '../pages/SyncSettings.vue'

const routes = [
  {
    path: '/app',
    name: 'dashboard',
    component: Dashboard
  },
  {
    path: '/app/accounts',
    name: 'child-accounts',
    component: ChildAccounts
  },
  {
    path: '/app/settings/:accountId',
    name: 'sync-settings',
    component: SyncSettings,
    props: true
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
