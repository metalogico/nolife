<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { IconChartBar, IconClock, IconLoader2 } from '@tabler/icons-vue'
import { RouterLink } from 'vue-router'
import { labels, formatDuration } from '../types'
import type { GameStatus, StatisticsGroup } from '../types'
import {
  statistics,
  statisticsLoading,
  statisticsError,
  statisticsScroll,
  loadStatistics,
} from '../statistics'
import CustomSelect from './CustomSelect.vue'
import PlatformIcon from './PlatformIcon.vue'
import StatisticsGameList from './StatisticsGameList.vue'

const emit = defineEmits<{ open: [number] }>()
const scroller = ref<HTMLElement>()
const number = new Intl.NumberFormat('en', { maximumFractionDigits: 1 })
const weeklyHours = ref<string | number>(readWeeklyHours())
const genreSort = ref('count')
const platformSort = ref('played_minutes')
const sortOptions = [
  { value: 'count', label: 'Most collected' },
  { value: 'played_minutes', label: 'Most played' },
  { value: 'average_rating', label: 'Highest rated' },
]
const statusColors: Record<GameStatus, string> = {
  playing: '#b8f34a',
  planned: '#9da6ba',
  completed: '#64c8ae',
  tried: '#dbbc78',
  dropped: '#cf8790',
}
const pace = computed(() => {
  const value = Number(weeklyHours.value)
  return Number.isFinite(value) && value > 0 && value <= 168 ? value : null
})
const months = computed(() => {
  const minutes = statistics.value?.backlog.expected_minutes
  return minutes === null || minutes === undefined || pace.value === null
    ? null
    : minutes / 60 / pace.value / (52 / 12)
})
const genres = computed(() => sortGroups(statistics.value?.genres.groups ?? [], genreSort.value))
const platforms = computed(() => sortGroups(statistics.value?.platforms ?? [], platformSort.value))

function readWeeklyHours(): number {
  try {
    const value = Number(localStorage.getItem('nolife:weekly-hours'))
    return Number.isFinite(value) && value > 0 && value <= 168 ? value : 5
  } catch {
    return 5
  }
}

watch(pace, (value) => {
  if (value !== null) {
    try {
      localStorage.setItem('nolife:weekly-hours', String(value))
    } catch {
      // The estimate remains usable when browser storage is unavailable.
    }
  }
})

function percentage(value: number | null): string {
  return value === null ? '—' : number.format(value) + '%'
}

function share(value: number | null, total: number | null): string {
  return (value !== null && total ? Math.min(100, (value / total) * 100) : 0) + '%'
}

function sortGroups(groups: StatisticsGroup[], field: string): StatisticsGroup[] {
  const key = field as 'count' | 'played_minutes' | 'average_rating'
  return [...groups].sort(
    (first, second) =>
      (second[key] ?? -1) - (first[key] ?? -1) ||
      first.name.localeCompare(second.name, 'en', { sensitivity: 'base' }),
  )
}

function projection(value: number): string {
  if (value > 0 && value < 0.1) {
    return 'less than 0.1 months'
  }
  const formatted = number.format(value)
  return 'about ' + formatted + (formatted === '1' ? ' month' : ' months')
}

onMounted(async () => {
  await loadStatistics()
  await nextTick()
  if (scroller.value) {
    scroller.value.scrollTop = statisticsScroll.value
  }
})
</script>

<template>
  <section
    ref="scroller"
    class="gallery-scroll statistics-scroll"
    aria-label="Statistics"
    :aria-busy="statisticsLoading"
    @scroll.passive="statisticsScroll = scroller?.scrollTop ?? 0"
  >
    <div class="statistics-heading">
      <div>
        <p class="eyebrow">Your collection, in numbers</p>
        <h1>Statistics</h1>
      </div>
      <span class="statistics-scope">
        <IconLoader2 v-if="statisticsLoading" :size="16" class="spin" />
        <IconChartBar v-else :size="17" />
        Entire collection · All time
      </span>
    </div>

    <div v-if="statisticsError" class="library-error statistics-error" role="alert">
      <p
        >{{ statisticsError
        }}<template v-if="statistics"> Showing the last loaded statistics.</template></p
      >
      <button class="button button--quiet" :disabled="statisticsLoading" @click="loadStatistics"
        >Try again</button
      >
    </div>
    <div v-if="!statistics && statisticsLoading" class="gallery-pending">
      <IconLoader2 class="spin" :size="28" />
      <span class="sr-only">Loading statistics…</span>
    </div>

    <template v-if="statistics">
      <div v-if="!statistics.collection.total" class="statistics-welcome">
        <IconChartBar :size="25" />
        <div>
          <h2>Your NoLife story starts here</h2>
          <p
            >Add games to your collection, then record your playtime and ratings to see the numbers
            grow.</p
          >
        </div>
      </div>

      <section class="statistics-hero" aria-labelledby="nolife-counter">
        <div class="statistics-counter">
          <p id="nolife-counter" class="eyebrow"><IconClock :size="18" /> The NoLife counter</p>
          <h2
            >{{
              statistics.playtime.total_minutes === null
                ? '—'
                : number.format(statistics.playtime.total_minutes / 60)
            }}<span>hours played</span></h2
          >
          <p v-if="statistics.playtime.total_minutes !== null" class="statistics-days">
            That's
            <strong>{{ number.format(statistics.playtime.total_minutes / 1440) }} days</strong> of
            24 hours.
          </p>
          <p v-else class="statistics-days">Record your playtime to start counting.</p>
          <p class="statistics-note"
            >Playtime recorded for {{ statistics.playtime.recorded_count }} of
            {{ statistics.collection.total }} games.</p
          >
        </div>
        <div class="statistics-hero-ranking">
          <h3>The games that absorbed you</h3>
          <p class="statistics-note">Your five most played games.</p>
          <StatisticsGameList
            :games="statistics.playtime.top_games"
            metric="played"
            empty="No positive playtime recorded yet."
            @open="emit('open', $event)"
          />
        </div>
      </section>

      <div class="statistics-grid">
        <section class="statistics-panel" aria-labelledby="collection-statistics">
          <header class="statistics-panel-heading">
            <div
              ><p class="eyebrow">The collection</p
              ><h2 id="collection-statistics">Where things stand</h2></div
            >
            <span class="statistics-total"
              >{{ number.format(statistics.collection.total) }} <small>games</small></span
            >
          </header>
          <div class="statistics-status-strip" aria-hidden="true">
            <span
              v-for="entry in statistics.collection.statuses"
              :key="entry.status"
              :style="{
                width: share(entry.count, statistics.collection.total),
                background: statusColors[entry.status],
              }"
            />
          </div>
          <ul class="statistics-statuses">
            <li v-for="entry in statistics.collection.statuses" :key="entry.status">
              <RouterLink :to="'/' + entry.status"
                ><span
                  class="statistics-dot"
                  :style="{ background: statusColors[entry.status] }"
                />{{ labels[entry.status] }}</RouterLink
              >
              <strong>{{ number.format(entry.count) }}</strong
              ><span>{{ percentage(entry.percentage) }}</span>
            </li>
          </ul>
          <div class="statistics-completion">
            <div
              ><strong>{{ percentage(statistics.collection.completed_percentage) }}</strong
              ><span>of the collection completed</span></div
            >
            <div
              ><strong>{{ percentage(statistics.collection.started_completed_percentage) }}</strong
              ><span
                >of {{ statistics.collection.started_count }} started games completed</span
              ></div
            >
          </div>
          <p class="statistics-note">Started includes Playing, Completed, Tried and Dropped.</p>
        </section>

        <section class="statistics-panel" aria-labelledby="backlog-statistics">
          <header class="statistics-panel-heading">
            <div
              ><p class="eyebrow">The backlog</p
              ><h2 id="backlog-statistics">How much is waiting?</h2></div
            >
            <span class="statistics-total"
              >{{ number.format(statistics.backlog.count) }} <small>Planned</small></span
            >
          </header>
          <div class="statistics-backlog-time"
            >{{ formatDuration(statistics.backlog.expected_minutes)
            }}<span>of expected playtime</span></div
          >
          <p class="statistics-note"
            >Expected length recorded for {{ statistics.backlog.known_duration_count }} of
            {{ statistics.backlog.count }} Planned games. Missing lengths are excluded.</p
          >
          <div class="statistics-pace">
            <label for="statistics-weekly-hours">Your weekly pace</label>
            <div
              ><input
                id="statistics-weekly-hours"
                v-model="weeklyHours"
                type="number"
                min="0"
                max="168"
                step="any"
                inputmode="decimal"
                :aria-invalid="pace === null"
                :aria-describedby="pace === null ? 'statistics-pace-error' : undefined"
              /><span>hours / week</span></div
            >
          </div>
          <p v-if="pace === null" id="statistics-pace-error" class="form-error"
            >Enter more than 0 and up to 168 hours per week.</p
          >
          <p class="statistics-projection" aria-live="polite">
            <template v-if="!statistics.backlog.count">Your backlog is clear.</template>
            <template v-else-if="months !== null"
              >At {{ pace }} hours a week, that's <strong>{{ projection(months) }}</strong> of games
              with a known length.</template
            >
            <template v-else-if="pace !== null"
              >Add expected lengths to estimate your backlog.</template
            >
            <template v-else>Set your weekly pace to see the estimate.</template>
          </p>
          <h3 class="statistics-list-heading">Five short games to start</h3>
          <StatisticsGameList
            :games="statistics.backlog.short_games"
            metric="length"
            empty="No Planned games with a positive expected length yet."
            @open="emit('open', $event)"
          />
        </section>
      </div>

      <section class="statistics-panel statistics-wide" aria-labelledby="genre-statistics">
        <header class="statistics-panel-heading">
          <div
            ><p class="eyebrow">Your player profile</p
            ><h2 id="genre-statistics">What you collect. What you play.</h2></div
          >
          <CustomSelect
            v-if="genres.length"
            v-model="genreSort"
            :options="sortOptions"
            label="Sort genres"
          />
        </header>
        <p class="statistics-note"
          >Genres recorded for {{ statistics.genres.classified_count }} of
          {{ statistics.collection.total }} games. Games and hours count fully in each matching
          genre, so genre totals overlap.</p
        >
        <p v-if="genres.length" class="statistics-note statistics-table-hint"
          >Scroll to see all columns →</p
        >
        <div
          v-if="genres.length"
          class="statistics-table-scroll"
          role="region"
          aria-label="Statistics by genre"
          tabindex="0"
        >
          <table class="statistics-table statistics-genre-table">
            <thead
              ><tr
                ><th scope="col">Genre</th><th scope="col">Games owned</th
                ><th scope="col">Hours played</th><th scope="col">Average rating</th></tr
              ></thead
            >
            <tbody>
              <tr v-for="genre in genres" :key="genre.name">
                <th scope="row"
                  ><RouterLink :to="{ path: '/collection', query: { genre: genre.name } }">{{
                    genre.name
                  }}</RouterLink></th
                >
                <td
                  ><span>{{ number.format(genre.count) }}</span
                  ><div class="statistics-mini-bar" aria-hidden="true"
                    ><span
                      :style="{ width: share(genre.count, statistics.collection.total) }" /></div
                ></td>
                <td
                  ><span>{{ formatDuration(genre.played_minutes) }}</span
                  ><small>{{ genre.played_count }} recorded</small
                  ><div class="statistics-mini-bar statistics-mini-bar--time" aria-hidden="true"
                    ><span
                      :style="{
                        width: share(genre.played_minutes, statistics.playtime.total_minutes),
                      }" /></div
                ></td>
                <td
                  ><strong>{{
                    genre.average_rating === null
                      ? '—'
                      : number.format(genre.average_rating) + '/10'
                  }}</strong
                  ><small>{{ genre.rated_count }} rated</small></td
                >
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="statistics-note statistics-empty"
          >Add genre metadata to discover your player profile.</p
        >
      </section>

      <section class="statistics-panel statistics-wide" aria-labelledby="platform-statistics">
        <header class="statistics-panel-heading">
          <div
            ><p class="eyebrow">Your platforms</p
            ><h2 id="platform-statistics">Where you spend your time</h2></div
          >
          <CustomSelect
            v-if="platforms.length"
            v-model="platformSort"
            :options="sortOptions"
            label="Sort platforms"
          />
        </header>
        <p v-if="platforms.length" class="statistics-note statistics-table-hint"
          >Scroll to see all columns →</p
        >
        <div
          v-if="platforms.length"
          class="statistics-table-scroll"
          role="region"
          aria-label="Statistics by platform"
          tabindex="0"
        >
          <table class="statistics-table">
            <thead
              ><tr
                ><th scope="col">Platform</th><th scope="col">Games owned</th
                ><th scope="col">Hours played</th><th scope="col">Completed</th
                ><th scope="col">Average rating</th></tr
              ></thead
            >
            <tbody>
              <tr v-for="platform in platforms" :key="platform.name">
                <th scope="row"
                  ><RouterLink :to="{ path: '/collection', query: { platform: platform.name } }"
                    ><PlatformIcon :platform="platform.name" />{{ platform.name }}</RouterLink
                  ></th
                >
                <td>{{ number.format(platform.count) }}</td>
                <td
                  >{{ formatDuration(platform.played_minutes)
                  }}<small>{{ platform.played_count }} recorded</small></td
                >
                <td
                  >{{ percentage(platform.completed_percentage)
                  }}<small>{{ platform.completed_count }} of {{ platform.count }} games</small></td
                >
                <td
                  ><strong>{{
                    platform.average_rating === null
                      ? '—'
                      : number.format(platform.average_rating) + '/10'
                  }}</strong
                  ><small>{{ platform.rated_count }} rated</small></td
                >
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="statistics-note statistics-empty"
          >Your platforms will appear when you add games.</p
        >
        <div class="statistics-formats" aria-label="Physical and Digital ownership">
          <div v-for="entry in statistics.formats" :key="entry.format">
            <div
              ><span>{{ entry.format === 'physical' ? 'Physical' : 'Digital' }}</span
              ><strong
                >{{ entry.count }} games <small>· {{ percentage(entry.percentage) }}</small></strong
              ></div
            >
            <div
              class="statistics-mini-bar"
              :class="{ 'statistics-mini-bar--time': entry.format === 'digital' }"
              aria-hidden="true"
              ><span :style="{ width: share(entry.count, statistics.collection.total) }"
            /></div>
          </div>
        </div>
      </section>

      <section class="statistics-panel statistics-wide" aria-labelledby="rating-statistics">
        <header class="statistics-panel-heading">
          <div
            ><p class="eyebrow">Your ratings</p
            ><h2 id="rating-statistics">How you score your games</h2></div
          >
          <div class="statistics-average"
            ><strong
              >{{
                statistics.ratings.average === null
                  ? '—'
                  : number.format(statistics.ratings.average)
              }}<small v-if="statistics.ratings.average !== null">/10</small></strong
            ><span>Personal average · {{ statistics.ratings.rated_count }} rated</span></div
          >
        </header>
        <div class="statistics-rating-grid">
          <div>
            <p class="statistics-note"
              >{{ statistics.ratings.rated_count }} of {{ statistics.collection.total }} games
              rated. Each column shows the number of games given that score.</p
            >
            <ol class="statistics-rating-chart" aria-label="Rating distribution">
              <li
                v-for="entry in statistics.ratings.distribution"
                :key="entry.rating"
                :aria-label="entry.rating + ' out of 10: ' + entry.count + ' games'"
              >
                <span>{{ entry.count }}</span
                ><div class="statistics-rating-track" aria-hidden="true"
                  ><div
                    :style="{ height: share(entry.count, statistics.ratings.rated_count) }" /></div
                ><strong>{{ entry.rating }}</strong>
              </li>
            </ol>
          </div>
          <div>
            <h3>Your favorites</h3>
            <StatisticsGameList
              :games="statistics.ratings.favorites"
              metric="rating"
              empty="Rate a game to start your favorites list."
              @open="emit('open', $event)"
            />
          </div>
        </div>
      </section>
      <p class="statistics-footnote"
        >Each platform and format copy counts separately. Playtime is entered manually; missing
        values are excluded from sums and averages.</p
      >
    </template>
  </section>
</template>
