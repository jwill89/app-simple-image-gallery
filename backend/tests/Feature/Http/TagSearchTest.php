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
// asked nothing; with include=counts, sort and filter it is the management list.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
});

/**
 * @param TestResponse<Response> $response
 * @return list<string>
 */
function tagNames(TestResponse $response, string $key = ''): array
{
    /** @var list<array{tagName: string}> $tags */
    $tags = $key === '' ? $response->json() : $response->json($key);

    return array_column($tags, 'tagName');
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
        ->and(getJson('/api/tags')->assertHeader('X-Total-Count', '16')->json())->toHaveCount(16);
});

test('a lookup or a page asks for at most 100, and anything over is refused', function (): void {
    getJson('/api/tags?ids=' . implode(',', range(1, 101)))->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.ids');
    getJson('/api/tags?names=' . implode(',', array_fill(0, 101, 'cat')))->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.names');
    getJson('/api/tags?limit=101')->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.limit');
    getJson('/api/tags?limit=0')->assertUnprocessable();
    getJson('/api/tags?offset=-1')->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.offset');
    getJson('/api/tags?ids=1,x')->assertUnprocessable()->assertJsonPath('type', problemType('InvalidInput'));
    getJson('/api/tags?sort=popularity')->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.sort');
    getJson('/api/tags?search=cat&sort=tagName')->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.search');
    getJson('/api/tags?include=everything')->assertUnprocessable();
});

test('a page of tags with their counts, most used first, and the total in X-Total-Count', function (): void {
    $page = getJson('/api/tags?include=counts&sort=mediaCount&direction=desc&limit=3')->assertOk()->assertHeader('X-Total-Count', '16');

    expect(tagNames($page))->toBe(['animal', 'cat', 'outdoors'])
        ->and($page->json('0'))->toBe(['tagId' => 1, 'categoryId' => 1, 'tagName' => 'animal', 'mediaCount' => 11, 'implicationCount' => 0])
        ->and(tagNames(getJson('/api/tags?include=counts&sort=mediaCount&direction=desc&limit=3&offset=3')))->toHaveCount(3)
        ->and(getJson('/api/tags?sort=mediaCount&direction=desc&limit=1')->json('0'))->toBe(['tagId' => 1, 'categoryId' => 1, 'tagName' => 'animal']);
});

test('the list filters on tag and category names, sorts, and picks ids', function (): void {
    expect(tagNames(getJson('/api/tags?filter=artist&sort=tagName&direction=asc')))->toBe(['artist one', 'artist two'])
        ->and(tagNames(getJson('/api/tags?filter=character&sort=tagName&direction=desc')))->toBe(['sidekick', 'hero'])
        ->and(tagNames(getJson('/api/tags?filter=GENERAL&sort=categoryName&limit=2')))->toBe(['animal', 'blue sky'])
        ->and(tagNames(getJson('/api/tags?ids=5,3&sort=tagName&direction=asc')))->toBe(['cat', 'kitten'])
        ->and(getJson('/api/tags?ids=5&include=counts')->json('0.implicationCount'))->toBe(1)
        ->and(getJson('/api/tags?filter=nothing-like-it')->assertHeader('X-Total-Count', '0')->json())->toBe([]);
});
