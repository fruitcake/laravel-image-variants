<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\Variant;
use Fruitcake\ImageVariants\VariantException;
use Fruitcake\ImageVariants\VariantFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nothing is generated without a signature, and nothing a signature covers can
 * be moved without invalidating it.
 *
 * The endpoint is unauthenticated and writes files to disk, so this is the one
 * guarantee everything else rests on.
 */
class SignatureTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/variant-signature-'.getmypid();

        File::ensureDirectoryExists($this->base.'/disk/uploads/nested');
        File::ensureDirectoryExists($this->base.'/outside');

        imagepng(imagecreatetruecolor(50, 50), $this->base.'/disk/uploads/ok.png');
        imagepng(imagecreatetruecolor(50, 50), $this->base.'/disk/uploads/nested/deep.png');
        imagepng(imagecreatetruecolor(50, 50), $this->base.'/outside/private.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->base.'/disk'],
            'image-variants.source' => ['disk' => 'images', 'prefix' => 'uploads'],
            'image-variants.cache' => $this->base.'/cache',
            'image-variants.presets.thumb' => ['cover' => [20, 20], 'quality' => 80],
            'image-variants.presets.wide' => ['cover' => [40, 20], 'quality' => 80],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    /**
     * @return array{preset: string, hash: string, name: string, query: string}
     */
    private function parts(Variant $variant): array
    {
        [$path, $query] = explode('?', $variant->url(), 2);
        [, , , $preset, $hash, $name] = explode('/', $path);

        return compact('preset', 'hash', 'name', 'query');
    }

    private function cachedFiles(): int
    {
        return count(glob($this->base.'/cache/*/*/*') ?: []);
    }

    #[Test]
    public function nothing_reaches_disk_without_a_matching_signature(): void
    {
        $variant = Variants::make('ok.png', 'thumb', 'webp');

        ['preset' => $preset, 'hash' => $hash, 'name' => $name, 'query' => $query] = $this->parts($variant);

        $forged = [
            'no query at all' => "/storage/variants/{$preset}/{$hash}/{$name}",
            'source swapped for one outside the prefix' => "/storage/variants/{$preset}/{$hash}/{$name}?src=../private.png&cover=20,20&quality=80",
            'source climbing out of the disk' => "/storage/variants/{$preset}/{$hash}/{$name}?src=../../outside/private.png&cover=20,20&quality=80",
            'absolute source' => "/storage/variants/{$preset}/{$hash}/{$name}?src=/etc/hosts&cover=20,20&quality=80",
            'an operation added' => "/storage/variants/{$preset}/{$hash}/{$name}?{$query}&grayscale=1",
            'an operation overridden' => "/storage/variants/{$preset}/{$hash}/{$name}?{$query}&quality=70",
            'name changed' => "/storage/variants/{$preset}/{$hash}/other.webp?{$query}",
            'hash zeroed' => "/storage/variants/{$preset}/0000000000/{$name}?{$query}",
        ];

        $this->assertSame(
            array_fill_keys(array_keys($forged), 404),
            array_map(fn ($url) => $this->get($url)->getStatusCode(), $forged),
        );

        $this->assertSame(0, $this->cachedFiles(), 'A refused request wrote something.');
    }

    /**
     * What is signed is the operations *after* the preset is merged in, so for a
     * preset URL the query is redundant — the preset already says everything,
     * and `?src=…` on its own rebuilds the identical variant.
     *
     * This is the preset earning its place in the path rather than a hole. The
     * only thing reachable under a given signature is still the one variant that
     * produced it: anything that changes the merged result changes the hash.
     */
    #[Test]
    public function a_preset_supplies_the_operations_the_query_leaves_out(): void
    {
        $variant = Variants::make('ok.png', 'thumb', 'webp');

        ['preset' => $preset, 'hash' => $hash, 'name' => $name] = $this->parts($variant);

        $spellings = [
            'as generated' => $variant->url(),
            'quality left to the preset' => "/storage/variants/{$preset}/{$hash}/{$name}?src=ok.png&cover=20,20",
            'everything left to the preset' => "/storage/variants/{$preset}/{$hash}/{$name}?src=ok.png",
        ];

        $this->assertSame(
            array_fill_keys(array_keys($spellings), 200),
            array_map(fn ($url) => $this->get($url)->getStatusCode(), $spellings),
        );

        // One variant however it was written, so one file — not three.
        $this->assertSame(1, $this->cachedFiles());
    }

    /**
     * The other half of that: an operation the preset does *not* define is only
     * in the URL because it was signed there, and dropping it is a real change.
     */
    #[Test]
    public function an_operation_the_preset_does_not_define_cannot_be_dropped(): void
    {
        $variant = Variants::make('ok.png', 'thumb', 'webp', null, ['grayscale' => true]);

        ['preset' => $preset, 'hash' => $hash, 'name' => $name] = $this->parts($variant);

        $this->get("/storage/variants/{$preset}/{$hash}/{$name}?src=ok.png&cover=20,20&quality=80")
            ->assertNotFound();

        $this->assertSame(0, $this->cachedFiles());
    }

    /**
     * The preset is part of what is signed, so a URL cannot be walked from one
     * preset to another — not to a different real preset, not to `custom`, and
     * not to one that does not exist.
     */
    #[Test]
    public function a_preset_cannot_be_substituted(): void
    {
        $variant = Variants::make('ok.png', 'thumb', 'webp');

        ['hash' => $hash, 'name' => $name, 'query' => $query] = $this->parts($variant);

        $presets = ['wide', 'custom', 'nope', 'thumb2'];

        $this->assertSame(
            array_fill_keys($presets, 404),
            array_combine($presets, array_map(
                fn ($preset) => $this->get("/storage/variants/{$preset}/{$hash}/{$name}?{$query}")->getStatusCode(),
                $presets
            )),
        );

        // And the reverse: another preset's own valid URL is a different hash.
        $this->assertNotSame($hash, $this->parts(Variants::make('ok.png', 'wide', 'webp'))['hash']);

        $this->assertSame(0, $this->cachedFiles());
    }

    #[Test]
    public function two_presets_that_normalise_alike_are_still_told_apart(): void
    {
        config(['image-variants.presets.twin' => ['cover' => [20, 20], 'quality' => 80]]);

        // Same operations, different preset name — the name is signed too, and
        // it decides which directory the file lands in.
        $this->assertNotSame(
            Variants::make('ok.png', 'thumb', 'webp')->hash(),
            Variants::make('ok.png', 'twin', 'webp')->hash(),
        );
    }

    #[Test]
    public function the_signature_is_required_by_the_factory_not_just_the_controller(): void
    {
        $request = Request::create('/x', 'GET', ['src' => 'ok.png', 'cover' => '20,20', 'quality' => '80']);

        $factory = app(VariantFactory::class);

        // There is no way to get a Variant out of a request without the
        // signature checking out, so no caller can forget to look.
        $this->assertThrows(
            fn () => $factory->fromRequest($request, 'thumb', '0000000000', 'ok.webp'),
            VariantException::class
        );

        $valid = $this->parts(Variants::make('ok.png', 'thumb', 'webp'))['hash'];

        $this->assertInstanceOf(
            Variant::class,
            $factory->fromRequest($request, 'thumb', $valid, 'ok.webp')
        );
    }

    /**
     * preset and name are path segments of the generated file, so a Variant that
     * could hold a separator or a climb would put path() outside the cache
     * directory — whatever the route pattern happens to allow.
     */
    #[Test]
    public function a_variant_cannot_hold_a_traversing_path_segment(): void
    {
        $bad = [
            ['../../evil', 'x.webp'],
            ['..', 'x.webp'],
            ['a/b', 'x.webp'],
            ['a\\b', 'x.webp'],
            ["a\0b", 'x.webp'],
            ['', 'x.webp'],
            ['ok', '../../../evil.webp'],
            ['ok', 'sub/evil.webp'],
            ['ok', ''],
        ];

        foreach ($bad as [$preset, $name]) {
            $this->assertThrows(
                fn () => new Variant($preset, [], 'ok.png', $name),
                VariantException::class
            );
        }
    }

    #[Test]
    public function a_variant_cannot_hold_a_climbing_source(): void
    {
        foreach (['../secret.png', '../../etc/passwd', '/etc/passwd', "ok\0.png", '', 'a/../../b.png'] as $src) {
            $this->assertThrows(
                fn () => new Variant('thumb', [], $src, 'ok.webp'),
                VariantException::class
            );
        }
    }

    #[Test]
    public function a_generated_path_always_stays_inside_the_cache_directory(): void
    {
        $variant = Variants::make('nested/deep.png', 'thumb', 'webp', 'Some Long Title From A CMS');

        $this->assertStringStartsWith($this->base.'/cache/', $variant->path());
        $this->assertStringNotContainsString('..', $variant->path());
    }

    #[Test]
    public function a_source_in_a_subdirectory_still_works(): void
    {
        // The guards must not cost legitimate nesting, which is the normal way
        // uploads are laid out.
        $variant = Variants::make('nested/deep.png', 'thumb', 'webp');

        $this->get($variant->url())->assertOk();
        $this->assertFileExists($variant->path());
    }

    #[Test]
    public function a_signed_url_is_honoured_exactly_once_per_variant(): void
    {
        $variant = Variants::make('ok.png', 'thumb', 'webp');

        $this->get($variant->url())->assertOk();
        $this->get($variant->url())->assertOk();

        $this->assertSame(1, $this->cachedFiles());
    }
}
