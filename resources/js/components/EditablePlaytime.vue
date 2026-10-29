<script setup lang="ts">
import { ref, watch } from 'vue'
import type { Component } from 'vue'
import { IconCheck, IconLoader2, IconPencil, IconX } from '@tabler/icons-vue'
import { formatDuration } from '../types'

const props = defineProps<{
  gameId: number
  label: string
  minutes: number | null
  icon: Component
  disabled?: boolean
  save: (minutes: number | null) => Promise<boolean>
}>()
const editing = ref(false)
const saving = ref(false)
const draft = ref<string | number>('')
const error = ref('')
const input = ref<HTMLInputElement>()
const trigger = ref<HTMLButtonElement>()
let restoreFocus = false

function focusControl() {
  if (!restoreFocus) {
    return
  }
  restoreFocus = false
  if (editing.value) {
    input.value?.focus()
    input.value?.select()
  } else {
    trigger.value?.focus({ preventScroll: true })
  }
}

function edit() {
  draft.value = props.minutes === null ? '' : String(props.minutes / 60)
  error.value = ''
  restoreFocus = true
  editing.value = true
}

function cancel() {
  if (saving.value) {
    return
  }
  error.value = ''
  restoreFocus = true
  editing.value = false
}

async function save() {
  if (saving.value || props.disabled) {
    return
  }
  const value = String(draft.value).trim()
  const hours = value === '' ? null : Number(value)
  if (hours !== null && (!Number.isFinite(hours) || hours < 0 || hours > 100000)) {
    error.value = 'Enter a value between 0 and 100,000 hours.'
    return
  }
  const gameId = props.gameId
  saving.value = true
  error.value = ''
  try {
    const saved = await props.save(hours === null ? null : Math.round(hours * 60))
    if (gameId !== props.gameId) {
      return
    }
    if (saved) {
      restoreFocus = true
      editing.value = false
    } else {
      error.value = 'Could not save. Please try again.'
    }
  } catch {
    if (gameId === props.gameId) {
      error.value = 'Could not save. Please try again.'
    }
  } finally {
    saving.value = false
  }
}
watch(
  () => props.gameId,
  () => {
    editing.value = false
    error.value = ''
    restoreFocus = false
  },
)
</script>

<template>
  <div class="duration-readout">
    <span class="control-label">{{ label }}</span>
    <Transition name="inline-editor" mode="out-in" @after-enter="focusControl">
      <form
        v-if="editing"
        class="playtime-editor"
        @submit.prevent="save"
        @keydown.esc.stop.prevent="cancel"
      >
        <div class="playtime-editor-controls">
          <div class="hours-input">
            <input
              ref="input"
              v-model="draft"
              :aria-label="label + ' in hours'"
              type="number"
              min="0"
              max="100000"
              step="any"
              placeholder="Not set"
              :disabled="saving"
            />
            <span aria-hidden="true">h</span>
          </div>
          <button
            class="icon-button playtime-save"
            type="submit"
            :aria-label="'Save ' + label.toLowerCase()"
            :disabled="saving || disabled"
          >
            <IconLoader2 v-if="saving" class="spin" :size="17" />
            <IconCheck v-else :size="17" />
          </button>
          <button
            class="icon-button"
            type="button"
            :aria-label="'Cancel ' + label.toLowerCase() + ' edit'"
            :disabled="saving"
            @click="cancel"
          >
            <IconX :size="17" />
          </button>
        </div>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      </form>
      <button
        v-else
        ref="trigger"
        class="playtime-value"
        type="button"
        :aria-label="'Edit ' + label.toLowerCase()"
        :disabled="disabled"
        @click="edit"
      >
        <component :is="icon" :size="18" />
        <span>{{ formatDuration(minutes) }}</span>
        <IconPencil :size="14" class="playtime-pencil" />
      </button>
    </Transition>
  </div>
</template>
