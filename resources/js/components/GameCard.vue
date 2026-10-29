<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import VanillaTilt from 'vanilla-tilt'
import { IconDeviceGamepad2, IconDots, IconStarFilled, IconRefresh } from '@tabler/icons-vue'
import type { Game } from '../types'
import { labels, formatDuration } from '../types'
const props = defineProps<{ game: Game; transitionId?: number | null; list?: boolean }>()
const emit = defineEmits<{
  open: [Game]
  menu: [Game, HTMLElement]
  status: [Game, HTMLElement]
  ambient: [Game]
}>()
const tilt = ref<HTMLElement & { vanillaTilt?: { destroy(): void } }>()
const loaded = ref(false)
const failed = ref(false)
let motionQuery: MediaQueryList
let pointerQuery: MediaQueryList
function imageFailed() {
  loaded.value = true
  failed.value = true
}

function setupTilt() {
  tilt.value?.vanillaTilt?.destroy()
  tilt.value?.querySelectorAll('.js-tilt-glare').forEach((element) => element.remove())
  if (!motionQuery.matches && pointerQuery.matches && tilt.value) {
    VanillaTilt.init(tilt.value, {
      max: props.list ? 4 : 12,
      perspective: props.list ? 1400 : 650,
      scale: 1.015,
      speed: 400,
      glare: true,
      'max-glare': 0.09,
      gyroscope: false,
    })
  }
}
watch(
  () => props.game.thumbnail_url,
  () => {
    loaded.value = false
    failed.value = false
  },
)
watch(() => props.list, setupTilt, { flush: 'post' })
onMounted(() => {
  motionQuery = matchMedia('(prefers-reduced-motion: reduce)')
  pointerQuery = matchMedia('(hover: hover) and (pointer: fine)')
  setupTilt()
  motionQuery.addEventListener('change', setupTilt)
  pointerQuery.addEventListener('change', setupTilt)
})
onBeforeUnmount(() => {
  tilt.value?.vanillaTilt?.destroy()
  motionQuery?.removeEventListener('change', setupTilt)
  pointerQuery?.removeEventListener('change', setupTilt)
})
</script>

<template>
  <article
    :class="['game-card', { 'game-card--list': list }]"
    @mouseenter="emit('ambient', game)"
    @focusin="emit('ambient', game)"
  >
    <div class="cover-shell">
      <div ref="tilt" class="cover-tilt">
        <div class="cover-frame">
          <button
            class="cover-open"
            type="button"
            :aria-label="'Open ' + game.title"
            @click="emit('open', game)"
          >
            <img
              v-if="game.thumbnail_url && !failed"
              :src="game.thumbnail_url"
              :alt="game.title + ' cover'"
              loading="lazy"
              decoding="async"
              :class="{ loaded }"
              :style="{
                viewTransitionName: transitionId === game.id ? 'game-cover-' + game.id : undefined,
              }"
              @load="loaded = true"
              @error="imageFailed"
            />
            <span v-else class="cover-placeholder">
              <IconDeviceGamepad2 :size="42" stroke="1" />
              <span>{{ game.title }}</span>
            </span>
            <span v-if="game.thumbnail_url && !loaded" class="cover-skeleton" />
          </button>
        </div>
      </div>
      <div class="cover-actions">
        <button
          type="button"
          :aria-label="'Change status for ' + game.title"
          @click.stop="emit('status', game, $event.currentTarget as HTMLElement)"
        >
          <IconRefresh :size="17" />
          <span>Change status</span>
        </button>
        <button
          type="button"
          :aria-label="'More actions for ' + game.title"
          @click.stop="emit('menu', game, $event.currentTarget as HTMLElement)"
        >
          <IconDots :size="20" />
        </button>
      </div>
    </div>
    <div class="card-caption">
      <h3>
        <button type="button" @click="emit('open', game)">{{ game.title }}</button>
      </h3>
      <p class="card-platform"
        >{{ game.platform }}<span>·</span
        >{{ game.format === 'physical' ? 'Physical' : 'Digital' }}</p
      >
      <div class="card-bottom">
        <span :class="['status-label', 'status--' + game.status]">
          <i />{{ labels[game.status] }}</span
        >
        <span v-if="game.rating !== null" class="card-rating">
          <IconStarFilled :size="15" />{{ game.rating }}<small>/10</small>
        </span>
        <span v-else class="unrated">—</span>
      </div>
      <span v-if="list" class="list-duration">{{ formatDuration(game.duration_minutes) }}</span>
    </div>
  </article>
</template>
