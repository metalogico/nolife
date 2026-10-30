<script setup lang="ts">
import { ref, shallowRef, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import {
  IconPlus,
  IconSearch,
  IconMenu2,
  IconX,
  IconLogout,
  IconPlayerPlay,
  IconClock,
  IconCircleCheck,
  IconCircleDot,
  IconCircleX,
  IconBooks,
  IconLayoutGrid,
  IconList,
  IconDeviceGamepad2,
  IconLoader2,
  IconPencil,
  IconDatabaseSearch,
  IconTrash,
  IconCheck,
  IconArrowRight,
  IconSettings,
  IconChartBar,
} from '@tabler/icons-vue'
import type { Component } from 'vue'
import { router } from './router'
import { api, setCsrfToken } from './api'
import {
  games,
  activeGame,
  info,
  loading,
  loadingMore,
  libraryError,
  page,
  lastPage,
  loadGames,
  loadGame,
  loadInfo,
  replaceGame,
} from './library'
import type { Game, GameStatus, LibraryView, Session, Filters } from './types'
import { statuses, labels } from './types'
import BrandLogo from './components/BrandLogo.vue'
import GameCard from './components/GameCard.vue'
import GameDetails from './components/GameDetails.vue'
import MetadataDialog from './components/MetadataDialog.vue'
import GameEditor from './components/GameEditor.vue'
import CustomSelect from './components/CustomSelect.vue'
import PlatformIcon from './components/PlatformIcon.vue'
import DialogShell from './components/DialogShell.vue'
import ActionMenu from './components/ActionMenu.vue'
import type { MenuItem } from './components/ActionMenu.vue'
import ImageViewer from './components/ImageViewer.vue'
import SettingsPage from './components/SettingsPage.vue'
import StatisticsPage from './components/StatisticsPage.vue'
import { clearStatistics, loadStatistics } from './statistics'

const route = useRoute()
const session = ref<Session | null>(null)
const booting = ref(true)
const login = ref({ username: '', password: '' })
const loginBusy = ref(false)
const loginError = ref('')
const mobileNav = ref(false)
const narrowQuery = matchMedia('(max-width: 900px)')
const narrowScreen = ref(narrowQuery.matches)
const sidebar = ref<HTMLElement>()
const navToggle = ref<HTMLButtonElement>()
function screenChanged() {
  narrowScreen.value = narrowQuery.matches
  if (!narrowQuery.matches) {
    mobileNav.value = false
  }
}
watch(mobileNav, async (open) => {
  await nextTick()
  if (narrowScreen.value) {
    if (open) {
      sidebar.value?.querySelector<HTMLButtonElement>('button')?.focus()
    } else {
      navToggle.value?.focus()
    }
  }
})
const searchInput = ref('')
const searchElement = ref<HTMLInputElement>()
const gallery = ref<HTMLElement>()
const paginationTrigger = ref<HTMLElement>()
const restoringScroll = ref(true)
let pageObserver: IntersectionObserver | undefined
const dialog = ref<InstanceType<typeof DialogShell>>()
const layout = ref(localStorage.getItem('nolife:layout') || 'grid')
const transitionId = ref<number | null>(null)
const busyGame = ref<number | null>(null)
const hoveredGame = shallowRef<Game | null>(null)
const menu = shallowRef<{ anchor: HTMLElement; items: MenuItem[] } | null>(null)
type Modal =
  | { kind: 'metadata'; game?: Game; initialStatus?: GameStatus }
  | { kind: 'edit'; game: Game }
  | { kind: 'delete'; game: Game }
  | { kind: 'images'; index: number }
const modal = shallowRef<Modal | null>(null)
const showLoading = ref(false)
let loadingTimer: ReturnType<typeof setTimeout>
watch(loading, (busy) => {
  clearTimeout(loadingTimer)
  showLoading.value = false
  if (busy) {
    loadingTimer = setTimeout(() => (showLoading.value = true), 180)
  }
})
const deleting = ref(false)
const deleteError = ref('')
const toast = ref<{ text: string; error?: boolean } | null>(null)
let toastTimer: ReturnType<typeof setTimeout>
let searchTimer: ReturnType<typeof setTimeout>
let poll: ReturnType<typeof setInterval>
const scrollPositions = new Map<string, number>()
const navIcons: Record<LibraryView, Component> = {
  playing: IconPlayerPlay,
  planned: IconClock,
  completed: IconCircleCheck,
  tried: IconCircleDot,
  dropped: IconCircleX,
  collection: IconBooks,
}
const isStatistics = computed(() => route.meta.statistics === true)
const isSettings = computed(() => route.meta.settings === true)
const isLibrary = computed(() => !isStatistics.value && !isSettings.value)
const view = computed(
  () => (route.params.view || (isLibrary.value ? 'playing' : 'collection')) as LibraryView,
)
const sectionPath = computed(() => (isStatistics.value ? '/statistics' : '/' + view.value))
const selectedId = computed(() => (route.params.id ? Number(route.params.id) : null))
function queryValue(key: string) {
  const value = route.query[key]
  return typeof value === 'string' ? value : ''
}
const filters = computed<Filters>(() => ({
  view: view.value,
  q: queryValue('q'),
  platform: queryValue('platform'),
  format: queryValue('format'),
  genre: queryValue('genre'),
  sort: queryValue('sort') || 'title',
}))
const filterKey = computed(() => JSON.stringify(filters.value))
const hasFilters = computed(() =>
  Boolean(filters.value.q || filters.value.platform || filters.value.format || filters.value.genre),
)
const selected = computed(() => {
  if (!selectedId.value) {
    return null
  }
  if (activeGame.value?.id === selectedId.value) {
    return activeGame.value
  }
  return games.value.find((game) => game.id === selectedId.value) || null
})
const visibleGames = computed(() =>
  isLibrary.value
    ? games.value.filter((game) => view.value === 'collection' || game.status === view.value)
    : [],
)
const ambient = computed(() =>
  isSettings.value
    ? null
    : selected.value?.ambient_url ||
      hoveredGame.value?.ambient_url ||
      visibleGames.value[0]?.ambient_url ||
      null,
)
const lightboxImages = computed(
  () => selected.value?.images.filter((image) => image.kind !== 'cover') || [],
)
const gameCount = computed(() =>
  view.value === 'collection' ? info.value.total : info.value.counts[view.value] || 0,
)
const emptyTitle = computed(() => {
  if (view.value === 'playing') {
    return 'Nothing in progress'
  }
  if (view.value === 'collection') {
    return 'Your collection starts here'
  }
  return 'No ' + labels[view.value].toLowerCase() + ' games yet'
})

function notify(text: string, error = false) {
  clearTimeout(toastTimer)
  toast.value = { text, error }
  toastTimer = setTimeout(() => (toast.value = null), 4500)
}

async function bootstrap() {
  try {
    session.value = await api<Session>('session')
    setCsrfToken(session.value.csrf_token)
    if (session.value.authenticated) {
      await loadInfo()
    }
  } catch {
    loginError.value = 'NoLife could not be reached. Please refresh the page.'
  } finally {
    booting.value = false
  }
}

async function signIn() {
  loginBusy.value = true
  loginError.value = ''
  try {
    session.value = await api<Session>('session', {
      method: 'POST',
      body: JSON.stringify(login.value),
    })
    setCsrfToken(session.value.csrf_token)
    login.value.password = ''
    await router.replace('/playing')
    await loadInfo()
  } catch (error) {
    loginError.value = error instanceof Error ? error.message : 'Sign in failed.'
  } finally {
    loginBusy.value = false
  }
}

async function signOut() {
  try {
    const result = await api<{ csrf_token: string }>('session', { method: 'DELETE' })
    setCsrfToken(result.csrf_token)
    if (session.value) {
      session.value.authenticated = false
    }
    games.value = []
    activeGame.value = null
    clearStatistics()
    modal.value = null
    menu.value = null
    hoveredGame.value = null
    await router.replace('/playing')
  } catch (error) {
    notify(error instanceof Error ? error.message : 'Sign out failed.', true)
  }
}

async function unauthorized() {
  if (session.value) {
    session.value.authenticated = false
  }
  modal.value = null
  menu.value = null
  clearStatistics()
  try {
    session.value = await api<Session>('session')
    setCsrfToken(session.value.csrf_token)
  } catch {}
}
watch(
  () => [session.value?.authenticated, filterKey.value, isLibrary.value, isSettings.value],
  async () => {
    clearTimeout(searchTimer)
    searchInput.value = isLibrary.value ? filters.value.q : ''
    hoveredGame.value = null
    if (session.value?.authenticated && isLibrary.value) {
      await loadGames(filters.value)
    }
  },
  { immediate: true },
)
watch(
  () => [session.value?.authenticated, selectedId.value],
  async () => {
    if (!session.value?.authenticated || !selectedId.value) {
      return
    }
    const id = selectedId.value
    const cached = games.value.find((game) => game.id === id)
    if (cached) {
      activeGame.value = cached
    }
    try {
      await loadGame(id)
    } catch (error) {
      if (selectedId.value === id) {
        notify(error instanceof Error ? error.message : 'Game not found.', true)
        await router.replace({ path: sectionPath.value, query: route.query })
      }
    }
  },
  { immediate: true },
)
watch([selectedId, filterKey, isLibrary], () => {
  if (!selectedId.value) {
    restoringScroll.value = true
  }
})
watch(
  [gallery, loading, restoringScroll],
  async () => {
    if (selectedId.value || loading.value || !restoringScroll.value || !gallery.value) {
      return
    }
    const root = gallery.value
    const key = filterKey.value
    await nextTick()
    if (root !== gallery.value || key !== filterKey.value || selectedId.value || loading.value) {
      return
    }
    // Restore the collection before observing its pagination trigger again.
    root.scrollTop = scrollPositions.get(key) || 0
    restoringScroll.value = false
  },
  { flush: 'post' },
)
async function loadNearBottom() {
  if (
    !session.value?.authenticated ||
    !isLibrary.value ||
    selectedId.value ||
    modal.value ||
    restoringScroll.value ||
    loading.value ||
    libraryError.value ||
    page.value >= lastPage.value
  ) {
    return
  }
  if (!gallery.value || !paginationTrigger.value) {
    return
  }
  const viewport = gallery.value.getBoundingClientRect()
  const trigger = paginationTrigger.value.getBoundingClientRect()
  if (trigger.top <= viewport.bottom + 500 && trigger.bottom >= viewport.top) {
    await loadGames(filters.value, true)
  }
}
watch(
  [gallery, paginationTrigger],
  ([root, trigger]) => {
    pageObserver?.disconnect()
    pageObserver = undefined
    if (!root || !trigger || typeof IntersectionObserver === 'undefined') {
      return
    }
    pageObserver = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          void loadNearBottom()
        }
      },
      { root, rootMargin: '0px 0px 500px 0px' },
    )
    pageObserver.observe(trigger)
  },
  { flush: 'post' },
)
watch(
  [loading, page, lastPage, restoringScroll, modal, () => visibleGames.value.length],
  () => {
    void loadNearBottom()
  },
  { flush: 'post' },
)
watch(layout, (value) => localStorage.setItem('nolife:layout', value))
function rememberScroll() {
  if (gallery.value && !restoringScroll.value) {
    scrollPositions.set(filterKey.value, gallery.value.scrollTop)
  }
  if (!pageObserver) {
    void loadNearBottom()
  }
}

async function goView(next: LibraryView) {
  rememberScroll()
  mobileNav.value = false
  clearTimeout(searchTimer)
  searchInput.value = ''
  const query = isLibrary.value ? { ...route.query } : {}
  delete query.q
  await router.push({ path: '/' + next, query })
}

async function goStatistics() {
  rememberScroll()
  mobileNav.value = false
  hoveredGame.value = null
  clearTimeout(searchTimer)
  searchInput.value = ''
  await router.push('/statistics')
}

function searchChanged() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    const query = isLibrary.value ? { ...route.query } : {}
    const value = searchInput.value.trim()
    if (value) {
      query.q = value
    } else {
      delete query.q
    }
    router[view.value === 'collection' && isLibrary.value ? 'replace' : 'push']({
      path: '/collection',
      query,
    })
  }, 280)
}

function setFilter(key: string, value: string) {
  rememberScroll()
  const query = isLibrary.value ? { ...route.query } : {}
  if (value) {
    query[key] = value
  } else {
    delete query[key]
  }
  router.push({ path: isLibrary.value ? '/' + view.value : '/collection', query })
  mobileNav.value = false
}

async function motionNavigate(path: string, gameId?: number) {
  if (gameId) {
    transitionId.value = gameId
  }
  await nextTick()
  const update = async () => {
    await router.push({ path, query: route.query })
    await nextTick()
  }
  if (document.startViewTransition && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    try {
      await document.startViewTransition(update).finished
    } catch {
      /* Navigation still completes if a snapshot is unavailable. */
    }
  } else {
    await update()
  }
  transitionId.value = null
}

async function openGame(game: Game) {
  rememberScroll()
  activeGame.value = game
  await motionNavigate(sectionPath.value + '/games/' + game.id, game.id)
}

async function openStatisticsGame(id: number) {
  await motionNavigate('/statistics/games/' + id)
}

async function closeGame() {
  await motionNavigate(sectionPath.value, selectedId.value || undefined)
}

async function openSettings() {
  rememberScroll()
  mobileNav.value = false
  menu.value = null
  hoveredGame.value = null
  clearTimeout(searchTimer)
  searchInput.value = ''
  await router.push('/settings')
}

function addGame() {
  modal.value = {
    kind: 'metadata',
    initialStatus: view.value === 'collection' ? 'planned' : view.value,
  }
}

function openStatus(game: Game, anchor: HTMLElement) {
  menu.value = {
    anchor,
    items: statuses.map((status) => ({
      label: labels[status],
      icon: navIcons[status],
      selected: status === game.status,
      action: () => updateGame(game, { status }),
    })),
  }
}

function openGameMenu(game: Game, anchor: HTMLElement) {
  menu.value = {
    anchor,
    items: [
      {
        label: 'Edit details',
        icon: IconPencil,
        action: () => (modal.value = { kind: 'edit', game }),
      },
      {
        label: 'Fetch metadata',
        icon: IconDatabaseSearch,
        action: () => (modal.value = { kind: 'metadata', game }),
      },
      {
        label: 'Remove game',
        icon: IconTrash,
        danger: true,
        action: () => {
          deleteError.value = ''
          modal.value = { kind: 'delete', game }
        },
      },
    ],
  }
}

function updateMessage(changes: Partial<Game>): string {
  if (changes.status) {
    return 'Marked as ' + labels[changes.status]
  }
  if ('header_image_id' in changes) {
    return 'Header image updated'
  }
  if ('duration_minutes' in changes || 'total_played_minutes' in changes) {
    return 'Play time saved'
  }
  return changes.rating === null ? 'Rating cleared' : 'Rating saved'
}

async function updateGame(game: Game, changes: Partial<Game>): Promise<boolean> {
  if (busyGame.value) {
    return false
  }
  const previous = { ...game }
  busyGame.value = game.id
  replaceGame({ ...game, ...changes })
  try {
    const result = await api<{ data: Game }>('games/' + game.id, {
      method: 'PATCH',
      body: JSON.stringify(changes),
    })
    replaceGame(result.data)
    await loadInfo()
    if (isStatistics.value) {
      await loadStatistics()
    }
    if (
      changes.status &&
      selectedId.value === game.id &&
      view.value !== 'collection' &&
      view.value !== changes.status
    ) {
      await router.replace({
        path: '/' + changes.status + '/games/' + game.id,
        query: route.query,
      })
    }
    notify(updateMessage(changes))
    return true
  } catch (error) {
    replaceGame(previous)
    notify(error instanceof Error ? error.message : 'Changes could not be saved.', true)
    return false
  } finally {
    busyGame.value = null
  }
}

async function saved(game: Game) {
  const isNew = modal.value?.kind === 'metadata' && !modal.value.game
  modal.value = null
  replaceGame(game)
  await loadInfo()
  if (isStatistics.value) {
    await loadStatistics()
    if (!isNew) {
      notify('Game updated')
      return
    }
  }
  if (isNew) {
    activeGame.value = game
    await router.push('/' + game.status + '/games/' + game.id)
  } else if (
    selectedId.value === game.id &&
    view.value !== 'collection' &&
    view.value !== game.status
  ) {
    await router.replace('/' + game.status + '/games/' + game.id)
  } else {
    await loadGames(filters.value)
  }
  notify(isNew ? 'Added to ' + labels[game.status] : 'Game updated')
}

async function removeGame() {
  if (modal.value?.kind !== 'delete') {
    return
  }
  const game = modal.value.game
  deleting.value = true
  deleteError.value = ''
  try {
    await api('games/' + game.id, { method: 'DELETE' })
    modal.value = null
    if (selectedId.value === game.id) {
      await router.replace({ path: sectionPath.value, query: route.query })
    }
    await Promise.all([
      loadInfo(),
      isStatistics.value ? loadStatistics() : loadGames(filters.value),
    ])
    notify('Game removed from your collection')
  } catch (error) {
    deleteError.value = error instanceof Error ? error.message : 'The game could not be removed.'
  } finally {
    deleting.value = false
  }
}

function keyboard(event: KeyboardEvent) {
  if (event.key === 'Escape' && mobileNav.value) {
    event.preventDefault()
    mobileNav.value = false
  }
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !modal.value) {
    event.preventDefault()
    searchElement.value?.focus()
  }
}
onMounted(() => {
  narrowQuery.addEventListener('change', screenChanged)
  bootstrap()
  document.addEventListener('keydown', keyboard)
  window.addEventListener('nolife:unauthorized', unauthorized)
  poll = setInterval(async () => {
    if (!session.value?.authenticated || document.hidden || modal.value || loading.value) {
      return
    }
    if (selected.value?.images_pending) {
      try {
        await loadGame(selected.value.id)
      } catch {}
    } else if (
      isLibrary.value &&
      page.value === 1 &&
      games.value.some((game) => game.images_pending)
    ) {
      await loadGames(filters.value, false, true)
    }
  }, 5000)
})
onBeforeUnmount(() => {
  pageObserver?.disconnect()
  narrowQuery.removeEventListener('change', screenChanged)
  clearInterval(poll)
  clearTimeout(loadingTimer)
  clearTimeout(searchTimer)
  clearTimeout(toastTimer)
  document.removeEventListener('keydown', keyboard)
  window.removeEventListener('nolife:unauthorized', unauthorized)
})
</script>

<template>
  <div v-if="booting" class="boot-screen">
    <BrandLogo />
    <IconLoader2 :size="24" class="spin" aria-label="Loading NoLife" />
  </div>
  <main v-else-if="!session?.authenticated" class="login-screen">
    <section class="login-panel">
      <BrandLogo />
      <p class="eyebrow">Your personal game collection</p>
      <h1>Sign in</h1>
      <form @submit.prevent="signIn">
        <label class="field"
          >Username<input
            v-model="login.username"
            name="username"
            autocomplete="username"
            autofocus
            required
            maxlength="100"
          />
        </label>
        <label class="field"
          >Password<input
            v-model="login.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
          />
        </label>
        <p v-if="!session?.configured" class="form-error"
          >Personal access has not been configured yet.</p
        >
        <p v-if="loginError" class="form-error" role="alert">{{ loginError }}</p>
        <button
          class="button button--primary login-submit"
          :disabled="loginBusy || !session?.configured"
        >
          <IconLoader2 v-if="loginBusy" :size="18" class="spin" />
          <span>{{ loginBusy ? 'Signing in…' : 'Sign in' }}</span>
          <IconArrowRight v-if="!loginBusy" :size="19" />
        </button>
      </form>
    </section>
  </main>
  <div v-else class="app-shell">
    <div class="ambient" aria-hidden="true">
      <Transition name="ambient">
        <div
          v-if="ambient"
          :key="ambient"
          class="ambient-image"
          :style="{ backgroundImage: 'url(' + JSON.stringify(ambient) + ')' }"
        />
      </Transition>
    </div>
    <Transition name="nav-backdrop">
      <button
        v-if="mobileNav"
        class="nav-backdrop"
        aria-label="Close navigation"
        @click="mobileNav = false"
      />
    </Transition>
    <aside
      ref="sidebar"
      :inert="narrowScreen && !mobileNav"
      class="sidebar"
      :class="{ 'sidebar--open': mobileNav }"
    >
      <div class="brand">
        <button class="wordmark" aria-label="NoLife home" @click="goView('playing')">
          <BrandLogo />
        </button>
        <button
          class="icon-button mobile-close"
          aria-label="Close navigation"
          @click="mobileNav = false"
        >
          <IconX :size="21" />
        </button>
      </div>
      <nav class="primary-nav" aria-label="Collection views">
        <button
          v-for="item in statuses"
          :key="item"
          :class="['nav-item', { active: isLibrary && view === item }]"
          :aria-current="isLibrary && view === item ? 'page' : undefined"
          @click="goView(item)"
        >
          <component :is="navIcons[item]" :size="21" stroke="1.7" />
          <span>{{ labels[item] }}</span>
          <small v-if="info.counts[item]">{{ info.counts[item] }}</small>
        </button>
        <div class="nav-divider" />
        <button
          :class="['nav-item', { active: isLibrary && view === 'collection' }]"
          :aria-current="isLibrary && view === 'collection' ? 'page' : undefined"
          @click="goView('collection')"
        >
          <IconBooks :size="21" stroke="1.7" />
          <span>Collection</span>
          <small v-if="info.total">{{ info.total }}</small>
        </button>
        <button
          :class="['nav-item', { active: isStatistics }]"
          :aria-current="isStatistics ? 'page' : undefined"
          @click="goStatistics"
        >
          <IconChartBar :size="21" stroke="1.7" />
          <span>Statistics</span>
        </button>
      </nav>
      <div v-if="info.platforms.length" class="platform-nav">
        <p class="eyebrow">Platforms</p>
        <button
          :class="{ active: isLibrary && !filters.platform }"
          @click="setFilter('platform', '')"
        >
          <PlatformIcon />All platforms</button
        >
        <button
          v-for="platform in info.platforms"
          :key="platform"
          :class="{ active: isLibrary && filters.platform === platform }"
          @click="setFilter('platform', platform)"
        >
          <PlatformIcon :platform="platform" />
          <span>{{ platform }}</span>
        </button>
      </div>
      <div class="sidebar-footer">
        <span class="user-avatar">{{ session.username?.slice(0, 1).toUpperCase() }}</span>
        <span class="min-w-0 flex-1 truncate">{{ session.username }}</span>
        <div class="ml-auto flex shrink-0 items-center gap-1">
          <button
            class="icon-button ml-0! h-8! w-8!"
            aria-label="Settings"
            :aria-current="isSettings ? 'page' : undefined"
            data-tooltip="Settings"
            @click="openSettings"
          >
            <IconSettings :size="19" />
          </button>
          <button
            class="icon-button ml-0! h-8! w-8!"
            aria-label="Sign out"
            data-tooltip="Sign out"
            @click="signOut"
          >
            <IconLogout :size="19" />
          </button>
        </div>
      </div>
    </aside>
    <div class="app-main" :inert="narrowScreen && mobileNav">
      <header class="topbar">
        <button
          ref="navToggle"
          class="icon-button mobile-menu"
          aria-label="Open navigation"
          :aria-expanded="mobileNav"
          @click="mobileNav = true"
        >
          <IconMenu2 :size="23" />
        </button>
        <div class="global-search">
          <IconSearch :size="20" stroke="1.6" />
          <input
            ref="searchElement"
            v-model="searchInput"
            aria-label="Search your collection"
            type="search"
            placeholder="Search your collection…"
            @input="searchChanged"
          />
          <kbd>⌘ K</kbd>
        </div>
        <button
          class="button button--primary add-game-button"
          aria-label="Add game"
          @click="addGame"
        >
          <IconPlus :size="21" />
          <span>Add game</span>
        </button>
      </header>
      <GameDetails
        v-if="selected"
        :game="selected"
        :games="visibleGames"
        :view="view"
        :back-label="isStatistics ? 'Statistics' : undefined"
        :transition-id="transitionId"
        :busy="busyGame === selected.id"
        :save-playtime="updateGame"
        @open="openGame"
        @back="closeGame"
        @edit="modal = { kind: 'edit', game: $event }"
        @metadata="modal = { kind: 'metadata', game: $event }"
        @menu="openGameMenu"
        @status="openStatus"
        @rating="(game, rating) => updateGame(game, { rating })"
        @image="modal = { kind: 'images', index: $event }"
        @header="(game, imageId) => updateGame(game, { header_image_id: imageId })"
      />
      <div v-else-if="selectedId" class="detail-loading">
        <IconLoader2 :size="30" class="spin" />
        <span>Loading game…</span>
      </div>
      <StatisticsPage v-else-if="isStatistics" @open="openStatisticsGame" />
      <SettingsPage v-else-if="isSettings" />
      <section
        v-else
        ref="gallery"
        class="gallery-scroll"
        :aria-label="labels[view] + ' games'"
        @scroll.passive="rememberScroll"
      >
        <div class="view-header">
          <div>
            <h1>{{ labels[view] }}</h1>
            <p v-if="gameCount" class="collection-count"
              >{{ gameCount }} {{ gameCount === 1 ? 'game' : 'games'
              }}<template v-if="filters.q"> · Results for “{{ filters.q }}”</template>
            </p>
          </div>
        </div>
        <div class="collection-toolbar">
          <div class="filters">
            <div class="platform-readout" aria-label="Current platform">
              <PlatformIcon :platform="filters.platform" />
              <span>{{ filters.platform || 'All platforms' }}</span>
            </div>
            <CustomSelect
              :model-value="filters.format"
              :options="[
                { value: '', label: 'All formats' },
                { value: 'physical', label: 'Physical' },
                { value: 'digital', label: 'Digital' },
              ]"
              label="Filter by format"
              @update:model-value="setFilter('format', $event)"
            />
            <CustomSelect
              :model-value="filters.genre"
              :options="[{ value: '', label: 'All genres' }, ...info.genres]"
              label="Filter by genre"
              @update:model-value="setFilter('genre', $event)"
            />
          </div>
          <div class="view-options">
            <CustomSelect
              :model-value="filters.sort"
              :options="[
                { value: 'title', label: 'Name A–Z' },
                { value: 'recent', label: 'Recently added' },
                { value: 'rating', label: 'Highest rated' },
              ]"
              label="Sort games"
              @update:model-value="setFilter('sort', $event)"
            />
            <div class="layout-toggle" role="group" aria-label="Collection layout">
              <button
                :class="{ active: layout === 'grid' }"
                :aria-pressed="layout === 'grid'"
                aria-label="Grid view"
                @click="layout = 'grid'"
              >
                <IconLayoutGrid :size="20" />
              </button>
              <button
                :class="{ active: layout === 'list' }"
                :aria-pressed="layout === 'list'"
                aria-label="List view"
                @click="layout = 'list'"
              >
                <IconList :size="22" />
              </button>
            </div>
          </div>
        </div>
        <div v-if="libraryError && !visibleGames.length" class="library-error" role="alert">
          <p>{{ libraryError }}</p>
          <button class="button button--quiet" @click="loadGames(filters)">Try again</button>
        </div>
        <div
          v-else-if="loading && !visibleGames.length"
          class="gallery-pending"
          aria-label="Loading games"
          aria-busy="true"
        >
          <Transition name="preview">
            <IconLoader2 v-if="showLoading" class="spin" :size="24" />
          </Transition>
        </div>
        <TransitionGroup
          v-else-if="visibleGames.length"
          name="card"
          appear
          tag="div"
          :class="layout === 'list' ? 'game-list' : 'game-grid'"
        >
          <GameCard
            v-for="game in visibleGames"
            :key="game.id"
            :game="game"
            :transition-id="transitionId"
            :list="layout === 'list'"
            @open="openGame"
            @menu="openGameMenu"
            @status="openStatus"
            @ambient="hoveredGame = $event"
          />
        </TransitionGroup>
        <div v-else class="empty-state">
          <span class="empty-icon">
            <IconDeviceGamepad2 :size="46" stroke="1.15" />
          </span>
          <h2>{{ hasFilters ? 'No games found' : emptyTitle }}</h2>
          <p>{{
            hasFilters
              ? 'Try a different search or change your filters.'
              : view === 'playing' && info.total
                ? 'Change a game’s status to Playing to see it here.'
                : view === 'collection'
                  ? 'Add a game to start your collection.'
                  : 'Add a game here or update a game in your collection.'
          }}</p>
          <button v-if="!hasFilters" class="button button--primary" @click="addGame">
            <IconPlus :size="19" />Add a game</button
          >
          <button v-else class="button button--quiet" @click="router.replace('/collection')"
            >Clear filters</button
          >
        </div>
        <div
          v-if="visibleGames.length && lastPage > page"
          ref="paginationTrigger"
          class="pagination-sentinel"
          role="status"
          aria-live="polite"
          :aria-busy="loadingMore"
        >
          <template v-if="libraryError">
            <span>{{ libraryError }}</span>
            <button class="text-button" @click="loadGames(filters, true)">Try again</button>
          </template>
          <template v-else-if="loadingMore">
            <IconLoader2 class="spin" :size="22" />
            <span class="sr-only">Loading more games…</span>
          </template>
        </div>
      </section>
    </div>
    <Transition name="toast">
      <div
        v-if="toast"
        :key="toast.text"
        :class="['toast-message', { error: toast.error }]"
        role="status"
      >
        <IconCircleX v-if="toast.error" :size="20" />
        <IconCheck v-else :size="20" />
        <span>{{ toast.text }}</span>
        <button class="icon-button" aria-label="Dismiss notification" @click="toast = null">
          <IconX :size="16" />
        </button>
      </div>
    </Transition>
    <MetadataDialog
      v-if="modal?.kind === 'metadata'"
      :target="modal.game"
      :enabled="session.igdb_enabled"
      :initial-status="modal.initialStatus"
      :platforms="info.platforms"
      @close="modal = null"
      @saved="saved"
    />
    <DialogShell
      ref="dialog"
      v-else-if="modal?.kind === 'edit'"
      title="Edit game"
      size="medium"
      @close="modal = null"
    >
      <GameEditor
        :game="modal.game"
        :platforms="info.platforms"
        @cancel="dialog?.close()"
        @saved="saved"
      />
    </DialogShell>
    <DialogShell
      ref="dialog"
      v-else-if="modal?.kind === 'delete'"
      title="Remove game?"
      size="small"
      @close="modal = null"
    >
      <div class="confirm-body">
        <p
          >Remove <strong>{{ modal.game.title }}</strong> from your collection?</p
        >
        <p class="muted">Its rating, play status and saved game data will be removed.</p>
        <p v-if="deleteError" class="form-error" role="alert">{{ deleteError }}</p>
      </div>
      <footer class="dialog-footer">
        <button class="button button--quiet" :disabled="deleting" @click="dialog?.close()"
          >Cancel</button
        >
        <button class="button button--danger" :disabled="deleting" @click="removeGame">
          <IconLoader2 v-if="deleting" class="spin" :size="18" />{{
            deleting ? 'Removing…' : 'Remove game'
          }}</button
        >
      </footer>
    </DialogShell>
    <ImageViewer
      v-else-if="modal?.kind === 'images' && selected"
      :title="selected.title"
      :images="lightboxImages"
      :initial="modal.index"
      @close="modal = null"
    />
    <ActionMenu v-if="menu" :anchor="menu.anchor" :items="menu.items" @close="menu = null" />
  </div>
</template>
