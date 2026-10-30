<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import {
  IconArrowLeft,
  IconPencil,
  IconDatabaseSearch,
  IconDots,
  IconClock,
  IconHourglass,
  IconDeviceGamepad2,
  IconChevronRight,
  IconPhotoUp,
  IconCheck,
} from '@tabler/icons-vue'
import StarRating from './StarRating.vue'
import PlatformIcon from './PlatformIcon.vue'
import EditablePlaytime from './EditablePlaytime.vue'
import type { Game, LibraryView } from '../types'
import { labels } from '../types'
const props = defineProps<{
  game: Game
  games: Game[]
  view: LibraryView
  backLabel?: string
  transitionId?: number | null
  busy?: boolean
  savePlaytime: (game: Game, changes: Partial<Game>) => Promise<boolean>
}>()
const emit = defineEmits<{
  open: [Game]
  back: []
  edit: [Game]
  metadata: [Game]
  menu: [Game, HTMLElement]
  status: [Game, HTMLElement]
  rating: [Game, number | null]
  image: [number]
  header: [Game, number]
}>()
const screenshots = computed(() =>
  props.game.images.filter((image) => image.kind === 'screenshot' || image.kind === 'artwork'),
)
const context = computed(() =>
  props.games.some((item) => item.id === props.game.id)
    ? props.games
    : [props.game, ...props.games],
)
const contextScroll = ref<HTMLDivElement>()
let contextObserver: ResizeObserver | undefined

function revealSelectedGame() {
  const scroller = contextScroll.value
  const selected = scroller?.querySelector<HTMLElement>('.context-game.selected')
  if (!scroller || !selected || !scroller.clientHeight) {
    return
  }
  const viewport = scroller.getBoundingClientRect()
  const item = selected.getBoundingClientRect()
  const itemTop = scroller.scrollTop + item.top - viewport.top
  scroller.scrollTop = Math.max(0, itemTop - (scroller.clientHeight - item.height) / 2)
}

watch(
  [contextScroll, () => props.game.id, () => context.value.map((item) => item.id).join(',')],
  revealSelectedGame,
  { flush: 'post' },
)
onMounted(() => {
  contextObserver = new ResizeObserver(revealSelectedGame)
  if (contextScroll.value) {
    contextObserver.observe(contextScroll.value)
  }
})
onBeforeUnmount(() => contextObserver?.disconnect())
</script>

<template>
  <div class="detail-layout">
    <aside class="context-list" aria-label="Games in this view">
      <div class="context-heading">
        <button class="text-button" @click="emit('back')">
          <IconArrowLeft :size="18" />{{ backLabel || labels[view] }}</button
        >
      </div>
      <div ref="contextScroll" class="context-scroll">
        <button
          v-for="item in context"
          :key="item.id"
          :class="['context-game', { selected: item.id === game.id }]"
          :aria-current="item.id === game.id ? 'true' : undefined"
          @click="emit('open', item)"
        >
          <img
            v-if="item.thumbnail_url"
            :src="item.thumbnail_url"
            :alt="item.title + ' cover'"
            loading="lazy"
            :style="{
              viewTransitionName:
                transitionId === item.id && item.id === game.id
                  ? 'game-cover-' + item.id
                  : undefined,
            }"
          />
          <span v-else class="context-placeholder">
            <IconDeviceGamepad2 :size="24" />
          </span>
          <span class="context-caption">
            <strong>{{ item.title }}</strong>
            <small
              >{{ item.platform }} ·
              {{ item.format === 'physical' ? 'Physical' : 'Digital' }}</small
            >
            <span :class="['status-label', 'status--' + item.status]">
              <i />{{ labels[item.status] }}</span
            >
          </span>
        </button>
      </div>
    </aside>
    <section class="game-detail" aria-label="Game details">
      <div class="detail-scroll">
        <div
          class="detail-artwork"
          :class="{ 'detail-artwork--cover': game.background_url === game.cover_url }"
        >
          <Transition name="artwork">
            <img
              v-if="game.background_url"
              :key="game.background_url"
              :src="game.background_url"
              alt=""
              decoding="async"
            />
          </Transition>
          <div class="detail-artwork-shade" />
          <button
            class="back-button"
            :aria-label="'Back to ' + (backLabel || labels[view])"
            @click="emit('back')"
          >
            <IconArrowLeft :size="20" />
            <span>{{ backLabel || labels[view] }}</span>
          </button>
        </div>
        <div class="detail-content" :key="game.id">
          <div class="detail-heading">
            <div>
              <h1>{{ game.title }}</h1>
              <p class="detail-meta">
                <span class="detail-platform">
                  <PlatformIcon :platform="game.platform" />{{ game.platform }}</span
                >
                <span class="meta-separator">·</span
                >{{ game.format === 'physical' ? 'Physical' : 'Digital'
                }}<template v-if="game.release_year">
                  <span class="meta-separator">·</span>{{ game.release_year }}</template
                >
                <template v-if="game.genres.length">
                  <span class="meta-separator">·</span>
                  <span class="detail-genres">{{ game.genres.join(' · ') }}</span>
                </template>
              </p>
            </div>
            <button
              class="icon-button edit-detail"
              aria-label="Edit game"
              data-tooltip="Edit game"
              @click="emit('edit', game)"
            >
              <IconPencil :size="21" />
            </button>
          </div>
          <div class="personal-controls">
            <div class="personal-status">
              <span class="control-label">Status</span>
              <button
                class="status-selector button button--quiet"
                :aria-label="'Change status, currently ' + labels[game.status]"
                @click="emit('status', game, $event.currentTarget as HTMLElement)"
              >
                <span :class="['status-label', 'status--' + game.status]">
                  <i />{{ labels[game.status] }}</span
                >
                <IconChevronRight class="chevron-down" :size="15" />
              </button>
            </div>
            <div class="personal-rating">
              <span class="control-label">Your rating</span>
              <StarRating
                :model-value="game.rating"
                :busy="busy"
                @update:model-value="emit('rating', game, $event)"
              />
            </div>
          </div>
          <div class="playtime-readouts" aria-label="Play time">
            <EditablePlaytime
              :game-id="game.id"
              label="Expected length"
              :minutes="game.duration_minutes"
              :icon="IconClock"
              :disabled="busy"
              :save="(minutes) => savePlaytime(game, { duration_minutes: minutes })"
            />
            <EditablePlaytime
              :game-id="game.id"
              label="Total played"
              :minutes="game.total_played_minutes"
              :icon="IconHourglass"
              :disabled="busy"
              :save="(minutes) => savePlaytime(game, { total_played_minutes: minutes })"
            />
          </div>
          <p class="detail-summary">{{
            game.summary || 'No description yet. Fetch the game data or add a description manually.'
          }}</p>
          <div class="detail-tools">
            <button class="text-button" @click="emit('metadata', game)">
              <IconDatabaseSearch :size="18" />Fetch metadata</button
            >
            <button
              class="text-button"
              @click="emit('menu', game, $event.currentTarget as HTMLElement)"
            >
              <IconDots :size="18" />More actions</button
            >
          </div>
          <div class="detail-data" v-if="game.developer || game.publisher">
            <div v-if="game.developer">
              <span class="eyebrow">Developer</span>
              <p>{{ game.developer }}</p>
            </div>
            <div v-if="game.publisher">
              <span class="eyebrow">Publisher</span>
              <p>{{ game.publisher }}</p>
            </div>
          </div>
          <section v-if="screenshots.length" class="screenshot-section">
            <h2>Screenshots</h2>
            <div class="screenshot-grid">
              <figure v-for="(image, index) in screenshots" :key="image.id" class="gallery-shot">
                <button
                  class="screenshot-open"
                  :aria-label="'Open screenshot ' + (index + 1)"
                  @click="emit('image', index)"
                >
                  <img
                    :src="image.thumbnail_url || image.url || ''"
                    :alt="game.title + ' screenshot ' + (index + 1)"
                    loading="lazy"
                    decoding="async"
                  />
                </button>
                <button
                  class="icon-button header-action"
                  :class="{ 'is-header': game.header_image_id === image.id }"
                  :aria-label="'Use image ' + (index + 1) + ' as header'"
                  :aria-pressed="game.header_image_id === image.id"
                  :aria-disabled="busy"
                  :data-tooltip="
                    game.header_image_id === image.id ? 'Current header' : 'Set as header'
                  "
                  @click="!busy && emit('header', game, image.id)"
                >
                  <IconCheck v-if="game.header_image_id === image.id" :size="18" />
                  <IconPhotoUp v-else :size="18" />
                </button>
              </figure>
            </div>
          </section>
          <div v-else class="no-artwork">
            <IconDeviceGamepad2 :size="25" stroke="1.25" />
            <p>Artwork appears here after fetching game data.</p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
