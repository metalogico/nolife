<script setup lang="ts">
import { onMounted, ref } from 'vue'
import McpSettings from './McpSettings.vue'

const heading = ref<HTMLHeadingElement>()

onMounted(() => heading.value?.focus({ preventScroll: true }))
</script>

<template>
  <section class="gallery-scroll" aria-labelledby="settings-heading">
    <div class="settings-content">
      <header class="settings-heading">
        <h1 id="settings-heading" ref="heading" tabindex="-1">Settings</h1>
        <p>Manage your application settings.</p>
      </header>
      <McpSettings />
    </div>
  </section>
</template>
