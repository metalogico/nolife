<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { IconCheck, IconChevronDown, IconPlus } from '@tabler/icons-vue'

type Option = { value: string; label: string; create?: boolean }
const props = withDefaults(
  defineProps<{
    modelValue: string
    options: (string | Option)[]
    label: string
    editable?: boolean
    required?: boolean
    disabled?: boolean
    placeholder?: string
  }>(),
  { editable: false, required: false, disabled: false, placeholder: 'Select an option' },
)
const emit = defineEmits<{ 'update:modelValue': [string] }>()
const uid = useId()
const listId = `options-${uid}`
const control = ref<HTMLElement>()
const root = ref<HTMLElement>()
const panel = ref<HTMLElement>()
const opened = ref(false)
const filtering = ref(false)
const active = ref(-1)
const placement = ref<Record<string, string>>({})
let typeahead = ''
let typeTimer: ReturnType<typeof setTimeout>
let observer: ResizeObserver | undefined
const choices = computed<Option[]>(() => {
  const seen = new Set<string>()
  return props.options
    .map((item) => (typeof item === 'string' ? { value: item, label: item } : item))
    .filter((item) => {
      const key = item.value.toLowerCase()
      if (seen.has(key)) {
        return false
      }
      seen.add(key)
      return true
    })
})
const selectedLabel = computed(
  () =>
    choices.value.find((item) => item.value === props.modelValue)?.label ||
    props.modelValue ||
    props.placeholder,
)
const visible = computed(() => {
  const query = props.modelValue.trim().toLowerCase()
  const options =
    props.editable && filtering.value
      ? choices.value.filter((item) => item.label.toLowerCase().includes(query))
      : [...choices.value]
  if (
    props.editable &&
    query &&
    !choices.value.some((item) => item.value.toLowerCase() === query)
  ) {
    options.push({
      value: props.modelValue.trim(),
      label: `Use “${props.modelValue.trim()}”`,
      create: true,
    })
  }
  return options
})

function position() {
  if (!opened.value || !root.value) {
    return
  }
  const rect = root.value.getBoundingClientRect()
  const below = window.innerHeight - rect.bottom - 16
  const above = rect.top - 16
  const upward = below < 170 && above > below
  const height = Math.max(70, Math.min(280, upward ? above : below))
  placement.value = {
    left: `${Math.max(8, Math.min(rect.left, innerWidth - rect.width - 8))}px`,
    width: `${rect.width}px`,
    maxHeight: `${height}px`,
    ...(upward
      ? { bottom: `${innerHeight - rect.top + 7}px`, top: 'auto' }
      : { top: `${rect.bottom + 7}px`, bottom: 'auto' }),
  }
}

async function open() {
  if (props.disabled || opened.value) {
    return
  }
  opened.value = true
  active.value = visible.value.findIndex((item) => item.value === props.modelValue)
  position()
  await nextTick()
  panel.value?.showPopover()
  revealActive()
}

function close() {
  if (!opened.value) {
    return
  }
  panel.value?.hidePopover()
  opened.value = false
  filtering.value = false
  active.value = -1
}

function choose(option: Option) {
  emit('update:modelValue', option.value)
  close()
  control.value?.focus({ preventScroll: true })
}

function toggle() {
  control.value?.focus({ preventScroll: true })
  if (opened.value) {
    close()
  } else {
    void open()
  }
}

function input(event: Event) {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
  filtering.value = true
  active.value = -1
  void open()
}

function revealActive() {
  panel.value
    ?.querySelectorAll<HTMLElement>('[role=option]')
    [active.value]?.scrollIntoView({ block: 'nearest' })
}

async function keydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && opened.value) {
    event.preventDefault()
    event.stopPropagation()
    close()
    return
  }
  if (event.key === 'Tab') {
    close()
    return
  }
  if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
    event.preventDefault()
    if (!opened.value) {
      await open()
    } else {
      active.value = Math.max(
        0,
        Math.min(visible.value.length - 1, active.value + (event.key === 'ArrowDown' ? 1 : -1)),
      )
    }
    if (active.value < 0) {
      active.value = event.key === 'ArrowUp' ? visible.value.length - 1 : 0
    }
    revealActive()
    return
  }
  if (!props.editable && opened.value && ['Home', 'End'].includes(event.key)) {
    event.preventDefault()
    active.value = event.key === 'Home' ? 0 : visible.value.length - 1
    revealActive()
    return
  }
  if (event.key === 'Enter' || (!props.editable && event.key === ' ')) {
    if (!opened.value && props.editable) {
      return
    }
    event.preventDefault()
    if (!opened.value) {
      await open()
    } else if (visible.value[active.value]) {
      choose(visible.value[active.value]!)
    } else if (props.editable) {
      emit('update:modelValue', props.modelValue.trim())
      close()
    }
    return
  }
  if (
    !props.editable &&
    event.key.length === 1 &&
    !event.ctrlKey &&
    !event.metaKey &&
    !event.altKey
  ) {
    event.preventDefault()
    clearTimeout(typeTimer)
    typeahead += event.key.toLowerCase()
    await open()
    active.value = visible.value.findIndex((item) => item.label.toLowerCase().startsWith(typeahead))
    revealActive()
    typeTimer = setTimeout(() => {
      typeahead = ''
    }, 700)
  }
}

function outside(event: PointerEvent) {
  if (!root.value?.contains(event.target as Node)) {
    close()
  }
}

function blur(event: FocusEvent) {
  if (!root.value?.contains(event.relatedTarget as Node | null)) {
    close()
  }
}

function scrolled(event: Event) {
  if (!panel.value?.contains(event.target as Node)) {
    position()
  }
}
watch(visible, async () => {
  if (active.value >= visible.value.length) {
    active.value = -1
  }
  await nextTick()
  position()
})
onMounted(() => {
  document.addEventListener('pointerdown', outside, true)
  window.addEventListener('resize', position)
  window.addEventListener('scroll', scrolled, true)
  observer = new ResizeObserver(position)
  if (root.value) {
    observer.observe(root.value)
  }
})
onBeforeUnmount(() => {
  clearTimeout(typeTimer)
  observer?.disconnect()
  document.removeEventListener('pointerdown', outside, true)
  window.removeEventListener('resize', position)
  window.removeEventListener('scroll', scrolled, true)
  if (opened.value) {
    panel.value?.hidePopover()
  }
})
</script>

<template>
  <div
    ref="root"
    class="custom-select"
    :class="{ 'custom-select--open': opened, 'custom-select--editable': editable }"
    @keydown="keydown"
    @focusout="blur"
  >
    <input
      v-if="editable"
      ref="control"
      :value="modelValue"
      role="combobox"
      :aria-label="label"
      :aria-expanded="opened"
      :aria-controls="listId"
      :aria-activedescendant="opened && active >= 0 ? `${listId}-${active}` : undefined"
      aria-autocomplete="list"
      :required="required"
      :disabled="disabled"
      :placeholder="placeholder"
      maxlength="80"
      autocomplete="off"
      @input="input"
      @click="open"
    />
    <button
      v-else
      ref="control"
      type="button"
      class="select-trigger"
      role="combobox"
      :aria-label="label"
      :aria-expanded="opened"
      :aria-controls="listId"
      :aria-activedescendant="opened && active >= 0 ? `${listId}-${active}` : undefined"
      aria-haspopup="listbox"
      :aria-required="required"
      :disabled="disabled"
      @click="toggle"
    >
      <span>{{ selectedLabel }}</span>
      <IconChevronDown :size="16" />
    </button>
    <button
      v-if="editable"
      type="button"
      class="select-toggle"
      :aria-label="`Show ${label.toLowerCase()} options`"
      tabindex="-1"
      :disabled="disabled"
      @mousedown.prevent
      @click.prevent="toggle"
    >
      <IconChevronDown :size="16" />
    </button>
    <div
      :id="listId"
      ref="panel"
      class="select-panel"
      role="listbox"
      :aria-label="label"
      :aria-hidden="!opened"
      popover="manual"
      :style="placement"
      @mousedown.prevent
    >
      <div
        v-for="(option, index) in visible"
        :id="`${listId}-${index}`"
        :key="option.value"
        role="option"
        :aria-selected="modelValue === option.value"
        class="select-option"
        :class="{
          'select-option--active': active === index,
          'select-option--create': option.create,
        }"
        @pointermove="active = index"
        @click.prevent.stop="choose(option)"
      >
        <IconPlus v-if="option.create" :size="16" />
        <span>{{ option.label }}</span>
        <IconCheck v-if="modelValue === option.value" :size="16" class="select-check" />
      </div>
      <p v-if="!visible.length" class="select-empty">No platforms found. Type a new one.</p>
    </div>
  </div>
</template>
