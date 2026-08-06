<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\Variant;
use Fruitcake\ImageVariants\VariantConfigurationException;
use Fruitcake\ImageVariants\VariantException;
use Fruitcake\ImageVariants\VariantGenerator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * The limits that stop a valid-looking request from costing more than it should.
 */
class HardeningTest extends TestCase
{
    private string $source;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-hardening-'.getmypid();
        $this->cache = sys_get_temp_dir().'/variant-hardening-cache-'.getmypid();

        File::ensureDirectoryExists($this->source);

        imagepng(imagecreatetruecolor(200, 100), $this->source.'/photo.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'prefix' => null],
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

    /**
     * Write a solid-colour PNG, which costs ~4 bytes per pixel to decode however
     * little it takes on disk.
     */
    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function oversized(string $name, int $width, int $height): void
    {
        imagepng(imagecreatetruecolor($width, $height), $this->source.'/'.$name, 9);
    }

    #[Test]
    public function it_refuses_a_source_over_the_megapixel_limit(): void
    {
        config(['image-variants.max_source_megapixels' => 1]);

        $this->oversized('big.png', 2000, 2000);   // 4 megapixels

        $this->assertThrows(
            fn () => app(VariantGenerator::class)->read(new Variant('custom', [], 'big.png', 'big.webp')),
            VariantException::class
        );
    }

    #[Test]
    public function the_megapixel_limit_is_about_pixels_not_file_size(): void
    {
        config(['image-variants.max_source_megapixels' => 1]);

        $this->oversized('big.png', 2000, 2000);

        // The point of the guard: tiny on disk, expensive to decode.
        $this->assertLessThan(100 * 1024, (int) filesize($this->source.'/big.png'));

        // And asking for a thumbnail does not make it cheaper.
        $variant = Variants::make('big.png', 'thumb', 'webp');

        $this->get($variant->url())->assertNotFound();
        $this->assertFileDoesNotExist($variant->path());
    }

    #[Test]
    public function a_source_within_the_limit_still_generates(): void
    {
        config(['image-variants.max_source_megapixels' => 1]);

        $variant = Variants::make('photo.png', 'thumb', 'webp');

        $this->get($variant->url())->assertOk();
    }

    #[Test]
    public function the_megapixel_limit_can_be_turned_off(): void
    {
        config(['image-variants.max_source_megapixels' => 0]);

        $this->oversized('big.png', 2000, 2000);

        $this->get(Variants::make('big.png', 'thumb', 'webp')->url())->assertOk();
    }

    #[Test]
    public function a_long_name_is_truncated_rather_than_breaking_the_write(): void
    {
        // Path segments over 255 bytes cannot be written, and names are often
        // built from a title the package never sees.
        $variant = Variants::make('photo.png', 'thumb', 'webp', str_repeat('a', 400));

        $this->assertLessThanOrEqual(255, strlen(basename($variant->path())));

        $this->get($variant->url())->assertOk();
        $this->assertFileExists($variant->path());
    }

    #[Test]
    public function truncation_collapses_names_that_would_have_produced_the_same_image(): void
    {
        $first = Variants::make('photo.png', 'thumb', 'webp', str_repeat('a', 400).'-one');
        $second = Variants::make('photo.png', 'thumb', 'webp', str_repeat('a', 400).'-two');

        // Same source, same operations: the two differ only in a part of the
        // name that no longer exists, so they are one variant. Sharing the file
        // is right — the bytes would have been identical anyway.
        $this->assertSame($first->path(), $second->path());
    }

    #[Test]
    public function truncation_does_not_let_different_images_share_a_file(): void
    {
        imagepng(imagecreatetruecolor(80, 80), $this->source.'/other.png');

        $name = str_repeat('a', 400);

        // Identical (truncated) names, different sources: the hash covers src,
        // so they stay apart.
        $this->assertNotSame(
            Variants::make('photo.png', 'thumb', 'webp', $name)->path(),
            Variants::make('other.png', 'thumb', 'webp', $name)->path(),
        );

        // ...and so do differing operations under one name.
        $this->assertNotSame(
            Variants::make('photo.png', 'thumb', 'webp', $name)->path(),
            Variants::make('photo.png', 'thumb', 'webp', $name, ['quality' => 50])->path(),
        );
    }

    #[Test]
    public function urls_cannot_be_signed_without_an_app_key(): void
    {
        config(['app.key' => '']);

        // Refused rather than falling back to a digest anyone could compute,
        // which would turn the endpoint into an open resize service.
        $this->assertThrows(
            fn () => Variants::make('photo.png', 'thumb', 'webp')->hash(),
            VariantConfigurationException::class
        );
    }

    #[Test]
    public function the_signature_is_domain_separated_from_the_rest_of_the_app(): void
    {
        $variant = Variants::make('photo.png', 'thumb', 'webp');

        $payload = "thumb\ncover=60,40&quality=80\nphoto.png\nphoto.webp";
        $key = (string) config('app.key');

        $this->assertNotSame(substr(hash_hmac('sha256', $payload, $key), 0, 10), $variant->hash());
        $this->assertSame(substr(hash_hmac('sha256', $payload, 'image-variants|'.$key), 0, 10), $variant->hash());
    }
}
