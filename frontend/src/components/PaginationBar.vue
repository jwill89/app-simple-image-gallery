<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import {
  PaginationRoot,
  PaginationList,
  PaginationListItem,
  PaginationPrev,
  PaginationNext,
  PaginationEllipsis,
} from 'reka-ui'

/**
 * Page navigation, on Reka UI.
 *
 * Computing the visible window by hand takes seven separate `v-if`s — first
 * page, leading ellipsis, previous, current, next, trailing ellipsis, last —
 * which have to be kept mutually consistent. Reka's PaginationRoot derives it
 * from `page`/`total`/`siblingCount` and hands back a typed list, so the window
 * arithmetic isn't ours to get wrong. It also brings real `aria-current` and
 * list semantics, which bare anchors lack.
 *
 * `itemsPerPage` is 1 and `total` is the page count, because the caller already
 * thinks in pages rather than items.
 */
const props = defineProps<{
  currentPage: number
  totalPages: number
}>()

const emit = defineEmits<{
  navigate: [page: number]
}>()

const page = computed({
  get: () => props.currentPage,
  set: (value: number) => {
    if (value !== props.currentPage) {
      emit('navigate', value)
    }
  },
})

// ── Jump-to-page ─────────────────────────────────────────
// Only worth showing when there are enough pages to make stepping tedious.
const showJump = computed(() => props.totalPages > 5)
const jumpId = useId()
const jumpValue = ref<number | null>(null)

function submitJump() {
  const n = Number(jumpValue.value)
  if (!Number.isFinite(n)) {
    return
  }
  const target = Math.min(Math.max(Math.trunc(n), 1), props.totalPages)
  jumpValue.value = null
  if (target !== props.currentPage) {
    emit('navigate', target)
  }
}
</script>

<template>
  <PaginationRoot
    v-model:page="page"
    :total="totalPages"
    :items-per-page="1"
    :sibling-count="1"
    show-edges
    class="pager"
  >
    <PaginationList v-slot="{ items }" class="pager-list">
      <PaginationPrev class="pager-btn">
        <span class="icon"><font-awesome-icon icon="fa-solid fa-chevron-left" /></span>
        <span class="pager-btn-label">Previous</span>
      </PaginationPrev>

      <template v-for="(item, index) in items">
        <PaginationListItem
          v-if="item.type === 'page'"
          :key="`p-${item.value}`"
          :value="item.value"
          class="pager-page"
        >
          {{ item.value }}
        </PaginationListItem>
        <PaginationEllipsis v-else :key="`e-${index}`" class="pager-ellipsis">
          &hellip;
        </PaginationEllipsis>
      </template>

      <!-- Jump-to-page rides along as one more chip in the list. `novalidate`
           so an out-of-range entry still submits and gets clamped. -->
      <li v-if="showJump" class="pager-jump-item">
        <form class="pager-jump" novalidate @submit.prevent="submitJump">
          <input
            :id="jumpId"
            v-model.number="jumpValue"
            class="input pager-jump-input"
            type="number"
            min="1"
            :max="totalPages"
            inputmode="numeric"
            :aria-label="`Go to page (1 to ${totalPages})`"
            placeholder="Go to…"
          />
        </form>
      </li>

      <PaginationNext class="pager-btn">
        <span class="pager-btn-label">Next</span>
        <span class="icon"><font-awesome-icon icon="fa-solid fa-chevron-right" /></span>
      </PaginationNext>
    </PaginationList>
  </PaginationRoot>
</template>

<style scoped>
.pager {
  display: flex;
  justify-content: center;
}

.pager-list {
  display: flex;
  align-items: center;
  gap: var(--sp-1);
  flex-wrap: wrap;
  justify-content: center;
  list-style: none;
  margin: 0;
  padding: 0;
}

.pager-btn,
.pager-page {
  display: inline-flex;
  align-items: center;
  gap: var(--sp-1);
  min-width: 2.125rem;
  height: 2.125rem;
  padding: 0 var(--sp-2);
  justify-content: center;
  background: transparent;
  border: 1px solid var(--border-strong);
  border-radius: var(--r-md);
  color: var(--text-2);
  font: inherit;
  font-size: var(--t-md);
  font-variant-numeric: tabular-nums;
  cursor: pointer;
}

.pager-btn:hover:not(:disabled),
.pager-page:hover:not([data-selected]) {
  background: var(--surface-2);
  color: var(--text-1);
}

.pager-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.pager-page[data-selected] {
  background: var(--text-1);
  border-color: var(--text-1);
  color: var(--surface-0);
}

.pager-ellipsis {
  min-width: 1.5rem;
  text-align: center;
  color: var(--text-3);
}

.pager-jump-item {
  display: flex;
}

.pager-jump {
  display: flex;
  margin: 0;
}

/* Sized like a page chip so it sits inline, with the number spinners dropped
   so it reads as a chip rather than a stepper. */
.pager-jump-input {
  width: 6.5em;
  height: 2.125rem;
  text-align: center;
  appearance: textfield;
}

.pager-jump-input::-webkit-outer-spin-button,
.pager-jump-input::-webkit-inner-spin-button {
  appearance: none;
  margin: 0;
}

@media screen and (width <= 640px) {
  .pager-btn-label {
    display: none;
  }
}
</style>
