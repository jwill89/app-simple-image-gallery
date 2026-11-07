<script setup lang="ts">
import { useGalleryStore } from '../stores/gallery'
import { colorToTagClass } from '../constants/categories'

/**
 * What the tag colours mean.
 *
 * Replaces TagShortcodeModal, which mostly documented the `a:artist name`
 * prefix syntax. That syntax was parsed by `TagRepository::getOrCreate()`, which
 * had no callers — tags are created either from the Tags page (explicit category
 * dropdown) or by the Danbooru importer (category map, by id). So the
 * instructions described something the app could not do, and the useful half was
 * always the colour legend.
 */
defineEmits<{ close: [] }>()

const store = useGalleryStore()
</script>

<template>
  <aside class="legend">
    <header class="legend-head">
      <h4 class="legend-title">Tag colours</h4>
      <button type="button" class="legend-close" aria-label="Hide tag help" @click="$emit('close')">
        <font-awesome-icon icon="fa-solid fa-xmark" />
      </button>
    </header>

    <p class="legend-intro">
      Every tag belongs to a category, and the category sets its colour. Tag names are stored in
      lowercase.
    </p>

    <dl class="legend-list">
      <div v-for="cat in store.categories" :key="cat.category_id" class="legend-row">
        <dt>
          <span class="tag" :class="colorToTagClass(cat.color)">{{ cat.category_name }}</span>
        </dt>
        <dd>{{ cat.description }}</dd>
      </div>
    </dl>
  </aside>
</template>

<style scoped>
.legend {
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  background: var(--surface-1);
  padding: var(--sp-3);
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}

.legend-head {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
}

.legend-title {
  font-size: var(--t-md);
  font-weight: 600;
  color: var(--text-1);
  margin: 0;
}

.legend-close {
  margin-left: auto;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: none;
  border-radius: var(--r-sm);
  background: transparent;
  color: var(--text-3);
  cursor: pointer;
  line-height: 1;
}

.legend-close:hover {
  background: var(--surface-2);
  color: var(--text-1);
}

.legend-intro {
  font-size: var(--t-sm);
  color: var(--text-3);
  margin: 0;
}

.legend-list {
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
  margin: 0;
}

.legend-row {
  display: grid;
  grid-template-columns: minmax(5rem, max-content) 1fr;
  gap: var(--sp-3);
  align-items: baseline;
}

.legend-row dt {
  min-width: 0;
}

.legend-row dd {
  margin: 0;
  font-size: var(--t-sm);
  color: var(--text-2);
}

@media screen and (width <= 480px) {
  .legend-row {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
