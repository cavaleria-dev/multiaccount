import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import ChildAccounts from '../pages/ChildAccounts.vue'
import GeneralSettings from '../pages/GeneralSettings.vue'
import FranchiseSettings from '../pages/FranchiseSettings.vue'
import WelcomeScreen from '../pages/WelcomeScreen.vue'
import axios from 'axios'

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

// Navigation guard: Check account type and redirect to welcome if null
router.beforeEach(async (to, from, next) => {
  // Skip check for welcome screen itself
  if (to.meta.skipAccountTypeCheck) {
    next()
    return
  }

  try {
    const response = await axios.get('/api/account/type')
    const accountType = response.data.account_type

    // If account type is not set, redirect to welcome screen
    if (accountType === null || accountType === undefined) {
      if (to.name !== 'welcome') {
        next({ name: 'welcome' })
      } else {
        next()
      }
    } else {
      // Account type is set, allow navigation
      next()
    }
  } catch (err) {
    console.error('Failed to check account type:', err)
    // Allow navigation even if check fails (to prevent blocking)
    next()
  }
})

export default router
