<script setup lang="ts">
import { reactive, ref } from 'vue'
import { IconLoader2, IconUpload, IconX } from '@tabler/icons-vue'
import { api } from '../api'
import CustomSelect from './CustomSelect.vue'
import type { Game, GameStatus, GameFormat } from '../types'
import { labels, statuses } from '../types'
const props = withDefaults(
  defineProps<{
    game?: Game | null
    initialTitle?: string
    initialStatus?: GameStatus
    platforms?: string[]
  }>(),
  { platforms: () => [] },
)
const emit = defineEmits<{ saved: [Game]; cancel: [] }>()
const statusOptions = statuses.map((status) => ({ value: status, label: labels[status] }))
const form = reactive({
  title: props.game?.title || props.initialTitle || '',
  platform: props.game?.platform || 'PS5',
  format: props.game?.format || ('physical' as GameFormat),
  status: props.game?.status || props.initialStatus || ('planned' as GameStatus),
  rating: props.game?.rating ?? '',
  release_year: props.game?.release_year ?? '',
  summary: props.game?.summary || '',
})
const cover = ref<File | null>(null)
const busy = ref(false)
const error = ref('')
let savedId = props.game?.id
function pickCover(event: Event) {
  cover.value = (event.target as HTMLInputElement).files?.[0] || null
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const payload = {
      title: form.title,
      platform: form.platform,
      format: form.format,
      status: form.status,
      rating: form.rating === '' ? null : Number(form.rating),
      release_year: form.release_year === '' ? null : Number(form.release_year),
      summary: form.summary || null,
    }
    let result = await api<{ data: Game }>('games' + (savedId ? '/' + savedId : ''), {
      method: savedId ? 'PATCH' : 'POST',
      body: JSON.stringify(payload),
    })
    savedId = result.data.id
    if (cover.value) {
      const upload = new FormData()
      upload.append('cover', cover.value)
      result = await api<{ data: Game }>('games/' + savedId + '/cover', {
        method: 'POST',
        body: upload,
      })
    }
    emit('saved', result.data)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'The game could not be saved.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="editor-form" @submit.prevent="save">
    <div class="editor-body">
      <label class="field field--full"
        >Title<input
          v-model="form.title"
          autofocus
          required
          maxlength="255"
          autocomplete="off"
          placeholder="Game title"
        />
      </label>
      <label class="field"
        >Platform<CustomSelect
          v-model="form.platform"
          :options="platforms"
          label="Platform"
          editable
          required
          placeholder="Select or enter a platform"
        />
      </label>
      <label class="field"
        >Format<CustomSelect
          v-model="form.format"
          :options="[
            { value: 'physical', label: 'Physical' },
            { value: 'digital', label: 'Digital' },
          ]"
          label="Format"
        />
      </label>
      <label class="field"
        >Play status<CustomSelect
          v-model="form.status"
          :options="statusOptions"
          label="Play status"
        />
      </label>
      <label class="field"
        >Your rating<input
          v-model="form.rating"
          type="number"
          min="1"
          max="10"
          step="1"
          placeholder="Not rated"
        />
        <span class="field-hint">Optional · 1–10</span>
      </label>
      <label class="field"
        >Release year<input
          v-model="form.release_year"
          type="number"
          min="1950"
          max="2200"
          placeholder="Year"
        />
        <span class="field-hint">Optional</span>
      </label>
      <label class="field field--full"
        >Description<textarea
          v-model="form.summary"
          rows="3"
          maxlength="10000"
          placeholder="A short description of the game"
        />
      </label>
      <div class="field field--full"
        >Cover image<span class="field-hint">JPG, PNG or WebP · up to 8 MB</span>
        <label class="upload-control">
          <IconUpload :size="18" />
          <span>{{ cover?.name || 'Choose cover image' }}</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" @change="pickCover" />
        </label>
        <button v-if="cover" type="button" class="text-button" @click="cover = null">
          <IconX :size="14" />Clear selection</button
        >
      </div>
      <p v-if="error" class="form-error field--full" role="alert">{{ error }}</p>
    </div>
    <footer class="dialog-footer">
      <button type="button" class="button button--quiet" :disabled="busy" @click="emit('cancel')"
        >Cancel</button
      >
      <button class="button button--primary" :disabled="busy">
        <IconLoader2 v-if="busy" class="spin" :size="18" />{{
          busy ? 'Saving…' : game ? 'Save changes' : 'Add game'
        }}</button
      >
    </footer>
  </form>
</template>
