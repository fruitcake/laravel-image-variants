---
name: image-variants-development
description: Generate resized, cropped and converted image variants on demand with fruitcake/laravel-image-variants — building variant URLs and srcsets, the <x-variant> Blade component, defining presets, and the operation grammar.
---

# Image Variants Development

## When to use this skill

Use this skill when the application has `fruitcake/laravel-image-variants`
installed and the task involves rendering images at a particular size or format:
thumbnails, responsive `srcset`s, WebP conversion, cropping, or anything that
would otherwise reach for a pre-generated image pipeline.

## How it works

You ask for a variant and get a URL back. Nothing is generated at that point. The
**first** request for that URL reaches PHP, which writes the image to exactly the
path the browser asked for — below the `storage:link` symlink. **Every request
after that** is served by the web server straight off disk, with PHP never
involved again.

The URL looks like this:

```
/storage/variants/{preset}/{hash}/{name}?src=…&…operations…
```

The `hash` is an HMAC keyed with `APP_KEY` over the preset, the merged
operations, the source and the name. It is what makes the endpoint safe to
expose — without the key nobody can produce a URL the package will act on.

There is no unsigned path to a variant, for any preset. `fromRequest()` verifies
the signature itself and throws rather than returning an unverified object, so
there is nothing to bypass and nothing for a caller to forget.

## Rules

- **Never build a variant URL by hand, in PHP or in a template.** The HMAC will
  not match and the request will 404. Always go through the facade or component.
  This applies to preset URLs too — the preset name in the path is signed, not a
  shortcut around signing.
- **Never write to `config('image-variants.cache')` yourself.** It is generated
  output; the package owns it.
- **`$src` is relative to the configured source disk**, not `public_path()`.
  Check `config('image-variants.source.disk')` (default: `public`, i.e.
  `storage/app/public`) before assuming a path resolves.
- **Only one geometry operation at a time.** `cover`, `contain`, `resize` and
  `scale` are mutually exclusive and combining them throws. This bites most often
  when calling `srcset()` (which adds `scale`) with a preset built on `cover`.
- **Prefer a named preset in `config/image-variants.php`** over repeating the
  same inline operations across templates. The preset name becomes a directory in
  the URL, so keep it readable.
- Editing a preset changes the hash of every URL using it, so old variants are
  never served stale. There is no cache to bust.

## Blade

Prefer the component. It assembles the URL, the `srcset`, and the `width` and
`height` that stop the page shifting — all from one description:

```blade
<x-variant src="uploads/team.jpg" preset="thumb" alt="The team" />
```

```blade
<x-variant
    src="uploads/office.jpg"
    :widths="[640, 1024, 1600]"
    sizes="(max-width: 900px) 100vw, 900px"
    format="webp"
    alt="Our office"
/>
```

Props: `src`, `preset` (name or `:preset="['cover' => [60, 40]]"`), `format`,
`name`, `:operations`, `:widths`, `sizes`. Anything else (`class`, `loading`,
`decoding`, `fetchpriority`, …) passes through to the tag.

Always pass a real `alt` for images that carry meaning. The component emits
`alt=""` when you do not, which tells a screen reader the image is decorative.

For a URL somewhere that is not an `<img>` — an `og:image`, a CSS background, a
`<source>` — use the facade.

## The facade

```php
use Fruitcake\ImageVariants\Facades\Variants;

Variants::url(
    string $src,                       // relative to the source disk
    string|array $preset = 'custom',   // a preset name, or operations
    ?string $format = null,            // output extension; defaults to the source's
    ?string $name = null,              // filename to serve as; defaults to the source's
    array $operations = [],            // merged over the preset
);
```

```php
Variants::url('uploads/bg.jpg', 'hero');
Variants::url('uploads/bg.jpg', ['cover' => [600, 500], 'quality' => 80], 'webp');
Variants::url('uploads/bg.jpg', 'thumb', 'webp', 'team-photo', ['grayscale' => true]);

Variants::srcset('uploads/bg.jpg', [640, 1024, 1600], 'webp');
Variants::dimensions('uploads/bg.jpg', 'thumb');   // ['width' => 100, 'height' => 100] or null
Variants::make('uploads/bg.jpg', 'thumb');         // the Variant object
```

`dimensions()` returns `null` when the answer depends on decoding the image —
an `orient` (EXIF) or a `rotate` off the square. Handle that case; do not guess.

## Twig

If the application renders Twig, register
`Fruitcake\ImageVariants\Twig\VariantExtension` with the environment. It provides
`variant()`, `srcset()` and `variant_size()` (named that way because Twig
functions share one global namespace).

## Operations

Named after the methods on `Illuminate\Image\Image`. Written as arrays in PHP, as
query parameters in the URL; both normalise to the same thing. Applied in this
fixed order regardless of how they were written:

| Operation | Arguments | Example |
| --- | --- | --- |
| `orient` | — | `['orient' => true]` |
| `rotate` | angle `0–359`, optional background | `['rotate' => [90, 'ffffff']]` |
| `flip` | `v` or `h` | `['flip' => 'v']` |
| `crop` | width, height, optional x, y | `['crop' => [300, 200, 50, 25]]` |
| `cover` | width, height (both required) | `['cover' => [600, 500]]` |
| `contain` | width, height, optional background | `['contain' => [600, 500, 'fff']]` |
| `resize` | width and/or height | `['resize' => [800, 600]]` |
| `scale` | width and/or height | `['scale' => [800, null]]` |
| `grayscale` | — | `['grayscale' => true]` |
| `blur` | `0–100`, default `5` | `['blur' => 10]` |
| `sharpen` | `0–100`, default `10` | `['sharpen' => 15]` |
| `quality` | `1–100` | `['quality' => 80]` |

- `scale` preserves aspect ratio and never enlarges past the source. `resize`
  does not preserve it. `cover` and `contain` need both dimensions.
- Backgrounds are bare hex without `#` (`ffffff` or `fff`), or `dominant`.
- Passing `false` or `null` removes an operation a preset set:
  `Variants::url('bg.jpg', 'thumb', operations: ['quality' => false])`.
- Anything outside the grammar throws `InvalidArgumentException` when building
  the URL, and 404s when it arrives over HTTP.

## Presets

```php
// config/image-variants.php
'presets' => [
    'thumb' => ['cover' => [100, 100], 'quality' => 80],
    'photo' => ['scale' => [800, null], 'quality' => 80],
    'hero'  => ['scale' => [1600, null], 'quality' => 80],
],
```

## Configuration worth knowing

| Key | Default | Notes |
| --- | --- | --- |
| `source.disk` | `public` | Disk sources are read from. Any disk, including `s3`. |
| `source.prefix` | `null` | Confines sources to a directory within that disk. |
| `cache` | `storage_path('app/public/variants')` | Always a local path, so the web server can serve it. |
| `max_dimension` | `4000` | Bounds what a URL may ask for. |
| `max_source_megapixels` | `24` | Bounds what decoding costs. `0` disables. |
| `blade.component` | `variant` | Rename if the app already has an `<x-variant>`. |
| `lock.*` | enabled | Serialises generation of the same variant across workers. |
| `presets` | thumb, photo, hero | |

## Exceptions

- `VariantException` (extends `InvalidArgumentException`) — a bad URL: source
  missing, escapes its root, wrong format, over the megapixel limit. Answered
  with **404**.
- `VariantConfigurationException` (extends `RuntimeException`) — the application
  is misconfigured: no source disk, a disk missing from `filesystems.disks`, an
  unset `APP_KEY`, a cache store that cannot lock. Deliberately **not** caught by
  the controller, so a broken deployment surfaces as a 500 rather than as images
  that quietly never appear.

## Setup and maintenance

```bash
php artisan storage:link                                   # required
php artisan vendor:publish --tag=image-variants-config      # optional

php artisan image-variants:cleanup --days=90 --dry-run      # sweep old variants
```

The web server must serve existing files before handing to PHP (the stock Laravel
nginx `try_files` and shipped `.htaccess` both do). If every request is routed to
`index.php` unconditionally, every request regenerates and the package's whole
point is lost.
