<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue'
import { IconBooks, IconDownload, IconLoader2, IconUpload } from '@tabler/icons-vue'
import { api } from '../api'
import { resetLibrary } from '../library'
import { clearStatistics } from '../statistics'

const picker = ref<HTMLInputElement>()
const confirmButton = ref<HTMLButtonElement>()
const importButton = ref<HTMLButtonElement>()
const selected = ref<File | null>(null)
const busy = ref('')
const error = ref('')
const notice = ref('')
let active = true

async function exportLibrary(): Promise<void> {
  if (busy.value) return
  busy.value = 'export'
  error.value = ''
  notice.value = ''
  try {
    const result = await api<{ download_url: string }>('settings/library/export', { method: 'POST' })
    const link = document.createElement('a')
    link.href = result.download_url
    link.download = ''
    document.body.append(link)
    link.click()
    link.remove()
    if (active) {
      notice.value = 'Your library archive is ready. The download has started.'
    }
  } catch (failure) {
    if (active) {
      error.value = failure instanceof Error ? failure.message : 'The library could not be exported.'
    }
  } finally {
    if (active) busy.value = ''
  }
}

async function chooseArchive(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  error.value = ''
  notice.value = ''
  if (!file) return
  if (!file.name.toLowerCase().endsWith('.zip') || file.size > 1073741824) {
    error.value = 'Choose a NoLife ZIP archive up to 1 GB.'
    return
  }
  selected.value = file
  await nextTick()
  confirmButton.value?.focus()
}

async function cancelImport(): Promise<void> {
  selected.value = null
  await nextTick()
  importButton.value?.focus()
}

async function importLibrary(): Promise<void> {
  if (!selected.value || busy.value) return
  busy.value = 'import'
  error.value = ''
  notice.value = ''
  const data = new FormData()
  data.append('archive', selected.value)
  data.append('confirm', '1')
  try {
    const result = await api<{ games: number; images: number }>('settings/library/import', {
      method: 'POST',
      body: data,
    })
    clearStatistics()
    resetLibrary()
    if (active) {
      notice.value =
        `Imported ${result.games} ${result.games === 1 ? 'game' : 'games'} and ` +
        `${result.images} ${result.images === 1 ? 'image' : 'images'}.`
      busy.value = ''
      await cancelImport()
    }
  } catch (failure) {
    if (active) {
      error.value = failure instanceof Error ? failure.message : 'The library could not be imported.'
    }
  } finally {
    if (active) busy.value = ''
  }
}

onBeforeUnmount(() => {
  active = false
})
</script>

<template>
  <section
    class="library-transfer"
    aria-labelledby="library-transfer-heading"
    :aria-busy="Boolean(busy)"
  >
    <div class="mb-3 flex items-center gap-2">
      <IconBooks :size="20" class="text-accent" />
      <h2 id="library-transfer-heading" class="text-base font-semibold">Library</h2>
    </div>
    <p class="text-sm leading-relaxed text-[#a5b197]">
      Transfer your entire library between NoLife installations in one ZIP file, including game
      data and images.
    </p>
    <p class="mt-2! text-xs leading-relaxed text-[#86977a]"
      >Your account settings and MCP keys stay on this installation. ZIP files up to 1 GB.</p
    >
    <div class="mt-5 flex flex-wrap gap-3">
      <button class="button button--quiet" :disabled="Boolean(busy)" @click="exportLibrary">
        <IconLoader2 v-if="busy === 'export'" :size="18" class="spin" />
        <IconDownload v-else :size="18" />{{ busy === 'export' ? 'Preparing export…' : 'Export' }}
      </button>
      <button
        ref="importButton"
        class="button button--quiet"
        :disabled="Boolean(busy) || Boolean(selected)"
        @click="picker?.click()"
      >
        <IconUpload :size="18" />Import
      </button>
      <input
        ref="picker"
        type="file"
        accept=".zip,application/zip"
        class="hidden"
        aria-label="Choose a library archive"
        @change="chooseArchive"
      />
    </div>
    <div
      v-if="selected"
      class="mt-5 rounded-lg border border-[#d6bb6a]/30 bg-[#d6bb6a]/5 p-4"
      role="alert"
    >
      <h3 class="mb-2 text-sm font-semibold">Replace your entire library?</h3>
      <p class="text-sm leading-relaxed text-[#c5c5b4]">
        Importing <strong class="break-all">{{ selected.name }}</strong> replaces all current games
        and images. This cannot be undone. Export your current library first if you want to keep a
        copy.
      </p>
      <div class="mt-4 flex flex-wrap gap-3">
        <button
          ref="confirmButton"
          class="button button--danger"
          :disabled="Boolean(busy)"
          @click="importLibrary"
        >
          <IconLoader2 v-if="busy === 'import'" :size="18" class="spin" />{{
            busy === 'import' ? 'Importing…' : 'Replace library'
          }}
        </button>
        <button class="button button--quiet" :disabled="Boolean(busy)" @click="cancelImport"
          >Cancel</button
        >
      </div>
    </div>
    <p v-if="notice" class="mt-4! text-sm text-[#c6e9a6]" role="status">{{ notice }}</p>
    <p v-if="error" class="form-error mt-4!" role="alert">{{ error }}</p>
  </section>
</template>
