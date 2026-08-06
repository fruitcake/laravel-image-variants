<?php

namespace Fruitcake\ImageVariants;

use Illuminate\Support\Facades\Cache;

/**
 * Works out how big a variant will be, without generating it.
 *
 * This is what lets a template emit `width` and `height` on an image it has not
 * built yet, which is the whole point of doing it: a browser that knows the
 * aspect ratio up front reserves the space and does not shift the page when the
 * image lands.
 *
 * Most of the time no source is read at all. `cover`, `crop` and `contain` state
 * their output dimensions outright, so a preset built from them is answered from
 * the operations alone; only `scale` and a one-sided `resize` need the source's
 * aspect ratio.
 */
class VariantDimensions
{
    public function __construct(protected VariantGenerator $generator)
    {
    }

    /**
     * The variant's dimensions, or null if they cannot be known without
     * generating it — see remarks on `orient` and `rotate` below.
     *
     * @return array{width: int, height: int}|null
     *
     * @throws VariantConfigurationException
     */
    public function for(Variant $variant): ?array
    {
        $width = null;
        $height = null;

        // Already in application order: Operations::normalize() sorts before it
        // returns, and that is what a Variant is built from.
        foreach ($variant->operations as $name => $args) {
            if (in_array($name, ['flip', 'grayscale', 'blur', 'sharpen', 'quality'], true)) {
                continue;
            }

            // Whether this swaps the axes depends on EXIF this has not read, and
            // reading it would cost the source fetch the rest of this avoids.
            if ($name === 'orient') {
                return null;
            }

            if ($name === 'rotate') {
                $angle = (int) $args[0];

                // Anything off the square lands on a bounding box the encoder
                // rounds its own way; only the exact quarter turns are safe.
                if ($angle % 90 !== 0) {
                    return null;
                }

                if ($angle % 180 === 90) {
                    [$width, $height] = $this->source($variant, $width, $height) ?? [null, null];

                    if ($width === null || $height === null) {
                        return null;
                    }

                    [$width, $height] = [$height, $width];
                }

                continue;
            }

            // These state their output outright, whatever came before them.
            if (in_array($name, ['cover', 'contain', 'crop'], true)) {
                [$width, $height] = [(int) $args[0], (int) $args[1]];

                continue;
            }

            $source = $this->source($variant, $width, $height);

            if ($source === null) {
                return null;
            }

            [$width, $height] = $name === 'scale'
                ? $this->scaled($source, $args)
                : [$args[0] ?? $source[0], $args[1] ?? $source[1]];
        }

        if ($width === null || $height === null) {
            [$width, $height] = $this->source($variant, $width, $height) ?? [null, null];
        }

        return $width === null || $height === null
            ? null
            : ['width' => (int) $width, 'height' => (int) $height];
    }

    /**
     * Fit inside the given box without enlarging, which is what `scale` maps to.
     *
     * @param  array{int, int}  $source
     * @param  list<mixed>  $args
     * @return array{int, int}
     */
    protected function scaled(array $source, array $args): array
    {
        [$width, $height] = $source;

        $ratio = 1.0;

        if (isset($args[0])) {
            $ratio = min($ratio, ((int) $args[0]) / $width);
        }

        if (isset($args[1])) {
            $ratio = min($ratio, ((int) $args[1]) / $height);
        }

        return [
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
        ];
    }

    /**
     * The dimensions in play at this point: whatever an earlier operation left,
     * or the source's own.
     *
     * @return array{int, int}|null
     */
    protected function source(Variant $variant, ?int $width, ?int $height): ?array
    {
        if ($width !== null && $height !== null) {
            return [$width, $height];
        }

        return $this->remember($variant);
    }

    /**
     * @return array{int, int}|null
     */
    protected function remember(Variant $variant): ?array
    {
        $ttl = (int) config('image-variants.dimensions.ttl', 86400);

        if ($ttl <= 0) {
            return $this->measure($variant);
        }

        $key = 'image-variants:dimensions:'.sha1(implode("\n", [
            $this->generator->disk(),
            $this->generator->prefixed($variant->src),
        ]));

        /** @var array{int, int}|null $cached */
        $cached = Cache::store(config('image-variants.cache_store'))
            // A null is stored as a miss and retried, which is what a source
            // that is not there yet deserves.
            ->remember($key, $ttl, fn () => $this->measure($variant));

        return $cached;
    }

    /**
     * Read the source's dimensions from its header.
     *
     * @return array{int, int}|null
     */
    protected function measure(Variant $variant): ?array
    {
        try {
            $path = $this->generator->localPath($variant);

            // A local disk can be measured from the header alone. A remote one
            // has to be fetched, which is what the cache above is for.
            $size = $path !== null
                ? @getimagesize($path)
                : @getimagesizefromstring($this->generator->contents($variant));
        } catch (VariantException) {
            // A source that is missing or refused has no dimensions to report.
            // It is not this method's job to raise that — the generator will,
            // when something actually asks for the image.
            return null;
        }

        return $size === false ? null : [$size[0], $size[1]];
    }
}
