<?php

declare(strict_types=1);

use App\Models\Media;
use Tests\Support\Contract\DanbooruStub;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withHeaders;

// What API 5 answers where API 4's recordings say otherwise (the cases
// divergences.php supersedes point here), and what it added: real 404s,
// partial edits, every validation error, logout, a media item with its tags,
// ETags, cursors, and writes that clear the cached lists themselves.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
});

test('a path id with no record behind it is a 404 naming what is missing', function (): void {
    $admin = adminHeaders();

    getJson('/api/media/999')->assertNotFound()->assertJson(['error' => 'MediaNotFound']);
    getJson('/api/media/0')->assertNotFound()->assertJson(['error' => 'MediaNotFound']);
    getJson('/api/media/999/tags')->assertNotFound()->assertJson(['error' => 'MediaNotFound']);
    getJson('/api/tags/999')->assertNotFound()->assertJson(['error' => 'TagNotFound', 'message' => 'The tag could not be found.']);
    withHeaders($admin)->deleteJson('/api/media/1/tags/999')->assertNotFound()->assertJson(['error' => 'TagNotFound']);
    withHeaders($admin)->deleteJson('/api/tags/5/migrate-to/999')->assertNotFound()->assertJson(['error' => 'TagNotFound']);
    withHeaders($admin)->patchJson('/api/tag-categories/999', ['color' => 'sky'])->assertNotFound()->assertJson(['error' => 'CategoryNotFound']);
    withHeaders($admin)->deleteJson('/api/danbooru/category-mappings/99')->assertNotFound()->assertJson(['error' => 'MappingNotFound']);
    withHeaders($admin)->putJson('/api/danbooru/tag-mappings/999', ['danbooru_tag' => 'a', 'gallery_tag' => 'b'])->assertNotFound()->assertJson(['error' => 'MappingNotFound']);
    withHeaders($admin)->deleteJson('/api/tag-implications/8/1')->assertNotFound()->assertExactJson(['error' => 'ImplicationNotFound', 'message' => '"smile" does not imply "animal".']);
});

test('an id that isn\'t digits matches no route: a plain 404', function (): void {
    getJson('/api/media/abc')->assertNotFound()->assertExactJson(['error' => 'NotFound', 'message' => 'The requested endpoint does not exist.']);
    getJson('/api/tags/-1')->assertNotFound()->assertJson(['error' => 'NotFound']);
});

test('an edit changes only the fields it sends, by PATCH or PUT', function (): void {
    $admin = adminHeaders();

    withHeaders($admin)->patchJson('/api/tags/13', ['tag_name' => 'artist uno'])
        ->assertOk()
        ->assertExactJson(['tag_id' => 13, 'category_id' => 2, 'tag_name' => 'artist uno']);
    withHeaders($admin)->putJson('/api/tags/13', ['category_id' => 3])
        ->assertOk()
        ->assertExactJson(['tag_id' => 13, 'category_id' => 3, 'tag_name' => 'artist uno']);

    withHeaders($admin)->patchJson('/api/tag-categories/2', ['color' => 'violet'])
        ->assertOk()
        ->assertJson(['category_name' => 'Artist', 'color' => 'violet', 'description' => 'The artist who created this specific piece.', 'sort_order' => 1]);
});

test('a new tag or category still needs what it always did', function (): void {
    $admin = adminHeaders();

    withHeaders($admin)->postJson('/api/tags', [])->assertStatus(400)->assertJson(['error' => 'InvalidTagName']);
    withHeaders($admin)->postJson('/api/tags', ['tag_name' => 'brand new'])->assertCreated()->assertJson(['category_id' => 1]);
});

test('a validation failure lists every broken field, with the first one\'s code', function (): void {
    withHeaders(adminHeaders())->postJson('/api/tag-categories', ['category_name' => str_repeat('x', 51), 'color' => 'puce'])
        ->assertStatus(400)
        ->assertExactJson([
            'error' => 'NameTooLong',
            'message' => 'Category name must be 50 characters or fewer.',
            'errors' => [
                'category_name' => ['Category name must be 50 characters or fewer.'],
                'color' => ['The selected color is not valid.'],
            ],
        ]);
});

test('logging out revokes the token at once', function (): void {
    $admin = adminHeaders();

    withHeaders($admin)->postJson('/api/tags', ['tag_name' => 'before logout'])->assertCreated();
    withHeaders($admin)->deleteJson('/api/auth/token')->assertNoContent();
    // A test's requests share one app, whose guard remembers the user; on the
    // server each request starts afresh.
    app('auth')->forgetGuards();
    withHeaders($admin)->postJson('/api/tags', ['tag_name' => 'after logout'])->assertUnauthorized();
});

test('a media item comes with its tags, in display order, when asked', function (): void {
    $withTags = getJson('/api/media/1?include=tags')->assertOk();

    expect(array_column($withTags->json('tags'), 'tag_name'))->toBe(['animal', 'cat', 'outdoors', 'artist one', 'hero'])
        ->and($withTags->json('media_id'))->toBe(1)
        ->and(array_key_exists('tags', (array) getJson('/api/media/1')->json()))->toBeFalse();
});

test('a read answers with an ETag, and 304 while it is unchanged', function (): void {
    $first = getJson('/api/tags')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toStartWith('"');
    withHeaders(['If-None-Match' => $etag])->getJson('/api/tags')->assertStatus(304)->assertNoContent(304);

    withHeaders(adminHeaders())->postJson('/api/tags', ['tag_name' => 'zebra'])->assertCreated();
    $changed = withHeaders(['If-None-Match' => $etag])->getJson('/api/tags')->assertOk();
    expect($changed->headers->get('ETag') === $etag)->toBeFalse();
});

test('the Danbooru import no longer sends every tag in the gallery back', function (): void {
    config(['gallery.danbooru.login' => 'contract', 'gallery.danbooru.api_key' => 'contract-key', 'gallery.url' => 'https://gallery.test']);
    DanbooruStub::install(up: true);

    $response = withHeaders(adminHeaders())->postJson('/api/media/1/danbooru-tags')->assertOk();

    expect(array_keys($response->json()))->toBe(['tags', 'method', 'tags_applied', 'tags_created']);
});

test('writes clear the cached lists themselves', function (): void {
    config(['gallery.allowed_origins' => ['https://gallery.test']]);
    getJson('/api/tags')->assertHeader('X-Cache', 'MISS');
    getJson('/api/tags')->assertHeader('X-Cache', 'HIT');
    getJson('/api/media/with-tags/sidekick/1')->assertHeader('X-Cache', 'MISS')->assertJsonCount(3, 'items');
    getJson('/api/media/with-tags/sidekick/1')->assertHeader('X-Cache', 'HIT');

    // Tagging is public, and the Media model clears both lists.
    withHeaders(['Origin' => 'https://gallery.test'])->patchJson('/api/media/1/tags', ['tag_ids' => [16]])->assertOk();

    getJson('/api/tags')->assertHeader('X-Cache', 'MISS');
    getJson('/api/media/with-tags/sidekick/1')->assertHeader('X-Cache', 'MISS')->assertJsonCount(4, 'items');
});

test('a page gives the cursor to go on from, which items added meanwhile don\'t throw off', function (): void {
    $first = getJson('/api/media/page/1/10')->assertOk();
    $secondBefore = array_column(getJson('/api/media/page/2/10')->json('items'), 'media_id');
    $cursor = $first->json('next_cursor');
    expect($cursor)->toBeString();

    // A new item lands at the top, pushing every numbered page down by one.
    Media::factory()->create(['file_time' => 1900000000]);
    expect(array_column(getJson('/api/media/page/2/10')->json('items'), 'media_id') === $secondBefore)->toBeFalse();

    $next = getJson('/api/media?cursor=' . urlencode((string) $cursor) . '&per_page=10')->assertOk();
    expect(array_column($next->json('items'), 'media_id'))->toBe($secondBefore)
        ->and($next->json('next_cursor'))->toBeString()
        ->and(getJson('/api/media/page/4/10')->json('next_cursor'))->toBeNull();
});

test('a cursor works within a tag search, and a bad one is refused', function (): void {
    $all = array_column(getJson('/api/media/with-tags/cat/1/40')->json('items'), 'media_id');

    $start = getJson('/api/media?tags=cat&per_page=3')->assertOk();
    $rest = getJson('/api/media?tags=cat&per_page=40&cursor=' . urlencode((string) $start->json('next_cursor')))->assertOk();

    expect([...array_column($start->json('items'), 'media_id'), ...array_column($rest->json('items'), 'media_id')])->toBe($all)
        ->and($rest->json('next_cursor'))->toBeNull();
    getJson('/api/media?cursor=not-a-cursor')->assertStatus(400)->assertJson(['error' => 'InvalidCursor']);
    expect(getJson('/api/media?untagged=1&per_page=40')->json('items'))->toBe(getJson('/api/media/untagged/1/40')->json('items'));
});
