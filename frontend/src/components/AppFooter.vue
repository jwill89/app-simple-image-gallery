<script setup lang="ts">
const version = __APP_VERSION__
</script>

<template>
  <footer class="footer is-flex-align-items-flex-end mt-auto">
    <div class="content has-text-centered">
      <p>
        Programmed by <a href="https://www.mathdad.me" target="_blank">MathDad</a>. View project on
        <a href="https://github.com/jwill89/image-gallery">GitHub</a>.<br />
        All images &copy; their respective creators. All characters in images &copy; their
        respective owners.
      </p>
      <p class="is-size-7">
        Gallery <a href="/api/docs" target="_blank" rel="noopener">v{{ version }}</a>
      </p>
    </div>
  </footer>
</template>
