<script setup lang="ts">
import { IconChevronRight, IconDeviceGamepad2 } from '@tabler/icons-vue'
import type { StatisticsGame } from '../types'
import { formatDuration } from '../types'

defineProps<{
  games: StatisticsGame[]
  metric: 'played' | 'length' | 'rating'
  empty: string
}>()
const emit = defineEmits<{ open: [number] }>()
</script>

<template>
  <ol v-if="games.length" class="statistics-game-list">
    <li v-for="(game, index) in games" :key="game.id">
      <button class="statistics-game" @click="emit('open', game.id)">
        <span class="statistics-game-rank">{{ index + 1 }}</span>
        <img v-if="game.thumbnail_url" :src="game.thumbnail_url" alt="" loading="lazy" />
        <span v-else class="statistics-game-cover"><IconDeviceGamepad2 :size="20" /></span>
        <span class="statistics-game-info">
          <strong>{{ game.title }}</strong>
          <small
            >{{ game.platform }} · {{ game.format === 'physical' ? 'Physical' : 'Digital' }}</small
          >
        </span>
        <span class="statistics-game-value">{{
          metric === 'rating'
            ? game.rating + '/10'
            : formatDuration(
                metric === 'played' ? game.total_played_minutes : game.duration_minutes,
              )
        }}</span>
        <IconChevronRight :size="16" class="statistics-game-arrow" />
      </button>
    </li>
  </ol>
  <p v-else class="statistics-note statistics-empty">{{ empty }}</p>
</template>
