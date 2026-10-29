<script setup lang="ts">
import { ref, computed } from 'vue'
import { IconX, IconChevronLeft, IconChevronRight } from '@tabler/icons-vue'
import type { GameImage } from '../types'
import DialogShell from './DialogShell.vue'
const props = defineProps<{ images: GameImage[]; initial: number; title: string }>()
const emit = defineEmits<{ close: [] }>()
const index = ref(props.initial)
const current = computed(() => props.images[index.value])
function move(delta: number) {
  index.value = (index.value + delta + props.images.length) % props.images.length
}

function key(event: KeyboardEvent) {
  if (event.key === 'ArrowRight') {
    event.preventDefault()
    move(1)
  }
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    move(-1)
  }
}
</script>
<template>
  <DialogShell
    :title="title + ' screenshots'"
    size="wide"
    class="lightbox-dialog"
    @close="emit('close')"
  >
    <div class="image-viewer" @keydown="key">
      <button
        v-if="images.length > 1"
        class="viewer-arrow viewer-arrow--previous icon-button"
        aria-label="Previous image"
        @click="move(-1)"
      >
        <IconChevronLeft :size="30" />
      </button>
      <Transition name="preview" mode="out-in">
        <img
          v-if="current?.url"
          :key="current.id"
          :src="current.url"
          :alt="title + ' screenshot ' + (index + 1)"
          class="viewer-image"
        />
      </Transition>
      <button
        v-if="images.length > 1"
        class="viewer-arrow viewer-arrow--next icon-button"
        aria-label="Next image"
        @click="move(1)"
      >
        <IconChevronRight :size="30" />
      </button>
      <div class="viewer-thumbnails">
        <button
          v-for="(image, number) in images"
          :key="image.id"
          :class="{ selected: index === number }"
          :aria-label="'Image ' + (number + 1) + ' of ' + images.length"
          :aria-pressed="index === number"
          @click="index = number"
        >
          <img :src="image.thumbnail_url || image.url || ''" alt="" />
        </button>
      </div>
      <span class="viewer-count" aria-live="polite">{{ index + 1 }} / {{ images.length }}</span>
    </div>
  </DialogShell>
</template>
