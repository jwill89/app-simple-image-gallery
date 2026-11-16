<script setup lang="ts">
import PageHeader from '../components/PageHeader.vue'

/**
 * The gallery's accessibility statement: the level its own pages are built to, and
 * a statement of partial conformance for the videos, which come from elsewhere
 * without captions.
 */
</script>

<template>
  <section class="section">
    <div class="container">
      <PageHeader title="Accessibility" :breadcrumb="false" />
      <div class="content">
        <h2>What the gallery's pages meet</h2>
        <p>
          The gallery's own pages are built to WCAG 2.2 at level AAA wherever it can be met, and to
          level AA throughout, in light and dark colours. Before each release, automated tests check
          the main pages against both levels, and check that all text reaches AAA contrast.
        </p>
        <p>
          Buttons, fields and links meet level AA's minimum target size, but most are smaller than
          the 44 pixels level AAA asks for, so that the toolbars and tag lists of a large library
          stay on one line.
        </p>
        <h2>Videos</h2>
        <p>
          The videos in the gallery are collected from elsewhere, as they were published, and have
          no captions, transcripts or audio descriptions. They are third-party content, so this is a
          statement of partial conformance: a page that shows a video does not conform, but would
          conform to WCAG 2.2 at level AA if the video were removed.
        </p>
        <h2>Reporting a problem</h2>
        <p>
          If something in the gallery is hard to use, please open an issue on the project's
          <a href="https://github.com/jwill89/app-simple-image-gallery/issues">GitHub page</a>.
        </p>
        <p>This statement was last reviewed on 16 November 2026.</p>
      </div>
    </div>
  </section>
</template>
