import { ref, shallowRef } from 'vue'
import { api } from './api'
import type { Statistics } from './types'

export const statistics = shallowRef<Statistics | null>(null)
export const statisticsLoading = ref(false)
export const statisticsError = ref('')
export const statisticsScroll = ref(0)
let controller: AbortController | null = null

export async function loadStatistics(): Promise<void> {
  controller?.abort()
  const request = new AbortController()
  controller = request
  statisticsLoading.value = true
  statisticsError.value = ''
  try {
    const result = await api<Statistics>('statistics', { signal: request.signal })
    if (!request.signal.aborted) {
      statistics.value = result
    }
  } catch (error) {
    if (!request.signal.aborted) {
      statisticsError.value =
        error instanceof Error ? error.message : 'Statistics could not be loaded.'
    }
  } finally {
    if (!request.signal.aborted) {
      statisticsLoading.value = false
    }
  }
}

export function clearStatistics(): void {
  controller?.abort()
  controller = null
  statistics.value = null
  statisticsLoading.value = false
  statisticsError.value = ''
  statisticsScroll.value = 0
}
