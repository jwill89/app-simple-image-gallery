<?php

declare(strict_types=1);

use App\Models\Tag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

// Finding tags on the server, so the app never needs all of them at once:
// GET /tags searches, looks up by name or id, and still lists everything when
// asked nothing; GET /tags/display is a page of the management list.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
});

/**
 * @param TestResponse<Response> $response
 * @return list<string>
 */
function tagNames(TestResponse $response, string $key = ''): array
{
    /** @var list<array{tag_name: string}> $tags */
    $tags = $key === '' ? $response->json() : $response->json($key);

    return array_column($tags, 'tag_name');
}

test('a search ranks the name itself, then names starting with it, then the rest', function (): void {
    Tag::query()->create(['tag_name' => 'catalog', 'category_id' => 1]);
    Tag::query()->create(['tag_name' => 'bobcat', 'category_id' => 1]);

    expect(tagNames(getJson('/api/tags?search=cat')->assertOk()))->toBe(['cat', 'catalog', 'bobcat'])
        ->and(tagNames(getJson('/api/tags?search=CAT&limit=2')))->toBe(['cat', 'catalog'])
        ->and(tagNames(getJson('/api/tags?search=cat&exclude=3,99')))->toBe(['catalog', 'bobcat']);
});

test('a search matches LIKE\'s wildcards as themselves', function (): void {
    Tag::query()->create(['tag_name' => '100%', 'category_id' => 1]);
    Tag::query()->create(['tag_name' => 'snake_case', 'category_id' => 1]);

    expect(tagNames(getJson('/api/tags?search=%')))->toBe(['100%'])
        ->and(tagNames(getJson('/api/tags?search=_')))->toBe(['snake_case']);
});

test('an empty search lists the first tags by name', function (): void {
    expect(tagNames(getJson('/api/tags?search=&limit=3')))->toBe(['animal', 'artist one', 'artist two']);
});

test('tags are looked up by name, in any case, or by id', function (): void {
    expect(tagNames(getJson('/api/tags?names=CAT,Dog,no-such-tag')))->toBe(['cat', 'dog'])
        ->and(tagNames(getJson('/api/tags?ids=4,3,999')))->toBe(['cat', 'dog'])
        ->and(getJson('/api/tags')->json())->toHaveCount(16);
});

test('the management list is a page of tags, most used first', function (): void {
    $page = getJson('/api/tags/display?per_page=3')->assertOk();

    expect(tagNames($page, 'items'))->toBe(['animal', 'cat', 'outdoors'])
        ->and($page->json('items.0'))->toBe(['tag_id' => 1, 'tag_name' => 'animal', 'category_id' => 1, 'category_name' => 'General', 'media_count' => 11, 'implication_count' => 0])
        ->and($page->json('total'))->toBe(16)
        ->and($page->json('current_page'))->toBe(1)
        ->and($page->json('total_pages'))->toBe(6);
});

test('the management list searches tag and category names, sorts, and picks ids', function (): void {
    expect(tagNames(getJson('/api/tags/display?search=artist&sort=tag_name&direction=asc'), 'items'))->toBe(['artist one', 'artist two'])
        ->and(tagNames(getJson('/api/tags/display?search=character&sort=tag_name&direction=desc'), 'items'))->toBe(['sidekick', 'hero'])
        ->and(tagNames(getJson('/api/tags/display?ids=5,3&sort=tag_name&direction=asc'), 'items'))->toBe(['cat', 'kitten'])
        ->and(getJson('/api/tags/display?ids=5')->json('items.0.implication_count'))->toBe(1)
        ->and(getJson('/api/tags/display?search=nothing-like-it')->json())->toBe(['items' => [], 'total' => 0, 'current_page' => 1, 'total_pages' => 1]);
});
