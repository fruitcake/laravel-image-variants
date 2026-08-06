<?php

namespace Fruitcake\ImageVariants\View\Components;

use Fruitcake\ImageVariants\VariantFactory;
use Illuminate\View\Component;

/**
 * Renders a complete <img> for a variant.
 *
 *     <x-variant src="img/bg.jpg" preset="hero" alt="Our office" />
 *     <x-variant src="img/bg.jpg" :widths="[640, 1024, 1600]" format="webp" alt="" />
 *
 * The point of it is that everything a good image tag needs comes from the same
 * description: the URL, the srcset, and the width and height that stop the page
 * shifting when it loads. Written out by hand that means asking for the same
 * variant three times and threading a null check through the middle of a
 * template.
 *
 * Anything else you put on the tag — class, loading, decoding, fetchpriority —
 * passes straight through.
 */
class VariantImage extends Component
{
    /**
     * @var array<string, string>
     */
    public array $imageAttributes;

    /**
     * @param  string|array<string, mixed>  $preset  A preset name, or operations.
     * @param  array<string, mixed>  $operations  Merged over the preset.
     * @param  list<int>|null  $widths  Widths to build a srcset from.
     * @param  string|null  $sizes  The sizes attribute; only meaningful with $widths.
     */
    public function __construct(
        string $src,
        string|array $preset = VariantFactory::CUSTOM,
        ?string $format = null,
        ?string $name = null,
        array $operations = [],
        ?array $widths = null,
        ?string $sizes = null,
    ) {
        $variants = app(VariantFactory::class);

        $widths = array_values(array_map(intval(...), $widths ?? []));

        // src is what anything ignoring srcset falls back to, so it is the
        // largest of the widths rather than whichever happened to be listed
        // first — a fallback should not be the smallest image on offer.
        if ($widths !== []) {
            $operations = array_merge($operations, ['scale' => [max($widths), null]]);
        }

        $variant = $variants->make($src, $preset, $format, $name, $operations);

        $size = $variant->dimensions();

        $this->imageAttributes = array_filter([
            'src' => $variant->url(),
            'srcset' => $widths === [] ? null : $variants->srcset($src, $widths, $format, $preset, $name),
            'sizes' => $widths === [] ? null : $sizes,
            // Omitted rather than guessed when the variant cannot be measured
            // without generating it; a wrong ratio shifts the page just as a
            // missing one does.
            'width' => isset($size['width']) ? (string) $size['width'] : null,
            'height' => isset($size['height']) ? (string) $size['height'] : null,
            // Always present, so the tag is valid even when nobody said. Pass a
            // real one for any image that carries meaning; leaving it empty
            // tells a screen reader the image is decorative.
            'alt' => '',
        ], fn ($value) => $value !== null);
    }

    public function render(): string
    {
        return '<img {{ $attributes->merge($imageAttributes) }}>';
    }
}
