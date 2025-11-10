import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../pages/Dashboard.vue'
import ChildAccounts from '../pages/ChildAccounts.vue'
import GeneralSettings from '../pages/GeneralSettings.vue'
import FranchiseSettings from '../pages/FranchiseSettings.vue'
import WelcomeScreen from '../pages/WelcomeScreen.vue'
import FranchiseLayout from '../layouts/FranchiseLayout.vue'
import FranchiseProducts from '../pages/franchise/FranchiseProducts.vue'
import FranchiseDocuments from '../pages/franchise/FranchiseDocuments.vue'
import FranchiseGeneral from '../pages/franchise/FranchiseGeneral.vue'

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
  // Legacy route for backwards compatibility
  {
    path: '/app/accounts/:accountId/settings',
    redirect: to => `/app/accounts/${to.params.accountId}/products`
  },
  // New nested routes for franchise settings
  {
    path: '/app/accounts/:accountId',
    component: FranchiseLayout,
    props: true,
    children: [
      {
        path: '',
        redirect: to => `/app/accounts/${to.params.accountId}/products`
      },
      {
        path: 'products',
        name: 'franchise-products',
        component: FranchiseProducts,
        props: true
      },
      {
        path: 'documents',
        name: 'franchise-documents',
        component: FranchiseDocuments,
        props: true
      },
      {
        path: 'general',
        name: 'franchise-general',
        component: FranchiseGeneral,
        props: true
      }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
