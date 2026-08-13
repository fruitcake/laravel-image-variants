<?php

namespace Fruitcake\ImageVariants;

use Illuminate\Image\Image;
use InvalidArgumentException;

/**
 * The operation grammar.
 *
 * Operations are query parameters named after the methods on Illuminate\Image\Image:
 *
 *     ?src=img/bg.jpg&cover=600,500&quality=80
 *     ?src=img/bg.jpg&scale=800&grayscale=1
 *     ?src=img/bg.jpg&crop=300,200,50,25&rotate=90,ffffff
 *
 * A query string carries no ordering of its own, so operations are applied in the
 * fixed order below rather than in the order they appear. Values are normalised
 * before hashing, which is what keeps one set of operations mapped to exactly one
 * file on disk however the URL happened to be written.
 */
final class Operations
{
    /**
     * The order operations are applied in, regardless of their order in the URL.
     */
    private const ORDER = [
        'orient', 'rotate', 'flip', 'crop',
        'cover', 'contain', 'resize', 'scale',
        'grayscale', 'blur', 'sharpen',
        'quality',
    ];

    /**
     * Operations that resize the whole image. At most one may be used at a time;
     * combining them is a mistake rather than a pipeline.
     */
    private const GEOMETRY = ['cover', 'contain', 'resize', 'scale'];

    /**
     * Operations that take no arguments; any value simply switches them on.
     */
    private const FLAGS = ['orient', 'grayscale'];

    /**
     * Validate and canonicalise a set of operations.
     *
     * Accepts both the string forms that arrive in a query string ('600,500') and
     * the array forms that presets are written in ([600, 500]). A false or null
     * value switches an operation off, so presets can be overridden away.
     *
     * @param  array<string, mixed>  $operations
     * @return array<string, list<mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(array $operations): array
    {
        $normalized = [];

        foreach ($operations as $name => $value) {
            $name = strtolower((string) $name);

            if (! in_array($name, self::ORDER, true)) {
                throw new InvalidArgumentException("Unknown image operation [{$name}].");
            }

            if ($value === false || $value === null) {
                continue;
            }

            if (in_array($name, self::FLAGS, true)) {
                $normalized[$name] = [];

                continue;
            }

            $args = self::arguments($value);

            $normalized[$name] = match ($name) {
                'flip' => [self::direction($name, $args)],
                'rotate' => self::rotate($name, $args),
                'crop' => self::crop($name, $args),
                'cover' => self::box($name, $args, both: true),
                'contain' => self::contain($name, $args),
                'resize', 'scale' => self::box($name, $args, both: false),
                'blur' => [self::number($name, $args[0] ?? '5', 0, 100)],
                'sharpen' => [self::number($name, $args[0] ?? '10', 0, 100)],
                'quality' => [self::number($name, $args[0] ?? null, 1, 100)],
            };
        }

        $geometry = array_values(array_intersect(self::GEOMETRY, array_keys($normalized)));

        if (count($geometry) > 1) {
            throw new InvalidArgumentException(
                'Only one of ['.implode(', ', self::GEOMETRY).'] may be used at a time, got ['.implode(', ', $geometry).'].'
            );
        }

        return self::sort($normalized);
    }

    /**
     * Render normalised operations back to their canonical query form. This is both
     * what goes into the URL and what gets hashed, so the two can never drift apart.
     *
     * @param  array<string, list<mixed>>  $normalized
     * @return array<string, string>
     */
    public static function toQuery(array $normalized): array
    {
        $query = [];

        foreach (self::sort($normalized) as $name => $args) {
            // Trailing omissions carry no meaning: scale=[800, null] is written
            // "scale=800", while scale=[null, 600] keeps its gap as "scale=,600".
            while ($args !== [] && end($args) === null) {
                array_pop($args);
            }

            $query[$name] = $args === []
                ? '1'
                : implode(',', array_map(fn ($arg) => $arg === null ? '' : (string) $arg, $args));
        }

        return $query;
    }

    /**
     * Apply the operations. Images are immutable, so every call returns a new instance.
     *
     * @param  array<string, list<mixed>>  $normalized
     */
    public static function apply(array $normalized, Image $image): Image
    {
        foreach (self::sort($normalized) as $name => $args) {
            $image = match ($name) {
                'orient' => $image->orient(),
                'rotate' => $image->rotate($args[0], self::background($args[1] ?? null)),
                'flip' => $args[0] === 'v' ? $image->flipVertically() : $image->flipHorizontally(),
                'crop' => $image->crop($args[0], $args[1], $args[2] ?? 0, $args[3] ?? 0),
                'cover' => $image->cover($args[0], $args[1]),
                'contain' => $image->contain($args[0], $args[1], self::background($args[2] ?? null)),
                'resize' => $image->resize($args[0], $args[1] ?? null),
                'scale' => $image->scale($args[0], $args[1] ?? null),
                'grayscale' => $image->grayscale(),
                'blur' => $image->blur($args[0]),
                'sharpen' => $image->sharpen($args[0]),
                'quality' => $image->quality($args[0]),
                default => throw new InvalidArgumentException("Unknown image operation [{$name}]."),
            };
        }

        return $image;
    }

    /**
     * @param  array<string, list<mixed>>  $normalized
     * @return array<string, list<mixed>>
     */
    private static function sort(array $normalized): array
    {
        $sorted = [];

        foreach (self::ORDER as $name) {
            if (array_key_exists($name, $normalized)) {
                $sorted[$name] = $normalized[$name];
            }
        }

        return $sorted;
    }

    /**
     * @return list<string|null>
     */
    private static function arguments(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(
                fn ($arg) => $arg === null || $arg === '' ? null : (string) $arg,
                $value
            ));
        }

        return array_map(
            fn ($arg) => trim($arg) === '' ? null : trim($arg),
            explode(',', (string) $value)
        );
    }

    /**
     * @param  list<string|null>  $args
     * @return list<int|string|null>
     */
    private static function rotate(string $name, array $args): array
    {
        self::expect($name, $args, 1, 2);

        return [
            self::number($name, $args[0] ?? null, 0, 359),
            isset($args[1]) ? self::color($name, $args[1]) : null,
        ];
    }

    /**
     * @param  list<string|null>  $args
     * @return list<int>
     */
    private static function crop(string $name, array $args): array
    {
        // Offsets come as a pair: an x without a y is a typo, not a default.
        self::expect($name, $args, 2, 4);

        $box = [
            self::number($name, $args[0] ?? null, 1),
            self::number($name, $args[1] ?? null, 1),
        ];

        if (count($args) === 2) {
            return $box;
        }

        return [...$box, self::number($name, $args[2] ?? null, 0), self::number($name, $args[3] ?? null, 0)];
    }

    /**
     * @param  list<string|null>  $args
     * @return list<int|string|null>
     */
    private static function contain(string $name, array $args): array
    {
        self::expect($name, $args, 2, 3);

        return [
            ...self::box($name, array_slice($args, 0, 2), both: true),
            isset($args[2]) ? self::color($name, $args[2]) : null,
        ];
    }

    /**
     * A width and a height, either of which may be omitted when $both is false.
     *
     * @param  list<string|null>  $args
     * @return list<int|null>
     */
    private static function box(string $name, array $args, bool $both): array
    {
        self::expect($name, $args, 1, 2);

        $width = isset($args[0]) ? self::number($name, $args[0], 1) : null;
        $height = isset($args[1]) ? self::number($name, $args[1], 1) : null;

        if ($both && ($width === null || $height === null)) {
            throw new InvalidArgumentException("Operation [{$name}] needs both a width and a height.");
        }

        if ($width === null && $height === null) {
            throw new InvalidArgumentException("Operation [{$name}] needs at least one dimension.");
        }

        return [$width, $height];
    }

    /**
     * @param  list<string|null>  $args
     */
    private static function direction(string $name, array $args): string
    {
        self::expect($name, $args, 1);

        if (! in_array($args[0], ['v', 'h'], true)) {
            throw new InvalidArgumentException("Operation [{$name}] expects [v] or [h], got [".($args[0] ?? 'nothing').'].');
        }

        return $args[0];
    }

    /**
     * Colours are stored bare so they survive a URL; Intervention wants them prefixed.
     */
    private static function background(?string $color): ?string
    {
        if ($color === null || $color === 'dominant') {
            return $color;
        }

        return '#'.$color;
    }

    /**
     * A whole number in range. Dimensions pass no $max: what a URL may ask for is
     * bounded by it having to be signed at all, and an application asking its own
     * code for a 20000px image is entitled to one.
     */
    private static function number(string $name, ?string $value, int $min, ?int $max = null): int
    {
        if ($value === null || ! preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException("Operation [{$name}] expects a whole number, got [".($value ?? 'nothing').'].');
        }

        $number = (int) $value;

        if ($number < $min) {
            throw new InvalidArgumentException("Operation [{$name}] expects a number of at least {$min}, got [{$number}].");
        }

        if ($max !== null && $number > $max) {
            throw new InvalidArgumentException("Operation [{$name}] expects a number between {$min} and {$max}, got [{$number}].");
        }

        return $number;
    }

    private static function color(string $name, string $value): string
    {
        $value = strtolower($value);

        if ($value === 'dominant') {
            return $value;
        }

        if (! preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
            throw new InvalidArgumentException("Operation [{$name}] expects a hex colour or [dominant], got [{$value}].");
        }

        return $value;
    }

    /**
     * @param  list<string|null>  $args
     */
    private static function expect(string $name, array $args, int ...$counts): void
    {
        if (! in_array(count($args), $counts, true)) {
            $expected = implode(' or ', $counts);

            throw new InvalidArgumentException("Operation [{$name}] expects {$expected} argument(s), got ".count($args).'.');
        }
    }
}
