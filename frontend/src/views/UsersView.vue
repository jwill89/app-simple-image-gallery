<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useApi, getErrorMessage, getFieldError } from '../composables/useApi'
import { endpoints } from '../api/endpoints'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import type { User } from '../types'
import PageHeader from '../components/PageHeader.vue'
import SignInGate from '../components/SignInGate.vue'
import AppDialog from '../components/AppDialog.vue'
import LoadingSpinner from '../components/LoadingSpinner.vue'

const api = useApi()
const auth = useAuthStore()
const toast = useToastStore()

const users = ref<User[]>([])
const loading = ref(false)

async function loadUsers() {
  if (!auth.isAdmin) {
    return
  }
  loading.value = true
  try {
    users.value = (await api.get<User[]>(endpoints.users.all)) ?? []
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not load the accounts.'), 6000, 'Users')
  } finally {
    loading.value = false
  }
}

onMounted(loadUsers)
watch(() => auth.isAdmin, loadUsers)

function replace(user: User) {
  users.value = users.value.map((u) => (u.id === user.id ? user : u))
}

async function setAdmin(user: User, isAdmin: boolean) {
  try {
    const updated = await api.patch<User>(endpoints.users.byId(user.id), { is_admin: isAdmin })
    if (updated) {
      replace(updated)
    }
    toast.success(
      isAdmin ? `${user.username} is now an admin.` : `${user.username} is no longer an admin.`,
      4000,
      'Users',
    )
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not change the account.'), 6000, 'Users')
  }
}

// ── Set a password ───────────────────────────────────────────────────
const passwordFor = ref<User | null>(null)
const newPassword = ref('')
const passwordError = ref('')

function askPassword(user: User) {
  passwordFor.value = user
  newPassword.value = ''
  passwordError.value = ''
}

async function setPassword() {
  const user = passwordFor.value
  if (!user) {
    return
  }
  try {
    await api.patch<User>(endpoints.users.byId(user.id), { password: newPassword.value })
    passwordFor.value = null
    toast.success(
      `${user.username} has a new password, and is signed out everywhere.`,
      5000,
      'Users',
    )
  } catch (e) {
    passwordError.value =
      getFieldError(e, 'password') || getErrorMessage(e, 'Could not set the password.')
  }
}

// ── Delete ───────────────────────────────────────────────────────────
const deleting = ref<User | null>(null)

async function deleteUser() {
  const user = deleting.value
  if (!user) {
    return
  }
  try {
    await api.del(endpoints.users.byId(user.id))
    users.value = users.value.filter((u) => u.id !== user.id)
    toast.success(`Deleted ${user.username}'s account.`, 4000, 'Users')
  } catch (e) {
    toast.error(getErrorMessage(e, 'Could not delete the account.'), 6000, 'Users')
  } finally {
    deleting.value = null
  }
}

function joined(user: User): string {
  return new Date(user.created_at).toLocaleDateString()
}
</script>

<template>
  <section class="section">
    <div class="container">
      <SignInGate admin>
        <PageHeader title="Users" :meta="`${users.length.toLocaleString()} accounts`" />
        <LoadingSpinner v-if="loading && users.length === 0" />
        <div v-else class="table-container">
          <table class="table is-fullwidth is-hoverable">
            <thead>
              <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Joined</th>
                <th><span class="is-sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in users" :key="user.id">
                <td>
                  {{ user.username }}
                  <span v-if="user.id === auth.user?.id" class="tag is-small ml-1">you</span>
                </td>
                <td>{{ user.is_admin ? 'Admin' : 'Member' }}</td>
                <td>{{ joined(user) }}</td>
                <td class="actions">
                  <button
                    type="button"
                    class="button is-small"
                    @click="setAdmin(user, !user.is_admin)"
                  >
                    {{ user.is_admin ? 'Remove admin' : 'Make admin' }}
                  </button>
                  <button type="button" class="button is-small" @click="askPassword(user)">
                    Set password
                  </button>
                  <button
                    type="button"
                    class="button is-small is-danger is-outlined"
                    @click="deleting = user"
                  >
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <AppDialog
          :open="passwordFor !== null"
          :title="`New password for ${passwordFor?.username ?? ''}`"
          @update:open="(open) => !open && (passwordFor = null)"
        >
          <p class="mb-3">They'll be signed out everywhere, and sign in with this one.</p>
          <input
            v-model="newPassword"
            class="input"
            type="password"
            autocomplete="new-password"
            aria-label="New password"
            @keyup.enter="setPassword"
          />
          <p v-if="passwordError" class="help is-danger" role="alert">{{ passwordError }}</p>
          <template #footer>
            <button type="button" class="button" @click="passwordFor = null">Cancel</button>
            <button
              type="button"
              class="button is-primary"
              :disabled="newPassword.length < 8"
              @click="setPassword"
            >
              Set password
            </button>
          </template>
        </AppDialog>

        <AppDialog
          :open="deleting !== null"
          :title="`Delete ${deleting?.username ?? ''}'s account?`"
          destructive
          @update:open="(open) => !open && (deleting = null)"
        >
          <p>Their lists, blocked tags and saved searches go with it. The media stays.</p>
          <template #footer>
            <button type="button" class="button" @click="deleting = null">Cancel</button>
            <button type="button" class="button is-danger" @click="deleteUser">Delete</button>
          </template>
        </AppDialog>
      </SignInGate>
    </div>
  </section>
</template>

<style scoped>
.actions {
  display: flex;
  gap: var(--sp-1);
  flex-wrap: wrap;
  justify-content: flex-end;
}
</style>
