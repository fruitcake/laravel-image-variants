<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\Operations;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class ImageVariantTest extends TestCase
{
    private string $source;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-source-'.getmypid();
        $this->cache = sys_get_temp_dir().'/variant-cache-'.getmypid();

        File::ensureDirectoryExists($this->source);

        $canvas = imagecreatetruecolor(200, 100);
        $blue = imagecolorallocate($canvas, 30, 90, 150);

        $this->assertNotFalse($blue);

        imagefill($canvas, 0, 0, $blue);
        imagepng($canvas, $this->source.'/photo.png');

        // Mirrors the shipped default, which reads sources off a disk.
        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'path' => null],
            'image-variants.cache' => $this->cache,
            'image-variants.presets.thumb' => ['cover' => [60, 40], 'quality' => 80],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    #[Test]
    public function it_generates_and_caches_a_variant(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp');

        $this->get($variant->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->assertFileExists($variant->path());
        $this->assertSame([60, 40], array_slice((array) getimagesize($variant->path()), 0, 2));
    }

    #[Test]
    public function it_serves_the_name_given_to_it(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp', 'My SEO Name');

        $this->assertStringEndsWith('/my-seo-name.webp', explode('?', $variant->url())[0]);

        $this->get($variant->url())->assertOk();
    }

    #[Test]
    public function it_leaves_a_cached_variant_alone_on_the_second_request(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp');

        $this->get($variant->url())->assertOk();

        // Overwritten with something the generator would never produce: if the
        // second request still returns it, the cached file was reused.
        File::put($variant->path(), 'cached');

        $this->assertSame('cached', $this->get($variant->url())->streamedContent());
    }

    #[Test]
    public function it_merges_operations_over_a_preset(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp', operations: ['quality' => 50]);

        $this->assertSame(['cover' => '60,40', 'quality' => '50'], Operations::toQuery($variant->operations));
    }

    #[Test]
    public function a_false_operation_drops_the_presets_value_rather_than_replacing_it(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp', operations: ['quality' => false]);

        $this->assertSame(['cover' => '60,40'], Operations::toQuery($variant->operations));
    }

    #[Test]
    public function it_normalises_the_forms_an_operation_can_be_written_in(): void
    {
        $cases = [
            // Trailing omissions carry no meaning and are dropped; a leading gap is kept.
            [['scale' => 800], ['scale' => '800']],
            [['scale' => [800, null]], ['scale' => '800']],
            [['scale' => ',600'], ['scale' => ',600']],
            [['scale' => [null, 600]], ['scale' => ',600']],
            // Flags take any value at all.
            [['grayscale' => true], ['grayscale' => '1']],
            [['orient' => 'yes'], ['orient' => '1']],
            // String and array forms agree, and colours are lowercased.
            [['crop' => '300,200,50,25'], ['crop' => '300,200,50,25']],
            [['crop' => [300, 200, 50, 25]], ['crop' => '300,200,50,25']],
            [['rotate' => [90, 'FFF']], ['rotate' => '90,fff']],
            [['contain' => [10, 10, 'dominant']], ['contain' => '10,10,dominant']],
            // Applied in a fixed order, whatever order they were written in.
            [['quality' => 80, 'cover' => [10, 10], 'orient' => 1], ['orient' => '1', 'cover' => '10,10', 'quality' => '80']],
        ];

        foreach ($cases as [$operations, $expected]) {
            $this->assertSame($expected, Operations::toQuery(Operations::normalize($operations)));
        }
    }

    #[Test]
    public function it_builds_a_srcset_over_the_given_widths(): void
    {
        $srcset = Variants::srcset('photo.png', [100, 200], 'webp');

        $this->assertCount(2, explode(', ', $srcset));
        $this->assertStringContainsString('scale=100', $srcset);
        $this->assertStringEndsWith(' 200w', $srcset);
    }

    #[Test]
    public function the_same_operations_always_produce_the_same_hash(): void
    {
        $hashes = collect([
            ['cover' => [60, 40], 'quality' => 80],
            ['quality' => '80', 'cover' => '60,40'],
            ['cover' => '60,40', 'quality' => 80],
        ])->map(fn ($operations) => Variants::make('photo.png', $operations, 'webp')->hash());

        $this->assertCount(1, $hashes->unique());
    }

    #[Test]
    public function editing_a_preset_moves_its_urls_to_a_new_hash(): void
    {
        $before = Variants::make('photo.png', 'thumb', 'webp')->hash();

        config(['image-variants.presets.thumb' => ['cover' => [60, 40], 'quality' => 70]]);

        $this->assertNotSame($before, Variants::make('photo.png', 'thumb', 'webp')->hash());
    }

    #[Test]
    public function it_rejects_tampered_urls(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp');

        [$path, $query] = explode('?', $variant->url(), 2);
        [, , , $preset, $hash, $name] = explode('/', $path);

        $tampered = [
            "/storage/variants/{$preset}/aaaaaaaaaa/{$name}?{$query}",   // hash
            "/storage/variants/{$preset}/{$hash}/other.webp?{$query}",   // name
            "/storage/variants/{$preset}/{$hash}/{$name}?{$query}&grayscale=1",
            "/storage/variants/{$preset}/{$hash}/{$name}?".str_replace('quality=80', 'quality=70', $query),
            "/storage/variants/nope/{$hash}/{$name}?{$query}",           // unknown preset
            "/storage/variants/{$preset}/{$hash}/{$name}",               // no query
        ];

        foreach ($tampered as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->assertSame([], glob($this->cache.'/*/*/*') ?: []);
    }

    #[Test]
    public function it_rejects_a_swapped_out_source_over_http(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp');

        [$path] = explode('?', $variant->url(), 2);

        // These never reach the generator: the hash covers `src`, so pointing an
        // otherwise valid URL somewhere else stops at the hash check.
        foreach (['../../.env', '/etc/hosts', 'photo.png/../../../etc/hosts'] as $src) {
            $this->get($path.'?src='.rawurlencode($src).'&cover=60,40&quality=80')->assertNotFound();
        }
    }

    #[Test]
    public function it_rejects_operations_outside_the_grammar(): void
    {
        $invalid = [
            ['frobnicate' => 1],                       // unknown operation
            ['cover' => [10, 10], 'scale' => [10]],    // two geometry operations
            ['cover' => [9999, 9999]],                 // over max_dimension
            ['cover' => [60, null]],                   // cover needs both sides
            ['crop' => [30, 20, 5]],                   // an x offset without a y
            ['quality' => 0],                          // out of range
            ['contain' => [10, 10, 'zzz']],            // not a colour
            ['flip' => 'x'],                           // not a direction
        ];

        foreach ($invalid as $operations) {
            $this->assertThrows(fn () => Operations::normalize($operations), InvalidArgumentException::class);
        }
    }

    #[Test]
    public function it_rejects_output_formats_that_are_not_images(): void
    {
        $this->assertThrows(fn () => Variants::make('photo.png', 'thumb', 'php'), InvalidArgumentException::class);
    }

    #[Test]
    public function it_rejects_an_unknown_preset(): void
    {
        $this->assertThrows(fn () => Variants::make('photo.png', 'nope'), InvalidArgumentException::class);
    }
}
