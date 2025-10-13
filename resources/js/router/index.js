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
    path: '/app/settings',
    name: 'sync-settings',
    component: SyncSettings
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
