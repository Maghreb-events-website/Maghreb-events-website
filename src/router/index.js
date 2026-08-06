import { createRouter, createWebHistory } from 'vue-router'

import Home from '@/views/Home.vue'
import Airlines from '../views/Airlines.vue'
import Breifing from '../views/Breifing.vue'
import GiveAway from '../views/GiveAway.vue'
import Hitsquad from '../views/Hitsquad.vue'
import Partners from '../views/Partners.vue'
import Planning from '../views/Planning.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: Home
    },
    {
      path: '/airlines',
      component: Airlines
    },
        {
      path: '/breifing',
      component: Breifing
    },
        {
      path: '/giveaway',
      component: GiveAway
    },
        {
      path: '/hitsquad',
      component: Hitsquad
    },
        {
      path: '/partners',
      component: Partners
    },
            {
      path: '/planning',
      component: Planning
    }
  ]
})

export default router