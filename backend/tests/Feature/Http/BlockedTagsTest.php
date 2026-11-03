<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestBrowser;

// Blocked tags: media carrying one leaves every listing, count and random pick
// of the account that blocks it, and of no one else; a single read still
// answers (the app asks before showing it).

/**
 * An account, and three media: one tagged "cats", one tagged "cats" and
 * "spiders", and one untagged, newest first.
 *
 * @return array{user: User, spiders: Tag, shown: Media, hidden: Media, untagged: Media}
 */
function blockedTagsScene(): array
{
    $user = User::factory()->create();
    $spiders = Tag::factory()->create(['tag_name' => 'spiders']);
    $cats = Tag::factory()->create(['tag_name' => 'cats']);
    $shown = Media::factory()->create(['file_time' => 200]);
    $shown->attachTags([$cats->tag_id]);
    $hidden = Media::factory()->create(['file_time' => 100]);
    $hidden->attachTags([$spiders->tag_id, $cats->tag_id]);
    $untagged = Media::factory()->create(['file_time' => 50]);

    return ['user' => $user, 'spiders' => $spiders, 'shown' => $shown, 'hidden' => $hidden, 'untagged' => $untagged];
}

/**
 * The media ids a listing answered, in order.
 *
 * @param TestResponse<Response> $response
 * @return list<int>
 */
function mediaIdsIn(TestResponse $response, string $path = 'items'): array
{
    return array_values(array_map(static fn(array $item): int => (int) $item['media_id'], (array) $response->assertOk()->json($path)));
}

test('blocking a tag is listed, and doing it twice changes nothing', function (): void {
    ['user' => $reader, 'spiders' => $spiders] = blockedTagsScene();

    asAccount($reader)->putJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    asAccount($reader)->putJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    asAccount($reader)->putJson('/api/account/blocked-tags/999')->assertNotFound()->assertJson(['error' => 'TagNotFound']);

    asAccount($reader)->getJson('/api/account/blocked-tags')->assertOk()
        ->assertExactJson([['tag_id' => $spiders->tag_id, 'category_id' => $spiders->category_id, 'tag_name' => 'spiders']]);

    asAccount($reader)->deleteJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    asAccount($reader)->getJson('/api/account/blocked-tags')->assertExactJson([]);
});

test('every listing leaves out what the account blocks, and only for that account', function (): void {
    ['user' => $reader, 'spiders' => $spiders, 'shown' => $shown, 'hidden' => $hidden, 'untagged' => $untagged] = blockedTagsScene();

    // Read once signed out first, so a cached answer would show if keys were shared.
    expect(mediaIdsIn(signedOut()->getJson('/api/media/page/1/40')))->toBe([$shown->media_id, $hidden->media_id, $untagged->media_id]);
    asAccount($reader)->putJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    $as = static fn(string $path): TestResponse => asAccount($reader)->getJson($path);

    expect(mediaIdsIn($as('/api/media/page/1/40')))->toBe([$shown->media_id, $untagged->media_id])
        ->and(mediaIdsIn($as('/api/media/with-tags/cats/1/40')))->toBe([$shown->media_id])
        ->and(mediaIdsIn($as('/api/media?per_page=40')))->toBe([$shown->media_id, $untagged->media_id])
        ->and(mediaIdsIn($as('/api/media?per_page=40&tags=cats')))->toBe([$shown->media_id])
        ->and(mediaIdsIn($as('/api/media/untagged/1/40')))->toBe([$untagged->media_id])
        ->and($as('/api/media/count')->json('count'))->toBe(2)
        ->and(mediaIdsIn(asAccount($reader)->postJson('/api/media/by-ids', ['ids' => [$shown->media_id, $hidden->media_id]]), '*'))
        ->toBe([$shown->media_id]);
    foreach (range(1, 10) as $ignored) {
        expect($as('/api/media/random')->json('media_id'))->not->toBe($hidden->media_id);
    }

    // The item itself still answers; the app asks before showing it.
    $as("/api/media/{$hidden->media_id}")->assertOk();

    // Others see everything still.
    expect(mediaIdsIn(signedOut()->getJson('/api/media/page/1/40')))->toHaveCount(3)
        ->and(signedOut()->getJson('/api/media/count')->json('count'))->toBe(3)
        ->and(mediaIdsIn(asAccount()->getJson('/api/media/page/1/40')))->toHaveCount(3);
});

test('unblocking shows the media again at once, though the listing was cached', function (): void {
    ['user' => $reader, 'spiders' => $spiders] = blockedTagsScene();

    asAccount($reader)->putJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    expect(mediaIdsIn(asAccount($reader)->getJson('/api/media/page/1/40')))->toHaveCount(2);

    asAccount($reader)->deleteJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();
    expect(mediaIdsIn(asAccount($reader)->getJson('/api/media/page/1/40')))->toHaveCount(3);
});

test('when the only media left is blocked, a random pick finds nothing', function (): void {
    ['user' => $reader, 'spiders' => $spiders, 'shown' => $shown, 'hidden' => $hidden, 'untagged' => $untagged] = blockedTagsScene();
    $shown->delete();
    $untagged->delete();
    asAccount($reader)->putJson("/api/account/blocked-tags/{$spiders->tag_id}")->assertNoContent();

    asAccount($reader)->getJson('/api/media/random')->assertNotFound()->assertJson(['error' => 'MediaNotFound']);
    signedOut()->getJson('/api/media/random')->assertOk()->assertJsonPath('media_id', $hidden->media_id);
});

test('a browser signed in as the account hides it too, and one signed out doesn\'t', function (): void {
    ['user' => $reader, 'spiders' => $spiders, 'hidden' => $hidden] = blockedTagsScene();
    $reader->update(['password' => 'long-enough']);
    $reader->blockedTags()->attach($spiders->tag_id);

    $browser = new TestBrowser();
    expect(mediaIdsIn($browser->send('GET', '/api/media/page/1/40')))->toHaveCount(3);
    $browser->send('POST', '/api/auth/login', ['username' => $reader->username, 'password' => 'long-enough'])->assertOk();
    expect(mediaIdsIn($browser->send('GET', '/api/media/page/1/40')))->toHaveCount(2);
    $browser->send('POST', '/api/media/by-ids', ['ids' => [$hidden->media_id]])->assertOk()->assertJsonCount(0);
    $browser->send('DELETE', '/api/auth/session')->assertNoContent();
    expect(mediaIdsIn($browser->send('GET', '/api/media/page/1/40')))->toHaveCount(3);
});
