import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type * as UseApiModule from '../composables/useApi'
import { useAuthStore } from '../stores/auth'
import type { User } from '../types'
import AccountView from '../views/AccountView.vue'
import UsersView from '../views/UsersView.vue'

// An admin can't remove their own admin role or delete their own account: the
// server refuses (OwnAdminRole, OwnAdminAccount), so the pages don't offer
// either, and say who can.

const { get } = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('../composables/useApi', async (importOriginal) => {
  const actual = await importOriginal<typeof UseApiModule>()
  return {
    ...actual,
    useApi: () => ({
      get,
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      del: vi.fn(),
      upload: vi.fn(),
    }),
  }
})

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ fullPath: '/' }),
}))

const me: User = { id: 1, username: 'admin', is_admin: true, created_at: '2026-01-01T00:00:00Z' }
const otherAdmin: User = {
  id: 2,
  username: 'second',
  is_admin: true,
  created_at: '2026-01-02T00:00:00Z',
}
const member: User = {
  id: 3,
  username: 'reader',
  is_admin: false,
  created_at: '2026-01-03T00:00:00Z',
}

const stubs = {
  PageHeader: true,
  AppDialog: true,
  AccountPasskeys: true,
  TagMultiSelect: true,
  SignInGate: { template: '<div><slot /></div>' },
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  get.mockResolvedValue(undefined)
})

/** The text of every button on the row of `username`. */
function buttonsOf(wrapper: ReturnType<typeof mount>, username: string): string[] {
  const row = wrapper.findAll('tbody tr').find((r) => r.text().includes(username))
  return (row?.findAll('button') ?? []).map((b) => b.text())
}

describe('UsersView', () => {
  async function usersPage() {
    useAuthStore().user = me
    get.mockResolvedValue([me, otherAdmin, member])
    const wrapper = mount(UsersView, { global: { stubs } })
    await flushPromises()
    return wrapper
  }

  it('offers an admin neither removing their own role nor deleting their own account', async () => {
    const wrapper = await usersPage()

    expect(buttonsOf(wrapper, 'admin')).toEqual(['Set password'])
  })

  it('offers every action on the other accounts', async () => {
    const wrapper = await usersPage()

    expect(buttonsOf(wrapper, 'second')).toEqual(['Remove admin', 'Set password', 'Delete'])
    expect(buttonsOf(wrapper, 'reader')).toEqual(['Make admin', 'Set password', 'Delete'])
  })

  it('says another admin does it', async () => {
    const wrapper = await usersPage()

    expect(wrapper.get('.help').text()).toContain("can't remove their own admin role")
  })
})

describe('AccountView', () => {
  function accountPage(user: User) {
    useAuthStore().user = user
    return mount(AccountView, { global: { stubs } })
  }

  it('tells an admin to ask another admin, and offers no deleting', () => {
    const wrapper = accountPage(me)

    expect(wrapper.text()).toContain("An admin can't delete their own account")
    expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Delete my account')
  })

  it('offers anyone else to delete theirs', () => {
    const wrapper = accountPage(member)

    expect(wrapper.text()).not.toContain("An admin can't delete their own account")
    expect(wrapper.findAll('button').map((b) => b.text())).toContain('Delete my account')
  })
})
