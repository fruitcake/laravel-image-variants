<?php

namespace Fruitcake\ImageVariants\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Fruitcake\ImageVariants\Variant make(string $src, string|array<string, mixed> $preset = 'custom', ?string $format = null, ?string $name = null, array<string, mixed> $operations = [])
 * @method static string url(string $src, string|array<string, mixed> $preset = 'custom', ?string $format = null, ?string $name = null, array<string, mixed> $operations = [])
 * @method static array{width: int, height: int}|null dimensions(string $src, string|array<string, mixed> $preset = 'custom', ?string $format = null, ?string $name = null, array<string, mixed> $operations = [])
 * @method static string srcset(string $src, list<int> $widths, ?string $format = null, string|array<string, mixed> $preset = 'custom', ?string $name = null)
 * @method static \Fruitcake\ImageVariants\Variant fromRequest(\Illuminate\Http\Request $request, string $preset, string $hash, string $name)
 *
 * @see \Fruitcake\ImageVariants\VariantFactory
 */
class Variants extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'image-variants';
    }
}
