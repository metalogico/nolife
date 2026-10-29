<script setup lang="ts">
import { ref, computed, nextTick, onBeforeUnmount } from 'vue'
import {
  IconSearch,
  IconLoader2,
  IconDeviceGamepad2,
  IconCheck,
  IconArrowLeft,
} from '@tabler/icons-vue'
import DialogShell from './DialogShell.vue'
import GameEditor from './GameEditor.vue'
import CustomSelect from './CustomSelect.vue'
import { api } from '../api'
import type { Game, IgdbGame, GameStatus } from '../types'
const props = withDefaults(
  defineProps<{
    target?: Game | null
    enabled: boolean
    initialStatus?: GameStatus
    platforms?: string[]
  }>(),
  { platforms: () => [] },
)
const emit = defineEmits<{ close: []; saved: [Game] }>()
const shell = ref<InstanceType<typeof DialogShell>>()
const manual = ref(false)
const query = ref(props.target?.title || '')
const by = ref('title')
const searched = ref(false)
const searching = ref(false)
const saving = ref(false)
const error = ref('')
const results = ref<IgdbGame[]>([])
const selected = ref<IgdbGame | null>(null)
const preview = ref<HTMLElement>()
const platform = ref('PS5')
const format = ref('physical')
const title = computed(() =>
  manual.value
    ? props.target
      ? 'Edit game'
      : 'Add a game'
    : props.target
      ? 'Find a match'
      : 'Add a game',
)
let controller: AbortController | null = null
function choose(game: IgdbGame) {
  selected.value = game
  if (!props.target && game.platforms.length) {
    const preferred =
      game.platforms.find((item) => /PlayStation 5|PS5/i.test(item.name + item.label)) ||
      game.platforms[0]
    platform.value = preferred?.label || preferred?.name || 'PS5'
  }
  void nextTick(() => {
    if (preview.value) {
      preview.value.scrollTop = 0
    }
    if (matchMedia('(max-width: 640px)').matches) {
      preview.value?.scrollIntoView({
        block: 'nearest',
        behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
      })
    }
  })
}

async function search() {
  if (!query.value.trim()) {
    return
  }
  controller?.abort()
  controller = new AbortController()
  searching.value = true
  searched.value = true
  error.value = ''
  selected.value = null
  try {
    const response = await api<{ data: IgdbGame[] }>(
      'igdb/search?' + new URLSearchParams({ query: query.value.trim(), by: by.value }),
      { signal: controller.signal },
    )
    results.value = response.data
    if (results.value.length === 1) {
      choose(results.value[0]!)
    }
  } catch (failure) {
    if (!controller.signal.aborted) {
      error.value = failure instanceof Error ? failure.message : 'Search could not be completed.'
      results.value = []
    }
  } finally {
    searching.value = false
  }
}

async function apply() {
  if (!selected.value || saving.value) {
    return
  }
  saving.value = true
  error.value = ''
  try {
    const path = props.target ? 'games/' + props.target.id + '/metadata' : 'games'
    const payload = props.target
      ? { igdb_id: selected.value.id }
      : {
          title: selected.value.title,
          igdb_id: selected.value.id,
          platform: platform.value,
          format: format.value,
          status: props.initialStatus || 'planned',
        }
    const result = await api<{ data: Game }>(path, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    emit('saved', result.data)
  } catch (failure) {
    error.value = failure instanceof Error ? failure.message : 'The game could not be saved.'
  } finally {
    saving.value = false
  }
}
onBeforeUnmount(() => controller?.abort())
</script>

<template>
  <DialogShell
    ref="shell"
    :title="title"
    :description="manual ? undefined : 'Search IGDB and preview the game before importing.'"
    size="wide"
    @close="emit('close')"
  >
    <GameEditor
      v-if="manual"
      :game="target"
      :initial-title="query"
      :initial-status="initialStatus"
      :platforms="platforms"
      @saved="emit('saved', $event)"
      @cancel="shell?.close()"
    />
    <template v-else>
      <div class="match-body">
        <span class="provider-label">IGDB</span>
        <form class="match-search" @submit.prevent="search">
          <div class="search-field">
            <IconSearch :size="19" />
            <input
              v-model="query"
              autofocus
              :placeholder="by === 'id' ? 'IGDB game ID' : 'Search for a game'"
              :inputmode="by === 'id' ? 'numeric' : 'text'"
              aria-label="Search IGDB"
              autocomplete="off"
            />
          </div>
          <CustomSelect
            v-model="by"
            :options="[
              { value: 'title', label: 'By title' },
              { value: 'id', label: 'IGDB ID' },
            ]"
            label="Search by"
          />
          <button class="button button--primary" :disabled="searching || !query.trim() || !enabled">
            <IconLoader2 v-if="searching" class="spin" :size="18" />
            <IconSearch v-else :size="18" />{{ searching ? 'Searching…' : 'Search' }}</button
          >
        </form>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <div v-if="!enabled" class="match-empty">
          <IconDeviceGamepad2 :size="38" stroke="1.25" />
          <h3>IGDB is not connected</h3>
          <p>You can enter the game manually and match it later.</p>
        </div>
        <div v-else-if="!searched" class="match-empty">
          <IconSearch :size="38" stroke="1.25" />
          <h3>Find your game</h3>
          <p>Search by title or enter an IGDB ID to find the right match.</p>
        </div>
        <div
          v-else-if="searching"
          class="match-columns"
          aria-label="Searching IGDB"
          aria-busy="true"
        >
          <div class="match-results">
            <div v-for="n in 4" :key="n" class="match-result skeleton-row" />
          </div>
          <div class="match-preview skeleton-preview" />
        </div>
        <div v-else-if="!results.length && !error" class="match-empty">
          <IconSearch :size="38" stroke="1.25" />
          <h3>No matches found</h3>
          <p>Try a different title or enter the game manually.</p>
        </div>
        <div v-else-if="results.length" class="match-columns">
          <div class="match-results">
            <p class="results-count" aria-live="polite"
              >{{ results.length }} {{ results.length === 1 ? 'match' : 'matches' }}</p
            >
            <button
              v-for="game in results"
              :key="game.id"
              class="match-result"
              :class="{ selected: selected?.id === game.id }"
              :aria-pressed="selected?.id === game.id"
              @click="choose(game)"
            >
              <img
                v-if="game.cover_url"
                :src="game.cover_url"
                :alt="game.title + ' cover'"
                loading="lazy"
              />
              <span v-else class="match-cover-placeholder">
                <IconDeviceGamepad2 :size="24" />
              </span>
              <span>
                <strong>{{ game.title }}</strong>
                <small
                  >{{ game.release_year || 'Year unknown'
                  }}<template v-if="game.platforms.length === 1">
                    · {{ game.platforms[0]?.label }}</template
                  >
                </small>
              </span>
            </button>
          </div>
          <section ref="preview" class="match-preview" aria-label="Selected game preview">
            <Transition name="preview" mode="out-in">
              <div v-if="selected" :key="selected.id" class="match-preview-content">
                <div class="match-preview-top">
                  <div class="preview-cover">
                    <img
                      v-if="selected.cover_url"
                      :src="selected.cover_url"
                      :alt="selected.title + ' selected cover'"
                    />
                    <span v-else class="cover-placeholder">
                      <IconDeviceGamepad2 :size="38" />
                    </span>
                    <span class="cover-selected">
                      <IconCheck :size="17" />
                    </span>
                  </div>
                  <div>
                    <h3>{{ selected.title }}</h3>
                    <p class="preview-meta"
                      >{{ selected.release_year || 'Year unknown'
                      }}<template v-if="selected.platforms.length">
                        · {{ selected.platforms.map((item) => item.label).join(', ') }}</template
                      >
                    </p>
                    <p class="preview-summary">{{
                      selected.summary || 'No description is available for this game.'
                    }}</p>
                  </div>
                </div>
                <div v-if="!target" class="ownership-fields">
                  <label class="field"
                    >Your platform<CustomSelect
                      v-model="platform"
                      :options="[...platforms, ...selected.platforms.map((p) => p.label)]"
                      label="Your platform"
                      editable
                      required
                      placeholder="Select or enter a platform"
                    />
                  </label>
                  <label class="field"
                    >Format<CustomSelect
                      v-model="format"
                      :options="[
                        { value: 'physical', label: 'Physical' },
                        { value: 'digital', label: 'Digital' },
                      ]"
                      label="Format"
                    />
                  </label>
                </div>
              </div>
              <div v-else key="empty" class="match-preview-empty">
                <IconArrowLeft :size="30" stroke="1.25" />
                <p>Choose a game to see its cover and details.</p>
              </div>
            </Transition>
          </section>
        </div>
      </div>
      <footer class="dialog-footer match-footer">
        <button type="button" class="text-button" :disabled="saving" @click="manual = true"
          >Enter details manually</button
        >
        <div>
          <button
            type="button"
            class="button button--quiet"
            :disabled="saving"
            @click="shell?.close()"
            >Cancel</button
          >
          <button
            class="button button--primary"
            :disabled="!selected || saving || (!target && !platform.trim())"
            @click="apply"
          >
            <IconLoader2 v-if="saving" class="spin" :size="18" />{{
              saving ? 'Saving…' : target ? 'Apply metadata' : 'Add game'
            }}</button
          >
        </div>
      </footer>
    </template>
  </DialogShell>
</template>
