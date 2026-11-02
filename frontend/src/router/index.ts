import { createRouter, createWebHistory } from 'vue-router'
import type { RouteLocationNormalized } from 'vue-router'
import { useGalleryStore } from '../stores/gallery'

/**
 * Which navbar controls a route supports.
 *
 * `showBlur`    — the page renders media, so the blur toggle does something.
 * `showViewMode`— the page is a paginated media list, so items-per-page and
 *                 continuous scrolling apply.
 *
 * These are declared per route rather than derived from a chain of negations in
 * the navbar, which is how the per-page select and infinite-scroll toggle ended
 * up live on /favorites (no pagination) and on the 404 page.
 */
declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    showBlur?: boolean
    showViewMode?: boolean
  }
}

const isMediaGrid = (r: RouteLocationNormalized) =>
  r.name === 'media' || r.name === 'media-with-tags'

const router = createRouter({
  history: createWebHistory('/'),
  routes: [
    {
      path: '/',
      redirect: '/media/1/40',
    },
    {
      path: '/media/:page?/:perPage?',
      name: 'media',
      component: () => import('../views/GalleryView.vue'),
      meta: { title: 'Media', showBlur: true, showViewMode: true },
      props: (route) => {
        const pp = Number(route.params.perPage)
        return {
          page: Number(route.params.page) || 1,
          perPage: isNaN(pp) || pp < 1 ? 40 : pp,
        }
      },
    },
    {
      path: '/media/:page/:perPage/with-tags/:tags',
      name: 'media-with-tags',
      component: () => import('../views/GalleryView.vue'),
      meta: { title: 'Media', showBlur: true, showViewMode: true },
      props: (route) => {
        const pp = Number(route.params.perPage)
        return {
          page: Number(route.params.page) || 1,
          perPage: isNaN(pp) || pp < 1 ? 40 : pp,
          tags: route.params.tags as string,
        }
      },
    },
    {
      path: '/media/:id/tags',
      name: 'media-tags',
      component: () => import('../views/MediaTagsView.vue'),
      meta: { title: 'Media Tags', showBlur: true },
      props: (route) => ({
        mediaId: Number(route.params.id),
      }),
    },
    {
      // Same detail view, but reached via Random — the `/random/` path segment
      // (rather than a query string) records that provenance for the navbar and
      // breadcrumb.
      path: '/random/media/:id/tags',
      name: 'media-random',
      component: () => import('../views/MediaTagsView.vue'),
      meta: { title: 'Random Media', showBlur: true },
      props: (route) => ({
        mediaId: Number(route.params.id),
      }),
    },
    {
      path: '/tags',
      name: 'tags',
      meta: { title: 'Tags' },
      component: () => import('../views/TagListView.vue'),
    },
    {
      path: '/tags/categories',
      name: 'tag-categories',
      meta: { title: 'Tag Categories' },
      component: () => import('../views/TagCategoriesView.vue'),
    },
    {
      path: '/tags/danbooru-rules',
      name: 'danbooru-rules',
      meta: { title: 'Danbooru Import Rules' },
      component: () => import('../views/DanbooruRulesView.vue'),
    },
    {
      path: '/tags/:tagId',
      name: 'tag-implications',
      meta: { title: 'Tag Implications' },
      component: () => import('../views/TagImplicationsView.vue'),
      props: (route) => ({
        tagId: Number(route.params.tagId),
      }),
    },
    {
      path: '/favorites',
      name: 'favorites',
      // Renders media (so blur applies) but has no pagination — which is why
      // the items-per-page select never belonged here.
      meta: { title: 'Favorites', showBlur: true },
      component: () => import('../views/FavoritesView.vue'),
    },
    {
      path: '/upload',
      name: 'upload',
      meta: { title: 'Upload' },
      component: () => import('../views/UploadView.vue'),
    },
    {
      path: '/duplicates',
      name: 'duplicates',
      // Side-by-side comparison images honour `blurThumbnails`, but the old
      // negation chain lumped this in with "admin utility" and hid the toggle.
      meta: { title: 'Duplicates', showBlur: true },
      component: () => import('../views/DuplicatesView.vue'),
    },
    {
      path: '/login',
      name: 'login',
      meta: { title: 'Admin Login' },
      component: () => import('../views/LoginView.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      meta: { title: 'Not Found' },
      component: () => import('../views/NotFoundView.vue'),
    },
  ],
  // Deprecated in vue-router 5.4 (2026-11), but its replacement, ScrollRestoration,
  // still lives in `vue-router/experimental`. Move once that's stable.
  // eslint-disable-next-line @typescript-eslint/no-deprecated
  scrollBehavior(to, _from, savedPosition) {
    // The infinite-scroll gallery manages its own scroll: it syncs the URL to
    // the position while scrolling and restores the position manually on return
    // (its DOM is kept alive). So never auto-scroll when landing on it.
    if (isMediaGrid(to) && useGalleryStore().infiniteScroll) return false
    // Everywhere else: restore on back/forward, otherwise go to the top.
    if (savedPosition) return savedPosition
    return { top: 0, behavior: 'smooth' }
  },
})

// Set document title from route meta
router.afterEach((to) => {
  // `meta.title` is typed by the RouteMeta declaration above, so no cast.
  document.title = to.meta.title ? `Gallery - ${to.meta.title}` : 'Gallery'
})

export default router
