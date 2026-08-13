# Changelog

All notable changes to `fruitcake/laravel-image-variants` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Variant URLs no longer spell out operations the server can look up for itself.
  The preset and the configured defaults are merged back in before the signature is
  checked, so the query now carries only the source plus whatever a caller added or
  overrode — `Variants::url('img/bg.jpg', 'hero')` returns
  `…/hero/9a8c70ca57/bg.jpg?src=img/bg.jpg` rather than
  `…?src=img/bg.jpg&scale=1600&quality=80`.

  **Hashes are unchanged**, because the signature was always computed over the full
  merged set: existing variants do not regenerate, and previously generated long
  URLs still resolve to the same file. `Variant` gained a fifth constructor
  argument and a readonly `$explicit` holding the subset the URL spells out;
  `$operations` still holds all of them. Constructing a `Variant` by hand without it
  spells out every operation, as before.

## [0.2.0] - 2026-08-13

### Added

- A configured default encoding quality, `image-variants.quality` (default `80`),
  used when neither the preset nor the URL asks for one. It is merged underneath
  both — a preset overrides it, and an operation passed alongside overrides that —
  and it is signed like any other operation, so changing it moves every URL that
  relied on it to a new hash and those variants regenerate rather than being served
  at the old quality. Set it to `null` to leave the encoder to its own default.
- A preset can drop the default with an explicit `false`:
  `'original' => ['scale' => [2000, null], 'quality' => false]`.

### Changed

- Switching an operation off with `false` or `null` in `$operations`, on top of one
  the preset or the configured default defines, now throws
  `InvalidArgumentException` when the URL is built. It previously returned a URL that
  404s: a dropped operation leaves no trace in the query, so the server merged the
  preset back in and refused its own signature. Drop it in the preset instead, where
  both sides see the same thing. Dropping something neither layer defines is still a
  no-op and still allowed.
- A misconfigured `image-variants.quality` throws `VariantConfigurationException`,
  which the controller deliberately does not catch — a broken deployment surfaces as
  a 500 rather than as images that quietly never appear.

### Removed

- `image-variants.max_dimension`. Every URL is keyed with an HMAC over its
  operations, so nothing can ask for a dimension this application did not itself
  decide to offer, and the ceiling guarded against nothing the signature did not
  already cover. Dimensions keep their floor (`1`, and `0` for crop offsets), and
  `max_source_megapixels` still bounds what a *source* costs to decode.

  Note that `cover`, `contain` and `resize` enlarge past the source, so an unbounded
  dimension in your own code can now ask for an output large enough to exhaust a
  worker. Only your own code can ask, since a URL only exists if this application
  signed it.

### Upgrading

Both changes below alter URL hashes, which costs one regeneration per affected
variant and nothing else — old files are never served stale, they simply stop being
requested. Run `php artisan image-variants:cleanup` to reclaim the superseded ones.

- `quality` now defaults to `80` for any variant that did not already set one. This
  applies whether or not you have published the config, because the package config is
  merged underneath yours. To keep the previous behaviour, set `'quality' => null`.
- `'max_dimension'` in a published config is now ignored and can be deleted.

## [0.1.1] - 2026-08-08

### Changed

- `VariantFactory::fromRequest()` verifies the signature itself and throws
  `VariantException` unless it matches, instead of returning an unverified `Variant`
  for the caller to check. There is now no way to obtain a `Variant` from a request
  without the check, so there is nothing for a caller to forget. **Breaking** for
  anything calling it directly — it takes the hash now:
  `fromRequest(Request $request, string $preset, string $hash, string $name)`.

### Added

- `Variant` validates `preset`, `name` and `src` in its constructor, refusing
  separators, `..` and null bytes. `path()` therefore cannot escape the cache
  directory however the object was built — including one constructed directly in PHP,
  which goes through neither the route pattern nor the signature check.
- Extensive coverage of the signing scheme in `tests/SignatureTest.php`.

## [0.1.0] - 2026-08-06

Initial release.

[Unreleased]: https://github.com/fruitcake/laravel-image-variants/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/fruitcake/laravel-image-variants/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fruitcake/laravel-image-variants/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fruitcake/laravel-image-variants/releases/tag/v0.1.0
