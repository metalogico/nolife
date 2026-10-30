import { ref } from 'vue'
import { api } from './api'
import type { Game, LibraryInfo, Filters, Page } from './types'

export const games = ref<Game[]>([])
export const activeGame = ref<Game | null>(null)
export const info = ref<LibraryInfo>({ counts: {}, total: 0, platforms: [], genres: [] })
export const loading = ref(false)
export const loadingMore = ref(false)
export const libraryError = ref('')
export const page = ref(1)
export const lastPage = ref(1)
export const libraryRevision = ref(0)
let collectionController: AbortController | null = null
const queryCache = new Map<
  string,
  { data: Game[]; page: number; lastPage: number; cachedAt: number }
>()
export async function loadInfo(): Promise<void> {
  queryCache.clear()
  info.value = await api<LibraryInfo>('library')
}

export function filterQuery(filters: Filters, nextPage = 1): string {
  const query = new URLSearchParams({ page: String(nextPage), sort: filters.sort })
  if (filters.view !== 'collection') {
    query.set('status', filters.view)
  }
  if (filters.q) {
    query.set('q', filters.q)
  }
  if (filters.platform) {
    query.set('platform', filters.platform)
  }
  if (filters.format) {
    query.set('format', filters.format)
  }
  if (filters.genre) {
    query.set('genre', filters.genre)
  }
  return query.toString()
}

export async function loadGames(filters: Filters, append = false, silent = false): Promise<void> {
  if (append && (loading.value || page.value >= lastPage.value)) {
    return
  }
  collectionController?.abort()
  const requestController = new AbortController()
  collectionController = requestController
  const key = filterQuery(filters)
  const cached = queryCache.get(key)
  if (!append && !silent && cached && Date.now() - cached.cachedAt < 15000) {
    games.value = cached.data
    page.value = cached.page
    lastPage.value = cached.lastPage
    loading.value = false
    loadingMore.value = false
    libraryError.value = ''
    return
  }
  if (!silent) {
    loading.value = true
    loadingMore.value = append
    libraryError.value = ''
    if (!append) {
      games.value = []
    }
  }
  try {
    const result = await api<Page<Game>>(
      'games?' + filterQuery(filters, append ? page.value + 1 : 1),
      { signal: requestController.signal },
    )
    if (requestController.signal.aborted) {
      return
    }
    const existing = new Set(games.value.map((game) => game.id))
    games.value = append
      ? [...games.value, ...result.data.filter((game) => !existing.has(game.id))]
      : result.data
    page.value = result.meta.current_page
    lastPage.value = result.meta.last_page
    queryCache.set(key, {
      data: games.value,
      page: page.value,
      lastPage: lastPage.value,
      cachedAt: Date.now(),
    })
  } catch (error) {
    if (!requestController.signal.aborted && !silent) {
      libraryError.value =
        error instanceof Error ? error.message : 'The collection could not be loaded.'
    }
  } finally {
    if (!requestController.signal.aborted && !silent) {
      loadingMore.value = false
      loading.value = false
    }
  }
}
let latestGameRequest = 0
export function resetLibrary(): void {
  collectionController?.abort()
  latestGameRequest++
  queryCache.clear()
  games.value = []
  activeGame.value = null
  loading.value = false
  loadingMore.value = false
  libraryError.value = ''
  page.value = 1
  lastPage.value = 1
  libraryRevision.value++
}

export async function loadGame(id: number): Promise<Game> {
  const requestId = ++latestGameRequest
  const result = await api<{ data: Game }>('games/' + id)
  // A slow response must not replace a game selected by a newer request.
  if (requestId === latestGameRequest) {
    activeGame.value = result.data
    replaceGame(result.data)
  }
  return result.data
}

export function replaceGame(game: Game): void {
  const index = games.value.findIndex((item) => item.id === game.id)
  if (index >= 0) {
    games.value[index] = game
  }
  if (activeGame.value?.id === game.id) {
    activeGame.value = game
  }
}
