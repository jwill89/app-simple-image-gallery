<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import {
  passkeysSupported,
  passkeyCancelled,
  passkeyErrorMessage,
  usePasskeys,
} from '../composables/usePasskeys'
import { endpoints } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import type { Passkey } from '../types'
import AppAlert from './AppAlert.vue'
import AppDialog from './AppDialog.vue'
import EmptyState from './EmptyState.vue'

/**
 * The Account page's passkeys: the ones the account signs in with, a way to
 * add another (after the password), and to rename or remove each.
 */
const api = useApi()
const passkeys = usePasskeys()
const auth = useAuthStore()
const toast = useToastStore()
const supported = passkeysSupported()

const list = ref<Passkey[]>([])
const loading = ref(true)
const loadError = ref('')

onMounted(async () => {
  try {
    list.value = (await api.get<Passkey[]>(endpoints.account.passkeys)) ?? []
  } catch (e) {
    loadError.value = getErrorMessage(e, 'Could not load your passkeys.')
  } finally {
    loading.value = false
  }
})

function when(iso: string | null): string {
  return iso === null ? 'never' : new Date(iso).toLocaleDateString()
}

// ── Add ──────────────────────────────────────────────────────────────
const addOpen = ref(false)
const addPassword = ref('')
const addName = ref('')
const addError = ref('')
const adding = ref(false)

async function add() {
  if (addPassword.value === '' || adding.value) {
    return
  }
  adding.value = true
  addError.value = ''
  try {
    const passkey = await passkeys.add(addPassword.value, addName.value.trim())
    if (passkey) {
      list.value.push(passkey)
    }
    addOpen.value = false
    addPassword.value = addName.value = ''
    toast.success(
      `Added the passkey "${passkey?.name ?? 'Passkey'}". You can sign in with it now.`,
      5000,
      'Passkeys',
    )
  } catch (e) {
    if (!passkeyCancelled(e)) {
      addError.value =
        getFieldError(e, 'name') || passkeyErrorMessage(e, 'Could not add the passkey.')
    }
  } finally {
    adding.value = false
  }
}

// ── Rename ───────────────────────────────────────────────────────────
const renaming = ref<number | null>(null)
const newName = ref('')

function startRename(passkey: Passkey) {
  renaming.value = passkey.id
  newName.value = passkey.name
}

async function finishRename() {
  const id = renaming.value
  const name = newName.value.trim()
  renaming.value = null
  if (id === null || name === '') {
    return
  }
  try {
    const renamed = await api.patch<Passkey>(endpoints.account.passkey(id), { name })
    list.value = list.value.map((p) => (p.id === id && renamed ? renamed : p))
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not rename the passkey.'), 6000, 'Passkeys')
  }
}

// ── Remove ───────────────────────────────────────────────────────────
const removing = ref<Passkey | null>(null)
const removeBusy = ref(false)

async function remove() {
  const passkey = removing.value
  if (passkey === null) {
    return
  }
  removeBusy.value = true
  try {
    await api.del(endpoints.account.passkey(passkey.id))
    list.value = list.value.filter((p) => p.id !== passkey.id)
    removing.value = null
    toast.success(`Removed the passkey "${passkey.name}".`, 4000, 'Passkeys')
  } catch (e) {
    removing.value = null
    toast.error(getErrorMessage(e, 'Could not remove the passkey.'), 6000, 'Passkeys')
  } finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <div class="box">
    <h3 class="title is-5">Passkeys</h3>
    <p class="section-note">
      Sign in with your phone, computer or password manager instead of your password. A passkey
      works only on this site, and your password still works too.
    </p>

    <AppAlert v-if="!supported" severity="info" class="mb-3">
      This browser can't use passkeys.
    </AppAlert>
    <AppAlert v-else-if="loadError" severity="danger" class="mb-3">{{ loadError }}</AppAlert>
    <template v-else-if="!loading">
      <EmptyState
        v-if="list.length === 0"
        icon="fa-solid fa-key"
        title="No passkeys yet."
        hint="Add one to sign in without typing your password."
      />
      <ul v-else class="passkeys">
        <li v-for="passkey in list" :key="passkey.id">
          <template v-if="renaming === passkey.id">
            <input
              v-model="newName"
              class="input is-small"
              aria-label="New name"
              maxlength="64"
              @keyup.enter="finishRename"
              @keyup.escape="renaming = null"
            />
            <button type="button" class="button is-small" @click="finishRename">Save</button>
          </template>
          <template v-else>
            <span class="icon passkey-icon"><font-awesome-icon icon="fa-solid fa-key" /></span>
            <span class="passkey-name">{{ passkey.name }}</span>
            <span class="passkey-meta">
              Added {{ when(passkey.created_at) }} · last used {{ when(passkey.last_used_at) }}
            </span>
            <button type="button" class="button is-small is-text" @click="startRename(passkey)">
              Rename
            </button>
            <button
              type="button"
              class="button is-small is-text has-text-danger"
              @click="removing = passkey"
            >
              Remove
            </button>
          </template>
        </li>
      </ul>
    </template>

    <button
      v-if="supported"
      type="button"
      class="button mt-3"
      :disabled="loading || loadError !== ''"
      @click="addOpen = true"
    >
      <span class="icon"><font-awesome-icon icon="fa-solid fa-plus" /></span>
      <span>Add a passkey</span>
    </button>

    <AppDialog v-model:open="addOpen" title="Add a passkey">
      <form id="add-passkey" @submit.prevent="add">
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
          <label class="label" for="passkey-password">Your password</label>
          <input
            id="passkey-password"
            v-model="addPassword"
            class="input"
            type="password"
            autocomplete="current-password"
          />
          <p class="help">So that only you can add a way into your account.</p>
        </div>
        <div class="field">
          <label class="label" for="passkey-name">Name (optional)</label>
          <input
            id="passkey-name"
            v-model="addName"
            class="input"
            type="text"
            maxlength="64"
            placeholder="Named after where it's kept, if left empty"
          />
        </div>
        <AppAlert v-if="addError" severity="danger">{{ addError }}</AppAlert>
      </form>
      <template #footer>
        <button type="button" class="button" @click="addOpen = false">Cancel</button>
        <button
          type="submit"
          form="add-passkey"
          class="button is-primary"
          :class="{ 'is-loading': adding }"
          :disabled="addPassword === ''"
        >
          Continue
        </button>
      </template>
    </AppDialog>

    <AppDialog
      :open="removing !== null"
      title="Remove this passkey?"
      destructive
      @update:open="(open: boolean) => !open && (removing = null)"
    >
      <p>
        "{{ removing?.name }}" won't sign you in any more. Your phone or password manager keeps
        listing it until you delete it there too.
      </p>
      <template #footer>
        <button type="button" class="button" @click="removing = null">Cancel</button>
        <button
          type="button"
          class="button is-danger"
          :class="{ 'is-loading': removeBusy }"
          @click="remove"
        >
          Remove passkey
        </button>
      </template>
    </AppDialog>
  </div>
</template>

<style scoped>
.section-note {
  color: var(--text-2);
  font-size: var(--t-sm);
  margin-bottom: var(--sp-3);
}

.passkeys {
  display: flex;
  flex-direction: column;
  gap: var(--sp-2);
}

.passkeys li {
  display: flex;
  align-items: center;
  gap: var(--sp-2);
  flex-wrap: wrap;
}

.passkey-icon {
  color: var(--text-2);
}

.passkey-name {
  font-weight: 600;
}

.passkey-meta {
  color: var(--text-2);
  font-size: var(--t-sm);
  margin-right: auto;
}
</style>
