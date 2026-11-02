-- Contract fixture for the Phase 0 API recordings (docs/laravel-migration.md).
--
-- Data only: load tests/Support/schema.sql first, then this file. bin/record-contract.php
-- does exactly that for every recorded case, on a fresh copy each time; a Laravel
-- seeder can load the same rows on top of its own migrations.
--
-- Rows were chosen to give each endpoint something to say. The notes on the media rows
-- name what depends on them: the duplicate-scan pairs (fingerprints a fixed Hamming
-- distance apart; any other two images are at least 17 apart), the MD5 that matches an
-- upload fixture, and the media the Danbooru stub (tests/Fixtures/danbooru-stub.php)
-- answers for by hash. Hashes are md5('contract-media-<id>') unless noted.
-- file_time counts up from 1767225600 (2026-01-01T00:00:00Z).

PRAGMA foreign_keys = ON;

-- ── Tag categories: three hold tags, two are empty (deletable) ──────────────
INSERT INTO tag_categories (category_id, category_name, color, description, sort_order) VALUES
    (1, 'General', 'neutral', 'General terms that describe features.', 0),
    (2, 'Artist', 'rose', 'The artist who created this specific piece.', 1),
    (3, 'Character', 'emerald', 'Character name, in <First Name> <Last Name> order or Name (Series Name).', 2),
    (4, 'Source', 'amber', 'Source material (movie, game, anime, etc.).', 3),
    (5, 'Personal List', 'sky', 'Personal lists for individuals to record favorites.', 4);

-- ── Tags: a few names chosen for tag-search encoding (c++, k-on!, café, a space) ──
INSERT INTO tags (tag_id, category_id, tag_name) VALUES
    (1, 1, 'animal'),
    (2, 1, 'blue sky'),
    (3, 1, 'cat'),
    (4, 1, 'dog'),
    (5, 1, 'kitten'),
    (6, 1, 'night'),
    (7, 1, 'outdoors'),
    (8, 1, 'smile'),
    (9, 1, 'c++'),
    (10, 1, 'k-on!'),
    (11, 1, 'café'),
    (12, 1, 'unused tag'),
    (13, 2, 'artist one'),
    (14, 2, 'artist two'),
    (15, 3, 'hero'),
    (16, 3, 'sidekick');

-- ── Implications: kitten → cat → animal is the transitive chain ─────────────
INSERT INTO tag_implications (tag_id, implied_tag_id) VALUES
    (5, 3),  -- kitten → cat
    (3, 1),  -- cat → animal
    (4, 1),  -- dog → animal
    (2, 7);  -- blue sky → outdoors

-- ── Media: 26 images, 4 videos (one an animated GIF); 4 untagged ────────────
INSERT INTO media (media_id, media_type, file_name, file_time, hash, bits_fingerprint, width, height, duration, file_size) VALUES
    -- 1: Danbooru stub: MD5 match (post 1001)
    (1, 'image', 'cat-01.jpg', 1767225600, 'dc5d38c939e8d8814795082b8c3a3e34', '1000001101110111010101011011101111001110111000011010010000001101', 1200, 1600, 0.0, 245760),
    -- 2: hash = MD5 of uploads/duplicate.png
    (2, 'image', 'cat-02.png', 1767229200, '280ac0254c400465415e5686f6dc97de', '1010110101101111101100010001100001011010000011010110001100001111', 800, 800, 0.0, 102400),
    -- 3: scan pair A: distance 0, thumbnails a re-save (kept)
    (3, 'image', 'dupe-a1.png', 1767232800, '5c49a87a1439b12632cbc5178fbc8e29', '0010110011000100011000101111001010100000101011101110101001011110', 1000, 1400, 0.0, 350000),
    -- 4: same file_time as 3: ties order by media_id DESC
    (4, 'image', 'dupe-a2.jpg', 1767232800, 'fddc55a3d180b2f6dbb1763e03f383ac', '0010110011000100011000101111001010100000101011101110101001011110', 900, 1260, 0.0, 180000),
    -- 5: video: no fingerprint, no IQDB
    (5, 'video', 'clip-01.mp4', 1767236400, 'd5d460c9701f5c80fefa578102766445', '', 1920, 1080, 12.5, 5242880),
    -- 6: Danbooru stub: MD5 miss, IQDB match (post 1002)
    (6, 'image', 'dog-01.webp', 1767240000, 'a35748317a7addf46898c1a400384d87', '1110011110111011000110111110101110011101010010001111110100101100', 1024, 768, 0.0, 88000),
    -- 7: scan pair B: distance 3, thumbnails a variant (region-rejected)
    (7, 'image', 'variant-b1.png', 1767243600, '7e07cd4e5550601cead4f151c07ac1e9', '1001000101000100110001110110000001110010001110010100001101011001', 1000, 1400, 0.0, 350100),
    (8, 'image', 'variant-b2.png', 1767247200, 'ddb0732bc98e86304708c10d4912bb0a', '1001010101000100110011110110000001110010011110010100001101011001', 1000, 1400, 0.0, 350200),
    -- 9: animated GIF: media_type video
    (9, 'video', 'anim-01.gif', 1767250800, '2bb4eebb9aab7feb545a040954f65c3e', '', 480, 270, 2.4, 990000),
    -- 10: scan pair C: distance 2, no thumbnails (kept, ssim null); Danbooru stub: MD5 and IQDB miss
    (10, 'image', 'near-c1.jpg', 1767254400, '24c6b612fe2b4700d5bdd39d81c76b1f', '0110001001010011110100100100111010010010011111010111110100100111', 640, 480, 0.0, 64000),
    -- 11: untagged
    (11, 'image', 'near-c2.jpg', 1767258000, 'd04b2c9a2a550a2dfa1063dfb8eaa39f', '0110001000010011110100100100111010010010011111010101110100100111', 640, 480, 0.0, 64100),
    -- 12: scan pair D: distance 1, dismissed
    (12, 'image', 'dismissed-d1.png', 1767261600, 'c8e119812ba8425a63642b880ce60791', '0110101101111100101111001001111100100111110100011011110011001100', 500, 500, 0.0, 50000),
    (13, 'image', 'dismissed-d2.png', 1767265200, '611597c56916b8f389c7c5c7f3ce7b1d', '0110101101111100101111001001111101100111110100011011110011001100', 500, 500, 0.0, 50001),
    -- 14: static GIF: media_type image
    (14, 'image', 'static-01.gif', 1767268800, '1df71dcfd6a481cb422ad140b6fbd3a1', '1100000101101101000000101110010011001110110011010101000110100000', 320, 240, 0.0, 12000),
    -- 15: Danbooru stub: HTTP 401
    (15, 'image', 'kon-01.jpg', 1767272400, '60e9012826556d079bc1cd341165875b', '1100100110010001100100100100110110000101111000001011101001101100', 1280, 720, 0.0, 150000),
    -- 16: Danbooru stub: HTTP 429
    (16, 'image', 'cafe-01.jpg', 1767276000, 'e1ba93cdd6aafa770dce1eec4888d974', '0111101111110110111111001000010000100001000001111011101111011000', 1280, 720, 0.0, 150001),
    -- 17: untagged video
    (17, 'video', 'clip-02.webm', 1767279600, 'e4342c57f4952177b994edfc2af3c128', '', 1280, 720, 30.0, 8000000),
    -- 18: Danbooru stub: HTTP 500
    (18, 'image', 'far-01.png', 1767283200, 'd4be7aa685c4260621cdfbdbc5779542', '0011101100110000110110100110101001011101100011101111101010011101', 600, 800, 0.0, 70000),
    -- 19: scan pair E: distance 7, just past the default cutoff of 6
    (19, 'image', 'edge-e1.png', 1767286800, '38cb8f45021334ec89f0d6a3e7d1745a', '0111000101101001111000110000111110110001111011100110111110110110', 600, 800, 0.0, 70001),
    (20, 'image', 'edge-e2.png', 1767290400, '1f339c46e92a346653aa384ad407a9cb', '0011000101001001111100110000011110110101111011000110111010110110', 600, 800, 0.0, 70002),
    (21, 'image', 'filler-21.jpg', 1767294000, 'b0990b5951e762b14ba25e793a41f2a0', '0110011100011100111111110110010000000111111110000110010011011000', 800, 600, 0.0, 81000),
    -- 22: untagged; same file_time as 21
    (22, 'image', 'filler-22.jpg', 1767294000, '8f04fd5098bba75daa534cd36ecc1d0a', '1001001010110010110001110010010101110000101001000000000100010101', 800, 600, 0.0, 81001),
    (23, 'image', 'filler-23.png', 1767297600, '5d07306e40098b84767bcefce854c8ff', '0010000001010110000010010111010111000011100111011100101011000010', 700, 700, 0.0, 91000),
    (24, 'image', 'filler-24.webp', 1767301200, '461342e0499b7fa6883509501ff53d90', '0001100010000100110111111111101001010101110101111000000010100010', 1600, 900, 0.0, 120000),
    (25, 'video', 'filler-25.mp4', 1767304800, '08ff94c665d1f6a79f207dbf9a645c38', '', 1280, 720, 8.25, 3000000),
    (26, 'image', 'filler-26.jpg', 1767308400, '1a6b1d7c908b75776f42e4ad9fcd48e2', '0100110110100100010011100011100110110001011111111101000110000110', 900, 1200, 0.0, 99000),
    -- 27: untagged
    (27, 'image', 'filler-27.jpg', 1767312000, 'f9a373b5195c09ab708086a1321ed958', '1111001111111101011000100000011110001001101100101000010011101010', 900, 1200, 0.0, 99001),
    (28, 'image', 'filler-28.png', 1767315600, '37d82f8ca0db17120ee738304b750a8f', '1011111100110001100011001100001001111101110101101011111101010000', 1024, 1024, 0.0, 130000),
    (29, 'image', 'filler-29.jpg', 1767319200, '8f4d5a90bd43a527c94538dbb857af04', '0111110101001110110100101100010111100011100011011111010110110010', 1024, 1024, 0.0, 130001),
    (30, 'image', 'filler-30.avif', 1767322800, 'eb31856e995a2034c53f60bc4ab96be9', '1011100001011010100001000011110110111000000100111001011011011101', 1500, 1000, 0.0, 60000);

-- ── Media tags: implied tags are stored, as the tagging endpoints store them ──
INSERT INTO media_tags (media_id, tag_id) VALUES
    (1, 3), (1, 1), (1, 7), (1, 13), (1, 15),
    (2, 5), (2, 3), (2, 1), (2, 6),
    (3, 2), (3, 7),
    (4, 2), (4, 7),
    (5, 4), (5, 1), (5, 14),
    (6, 4), (6, 1), (6, 8),
    (7, 15), (7, 16),
    (8, 15),
    (9, 8),
    (10, 6),
    (12, 3), (12, 1),
    (13, 3), (13, 1),
    (14, 9),
    (15, 10), (15, 8),
    (16, 11), (16, 7),
    (18, 13), (18, 8),
    (19, 4), (19, 1),
    (20, 4), (20, 1), (20, 6),
    (21, 3), (21, 1), (21, 14),
    (23, 16),
    (24, 2), (24, 7), (24, 6),
    (25, 15), (25, 13),
    (26, 5), (26, 3), (26, 1), (26, 8),
    (28, 7),
    (29, 8), (29, 6),
    (30, 3), (30, 1), (30, 15), (30, 16);

-- ── Danbooru import rules (category map mirrors the production seed) ────────
INSERT INTO danbooru_category_map (danbooru_category_id, danbooru_category_name, gallery_category_id) VALUES
    (0, 'General', 1),
    (1, 'Artist', 2),
    (3, 'Copyright', 4),
    (4, 'Character', 3);

INSERT INTO danbooru_tag_map (id, danbooru_tag, gallery_tag) VALUES
    (1, '1girl', 'one woman'),
    (2, 'cat_ears', 'cat'),
    (3, 'smiling', 'smile');

-- ── Duplicates: pair D (12, 13) was dismissed in the UI ──────────────────────
INSERT INTO dismissed_duplicates (media_id_1, media_id_2, dismissed_at) VALUES
    (12, 13, 1767900000);

-- ── Auth: one long-expired token. The recorder adds a fresh admin token per case. ──
INSERT INTO auth_tokens (token, created_at) VALUES
    ('deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef', 1700000000);
