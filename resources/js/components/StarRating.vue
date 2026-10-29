<script setup lang="ts">
import { ref } from 'vue'
import { IconStar, IconStarFilled, IconX } from '@tabler/icons-vue'
const props = defineProps<{ modelValue: number | null; busy?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [number | null] }>()
const hover = ref<number | null>(null)
const buttons = ref<HTMLButtonElement[]>([])
function choose(value: number | null) {
  if (!props.busy) {
    emit('update:modelValue', value)
  }
}

function key(event: KeyboardEvent, value: number) {
  if (['ArrowRight', 'ArrowUp', 'ArrowLeft', 'ArrowDown', 'Home', 'End'].includes(event.key)) {
    event.preventDefault()
    if (props.busy) {
      return
    }
    const next =
      event.key === 'Home'
        ? 1
        : event.key === 'End'
          ? 10
          : Math.min(
              10,
              Math.max(1, value + (['ArrowRight', 'ArrowUp'].includes(event.key) ? 1 : -1)),
            )
    emit('update:modelValue', next)
    buttons.value[next - 1]?.focus()
  }
}
</script>

<template>
  <div class="rating-control">
    <div
      class="rating-stars"
      role="radiogroup"
      aria-label="Your rating, from 1 to 10"
      @mouseleave="hover = null"
    >
      <button
        v-for="value in 10"
        :key="value"
        :ref="
          (el) => {
            if (el) buttons[value - 1] = el as HTMLButtonElement
          }
        "
        type="button"
        role="radio"
        :aria-checked="modelValue === value"
        :aria-label="value + ' out of 10'"
        :aria-disabled="busy"
        :tabindex="(modelValue || 1) === value ? 0 : -1"
        :class="{ filled: value <= (hover ?? modelValue ?? 0) }"
        @mouseenter="hover = value"
        @focus="hover = value"
        @blur="hover = null"
        @keydown="key($event, value)"
        @click="choose(value)"
      >
        <IconStarFilled v-if="value <= (hover ?? modelValue ?? 0)" :size="21" />
        <IconStar v-else :size="21" />
      </button>
    </div>
    <span class="rating-number"
      >{{ hover ?? modelValue ?? '—' }}<small v-if="hover || modelValue">/10</small>
    </span>
    <button
      v-if="modelValue !== null"
      type="button"
      class="icon-button clear-rating"
      aria-label="Clear rating"
      :disabled="busy"
      @click="choose(null)"
    >
      <IconX :size="14" />
    </button>
  </div>
</template>
