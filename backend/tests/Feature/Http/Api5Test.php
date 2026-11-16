<?php

declare(strict_types=1);

use App\Models\Media;
use Tests\Support\Contract\DanbooruStub;
use Tests\Support\ContractFixtureSeeder;
use Tests\Support\TestBrowser;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withHeaders;

// What the app answers where API 4's recordings say otherwise (the cases
// divergences.php supersedes point here), and what API 5 brought: real 404s,
// partial edits, every validation error, logout, a media item with its tags,
// ETags, cursors, and writes that clear the cached lists themselves.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
});

test('a path id with no record behind it is a 404 naming what is missing', function (): void {
    getJson('/api/media/999')->assertNotFound()->assertJson(['type' => problemType('MediaNotFound')]);
    getJson('/api/media/0')->assertNotFound()->assertJson(['type' => problemType('MediaNotFound')]);
    getJson('/api/media/999/tags')->assertNotFound()->assertJson(['type' => problemType('MediaNotFound')]);
    getJson('/api/tags/999')->assertNotFound()->assertJson(['type' => problemType('TagNotFound'), 'detail' => 'The tag could not be found.']);
    asAdmin()->deleteJson('/api/media/1/tags/999')->assertNotFound()->assertJson(['type' => problemType('TagNotFound')]);
    asAdmin()->deleteJson('/api/tags/5/migrate-to/999')->assertNotFound()->assertJson(['type' => problemType('TagNotFound')]);
    asAdmin()->patchJson('/api/tag-categories/999', ['color' => 'sky'])->assertNotFound()->assertJson(['type' => problemType('CategoryNotFound')]);
    asAdmin()->deleteJson('/api/danbooru/category-mappings/99')->assertNotFound()->assertJson(['type' => problemType('MappingNotFound')]);
    asAdmin()->patchJson('/api/danbooru/tag-mappings/999', ['danbooruTag' => 'a', 'galleryTag' => 'b'])->assertNotFound()->assertJson(['type' => problemType('MappingNotFound')]);
    asAdmin()->deleteJson('/api/tag-implications/8/1')->assertNotFound()->assertExactJson([
        'type' => problemType('ImplicationNotFound'),
        'title' => 'Not Found',
        'status' => 404,
        'detail' => '"smile" does not imply "animal".',
    ]);
});

test('an id that isn\'t digits matches no route: a plain 404', function (): void {
    getJson('/api/media/abc')->assertNotFound()->assertExactJson(['type' => problemType('NotFound'), 'title' => 'Not Found', 'status' => 404, 'detail' => 'The requested endpoint does not exist.']);
    getJson('/api/tags/-1')->assertNotFound()->assertJson(['type' => problemType('NotFound')]);
});

test('an edit changes only the fields it sends', function (): void {
    asAdmin()->patchJson('/api/tags/13', ['tagName' => 'artist uno'])
        ->assertOk()
        ->assertExactJson(['tagId' => 13, 'categoryId' => 2, 'tagName' => 'artist uno']);
    asAdmin()->patchJson('/api/tags/13', ['categoryId' => 3])
        ->assertOk()
        ->assertExactJson(['tagId' => 13, 'categoryId' => 3, 'tagName' => 'artist uno']);

    asAdmin()->patchJson('/api/tag-categories/2', ['color' => 'violet'])
        ->assertOk()
        ->assertJson(['categoryName' => 'Artist', 'color' => 'violet', 'description' => 'The artist who created this specific piece.', 'sortOrder' => 1]);
    asAdmin()->patchJson('/api/tag-categories/2', ['sortOrder' => 7])
        ->assertOk()
        ->assertJson(['categoryName' => 'Artist', 'color' => 'violet', 'sortOrder' => 7]);

    asAdmin()->patchJson('/api/danbooru/tag-mappings/1', ['galleryTag' => 'renamed'])
        ->assertOk()
        ->assertJson(['id' => 1, 'galleryTag' => 'renamed']);
});

test('a PUT that would only edit some fields is no route: PATCH is the edit', function (string $path): void {
    asAdmin()->putJson($path, [])->assertStatus(405)->assertJson(['type' => problemType('MethodNotAllowed')]);
})->with(['/api/tags/13', '/api/tag-categories/2', '/api/danbooru/tag-mappings/1']);

test('a new tag or category still needs what it always did', function (): void {
    asAdmin()->postJson('/api/tags', [])->assertUnprocessable()->assertJson(['type' => problemType('InvalidTagName')]);
    asAdmin()->postJson('/api/tags', ['tagName' => 'brand new'])->assertCreated()->assertJson(['categoryId' => 1]);
    asAdmin()->postJson('/api/tags', ['tagName' => 'Brand New'])->assertConflict()->assertJson(['type' => problemType('TagAlreadyExists')]);
});

test('a validation failure lists every broken field, with the first one\'s code', function (): void {
    asAdmin()->postJson('/api/tag-categories', ['categoryName' => str_repeat('x', 51), 'color' => 'puce'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => problemType('NameTooLong'),
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'Category name must be 50 characters or fewer.',
            'errors' => [
                ['message' => 'Category name must be 50 characters or fewer.', 'location' => 'body.categoryName'],
                ['message' => 'The selected color is not valid.', 'location' => 'body.color'],
            ],
        ]);
});

test('logging out signs the browser out at once', function (): void {
    adminUser()->update(['password' => 'long-enough']);
    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'long-enough'])->assertOk();

    $browser->send('POST', '/api/tags', ['tagName' => 'before logout'])->assertCreated();
    $browser->send('DELETE', '/api/auth/session')->assertNoContent();
    $browser->send('POST', '/api/tags', ['tagName' => 'after logout'])->assertUnauthorized();
});

test('a media item comes with its tags, in display order, when asked', function (): void {
    $withTags = getJson('/api/media/1?include=tags')->assertOk();

    expect(array_column($withTags->json('tags'), 'tagName'))->toBe(['animal', 'cat', 'outdoors', 'artist one', 'hero'])
        ->and($withTags->json('mediaId'))->toBe(1)
        ->and($withTags->json('fileTime'))->toBe('2026-01-01T00:00:00Z')
        ->and(array_key_exists('tags', (array) getJson('/api/media/1')->json()))->toBeFalse();
    getJson('/api/media/1?include=lists')->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.include');
});

test('a read answers with an ETag, and 304 while it is unchanged', function (): void {
    $first = getJson('/api/tags')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toStartWith('"');
    withHeaders(['If-None-Match' => $etag])->getJson('/api/tags')->assertStatus(304)->assertNoContent(304);

    asAdmin()->postJson('/api/tags', ['tagName' => 'zebra'])->assertCreated();
    $changed = withHeaders(['If-None-Match' => $etag])->getJson('/api/tags')->assertOk();
    expect($changed->headers->get('ETag') === $etag)->toBeFalse();
});

test('the Danbooru import no longer sends every tag in the gallery back', function (): void {
    config(['gallery.danbooru.login' => 'contract', 'gallery.danbooru.api_key' => 'contract-key', 'gallery.url' => 'https://gallery.test']);
    DanbooruStub::install(up: true);

    $response = asAdmin()->postJson('/api/media/1/danbooru-tags')->assertOk();

    expect(array_keys($response->json()))->toBe(['tags', 'method', 'tagsApplied', 'tagsCreated']);
});

test('writes clear the cached lists themselves', function (): void {
    config(['gallery.allowed_origins' => ['https://gallery.test']]);
    getJson('/api/tags')->assertHeader('X-Cache', 'MISS');
    getJson('/api/tags')->assertHeader('X-Cache', 'HIT');
    getJson('/api/media?tags=sidekick')->assertHeader('X-Cache', 'MISS')->assertHeader('X-Total-Count', '3')->assertJsonCount(3, 'items');
    getJson('/api/media?tags=sidekick')->assertHeader('X-Cache', 'HIT')->assertHeader('X-Total-Count', '3');

    // Tagging is public, and the Media model clears both lists.
    withHeaders(['Origin' => 'https://gallery.test'])->patchJson('/api/media/1/tags', ['tagIds' => [16]])->assertOk();

    getJson('/api/tags')->assertHeader('X-Cache', 'MISS');
    getJson('/api/media?tags=sidekick')->assertHeader('X-Cache', 'MISS')->assertHeader('X-Total-Count', '4')->assertJsonCount(4, 'items');
});

test('a page gives the cursor to go on from, which items added meanwhile don\'t throw off', function (): void {
    $first = getJson('/api/media?limit=10')->assertOk()->assertHeader('X-Total-Count', '30');
    $secondBefore = array_column(getJson('/api/media?limit=10&offset=10')->json('items'), 'mediaId');
    $cursor = $first->json('nextCursor');
    expect($cursor)->toBeString();

    // A new item lands at the top, pushing every numbered page down by one.
    Media::factory()->create(['file_time' => '2030-03-17T17:46:40Z']);
    expect(array_column(getJson('/api/media?limit=10&offset=10')->json('items'), 'mediaId') === $secondBefore)->toBeFalse();

    $next = getJson('/api/media?cursor=' . urlencode((string) $cursor) . '&limit=10')->assertOk()->assertHeaderMissing('X-Total-Count');
    expect(array_column($next->json('items'), 'mediaId'))->toBe($secondBefore)
        ->and($next->json('nextCursor'))->toBeString()
        ->and(getJson('/api/media?limit=10&offset=30')->json('nextCursor'))->toBeNull();
});

test('a cursor works within a tag search, and a bad one is refused', function (): void {
    $all = array_column(getJson('/api/media?tags=cat&limit=40')->json('items'), 'mediaId');

    $start = getJson('/api/media?tags=cat&limit=3')->assertOk();
    $rest = getJson('/api/media?tags=cat&limit=40&cursor=' . urlencode((string) $start->json('nextCursor')))->assertOk();

    expect([...array_column($start->json('items'), 'mediaId'), ...array_column($rest->json('items'), 'mediaId')])->toBe($all)
        ->and($rest->json('nextCursor'))->toBeNull();
    getJson('/api/media?cursor=not-a-cursor')->assertUnprocessable()->assertJson(['type' => problemType('InvalidCursor')])->assertJsonPath('errors.0.location', 'query.cursor');
    expect(getJson('/api/media?untagged=1&limit=40')->json('items'))->toBe(getJson('/api/media?untagged=true&limit=40')->json('items'))
        ->and(getJson('/api/media?untagged=true')->json('items'))->toHaveCount(4);
});
