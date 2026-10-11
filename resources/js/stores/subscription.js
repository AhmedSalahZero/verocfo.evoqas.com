import { defineStore } from 'pinia'
import axios from 'axios'

export const useSubscriptionStore = defineStore('subscription', {
  state: () => ({
    isSuperAdmin: false,
    remainingDays: null,
    warningDays: null,
    isExpired: false,
    isExpiringSoon: false,
    subscriptionEndDate: null,
    message: null,
    loaded: false,
  }),

  actions: {
    async fetchStatus() {
      try {
        const { data } = await axios.get(route('subscription.status'))

        this.isSuperAdmin = !!data.is_super_admin
        this.remainingDays = data.remaining_days
        this.warningDays = data.warning_days
        this.isExpired = !!data.is_expired
        this.isExpiringSoon = !!data.is_expiring_soon
        this.subscriptionEndDate = data.subscription_end_date
        this.message = data.message
        this.loaded = true
      } catch (_error) {
        this.loaded = true
      }
    },
  },
})

