<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { useAccountStore } from '../stores/account'
import { useGalleryStore } from '../stores/gallery'
import { useToastStore } from '../stores/toast'
import type { SavedSearch } from '../types'
import PageHeader from '../components/PageHeader.vue'
import SignInGate from '../components/SignInGate.vue'
import TagMultiSelect from '../components/TagMultiSelect.vue'
import AppDialog from '../components/AppDialog.vue'
import EmptyState from '../components/EmptyState.vue'

const api = useApi()
const router = useRouter()
const auth = useAuthStore()
const account = useAccountStore()
const gallery = useGalleryStore()
const toast = useToastStore()

// ── Password ─────────────────────────────────────────────────────────
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordError = ref('')
const changingPassword = ref(false)
const passwordReady = computed(
  () =>
    currentPassword.value !== '' &&
    newPassword.value.length >= 8 &&
    newPassword.value === confirmPassword.value,
)

async function changePassword() {
  if (!passwordReady.value) return
  changingPassword.value = true
  passwordError.value = ''
  try {
    await api.patch(endpoints.account.password, {
      current_password: currentPassword.value,
      password: newPassword.value,
    })
    currentPassword.value = newPassword.value = confirmPassword.value = ''
    toast.success('Your password is changed. Your other sign-ins are signed out.', 5000, 'Password')
  } catch (e) {
    passwordError.value =
      getFieldError(e, 'password') || getErrorMessage(e, 'Could not change the password.')
  } finally {
    changingPassword.value = false
  }
}

// ── Blocked tags ─────────────────────────────────────────────────────
// The picker's chips are the blocked tags: adding one blocks it, removing one
// unblocks it.
const blockedIds = computed(() => account.blockedTags.map((t) => t.tag_id))

async function onBlockedChange(ids: number[]) {
  const before = new Set(blockedIds.value)
  const after = new Set(ids)
  try {
    for (const id of after) {
      const tag = gallery.tagById(id)
      if (!before.has(id) && tag) await account.block(tag)
    }
    for (const id of before) {
      if (!after.has(id)) await account.unblock(id)
    }
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not change your blocked tags.'), 6000, 'Blocked tags')
  }
}

// ── Saved searches ───────────────────────────────────────────────────
const renaming = ref<number | null>(null)
const newName = ref('')

function searchRoute(search: SavedSearch) {
  return { name: 'media-with-tags', params: { page: 1, perPage: 40, tags: search.query } }
}

function startRename(search: SavedSearch) {
  renaming.value = search.search_id
  newName.value = search.name
}

async function finishRename() {
  const id = renaming.value
  const name = newName.value.trim()
  renaming.value = null
  if (id === null || name === '') return
  try {
    await account.renameSearch(id, name)
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not rename the search.'), 6000, 'Saved searches')
  }
}

async function deleteSearch(search: SavedSearch) {
  try {
    await account.deleteSearch(search.search_id)
    toast.success(`Deleted the saved search "${search.name}".`, 4000, 'Saved searches')
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not delete the search.'), 6000, 'Saved searches')
  }
}

// ── Delete the account ───────────────────────────────────────────────
const deleteOpen = ref(false)
const deletePassword = ref('')
const deleteError = ref('')
const deleting = ref(false)

async function deleteAccount() {
  deleting.value = true
  deleteError.value = ''
  try {
    await api.del(endpoints.account.self, { password: deletePassword.value })
    deleteOpen.value = false
    toast.success('Your account is deleted.', 5000, 'Account')
    // The token went with the account; forget it here too.
    await auth.signOut()
    void router.push('/')
  } catch (e) {
    deleteError.value = getErrorMessage(e, 'Could not delete the account.')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <section class="section">
    <div class="container account-page">
      <SignInGate>
        <PageHeader
          :title="auth.user?.username ?? 'Account'"
          :meta="auth.isAdmin ? 'Admin account' : 'Account'"
        />

        <div class="box">
          <h3 class="title is-5">Blocked tags</h3>
          <p class="section-note">
            Media carrying any of these tags is left out of your listings, searches and random
            picks. Opening one directly asks before showing it.
          </p>
          <TagMultiSelect
            :model-value="blockedIds"
            placeholder="Add a tag to block..."
            @update:model-value="onBlockedChange"
          />
        </div>

        <div class="box">
          <h3 class="title is-5">Saved searches</h3>
          <EmptyState
            v-if="account.searches.length === 0"
            icon="fa-regular fa-bookmark"
            title="No saved searches yet."
            hint="Search by tags, then choose Save this search from the bookmark menu."
          />
          <ul v-else class="saved-searches">
            <li v-for="search in account.searches" :key="search.search_id">
              <template v-if="renaming === search.search_id">
                <input
                  v-model="newName"
                  class="input is-small"
                  aria-label="New name"
                  maxlength="60"
                  @keyup.enter="finishRename"
                  @keyup.escape="renaming = null"
                />
                <button type="button" class="button is-small" @click="finishRename">Save</button>
              </template>
              <template v-else>
                <router-link :to="searchRoute(search)" class="search-name">{{
                  search.name
                }}</router-link>
                <code class="search-query">{{ search.query }}</code>
                <button type="button" class="button is-small is-text" @click="startRename(search)">
                  Rename
                </button>
                <button
                  type="button"
                  class="button is-small is-text has-text-danger"
                  @click="deleteSearch(search)"
                >
                  Delete
                </button>
              </template>
            </li>
          </ul>
        </div>

        <form class="box" @submit.prevent="changePassword">
          <h3 class="title is-5">Change password</h3>
          <input
            :value="auth.user?.username"
            type="text"
            autocomplete="username"
            class="is-hidden"
            aria-hidden="true"
            tabindex="-1"
            readonly
          />
          <div class="field">
            <label class="label" for="current-password">Current password</label>
            <input
              id="current-password"
              v-model="currentPassword"
              class="input"
              type="password"
              autocomplete="current-password"
            />
          </div>
          <div class="field">
            <label class="label" for="new-password">New password</label>
            <input
              id="new-password"
              v-model="newPassword"
              class="input"
              type="password"
              autocomplete="new-password"
            />
            <p v-if="newPassword && newPassword.length < 8" class="help is-danger">
              At least 8 characters.
            </p>
          </div>
          <div class="field">
            <label class="label" for="confirm-password">New password again</label>
            <input
              id="confirm-password"
              v-model="confirmPassword"
              class="input"
              type="password"
              autocomplete="new-password"
            />
            <p v-if="confirmPassword && confirmPassword !== newPassword" class="help is-danger">
              The passwords don't match.
            </p>
          </div>
          <p v-if="passwordError" class="help is-danger mb-3" role="alert">{{ passwordError }}</p>
          <button
            type="submit"
            class="button"
            :class="{ 'is-loading': changingPassword }"
            :disabled="!passwordReady"
          >
            Change password
          </button>
        </form>

        <div class="box">
          <h3 class="title is-5">Delete account</h3>
          <p class="section-note">
            Deletes your account with its lists, blocked tags and saved searches. The media stays in
            the gallery.
          </p>
          <button type="button" class="button is-danger is-outlined" @click="deleteOpen = true">
            Delete my account
          </button>
        </div>

        <AppDialog v-model:open="deleteOpen" title="Delete your account?" destructive>
          <p class="mb-3">This can't be undone. Enter your password to confirm.</p>
          <input
            v-model="deletePassword"
            class="input"
            type="password"
            autocomplete="current-password"
            aria-label="Password"
            @keyup.enter="deleteAccount"
          />
          <p v-if="deleteError" class="help is-danger" role="alert">{{ deleteError }}</p>
          <template #footer>
            <button type="button" class="button" @click="deleteOpen = false">Cancel</button>
            <button
              type="button"
              class="button is-danger"
              :class="{ 'is-loading': deleting }"
              :disabled="deletePassword === ''"
              @click="deleteAccount"
            >
              Delete account
            </button>
          </template>
        </AppDialog>
      </SignInGate>
    </div>
  </section>
</template>

<style scoped>
.account-page {
  max-width: 48rem;
}

.section-note {
  color: var(--text-2);
  font-size: var(--t-sm);
  margin-bottom: var(--sp-3);
}

.saved-searches {
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}

.saved-searches li {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  flex-wrap: wrap;
}

.search-name {
  font-weight: 600;
}

.search-query {
  color: var(--text-2);
  font-size: var(--t-sm);
  margin-right: auto;
}
</style>
