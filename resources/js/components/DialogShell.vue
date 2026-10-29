<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, useId } from 'vue'
import { IconX } from '@tabler/icons-vue'
const props = withDefaults(
  defineProps<{ title: string; description?: string; size?: 'wide' | 'medium' | 'small' }>(),
  { size: 'wide' },
)
const emit = defineEmits<{ close: [] }>()
const element = ref<HTMLDialogElement>()
const leaving = ref(false)
const titleId = useId()
let previous: HTMLElement | null = null
let timer: ReturnType<typeof setTimeout>
function close() {
  if (leaving.value) {
    return
  }
  leaving.value = true
  timer = setTimeout(
    () => emit('close'),
    window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180,
  )
}

function backdrop(event: MouseEvent) {
  if (event.target !== element.value) {
    return
  }
  const rect = element.value!.getBoundingClientRect()
  if (
    event.clientX < rect.left ||
    event.clientX > rect.right ||
    event.clientY < rect.top ||
    event.clientY > rect.bottom
  ) {
    close()
  }
}
onMounted(() => {
  previous = document.activeElement as HTMLElement
  element.value?.showModal()
})
onBeforeUnmount(() => {
  clearTimeout(timer)
  element.value?.close()
  if (previous?.isConnected) {
    previous.focus({ preventScroll: true })
  }
})
defineExpose({ close })
</script>

<template>
  <dialog
    ref="element"
    :aria-labelledby="titleId"
    :class="['dialog-shell', 'dialog--' + props.size, { 'dialog--leaving': leaving }]"
    @cancel.prevent="close"
    @click="backdrop"
  >
    <header class="dialog-header">
      <div>
        <h2 :id="titleId">{{ title }}</h2>
        <p v-if="description">{{ description }}</p>
      </div>
      <button
        type="button"
        class="icon-button dialog-close"
        aria-label="Close dialog"
        @click="close"
      >
        <IconX :size="22" />
      </button>
    </header>
    <slot :close="close" />
  </dialog>
</template>
