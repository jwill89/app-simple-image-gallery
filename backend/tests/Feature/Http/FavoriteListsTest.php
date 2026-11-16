<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;

use function Pest\Laravel\travel;

// An account's lists of media: the default "Favorites" (the heart on a card),
// any others it makes, and how a list pages, imports and stays private.

/**
 * The account's default list's id, made on first read.
 */
function defaultListId(User $reader): int
{
    return (int) asAccount($reader)->getJson('/api/lists')->json('0.listId');
}

test('every account has a Favorites list, first, and can make, rename and delete others', function (): void {
    $reader = User::factory()->create();

    asAccount($reader)->getJson('/api/lists')->assertOk()->assertExactJson([
        ['listId' => 1, 'name' => 'Favorites', 'isDefault' => true, 'mediaCount' => 0],
    ]);

    $id = asAccount($reader)->postJson('/api/lists', ['name' => '  Wallpapers  '])->assertCreated()
        ->assertJsonPath('name', 'Wallpapers')->assertJsonPath('mediaCount', 0)->json('listId');
    asAccount($reader)->postJson('/api/lists', ['name' => 'wallpapers'])->assertStatus(409)->assertJson(['type' => problemType('ListExists')]);
    asAccount($reader)->postJson('/api/lists', ['name' => ''])->assertStatus(422)->assertJson(['type' => problemType('InvalidListName')]);
    asAccount($reader)->patchJson("/api/lists/{$id}", ['name' => 'Backgrounds'])->assertOk()->assertJsonPath('name', 'Backgrounds');
    asAccount($reader)->patchJson('/api/lists/1', ['name' => 'Best'])->assertOk()->assertJsonPath('isDefault', true);
    asAccount($reader)->deleteJson('/api/lists/1')->assertStatus(409)->assertJson(['type' => problemType('DefaultList')]);
    asAccount($reader)->deleteJson("/api/lists/{$id}")->assertNoContent();

    asAccount($reader)->getJson('/api/lists')->assertJsonCount(1)->assertJsonPath('0.name', 'Best');
});

test('media goes in and out of a list, and a list pages the latest added first', function (): void {
    $reader = User::factory()->create();
    $list = defaultListId($reader);
    [$first, $second, $third] = Media::factory()->count(3)->create()->all();

    foreach ([$first, $second, $third] as $media) {
        travel(1)->minutes();
        asAccount($reader)->putJson("/api/lists/{$list}/media/{$media->media_id}")->assertNoContent();
    }
    asAccount($reader)->putJson("/api/lists/{$list}/media/{$first->media_id}")->assertNoContent();
    asAccount($reader)->deleteJson("/api/lists/{$list}/media/{$second->media_id}")->assertNoContent();

    expect(asAccount($reader)->getJson("/api/lists/{$list}/ids")->assertOk()->json())
        ->toEqualCanonicalizing([$first->media_id, $third->media_id]);
    asAccount($reader)->getJson("/api/lists/{$list}/media?limit=1")->assertOk()
        ->assertJsonPath('0.mediaId', $third->media_id)
        ->assertJsonCount(1)
        ->assertHeader('X-Total-Count', '2');
    asAccount($reader)->getJson("/api/lists/{$list}/media?limit=1&offset=1")->assertOk()->assertJsonPath('0.mediaId', $first->media_id);
    asAccount($reader)->getJson("/api/lists/{$list}/media?limit=101")->assertUnprocessable()->assertJsonPath('errors.0.location', 'query.limit');
    asAccount($reader)->getJson("/api/media/{$first->media_id}/lists")->assertOk()->assertExactJson([$list]);
    asAccount($reader)->getJson('/api/lists')->assertJsonPath('0.mediaCount', 2);
    asAccount($reader)->putJson("/api/lists/{$list}/media/999")->assertNotFound()->assertJson(['type' => problemType('MediaNotFound')]);
});

test('many media can be added at once, unknown ids skipped', function (): void {
    $reader = User::factory()->create();
    $list = defaultListId($reader);
    $ids = Media::factory()->count(3)->create()->pluck('media_id')->all();
    asAccount($reader)->putJson("/api/lists/{$list}/media/{$ids[0]}")->assertNoContent();

    asAccount($reader)->postJson("/api/lists/{$list}/media", ['mediaIds' => [...$ids, 999, -1, 'x']])
        ->assertOk()->assertExactJson(['added' => 2]);
    asAccount($reader)->postJson("/api/lists/{$list}/media", ['mediaIds' => range(1, 2001)])
        ->assertStatus(422)->assertJson(['type' => problemType('InvalidInput')]);
});

test('a list hides media carrying a blocked tag, and loses media that is deleted', function (): void {
    $user = User::factory()->create();
    $list = defaultListId($user);
    [$shown, $blocked] = Media::factory()->count(2)->create()->all();
    $tag = Tag::factory()->create();
    $blocked->attachTags([$tag->tag_id]);
    $user->blockedTags()->attach($tag->tag_id);
    asAccount($user)->postJson("/api/lists/{$list}/media", ['mediaIds' => [$shown->media_id, $blocked->media_id]])->assertOk();

    asAccount($user)->getJson("/api/lists/{$list}/media")->assertJsonCount(1)->assertJsonPath('0.mediaId', $shown->media_id)->assertHeader('X-Total-Count', '1');

    $shown->delete();
    asAccount($user)->getJson("/api/lists/{$list}/ids")->assertExactJson([$blocked->media_id]);
});

test('someone else\'s list is as missing as a deleted one', function (string $method, string $path): void {
    $list = User::factory()->create()->defaultList()->list_id;
    $media = Media::factory()->create()->media_id;

    asAccount()->json($method, str_replace(['{list}', '{media}'], [(string) $list, (string) $media], $path), ['name' => 'Mine now'])
        ->assertNotFound()->assertJson(['type' => problemType('ListNotFound')]);
})->with([
    ['PATCH', '/api/lists/{list}'],
    ['DELETE', '/api/lists/{list}'],
    ['GET', '/api/lists/{list}/ids'],
    ['GET', '/api/lists/{list}/media'],
    ['PUT', '/api/lists/{list}/media/{media}'],
    ['DELETE', '/api/lists/{list}/media/{media}'],
]);
