<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  IconCheck,
  IconCopy,
  IconKey,
  IconLoader2,
  IconPlugConnected,
  IconRefresh,
  IconTrash,
} from '@tabler/icons-vue'
import { api } from '../api'
import type { McpAccessLevel, McpSettingsState, McpGeneratedKey } from '../types'

const settings = ref<McpSettingsState | null>(null)
const loading = ref(true)
const busy = ref('')
const error = ref('')
const notice = ref('')
const generated = ref<McpGeneratedKey | null>(null)
const pending = ref<{ access: McpAccessLevel; operation: 'regenerate' | 'revoke' } | null>(null)
const keyInput = ref<HTMLTextAreaElement>()
const urlInput = ref<HTMLInputElement>()
const keyPanel = ref<HTMLElement>()
const root = ref<HTMLElement>()
const confirmButton = ref<HTMLButtonElement>()
let active = true
const levels: { access: McpAccessLevel; title: string; description: string }[] = [
  {
    access: 'read',
    title: 'Read only',
    description: 'Search your library, read game details and build rankings.',
  },
  {
    access: 'write',
    title: 'Read & write',
    description: 'Everything in read only, plus adding and updating games.',
  },
]

function accessLabel(access: McpAccessLevel): string {
  return access === 'read' ? 'Read-only' : 'Read & write'
}

function generatedDate(value: string | null): string {
  if (!value) return 'Key available'
  return (
    'Generated ' + new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(new Date(value))
  )
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await api<McpSettingsState>('settings/mcp')
    if (active) settings.value = result
  } catch (failure) {
    if (active)
      error.value = failure instanceof Error ? failure.message : 'Settings could not be loaded.'
  } finally {
    if (active) loading.value = false
  }
}

async function toggleEnabled(): Promise<void> {
  if (!settings.value || busy.value) return
  busy.value = 'toggle'
  error.value = ''
  notice.value = ''
  try {
    const result = await api<McpSettingsState>('settings/mcp', {
      method: 'PATCH',
      body: JSON.stringify({ enabled: !settings.value.enabled }),
    })
    if (active) {
      settings.value = result
      notice.value = result.enabled ? 'MCP enabled.' : 'MCP disabled. Your keys are kept for later.'
    }
  } catch (failure) {
    if (active)
      error.value = failure instanceof Error ? failure.message : 'The change could not be saved.'
  } finally {
    if (active) busy.value = ''
  }
}

async function generate(access: McpAccessLevel): Promise<void> {
  if (busy.value) return
  busy.value = access
  pending.value = null
  error.value = ''
  notice.value = ''
  try {
    const result = await api<McpGeneratedKey>('settings/mcp/tokens/' + access, { method: 'POST' })
    if (!active) return
    settings.value = result.settings
    generated.value = result
    await nextTick()
    keyPanel.value?.scrollIntoView({ block: 'nearest' })
    keyInput.value?.focus()
    keyInput.value?.select()
  } catch (failure) {
    if (active)
      error.value = failure instanceof Error ? failure.message : 'The key could not be generated.'
  } finally {
    if (active) busy.value = ''
  }
}

async function requestAction(
  access: McpAccessLevel,
  operation: 'regenerate' | 'revoke',
): Promise<void> {
  if (busy.value || generated.value) return
  notice.value = ''
  error.value = ''
  if (operation === 'regenerate' && !settings.value?.keys[access].configured) {
    await generate(access)
    return
  }
  pending.value = { access, operation }
  await nextTick()
  confirmButton.value?.focus()
}

async function restoreKeyFocus(access: McpAccessLevel): Promise<void> {
  await nextTick()
  root.value
    ?.querySelector<HTMLButtonElement>(`[data-key-access="${access}"]`)
    ?.focus({ preventScroll: true })
}

async function cancelAction(): Promise<void> {
  const access = pending.value?.access
  pending.value = null
  if (access) await restoreKeyFocus(access)
}

async function confirmAction(): Promise<void> {
  const action = pending.value
  if (!action || busy.value) return
  if (action.operation === 'regenerate') {
    await generate(action.access)
    return
  }
  busy.value = action.access
  error.value = ''
  try {
    const result = await api<McpSettingsState>('settings/mcp/tokens/' + action.access, {
      method: 'DELETE',
    })
    if (active) {
      settings.value = result
      pending.value = null
      notice.value = accessLabel(action.access) + ' key revoked.'
    }
  } catch (failure) {
    if (active)
      error.value = failure instanceof Error ? failure.message : 'The key could not be revoked.'
  } finally {
    if (active) busy.value = ''
  }
  if (active && !pending.value) await restoreKeyFocus(action.access)
}

async function copy(kind: 'url' | 'key'): Promise<void> {
  const value = kind === 'url' ? settings.value?.url : generated.value?.token
  if (!value) return
  try {
    if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable')
    await navigator.clipboard.writeText(value)
    notice.value = kind === 'url' ? 'Server URL copied.' : 'Key copied. Save it in your agent.'
  } catch {
    const input = kind === 'url' ? urlInput.value : keyInput.value
    input?.focus()
    input?.select()
    notice.value = 'Text selected. Press Ctrl+C or ⌘C to copy.'
  }
}

async function dismissKey(): Promise<void> {
  const access = generated.value?.access
  generated.value = null
  notice.value = ''
  if (access) await restoreKeyFocus(access)
}

onMounted(load)
onBeforeUnmount(() => {
  active = false
  generated.value = null
})
</script>

<template>
  <div ref="root" :aria-busy="Boolean(busy) || loading">
    <div v-if="loading" class="flex items-center gap-3 py-8 text-sm text-[#a5b197]" role="status">
      <IconLoader2 class="spin" :size="20" /> Loading settings…
    </div>
    <section v-else-if="settings" class="space-y-5" aria-labelledby="mcp-settings-heading">
      <div
        class="flex items-start justify-between gap-5 rounded-lg border border-white/10 bg-white/[.025] p-4"
      >
        <div class="min-w-0">
          <div class="mb-2 flex items-center gap-2">
            <IconPlugConnected :size="19" class="text-accent" />
            <h2 id="mcp-settings-heading" class="text-base font-semibold">MCP server</h2>
          </div>
          <p class="max-w-md text-sm leading-relaxed text-[#a5b197]"
            >Connect your agents to your game library.</p
          >
          <p class="mt-2! text-xs text-[#86977a]"
            >Changes take effect immediately. Disabling MCP keeps your keys.</p
          >
        </div>
        <button
          type="button"
          role="switch"
          aria-label="Enable MCP"
          :aria-checked="settings.enabled"
          :disabled="Boolean(busy)"
          class="relative mt-1 h-7 w-12 shrink-0 rounded-full! transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none"
          :class="settings.enabled ? 'bg-accent!' : 'bg-white/20!'"
          @click="toggleEnabled"
        >
          <span
            class="absolute top-1 left-1 h-5 w-5 rounded-full transition-transform duration-150 motion-reduce:transition-none"
            :class="settings.enabled ? 'translate-x-5 bg-[#18200e]' : 'bg-[#e1e6da]'"
          />
        </button>
      </div>

      <label class="field">
        Server URL
        <span class="flex min-w-0 items-center gap-2">
          <input
            ref="urlInput"
            :value="settings.url"
            readonly
            class="w-full min-w-0 font-mono! text-xs"
            aria-label="MCP server URL"
            @focus="urlInput?.select()"
          />
          <button
            type="button"
            class="icon-button shrink-0"
            aria-label="Copy MCP server URL"
            @click="copy('url')"
          >
            <IconCopy :size="19" />
          </button>
        </span>
        <span class="field-hint">Use this URL with a key in your agent’s MCP connection.</span>
      </label>

      <div class="grid gap-3 sm:grid-cols-2">
        <article
          v-for="level in levels"
          :key="level.access"
          class="flex min-w-0 flex-col rounded-lg border border-white/10 bg-white/[.025] p-4"
        >
          <div class="mb-3 flex items-center gap-2">
            <IconKey :size="18" class="text-[#a5b197]" />
            <h3 class="text-sm font-semibold">{{ level.title }}</h3>
          </div>
          <p class="mb-4! flex-1 text-xs leading-relaxed text-[#a5b197]">{{ level.description }}</p>
          <p
            class="mb-4! flex items-center gap-2 text-xs"
            :class="settings.keys[level.access].configured ? 'text-[#c6e9a6]' : 'text-[#86977a]'"
          >
            <span
              class="h-1.5 w-1.5 rounded-full"
              :class="settings.keys[level.access].configured ? 'bg-accent' : 'bg-white/25'"
            />
            {{
              settings.keys[level.access].configured
                ? generatedDate(settings.keys[level.access].generated_at)
                : 'No key generated'
            }}
          </p>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="button button--quiet px-3! py-2! text-xs!"
              :data-key-access="level.access"
              :aria-label="
                (settings.keys[level.access].configured ? 'Regenerate ' : 'Generate ') +
                level.title.toLowerCase() +
                ' key'
              "
              :disabled="Boolean(busy) || Boolean(generated)"
              @click="requestAction(level.access, 'regenerate')"
            >
              <IconLoader2 v-if="busy === level.access" :size="15" class="spin" />
              <IconRefresh v-else-if="settings.keys[level.access].configured" :size="15" />
              <IconKey v-else :size="15" />
              {{ settings.keys[level.access].configured ? 'Regenerate key' : 'Generate key' }}
            </button>
            <button
              v-if="settings.keys[level.access].configured"
              type="button"
              class="icon-button h-8! w-8! text-[#c5b4a9]"
              :aria-label="'Revoke ' + level.title.toLowerCase() + ' key'"
              data-tooltip="Revoke key"
              :disabled="Boolean(busy) || Boolean(generated)"
              @click="requestAction(level.access, 'revoke')"
            >
              <IconTrash :size="17" />
            </button>
          </div>
        </article>
      </div>

      <div
        v-if="pending"
        class="rounded-lg border border-[#d6bb6a]/30 bg-[#d6bb6a]/5 p-4"
        role="alert"
      >
        <h3 class="mb-2 text-sm font-semibold"
          >{{ pending.operation === 'revoke' ? 'Revoke' : 'Regenerate' }}
          {{ accessLabel(pending.access).toLowerCase() }} key?</h3
        >
        <p class="text-xs leading-relaxed text-[#c5c5b4]"
          >Agents using the current key will lose access.
          {{
            pending.operation === 'regenerate'
              ? 'Update them with the new key after generating it.'
              : 'You can generate a new key later.'
          }}</p
        >
        <div class="mt-4 flex flex-wrap gap-2">
          <button
            ref="confirmButton"
            type="button"
            class="button button--primary px-3! py-2! text-xs!"
            :disabled="Boolean(busy)"
            @click="confirmAction"
            >{{ pending.operation === 'revoke' ? 'Revoke key' : 'Regenerate key' }}</button
          >
          <button
            type="button"
            class="button button--quiet px-3! py-2! text-xs!"
            :disabled="Boolean(busy)"
            @click="cancelAction"
            >Cancel</button
          >
        </div>
      </div>

      <div
        v-if="generated"
        ref="keyPanel"
        class="rounded-lg border border-accent/30 bg-accent/5 p-4"
      >
        <div class="mb-2 flex items-center gap-2 text-accent" role="status">
          <IconCheck :size="18" />
          <h3 class="text-sm font-semibold">{{ accessLabel(generated.access) }} key generated</h3>
        </div>
        <p class="mb-3! text-xs leading-relaxed text-[#bdc9b2]"
          >Copy this key now and save it in your agent. It will not be shown again.</p
        >
        <label class="field">
          <span class="sr-only">Generated MCP key</span>
          <textarea
            ref="keyInput"
            :value="generated.token"
            readonly
            rows="3"
            autocomplete="off"
            spellcheck="false"
            class="w-full resize-none break-all font-mono! text-xs!"
            @focus="keyInput?.select()"
          />
        </label>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            type="button"
            class="button button--primary px-3! py-2! text-xs!"
            @click="copy('key')"
          >
            <IconCopy :size="16" />Copy key</button
          >
          <button
            type="button"
            class="button button--quiet px-3! py-2! text-xs!"
            @click="dismissKey"
            >Done</button
          >
        </div>
      </div>
      <p class="text-xs leading-relaxed text-[#86977a]"
        >Keep your keys private. A read & write key allows an agent to change your library.</p
      >
    </section>
    <p v-if="notice" class="mt-4! text-xs leading-relaxed text-[#c6e9a6]" role="status">{{
      notice
    }}</p>
    <div v-if="error" class="mt-4" role="alert">
      <p class="form-error">{{ error }}</p>
      <button v-if="!settings && !loading" type="button" class="text-button mt-3" @click="load"
        >Try again</button
      >
    </div>
  </div>
</template>
