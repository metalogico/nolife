export type GameStatus = 'playing' | 'planned' | 'completed' | 'tried' | 'dropped'
export type GameFormat = 'physical' | 'digital'
export type LibraryView = GameStatus | 'collection'
export interface GameImage {
  id: number
  kind: 'cover' | 'artwork' | 'screenshot'
  url: string | null
  thumbnail_url: string | null
}
export interface Game {
  id: number
  title: string
  platform: string
  format: GameFormat
  status: GameStatus
  rating: number | null
  duration_minutes: number | null
  total_played_minutes: number | null
  summary: string | null
  release_year: number | null
  developer: string | null
  publisher: string | null
  genres: string[]
  igdb_id: number | null
  igdb_url: string | null
  metadata_fetched_at: string | null
  cover_url: string | null
  thumbnail_url: string | null
  background_url: string | null
  header_image_id: number | null
  ambient_url: string | null
  images_pending: boolean
  images: GameImage[]
}
export interface IgdbGame {
  id: number
  title: string
  summary: string
  release_year: number | null
  developer: string | null
  publisher: string | null
  url: string | null
  cover_url: string | null
  genres: string[]
  platforms: { id: number; name: string; label: string }[]
  images: { kind: string; source_id: string; url: string }[]
}
export interface Session {
  authenticated: boolean
  configured: boolean
  username: string | null
  igdb_enabled: boolean
  csrf_token: string
}
export interface LibraryInfo {
  counts: Partial<Record<GameStatus, number>>
  total: number
  platforms: string[]
  genres: string[]
}
export interface Filters {
  view: LibraryView
  q: string
  platform: string
  format: string
  genre: string
  sort: string
}
export interface Page<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}
export const statuses: GameStatus[] = ['playing', 'planned', 'completed', 'tried', 'dropped']
export const labels: Record<LibraryView, string> = {
  playing: 'Playing',
  planned: 'Planned',
  completed: 'Completed',
  tried: 'Tried',
  dropped: 'Dropped',
  collection: 'Collection',
}
export const platformOptions = [
  'PS5',
  'PS4',
  'PS3',
  'PS2',
  'PlayStation',
  'PSP',
  'PS Vita',
  'Nintendo Switch',
  'Nintendo 3DS',
  'Nintendo DS',
  'Wii',
  'Wii U',
  'GameCube',
  'Game Boy Advance',
  'Game Boy Color',
  'Game Boy',
  'SNES',
  'NES',
  'Xbox Series X|S',
  'Xbox One',
  'Xbox 360',
  'Xbox',
  'PC',
]
export function formatDuration(minutes: number | null): string {
  if (minutes === null) {
    return '—'
  }
  return new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(minutes / 60) + ' h'
}

export type McpAccessLevel = 'read' | 'write'
export interface McpSettingsState {
  enabled: boolean
  url: string
  keys: Record<McpAccessLevel, { configured: boolean; generated_at: string | null }>
}
export interface McpGeneratedKey {
  access: McpAccessLevel
  token: string
  settings: McpSettingsState
}
