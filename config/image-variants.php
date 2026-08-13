<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | Where the endpoint is mounted. The prefix must line up with a publicly
    | reachable path to the cache directory below, so that once a variant has
    | been generated the web server serves it straight off disk without ever
    | reaching PHP again. With the stock `storage:link` symlink in place,
    | "storage/variants" and storage_path('app/public/variants') do exactly that.
    |
    */

    'route' => [
        'prefix' => 'storage/variants',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | The filesystem disk source images are read from, and an optional directory
    | within it to confine them to. Every `$src` is addressed relative to the
    | two together; anything resolving outside is rejected.
    |
    | The default is Laravel's own "public" disk, storage/app/public, which is
    | where uploads normally land and what `storage:link` exposes. To serve
    | images committed under public/ instead, add a disk for them in
    | config/filesystems.php and name it here:
    |
    |     'assets' => ['driver' => 'local', 'root' => public_path()],
    |
    | Any disk works, remote ones included: only the first request for a variant
    | reads the source, so an s3 disk costs one read per variant, ever.
    |
    */

    'source' => [

        'disk' => 'public',

        'prefix' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Where generated variants are written. This is a local path rather than a
    | disk on purpose: the file has to land exactly where the browser asked for
    | it, so that the web server answers every request after the first without
    | PHP. Serving from a CDN is a matter of putting one in front of this app,
    | not of writing the variants somewhere else.
    |
    */

    'cache' => storage_path('app/public/variants'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Every URL is keyed with an HMAC over the preset, the merged operations, the
    | source and the name — there is no unsigned path to a variant, whatever the
    | preset — so only URLs this application generated can produce one at all.
    | What a URL may *ask* for therefore needs no limit of its own: nothing can
    | ask for anything this application did not already decide to offer.
    |
    | What it costs to answer is another matter, because that is decided by the
    | source rather than by the URL. Decoding takes roughly 4 bytes per pixel
    | whatever the file size, so a 300KB solid-colour 10000x10000 PNG needs
    | ~380MB even to make a thumbnail of. This is the largest source that will be
    | decoded, in megapixels, read from the image header without decoding it.
    |
    | 24 clears any camera upload; lower it if memory_limit is tight, or set it
    | to 0 to accept anything.
    |
    */

    'max_source_megapixels' => 24,

    'hash_length' => 10,

    'source_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'],

    'output_formats' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],

    /*
    |--------------------------------------------------------------------------
    | Quality
    |--------------------------------------------------------------------------
    |
    | The encoding quality, 1–100, used when neither the preset nor the URL asks
    | for one. It is merged underneath both, so a preset overrides it and an
    | operation passed alongside overrides that in turn — and a preset setting
    | `'quality' => false` drops it, leaving the encoder to its own default.
    |
    | Like a preset it is part of what a URL is signed with, so changing it
    | changes every hash that relied on it: those variants regenerate rather than
    | being served at the old quality. Set it to null to leave the encoder alone
    | unless something asks otherwise.
    |
    */

    'quality' => 80,

    /*
    |--------------------------------------------------------------------------
    | Blade
    |--------------------------------------------------------------------------
    |
    | The name the <x-variant> component is registered under. Rename it if the
    | application already has one by that name, or set it to null to leave the
    | component unregistered and use the facade directly.
    |
    */

    'blade' => [

        'component' => 'variant',

    ],

    /*
    |--------------------------------------------------------------------------
    | Locking and measuring
    |--------------------------------------------------------------------------
    |
    | Generating a variant is serialised per URL, so that N requests arriving
    | for the same uncached image do the work once instead of N times. `wait` is
    | how long a queued request waits before giving up and generating anyway;
    | `ttl` is how long the lock survives a worker that dies holding it, so it
    | wants to be comfortably longer than a slow generation.
    |
    | `dimensions.ttl` is how long a source's measurements are remembered for
    | Variants::dimensions(). Local sources are measured from the file header
    | and barely need it; a remote disk has to fetch the file, and does.
    |
    */

    'cache_store' => null,

    'lock' => [

        'enabled' => true,

        'ttl' => 30,

        'wait' => 10,

    ],

    'dimensions' => [

        'ttl' => 86400,

    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    |
    | The default age, in days, used by `php artisan image-variants:cleanup`.
    | Nothing in the cache is irreplaceable — a variant that gets deleted is
    | regenerated by the next request for it — so this is only a trade between
    | disk usage and how often a visitor pays for a regeneration.
    |
    */

    'cleanup' => [

        'days' => 30,

    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Named operation sets. The preset name becomes a directory in the URL, so
    | it is worth keeping them readable. Operations passed alongside a preset
    | are merged over it.
    |
    | Editing a preset changes the hash of every URL using it, so variants from
    | the old definition are never served: they simply regenerate.
    |
    */

    'presets' => [

//        'thumb' => ['cover' => [100, 100], 'quality' => 80],
//
//        'photo' => ['scale' => [800, null], 'quality' => 80],
//
//        'hero' => ['scale' => [1600, null], 'quality' => 80],

    ],

];
