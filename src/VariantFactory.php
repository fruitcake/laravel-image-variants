<?php

namespace Fruitcake\ImageVariants;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds variants, both the ones we hand out in HTML and the one described by an
 * incoming request.
 */
class VariantFactory
{
    /**
     * The preset name used when there is no preset, only ad-hoc operations.
     */
    public const CUSTOM = 'custom';

    /**
     * How much of a filename survives, before its extension. Well under the
     * 255-byte path segment limit, with room for the generator's temp suffix.
     */
    private const MAX_STEM = 100;

    /**
     * Describe a variant of the given source image.
     *
     * The second argument is either a preset name or a map of operations. Anything
     * in $operations is merged over the preset, so a named preset can be reused
     * with one value changed.
     *
     * @param  string|array<string, mixed>  $preset
     * @param  array<string, mixed>  $operations
     *
     * @throws InvalidArgumentException
     */
    public function make(
        string $src,
        string|array $preset = self::CUSTOM,
        ?string $format = null,
        ?string $name = null,
        array $operations = [],
    ): Variant {
        if (is_array($preset)) {
            $operations = array_merge($preset, $operations);
            $preset = self::CUSTOM;
        }

        $normalized = Operations::normalize(array_merge($this->preset($preset), $operations));

        $format = strtolower($format ?: pathinfo($src, PATHINFO_EXTENSION));

        $this->guardFormat($format);

        return new Variant($preset, $normalized, ltrim($src, '/'), $this->filename($name ?: $src, $format));
    }

    /**
     * The URL for a variant, which is all a template ever needs.
     *
     * @param  string|array<string, mixed>  $preset
     * @param  array<string, mixed>  $operations
     */
    public function url(
        string $src,
        string|array $preset = self::CUSTOM,
        ?string $format = null,
        ?string $name = null,
        array $operations = [],
    ): string {
        return $this->make($src, $preset, $format, $name, $operations)->url();
    }

    /**
     * How big the variant will be, without generating it.
     *
     * Takes the same arguments as url(), so a template can ask for the URL and
     * the dimensions of one image the same way.
     *
     * @param  string|array<string, mixed>  $preset
     * @param  array<string, mixed>  $operations
     * @return array{width: int, height: int}|null
     */
    public function dimensions(
        string $src,
        string|array $preset = self::CUSTOM,
        ?string $format = null,
        ?string $name = null,
        array $operations = [],
    ): ?array {
        return $this->make($src, $preset, $format, $name, $operations)->dimensions();
    }

    /**
     * A srcset over the given widths, scaling the source to each one.
     *
     * @param  list<int>  $widths
     * @param  string|array<string, mixed>  $preset
     */
    public function srcset(
        string $src,
        array $widths,
        ?string $format = null,
        string|array $preset = self::CUSTOM,
        ?string $name = null,
    ): string {
        $sources = array_map(function ($width) use ($src, $format, $preset, $name) {
            $width = (int) $width;

            return $this->url($src, $preset, $format, $name, ['scale' => [$width, null]]).' '.$width.'w';
        }, $widths);

        return implode(', ', $sources);
    }

    /**
     * Rebuild the variant an incoming request describes, and refuse it unless
     * the signature in the path is the one this application would have produced.
     *
     * The check lives here rather than in the caller so that there is no way to
     * obtain a Variant from a request without it: an unsigned request cannot be
     * turned into something a caller might go on to generate, whatever the
     * preset, the operations, or the source say.
     *
     * @throws VariantException
     * @throws InvalidArgumentException
     */
    public function fromRequest(Request $request, string $preset, string $hash, string $name): Variant
    {
        $query = $request->query();

        $src = $query['src'] ?? null;

        unset($query['src']);

        if (! is_string($src) || $src === '') {
            throw new InvalidArgumentException('A [src] parameter is required.');
        }

        $normalized = Operations::normalize(array_merge($this->preset($preset), $query));

        $this->guardFormat(strtolower(pathinfo($name, PATHINFO_EXTENSION)));

        $variant = new Variant($preset, $normalized, ltrim($src, '/'), $name);

        if (! hash_equals($variant->hash(), $hash)) {
            throw new VariantException('The signature does not match this URL.');
        }

        return $variant;
    }

    /**
     * @return array<string, mixed>
     */
    protected function preset(string $preset): array
    {
        if ($preset === self::CUSTOM) {
            return [];
        }

        $operations = config('image-variants.presets.'.$preset);

        if (! is_array($operations)) {
            throw new InvalidArgumentException("Unknown image preset [{$preset}].");
        }

        return $operations;
    }

    /**
     * Reduce a name to something that is safe as a single URL and path segment.
     * It is otherwise free: the hash covers it, so it can be chosen for looks.
     *
     * Length is capped because a path segment over 255 bytes cannot be written
     * on any common filesystem, and names are routinely built from a title the
     * package never sees. Truncating costs nothing — the hash, not the name, is
     * what keeps two variants apart.
     */
    protected function filename(string $name, string $format): string
    {
        $stem = Str::slug(pathinfo($name, PATHINFO_FILENAME));

        return substr($stem !== '' ? $stem : 'image', 0, self::MAX_STEM).'.'.$format;
    }

    protected function guardFormat(string $format): void
    {
        if (! in_array($format, (array) config('image-variants.output_formats'), true)) {
            throw new InvalidArgumentException("Unsupported output format [{$format}].");
        }
    }
}
