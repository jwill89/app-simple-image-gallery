<?php

declare(strict_types=1);

use App\Models\DanbooruCategoryMapping;
use App\Models\DanbooruTagMapping;
use App\Models\DismissedDuplicate;
use App\Models\Media;
use App\Models\Tag;
use App\Models\TagCategory;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\seed;

// The models and their relationships, read from the contract fixture, whose
// rows are the ones the API was recorded on.

beforeEach(function (): void {
    seed(ContractFixtureSeeder::class);
});

test('the contract fixture loads onto the migrated schema', function (): void {
    expect(Media::count())->toBe(30)
        ->and(Tag::count())->toBe(16)
        ->and(TagCategory::count())->toBe(5)
        ->and(DanbooruCategoryMapping::count())->toBe(4)
        ->and(DanbooruTagMapping::count())->toBe(3)
        ->and(DismissedDuplicate::count())->toBe(1);
});

test('media and tags are linked both ways through media_tags', function (): void {
    $media = Media::query()->findOrFail(1);

    expect($media->tags->pluck('tag_name')->sort()->values()->all())->toBe(['animal', 'artist one', 'cat', 'hero', 'outdoors'])
        ->and(Tag::query()->findOrFail(15)->media->pluck('media_id')->sort()->values()->all())->toBe([1, 7, 8, 25, 30]);
});

test('a tag belongs to a category, which has many tags', function (): void {
    expect(Tag::query()->findOrFail(13)->category?->category_name)->toBe('Artist')
        ->and(TagCategory::query()->findOrFail(2)->tags->pluck('tag_name')->all())->toBe(['artist one', 'artist two']);
});

test('implications run from a tag to the tags it implies, and back', function (): void {
    $cat = Tag::query()->findOrFail(3);

    expect($cat->implies->pluck('tag_name')->all())->toBe(['animal'])
        ->and($cat->impliedBy->pluck('tag_name')->all())->toBe(['kitten']);
});

test('tag and category names match without regard to case', function (): void {
    expect(Tag::query()->where('tag_name', 'CAT')->value('tag_id'))->toBe(3)
        ->and(TagCategory::query()->where('category_name', 'artist')->value('category_id'))->toBe(2);
});

test('the Danbooru mappings and dismissed pairs read by their own keys', function (): void {
    expect(DanbooruCategoryMapping::query()->findOrFail(3)->gallery_category_id)->toBe(4)
        ->and(DanbooruTagMapping::query()->findOrFail(2)->gallery_tag)->toBe('cat')
        ->and(DismissedDuplicate::query()->sole()->only(['media_id_1', 'media_id_2']))->toBe(['media_id_1' => 12, 'media_id_2' => 13]);
});

test('the factories make rows the schema accepts', function (): void {
    $tag = Tag::factory()->create();
    $video = Media::factory()->video()->create();
    $video->tags()->attach($tag);

    $video->refresh();

    expect($tag->category)->toBeInstanceOf(TagCategory::class)
        ->and($video->media_type)->toBe('video')
        ->and($video->bits_fingerprint)->toBe('')
        ->and($video->tags->pluck('tag_id')->all())->toBe([$tag->tag_id]);
});
