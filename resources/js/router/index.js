import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import ChildAccounts from '../pages/ChildAccounts.vue'
import GeneralSettings from '../pages/GeneralSettings.vue'
import FranchiseSettings from '../pages/FranchiseSettings.vue'

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
    name: 'general-settings',
    component: GeneralSettings
  },
  {
    path: '/app/accounts/:accountId/settings',
    name: 'franchise-settings',
    component: FranchiseSettings,
    props: true
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
