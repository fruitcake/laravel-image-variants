## Laravel Image Variants

![Unit Tests](https://github.com/fruitcake/laravel-image-variants/workflows/Unit%20Tests/badge.svg)
[![Packagist License](https://img.shields.io/badge/Licence-MIT-blue)](http://choosealicense.com/licenses/mit/)
[![Latest Stable Version](https://img.shields.io/packagist/v/fruitcake/laravel-image-variants?label=Stable)](https://packagist.org/packages/fruitcake/laravel-image-variants)
[![Total Downloads](https://img.shields.io/packagist/dt/fruitcake/laravel-image-variants?label=Downloads)](https://packagist.org/packages/fruitcake/laravel-image-variants)
[![Fruitcake](https://img.shields.io/badge/Powered%20By-Fruitcake-b2bc35.svg)](https://fruitcake.nl/)

### Resize, crop and convert images on the fly — without pre-rendering

<img width="1774" height="887" alt="image" src="https://github.com/user-attachments/assets/d10412b8-b87f-47e3-8d52-aeacebab95b5" />


Ask for a variant in a template and you get a URL back. Nothing is generated at
that point, and nothing needs to have been generated ahead of time:

```blade
<img src="{{ Variants::url('img/bg.jpg', 'hero') }}" alt="">
```

```html
<img src="/storage/variants/hero/9a8c70ca57/bg.jpg?src=img/bg.jpg" alt="">
```

The **first** request for that URL reaches PHP, which generates the image and
writes it to exactly the path the browser asked for — below the `storage:link`
symlink. **Every request after that** is answered by the web server straight off
disk. PHP is never involved again, so there is no route to hit, no cache to
consult, and no per-request overhead once an image exists.

That is the whole idea: on-demand generation with the serving cost of a static
file.

#### Requirements

- PHP 8.3+
- Laravel 13
- `ext-fileinfo`
- `ext-gd` or `ext-imagick`, plus [intervention/image](https://image.intervention.io/) (pulled in as a dependency)

Image work is done through Laravel's own `Illuminate\Image` layer, so the driver
is whatever `config/images.php` says (`gd` by default).

> **WebP and AVIF need a PHP build compiled for them.** Both are permitted by
> the default `output_formats`, but asking for a format this build cannot encode
> raises an `ImageException`, which the controller answers with a 404 — a
> puzzling one to debug. Check with `php -r 'var_dump(function_exists("imagewebp"), function_exists("imageavif"));'`
> before relying on either.

#### Install

```bash
composer require fruitcake/laravel-image-variants
```

The package registers itself. It needs the public storage symlink, so if you
have not already:

```bash
php artisan storage:link
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=image-variants-config
```

> **Your web server must serve existing files before handing off to PHP.** The
> stock Laravel nginx config (`try_files $uri $uri/ /index.php?$query_string`)
> and the shipped `public/.htaccess` both do this already. If yours routes
> everything to `index.php` unconditionally, every request will regenerate and
> the point of the package is lost.

### Cache headers

The one response that comes from PHP carries
`Cache-Control: public, max-age=31536000, immutable`. Every response after that
comes from the web server, which sends whatever *it* is configured to send —
usually just `Last-Modified`, so browsers revalidate on each visit.

Since variant URLs change whenever their content does, it is worth saying so
once in the server config:

```nginx
location ^~ /storage/variants/ {
    expires max;
    add_header Cache-Control "public, immutable";
}
```

```apache
<LocationMatch "^/storage/variants/">
    Header set Cache-Control "public, max-age=31536000, immutable"
</LocationMatch>
```

## Where images come from

Sources are read from a **filesystem disk**, and every `$src` you pass is
relative to it. The default is Laravel's own `public` disk —
`storage/app/public`, where uploads normally land:

```php
'source' => [
    'disk' => 'public',
    'prefix' => null,
],
```

```php
Variants::url('uploads/avatar.png', 'thumb');   // storage/app/public/uploads/avatar.png
```

Any disk works, remote ones included — sources are read through Flysystem, so an
`s3` disk needs no special handling. Only the *first* request for a variant
reads the source; everything after that is served off local disk, so a remote
source costs one read per variant, ever.

To serve images committed under `public/` instead, define a disk for them in
`config/filesystems.php` and name it here:

```php
// config/filesystems.php
'assets' => ['driver' => 'local', 'root' => public_path()],

// config/image-variants.php
'source' => ['disk' => 'assets', 'prefix' => null],
```

### Confining sources to a subdirectory

`prefix` narrows a disk to one directory without needing a disk of its own.
`$src` is then relative to the prefix, and nothing outside it is addressable:

```php
'source' => ['disk' => 'public', 'prefix' => 'uploads'],
```

```php
Variants::url('avatar.png', 'thumb');   // storage/app/public/uploads/avatar.png
```

### Sources that do not resolve

A `$src` that escapes its root, does not exist, or is not one of
`source_formats` throws `VariantException` and returns **404** — it is a bad URL.

A source that is not *configured* — no disk set, or a disk missing from
`filesystems.disks` — throws `VariantConfigurationException`, which is
deliberately **not** caught by the controller. A broken deployment should
surface as a 500 in your error tracker, not as images that quietly never appear.

## Usage

### The facade

```php
use Fruitcake\ImageVariants\Facades\Variants;

// A named preset from the config
Variants::url('img/bg.jpg', 'hero');
// /storage/variants/hero/9a8c70ca57/bg.jpg?src=img/bg.jpg

// Ad-hoc operations, converting to webp
Variants::url('img/bg.jpg', ['cover' => [600, 500], 'quality' => 80], 'webp');
// /storage/variants/custom/b754ff4bfa/bg.webp?src=img/bg.jpg&cover=600,500&quality=80

// A preset, a nicer filename, and one operation added on top
Variants::url('img/bg.jpg', 'thumb', 'webp', 'team-photo', ['grayscale' => true]);
// /storage/variants/thumb/cb20a26822/team-photo.webp?src=img/bg.jpg&grayscale=1
```

The signature is the same throughout:

```php
Variants::url(
    string $src,                       // relative to the configured source disk
    string|array $preset = 'custom',   // a preset name, or operations
    ?string $format = null,            // output extension; defaults to the source's
    ?string $name = null,              // filename to serve as; defaults to the source's
    array $operations = [],            // merged over the preset
);
```

`Variants::make(...)` takes the same arguments and returns the `Variant` object
instead, which also exposes `->url()`, `->path()`, `->hash()`, `->format()`,
`->dimensions()` and `->query()`, plus the readonly `$preset`, `$operations`,
`$src` and `$name` it was built from. `$operations` is the full merged set — the
smaller subset the URL spells out is `$explicit`.

### Responsive images

```php
Variants::srcset('img/bg.jpg', [640, 1024], 'webp');
// /storage/variants/custom/cf6b9805e5/bg.webp?src=img/bg.jpg&scale=640 640w,
// /storage/variants/custom/e27357f48a/bg.webp?src=img/bg.jpg&scale=1024 1024w
```

`srcset()` adds a `scale` operation per width. It can be combined with a preset,
as long as that preset is not itself a resize — see *One resize at a time* below.

In Blade you rarely need to call it yourself; the `<x-variant>` component below
assembles the whole tag.

### Dimensions, without generating anything

A browser that knows an image's aspect ratio up front reserves the space and
does not shift the page when the image lands. `dimensions()` works out what a
variant *will* be:

```php
Variants::dimensions('img/bg.jpg', 'thumb');   // ['width' => 100, 'height' => 100]
```

Most presets cost nothing to measure. `cover`, `contain` and `crop` state their
output dimensions outright, so a `thumb` is answered from the operations alone
and the source is never opened. Only `scale` and a one-sided `resize` need the
source's aspect ratio; a local source is then measured from its file header, and
a remote one is fetched once and remembered for `dimensions.ttl` seconds.

It returns `null` rather than guessing when the answer genuinely depends on
decoding the image — an `orient`, whose result depends on EXIF this has not
read, or a `rotate` off the square, which lands on a bounding box the encoder
rounds its own way. In Blade, the component below handles that for you.

### Blade

Everything a good image tag needs comes from the same description, so there is a
component that writes the whole thing:

```blade
<x-variant src="img/bg.jpg" preset="thumb" alt="Team photo" />
```

```html
<img src="/storage/variants/thumb/2de1510004/bg.png?src=img/bg.jpg"
     width="100" height="100" alt="Team photo">
```

Give it widths and it builds the srcset too, using the largest as the `src` that
anything ignoring srcset falls back to:

```blade
<x-variant
    src="img/bg.jpg"
    :widths="[640, 1024, 1600]"
    sizes="(max-width: 900px) 100vw, 900px"
    format="webp"
    alt="Our office"
/>
```

```html
<img src="…/bg.webp?src=img/bg.jpg&amp;scale=1600"
     srcset="…scale=640 640w, …scale=1024 1024w, …scale=1600 1600w"
     sizes="(max-width: 900px) 100vw, 900px"
     width="1600" height="1067" alt="Our office">
```

| Prop | Meaning |
| --- | --- |
| `src` | Source path, relative to the configured disk. |
| `preset` | A preset name, or `:preset="['cover' => [60, 40]]"` for ad-hoc operations. |
| `format` | Output extension; defaults to the source's. |
| `name` | Filename to serve as; defaults to the source's. |
| `:operations` | Merged over the preset. |
| `:widths` | Builds a `srcset` over these widths. |
| `sizes` | The `sizes` attribute. Only emitted alongside a srcset. |

`width` and `height` are worked out and added, and left off entirely rather than
guessed when the variant cannot be measured without generating it. `alt` is
always emitted so the tag is valid — pass a real one for any image that carries
meaning, since an empty `alt` tells a screen reader the image is decorative.
Anything else you put on the tag (`class`, `loading`, `decoding`,
`fetchpriority`, …) passes straight through.

Rename it, or turn it off, if `variant` is a name your application already uses:

```php
'blade' => ['component' => 'variant'],   // null leaves it unregistered
```

For a URL somewhere that is not an `<img>` — an `og:image`, a CSS background, a
`<source>` — use the facade directly, as above.

### Twig

If your app renders Twig, register the bundled extension with your Twig
environment:

```php
$twig->addExtension($container->make(\Fruitcake\ImageVariants\Twig\VariantExtension::class));
```

```twig
{{ variant('img/bg.jpg', 'hero') }}
{{ variant('content/team/photo.jpg', 'photo', 'webp', team.key) }}
{{ variant('img/bg.jpg', {cover: [600, 500], quality: 80}, 'webp') }}
{{ srcset('img/bg.jpg', [640, 1024, 1600], 'webp') }}

{% set size = variant_size('img/bg.jpg', 'photo') %}
<img src="{{ variant('img/bg.jpg', 'photo') }}"
     {% if size %}width="{{ size.width }}" height="{{ size.height }}"{% endif %}
     alt="">
```

`variant_size` rather than `dimensions`, because Twig functions share one global
namespace with whatever else the application has registered.

## Operations

Operations are named after the methods on `Illuminate\Image\Image` and can be
written either as PHP arrays (in presets and calls) or as query parameters (in
the URL). Both forms normalise to the same thing.

| Operation | Arguments | Example |
| --- | --- | --- |
| `orient` | — | `orient=1` |
| `rotate` | angle `0–359`, optional background | `rotate=90,ffffff` |
| `flip` | `v` or `h` | `flip=v` |
| `crop` | width, height, optional x, y | `crop=300,200,50,25` |
| `cover` | width, height (both required) | `cover=600,500` |
| `contain` | width, height, optional background | `contain=600,500,fff` |
| `resize` | width and/or height | `resize=800,600` |
| `scale` | width and/or height | `scale=800` |
| `grayscale` | — | `grayscale=1` |
| `blur` | `0–100`, default `5` | `blur=10` |
| `sharpen` | `0–100`, default `10` | `sharpen=15` |
| `quality` | `1–100`, default from config | `quality=80` |

A few rules the grammar enforces:

- **Fixed order.** A query string carries no ordering, so operations are applied
  in the order of the table above rather than the order they appear in the URL.
- **One resize at a time.** `cover`, `contain`, `resize` and `scale` are mutually
  exclusive; combining them is a mistake rather than a pipeline, and throws.
- **Omitted dimensions.** `scale=800` scales to a width of 800; `scale=,600`
  scales to a height of 600. `cover` and `contain` need both.
- **Backgrounds** are bare hex (`ffffff` or `fff`, no `#`), or the literal
  `dominant` to sample the image's own average colour.
- **Turning an operation off.** Passing `false` or `null` drops it, which is how
  a preset removes an inherited value rather than replacing it:
  `'raw' => ['scale' => [1600, null], 'quality' => false]`.
- **Default quality.** `quality` is the one operation with a configured default,
  `image-variants.quality`, applied when neither the preset nor the URL asks for
  one — see below.
- **No upper bound on dimensions.** They have a floor but no ceiling: nothing can
  ask for a variant without a signature over the operations describing it, so the
  only thing able to request a 20000px image is your own code.
- Anything outside the grammar — an unknown operation, a value out of range, a
  dimension below `1` — throws `InvalidArgumentException` when you build the URL,
  and returns a 404 when it arrives over HTTP.

## Presets

Presets are named operation sets. The name becomes a path segment, so it is
worth keeping them readable.

```php
'presets' => [
    'thumb' => ['cover' => [100, 100], 'quality' => 80],
    'photo' => ['scale' => [800, null], 'quality' => 80],
    'hero'  => ['scale' => [1600, null], 'quality' => 80],
],
```

Anything in `$operations` is merged over the preset, so a preset can be reused
with one value changed. Editing a preset changes the hash of every URL using it,
so variants built from the old definition are simply never requested again —
they are not served stale, and there is no cache to bust. (They do stay on disk
until cleaned up; see below.)

### Default quality

`quality` is the one operation with a configured default, so it does not have to
be repeated in every preset:

```php
'quality' => 80,
```

It sits underneath both layers above — a preset overrides it, and an operation
passed alongside overrides that — and it is signed like anything else, so
changing it moves every URL that relied on it to a new hash and those variants
regenerate rather than being served at the old quality. Set it to `null` to
leave the encoder to its own default.

Being signed does not mean being spelled out: like a preset's operations, the
default is merged back in server-side, so it never appears in the URL. Only a
`quality` a caller asked for does.

To keep it out of one particular variant, give that preset an explicit `false`:

```php
'presets' => [
    'original' => ['scale' => [2000, null], 'quality' => false],
],
```

It has to be the preset rather than the call, because the query carries only what
the operations normalise to, and a dropped operation normalises to nothing at
all: the server would merge the default back in and refuse its own URL. Doing it
in `$operations` throws rather than handing back a URL that 404s.

## How the URL works

```
/storage/variants/{preset}/{hash}/{name}?src=…&…operations…
└──── route prefix ────┘
```

- The three path segments decide where the file lands on disk, which is why they
  have to match the public path: `storage/variants/...` in the URL is
  `storage_path('app/public/variants')/...` on disk, via `storage:link`.
- The query is what the first request reconstructs the work from. Web servers
  ignore the query string when serving static files, so it costs nothing after
  generation.
- **The hash covers more than the query says.** It is computed over every merged
  operation, but the query only carries what the server cannot look up for
  itself: the preset and the configured defaults are merged back in before the
  signature is checked. So a preset URL is nothing but its source, and an
  operation appears only where a caller added or overrode one.

That last point is why these are all the same variant, and all valid:

```
…/thumb/2de1510004/bg.webp?src=img/bg.jpg
…/thumb/2de1510004/bg.webp?src=img/bg.jpg&cover=100,100
…/thumb/2de1510004/bg.webp?src=img/bg.jpg&cover=100,100&quality=80
```

The first is what the package generates. The others spell out what `thumb`
already says, which changes nothing — they normalise to the same operations, so
they hash the same and resolve to the same file. Say something the preset does
*not* say, though, and the signature no longer covers it: `&quality=70` on any of
those is a different variant and returns a 404.
- The `hash` is an **HMAC keyed with `APP_KEY`**, computed over the preset, the
  normalised operations, the source and the name. It is not a cache key — it is
  what makes the endpoint safe. Without the key nobody can compute a URL that
  the controller will act on, so the endpoint cannot be used to fill your disk
  with a million sizes of the same image, and no separate signature parameter is
  needed. Nothing is read from or written to disk until the hash checks out.

### The signature is never optional

There is no unsigned path to a variant. `VariantFactory::fromRequest()` verifies
the hash itself and throws rather than returning, so a request that is not signed
cannot become a `Variant` at all — there is no unverified object for a caller to
forget to check. That covers every case equally: presets, `custom` operations, a
source in a subdirectory, a renamed file.

Every part of the URL is covered. Swapping `thumb` for `wide`, for `custom`, or
for a preset that does not exist, changing the source, adding or altering an
operation, renaming the file — each produces a different hash and a 404, and
nothing is written to disk on the way.

What is signed is the operations **after** the preset and the defaults are merged
in, which is why a preset URL needs no operations in its query and is generated
without them: `?src=uploads/photo.jpg` on its own rebuilds the identical variant
and validates. That is the preset name in the path earning its keep, not a gap.
The merged result is what gets hashed, so the only thing reachable under a given
signature is still the one variant that produced it — a shorter query says less,
it does not permit more.

Behind that, a `Variant` cannot even hold a path that would escape. `preset` and
`name` are single path segments and are refused if they contain a separator, a
`..`, or a null byte; `src` may name a subdirectory but may not climb or be
absolute. So `path()` stays inside the cache directory however a `Variant` was
built — including one constructed directly in PHP, which goes through neither the
route pattern nor the signature.

Sources are then resolved inside the configured disk (and prefix) and rejected if
they escape it, and output formats are limited to `output_formats`.

Dimensions have no upper bound. There is nothing for one to protect against: a
URL asking for a 20000px image only exists if this application signed it, so the
limit that matters is whichever line of your own code asked for it.

`APP_KEY` must be set. Without it the digest would be plain SHA-256 over public
inputs — computable by anyone, turning the endpoint into an open resize service —
so building a URL throws instead.

### Guard the input, not just the output

The signature bounds what a URL may *ask* for. It says nothing about what
answering costs, because that is decided by the source rather than by the
operations: decoding takes roughly 4 bytes per pixel whatever the file size, so a
solid-colour 10000×10000 PNG is ~300KB on disk and ~380MB decoded, and asking it
for a 60×40 thumbnail still pays that in full.

`max_source_megapixels` (default `24`) bounds the source itself, read from the
image header without decoding. This matters most with the default source disk,
which is where uploads land: without it, anyone who can upload an avatar can
exhaust a worker on every variant of it. Lower it if `memory_limit` is tight;
set it to `0` to accept anything.

Rotating `APP_KEY` invalidates every previously generated URL. Existing files
stay on disk (until cleaned up) but are never requested again, and new URLs
regenerate under a new hash.

## Concurrency

Generating a variant is serialised per URL. Without that, a burst of requests
arriving for the same uncached image would each decode, resize and encode it at
the same time — a page of cold images costs what it should, but one cold URL
under load costs N times over.

The first request in takes a lock and generates; the rest wait, then find the
file already there and serve it. A request that waits longer than `lock.wait`
gives up and generates anyway, on the grounds that a slow image beats a broken
one. Writes go to a temporary file and are renamed into place regardless, so
nothing can ever serve a half-written image even if the lock is off.

```php
'cache_store' => null,   // null = default store

'lock' => [
    'enabled' => true,
    'ttl' => 30,   // how long the lock survives a worker that dies holding it
    'wait' => 10,  // how long a queued request waits before generating anyway
],
```

Every cache store Laravel ships supports locking, including `null` (as a no-op).
A custom store that does not will raise `VariantConfigurationException` rather
than fail obscurely; set `lock.enabled` to `false` if that is deliberate.

## Cleaning up

Variants accumulate: a preset you edited last year, a page you deleted, a size
nobody requests any more. Nothing in the cache is irreplaceable — a deleted
variant is regenerated by the next request for it, at the cost of one slow
response — so sweeping it is safe.

```bash
# Delete variants not written to in the last 30 days (config default)
php artisan image-variants:cleanup

# Pick the age yourself
php artisan image-variants:cleanup --days=90

# See what would go, without deleting anything
php artisan image-variants:cleanup --days=90 --dry-run

# List each file as it goes
php artisan image-variants:cleanup -v
```

Only files below the configured `cache` directory are touched; sources are never
read. Directories emptied by the sweep are removed, the cache root itself is
kept, and any `.tmp` files left behind by an interrupted generation get swept up
with everything else.

Because generated files are written once and never rewritten, "last written" is
effectively "created". If your filesystem records access times (many are mounted
`relatime` or `noatime` and do not), `--accessed` ages files by when they were
last *read* instead, which prunes what is genuinely unused rather than what is
merely old:

```bash
php artisan image-variants:cleanup --accessed --days=90
```

Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('image-variants:cleanup')->weekly();
```

The default age lives in the config:

```php
'cleanup' => [
    'days' => 30,
],
```

There is deliberately no "clear everything" command, because there is nothing to
clear: an edited preset moves its URLs to a new hash rather than leaving stale
ones behind, so the old files are simply never asked for again and age out on
their own. `--days=0` is not that command either — it deletes what is older than
*now*, so anything written in the current second survives. If you really want the
cache gone, delete the directory:

```bash
rm -rf storage/app/public/variants
```

## Configuration

| Key | Default | What it does |
| --- | --- | --- |
| `route.prefix` | `storage/variants` | Where the endpoint is mounted. Must line up with a publicly reachable path to `cache`. |
| `route.middleware` | `[]` | Middleware for the endpoint. Deliberately empty — see below. |
| `source.disk` | `public` | The filesystem disk sources are read from. |
| `source.prefix` | `null` | An optional directory within that disk to confine sources to. |
| `cache` | `storage_path('app/public/variants')` | Where generated variants are written. Always a local path — see above. |
| `max_source_megapixels` | `24` | Largest source that will be decoded. `0` disables the check. |
| `hash_length` | `10` | Characters of the HMAC kept in the URL. |
| `source_formats` | jpg, jpeg, png, gif, webp, avif, bmp | What may be read. |
| `output_formats` | jpg, jpeg, png, gif, webp, avif | What may be written. |
| `quality` | `80` | Encoding quality when neither the preset nor the URL sets one. `null` leaves it to the encoder. |
| `presets` | `[]` | Named operation sets. |
| `blade.component` | `variant` | Name the `<x-variant>` component registers under. `null` to skip it. |
| `cache_store` | `null` | Cache store for generation locks and source measurements. `null` uses the default. |
| `lock.enabled` | `true` | Serialise generation of the same variant across workers. |
| `lock.ttl` | `30` | Seconds a lock survives a worker that dies holding it. |
| `lock.wait` | `10` | Seconds a queued request waits before generating anyway. |
| `dimensions.ttl` | `86400` | Seconds a source's measurements are remembered. `0` disables caching. |
| `cleanup.days` | `30` | Default age for `image-variants:cleanup`. |

The route is registered outside any middleware group on purpose. Session
middleware in particular would attach a `Set-Cookie` and a private
`Cache-Control` to the one response a real visitor gets from PHP, undoing the
long-lived caching the whole scheme exists for.

If you point `source.disk` at a private disk, note that generated variants still
land in `cache` and are public once generated — the source stays unreachable,
the variant does not. Put the endpoint behind `route.middleware` if that matters.

## AI agents

The package ships an [Agent Skill](https://agentskills.io/what-are-skills) for
[Laravel Boost](https://laravel.com/docs/13.x/boost), at
`resources/boost/skills/image-variants-development/SKILL.md`. If your application
uses Boost, it is installed with:

```bash
php artisan boost:install

# or, for an application that already has Boost
php artisan boost:update --discover
```

The agent then loads it on demand when a task involves images, which saves it
inferring the operation grammar from the source — and, more usefully, stops it
hand-assembling variant URLs that will never match the HMAC.

## Running the Test Suite

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
```

## License and attribution

Laravel Image Variants is open-sourced software licensed under the
[MIT license](https://opensource.org/licenses/MIT).
