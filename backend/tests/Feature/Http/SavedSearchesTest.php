<?php

declare(strict_types=1);

use App\Models\User;

// Saved searches: tag searches an account keeps by name, private to it.

test('a search is saved, listed by name, renamed and deleted', function (): void {
    $reader = User::factory()->create();

    $id = asAccount($reader)->postJson('/api/saved-searches', ['name' => ' Cats ', 'query' => 'cat,-dog'])
        ->assertCreated()->assertJsonPath('name', 'Cats')->assertJsonPath('query', 'cat,-dog')->json('search_id');
    asAccount($reader)->postJson('/api/saved-searches', ['name' => 'Arrivals', 'query' => 'untagged'])->assertCreated();

    asAccount($reader)->getJson('/api/saved-searches')->assertOk()
        ->assertJsonPath('0.name', 'Arrivals')->assertJsonPath('1.name', 'Cats');

    asAccount($reader)->patchJson("/api/saved-searches/{$id}", ['query' => 'cat'])->assertOk()
        ->assertJsonPath('name', 'Cats')->assertJsonPath('query', 'cat');
    asAccount($reader)->patchJson("/api/saved-searches/{$id}", ['name' => 'Kitties'])->assertOk()
        ->assertJsonPath('name', 'Kitties')->assertJsonPath('query', 'cat');
    asAccount($reader)->deleteJson("/api/saved-searches/{$id}")->assertNoContent();
    asAccount($reader)->getJson('/api/saved-searches')->assertJsonCount(1);
});

test('a search needs a name and a query, and its name is the account\'s alone', function (): void {
    $reader = User::factory()->create();
    asAccount($reader)->postJson('/api/saved-searches', ['name' => 'Cats', 'query' => 'cat'])->assertCreated();

    asAccount($reader)->postJson('/api/saved-searches', ['name' => 'CATS', 'query' => 'cat'])
        ->assertStatus(409)->assertJson(['error' => 'SearchExists']);
    asAccount($reader)->postJson('/api/saved-searches', ['name' => '', 'query' => ''])
        ->assertStatus(400)->assertJson(['error' => 'InvalidSearchName'])
        ->assertJsonStructure(['errors' => ['name', 'query']]);
    asAccount($reader)->postJson('/api/saved-searches', ['name' => 'Long', 'query' => str_repeat('x', 501)])
        ->assertStatus(400)->assertJson(['error' => 'InvalidQuery']);

    asAccount()->postJson('/api/saved-searches', ['name' => 'Cats', 'query' => 'cat'])->assertCreated();
});

test('someone else\'s search is as missing as a deleted one', function (): void {
    $reader = User::factory()->create();
    $search = User::factory()->create()->savedSearches()->create(['name' => 'Theirs', 'query' => 'cat']);

    asAccount($reader)->patchJson("/api/saved-searches/{$search->search_id}", ['name' => 'Mine'])
        ->assertNotFound()->assertJson(['error' => 'SearchNotFound']);
    asAccount($reader)->deleteJson("/api/saved-searches/{$search->search_id}")->assertNotFound();
    asAccount($reader)->deleteJson('/api/saved-searches/999')->assertNotFound()->assertJson(['error' => 'SearchNotFound']);
});
