<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import type { Component } from 'vue'
import { IconCheck } from '@tabler/icons-vue'
export interface MenuItem {
  label: string
  icon?: Component
  action: () => void
  selected?: boolean
  danger?: boolean
}
const props = defineProps<{ anchor: HTMLElement; items: MenuItem[] }>()
const emit = defineEmits<{ close: [] }>()
const panel = ref<HTMLElement>()
const position = ref({ left: '0px', top: '0px', visibility: 'hidden' as 'hidden' | 'visible' })
function close(restore = false) {
  if (restore && props.anchor.isConnected) {
    props.anchor.focus()
  }
  emit('close')
}

async function activate(item: MenuItem) {
  const anchor = props.anchor
  emit('close')
  await nextTick()
  if (anchor.isConnected) {
    anchor.focus({ preventScroll: true })
  }
  item.action()
}

function outside(event: PointerEvent) {
  if (
    !panel.value?.contains(event.target as Node) &&
    !props.anchor.contains(event.target as Node)
  ) {
    close()
  }
}

function keyboard(event: KeyboardEvent) {
  const buttons = [...(panel.value?.querySelectorAll<HTMLButtonElement>('[role="menuitem"]') || [])]
  const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close(true)
  }
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
    event.preventDefault()
    const next =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? buttons.length - 1
          : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length
    buttons[next]?.focus()
  }
}
onMounted(async () => {
  await nextTick()
  const anchor = props.anchor.getBoundingClientRect()
  const height = panel.value?.offsetHeight || 240
  position.value = {
    left: Math.max(12, Math.min(anchor.right - 228, window.innerWidth - 240)) + 'px',
    top:
      (anchor.bottom + height + 12 > window.innerHeight
        ? Math.max(12, anchor.top - height - 8)
        : anchor.bottom + 8) + 'px',
    visibility: 'visible',
  }
  panel.value?.querySelector<HTMLButtonElement>('button')?.focus()
  document.addEventListener('pointerdown', outside)
  document.addEventListener('keydown', keyboard)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', outside)
  document.removeEventListener('keydown', keyboard)
})
</script>

<template>
  <Teleport to="body">
    <div ref="panel" class="action-menu" role="menu" :style="position">
      <button
        v-for="item in items"
        :key="item.label"
        role="menuitem"
        :class="{ danger: item.danger, selected: item.selected }"
        @click="activate(item)"
      >
        <component :is="item.icon" v-if="item.icon" :size="18" />
        <span>{{ item.label }}</span>
        <IconCheck v-if="item.selected" :size="16" class="menu-check" />
      </button>
    </div>
  </Teleport>
</template>
