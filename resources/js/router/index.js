import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import GeneralSettings from '../pages/GeneralSettings.vue'
import FranchiseSettings from '../pages/FranchiseSettings.vue'
import WelcomeScreen from '../pages/WelcomeScreen.vue'

const routes = [
  {
    path: '/app/welcome',
    name: 'welcome',
    component: WelcomeScreen,
    meta: { skipAccountTypeCheck: true }
  },
  {
    path: '/app',
    name: 'dashboard',
    component: Dashboard
  },
  // Redirect old /app/accounts to dashboard
  {
    path: '/app/accounts',
    redirect: '/app'
  },
  {
    path: '/app/settings',
    name: 'general-settings',
    component: GeneralSettings
  },
  // Franchise settings with tabs
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
