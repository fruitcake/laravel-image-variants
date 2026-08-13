<?php

namespace Fruitcake\ImageVariants;

/**
 * One image variant: a source, a set of operations, and the name it is served under.
 *
 *     /storage/variants/{preset}/{hash}/{name}?src=…&…operations…
 *
 * Only the three path segments decide where the file lands on disk. The hash is
 * computed over every operation, so the first request can reconstruct the work to
 * do — and every request after that is answered by the web server from the cached
 * file, query string and all, without PHP being involved.
 *
 * The query carries less than the hash covers, because it does not have to carry
 * what the server can look up: the preset and the configured defaults are merged
 * back in before the signature is checked, so an operation that came from either
 * is spelled out only when a caller overrode it. A preset URL is therefore just
 * its source, and none of it is optional — anything the query does say is signed.
 */
final class Variant
{
    /**
     * @param  string  $preset  A preset name from config, or "custom" for ad-hoc operations.
     * @param  array<string, list<mixed>>  $operations  Normalised, as returned by Operations::normalize().
     * @param  string  $src  Source path, relative to the configured source directory.
     * @param  string  $name  The filename the variant is served as, including its extension.
     * @param  array<string, list<mixed>>|null  $explicit  The subset of $operations the URL spells
     *                                                     out. Null spells out all of them, which
     *                                                     is what a Variant built by hand wants:
     *                                                     nothing else knows what its preset covers.
     */
    public function __construct(
        public readonly string $preset,
        public readonly array $operations,
        public readonly string $src,
        public readonly string $name,
        public readonly ?array $explicit = null,
    ) {
        // Checked here rather than trusted from whoever built this, so that
        // path() cannot escape the cache directory however a Variant came to
        // exist. Over HTTP the route pattern already constrains these and the
        // signature has to match — but that makes this safe by arrangement,
        // and a Variant built in PHP goes through neither.
        $this->guardSegment('preset', $preset);
        $this->guardSegment('name', $name);
        $this->guardSource($src);
    }

    /**
     * A single path and URL segment: no separators, no climbing, nothing that
     * changes which directory it lands in.
     *
     * @throws VariantException
     */
    private function guardSegment(string $what, string $value): void
    {
        if ($value === '' || preg_match('#[/\\\\\0]#', $value) === 1 || str_contains($value, '..')) {
            throw new VariantException("A variant [{$what}] cannot be [{$value}].");
        }
    }

    /**
     * The source may name a subdirectory, so it keeps its slashes — but it is
     * still relative, and still may not climb.
     *
     * @throws VariantException
     */
    private function guardSource(string $src): void
    {
        if ($src === '' || str_contains($src, "\0") || str_contains($src, '..') || str_starts_with($src, '/')) {
            throw new VariantException("Source [{$src}] is not a valid relative path.");
        }
    }

    /**
     * The output format, taken from the name's extension.
     */
    public function format(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * How big this will be once generated, for a template that wants to say so
     * on the tag and stop the page shifting when the image arrives.
     *
     * Null when it cannot be known without generating — see VariantDimensions.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(): ?array
    {
        return app(VariantDimensions::class)->for($this);
    }

    /**
     * A keyed digest over everything that describes this variant.
     *
     * Keyed rather than plain, because the server has to recompute it to know where
     * the file belongs — and if an attacker could compute it too, they could point
     * any URL at any path and fill the disk. With the key, only URLs this
     * application generated can produce a variant at all, which is why no separate
     * signature parameter is needed.
     *
     * @throws VariantConfigurationException
     */
    public function hash(): string
    {
        $key = (string) config('app.key');

        // Without a key the digest is plain SHA-256 of public inputs, which
        // anyone can compute — the endpoint would quietly become an open resize
        // service. Refused rather than degraded, because the failure is silent.
        if ($key === '') {
            throw new VariantConfigurationException(
                'Image variant URLs are signed with [app.key], which is not set. Run `php artisan key:generate`.'
            );
        }

        $payload = implode("\n", [
            $this->preset,
            $this->canonicalQuery(),
            $this->src,
            $this->name,
        ]);

        return substr(
            // Prefixed so this digest cannot collide with anything else the
            // application signs with the same key.
            hash_hmac('sha256', $payload, 'image-variants|'.$key),
            0,
            (int) config('image-variants.hash_length', 10)
        );
    }

    /**
     * Where the generated file lives.
     */
    public function path(): string
    {
        return implode('/', [
            rtrim((string) config('image-variants.cache'), '/'),
            $this->preset,
            $this->hash(),
            $this->name,
        ]);
    }

    /**
     * The root-relative URL, including the query the first request needs.
     */
    public function url(): string
    {
        $prefix = trim((string) config('image-variants.route.prefix', 'storage/variants'), '/');

        return '/'.implode('/', [
            $prefix,
            $this->preset,
            $this->hash(),
            rawurlencode($this->name),
        ]).'?'.$this->encodedQuery();
    }

    /**
     * What the URL says. Only what the server cannot work out for itself, which is
     * the source and whatever a caller asked for on top of the preset.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return ['src' => $this->src] + Operations::toQuery($this->explicit ?? $this->operations);
    }

    /**
     * The exact string that gets hashed. Built from *every* normalised operation,
     * including the ones the query leaves to the preset and the defaults, so
     * however the incoming URL was written, both sides arrive at the same digest.
     */
    private function canonicalQuery(): string
    {
        $parts = [];

        foreach (Operations::toQuery($this->operations) as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode('&', $parts);
    }

    private function encodedQuery(): string
    {
        $parts = [];

        foreach ($this->query() as $key => $value) {
            // Slashes and commas are legal in a query string and much easier to
            // read left alone, so only genuinely unsafe characters get encoded.
            $parts[] = $key.'='.str_replace(['%2F', '%2C'], ['/', ','], rawurlencode($value));
        }

        return implode('&', $parts);
    }
}
