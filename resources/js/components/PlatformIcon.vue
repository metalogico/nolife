<script setup lang="ts">
import { computed } from 'vue'
import { IconDeviceDesktop, IconDeviceGamepad2, IconLayoutGrid } from '@tabler/icons-vue'

const props = defineProps<{ platform?: string }>()
const key = computed(() => (props.platform || '').toLowerCase().replace(/[^a-z0-9]/g, ''))
const icons: Record<string, string> = {
  ps5: 'ps5',
  playstation5: 'ps5',
  ps4: 'ps4',
  playstation4: 'ps4',
  ps3: 'ps3',
  playstation3: 'ps3',
  ps2: 'ps2',
  playstation2: 'ps2',
  ps1: 'psx',
  psx: 'psx',
  playstation: 'psx',
  psp: 'psp',
  playstationportable: 'psp',
  psvita: 'psvita.ico',
  playstationvita: 'psvita.ico',
  nintendoswitch: 'switch',
  switch: 'switch',
  nintendoswitch2: 'switch-2',
  switch2: 'switch-2',
  nintendo3ds: '3ds',
  '3ds': '3ds',
  nintendods: 'nds',
  ds: 'nds',
  nds: 'nds',
  wii: 'wii',
  nintendowii: 'wii',
  wiiu: 'wiiu',
  nintendowiiu: 'wiiu',
  gamecube: 'ngc',
  nintendogamecube: 'ngc',
  ngc: 'ngc',
  n64: 'n64',
  nintendo64: 'n64',
  gameboyadvance: 'gba',
  gba: 'gba',
  gameboycolor: 'gbc',
  gbc: 'gbc',
  gameboy: 'gb',
  gb: 'gb',
  snes: 'snes',
  supernintendo: 'snes',
  supernintendoentertainmentsystem: 'snes',
  nes: 'nes',
  nintendoentertainmentsystem: 'nes',
  xboxseriesxs: 'series-x-s',
  seriesxs: 'series-x-s',
  xboxseriesx: 'series-x-s',
  xboxseriess: 'series-x-s',
  xboxone: 'xboxone',
  xbox360: 'xbox360',
  xbox: 'xbox',
  dreamcast: 'dc',
  segadreamcast: 'dc',
  megadrive: 'genesis',
  segamegadrive: 'genesis',
  genesis: 'genesis',
  gamegear: 'gamegear',
  segagamegear: 'gamegear',
}
const source = computed(() => {
  const icon = icons[key.value]
  return icon ? '/platforms/' + icon + (icon.endsWith('.ico') ? '' : '.svg') : null
})
const desktop = computed(() =>
  ['pc', 'windows', 'pcwindows', 'win', 'linux', 'mac', 'macos'].includes(key.value),
)
const darkArtwork = computed(() =>
  [
    'ps2',
    'ps3',
    'ps4',
    'psp',
    'psvita.ico',
    '3ds',
    'wiiu',
    'xbox',
    'xboxone',
    'series-x-s',
    'genesis',
    'gamegear',
  ].includes(icons[key.value] || ''),
)
</script>

<template>
  <span class="platform-icon" aria-hidden="true">
    <img
      v-if="source"
      :src="source"
      :class="{ 'dark-artwork': darkArtwork }"
      alt=""
      decoding="async"
    />
    <IconLayoutGrid v-else-if="!platform" :size="20" stroke="1.6" />
    <IconDeviceDesktop v-else-if="desktop" :size="23" stroke="1.6" />
    <IconDeviceGamepad2 v-else :size="23" stroke="1.6" />
  </span>
</template>
