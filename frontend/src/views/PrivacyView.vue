<script setup lang="ts">
import PageHeader from '../components/PageHeader.vue'

/**
 * What the gallery keeps in the reader's browser, and the one thing it loads from
 * another site. e2e/privacy.spec.ts fails when a page loads from a host this page
 * doesn't name.
 */
</script>

<template>
  <section class="section">
    <div class="container">
      <PageHeader title="Privacy" :breadcrumb="false" />
      <div class="content">
        <h2>What the gallery keeps</h2>
        <ul>
          <li>
            When you sign in, a cookie that says which account this browser is signed in as. It goes
            when you close the browser, or after a day without a visit.
          </li>
          <li>
            With &ldquo;Keep me signed in for 30 days&rdquo;, a second cookie that keeps you signed
            in for 30 days.
          </li>
          <li>
            While you are signed out, the items you add to your favorites, kept in this browser's
            local storage. They move into your account the first time you sign in.
          </li>
          <li>
            Your choice of light or dark colours, and display settings such as blurred thumbnails
            and continuous scrolling, kept in this browser's local storage.
          </li>
        </ul>
        <h2>What it loads from elsewhere</h2>
        <p>
          The sign-in and sign-up pages load Cloudflare Turnstile from challenges.cloudflare.com,
          which checks that you are not a bot. Nothing else is loaded from another site.
        </p>
      </div>
    </div>
  </section>
</template>
