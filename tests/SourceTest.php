<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Variant;
use Fruitcake\ImageVariants\VariantConfigurationException;
use Fruitcake\ImageVariants\VariantException;
use Fruitcake\ImageVariants\VariantFactory;
use Fruitcake\ImageVariants\VariantGenerator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * Where sources are read from, and what is refused.
 *
 * Laid out as the standard Laravel tree, because a disk rooted at public/ has to
 * be able to follow the storage symlink to be of any use:
 *
 *     base/public/img/photo.png
 *     base/public/storage -> base/storage/app/public
 *     base/storage/app/public/uploads/photo.png
 */
class SourceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/variant-tree-'.getmypid();

        File::ensureDirectoryExists($this->base.'/public/img');
        File::ensureDirectoryExists($this->base.'/storage/app/public/uploads');

        $this->image($this->base.'/public/img/photo.png');
        $this->image($this->base.'/storage/app/public/uploads/photo.png');

        symlink($this->base.'/storage/app/public', $this->base.'/public/storage');

        config([
            'filesystems.disks.source' => ['driver' => 'local', 'root' => $this->base.'/public'],
            'image-variants.source' => ['disk' => 'source', 'prefix' => null],
            'image-variants.cache' => $this->base.'/cache',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function image(string $path): void
    {
        $canvas = imagecreatetruecolor(20, 10);

        imagepng($canvas, $path);
    }

    private function read(string $src): string
    {
        return app(VariantGenerator::class)->contents(new Variant('custom', [], $src, 'photo.webp'));
    }

    #[Test]
    public function it_reads_a_source_off_the_configured_disk(): void
    {
        $this->assertNotSame('', $this->read('img/photo.png'));
    }

    #[Test]
    public function a_disk_rooted_at_public_follows_the_storage_symlink(): void
    {
        $this->assertNotSame('', $this->read('storage/uploads/photo.png'));
    }

    #[Test]
    public function a_prefix_confines_sources_to_a_directory_within_the_disk(): void
    {
        config(['image-variants.source.prefix' => 'img']);

        $this->assertNotSame('', $this->read('photo.png'));

        // The prefix is not something a URL can climb out of.
        $this->assertThrows(fn () => $this->read('../storage/uploads/photo.png'), VariantException::class);

        // And what was addressable without it no longer is.
        $this->assertThrows(fn () => $this->read('img/photo.png'), VariantException::class);
    }

    #[Test]
    public function a_prefix_is_accepted_with_or_without_slashes(): void
    {
        foreach (['img', '/img', 'img/', '/img/'] as $prefix) {
            config(['image-variants.source.prefix' => $prefix]);

            $this->assertNotSame('', $this->read('photo.png'));
        }
    }

    #[Test]
    public function it_refuses_a_source_outside_the_disk_root(): void
    {
        foreach (['../../.env', '/etc/hosts', "photo.png\0", '', 'img/../../secret.png'] as $src) {
            $this->assertThrows(fn () => $this->read($src), VariantException::class);
        }
    }

    #[Test]
    public function it_refuses_a_source_that_is_not_there(): void
    {
        $this->assertThrows(fn () => $this->read('img/nope.png'), VariantException::class);
    }

    #[Test]
    public function it_refuses_a_source_that_is_not_an_image(): void
    {
        File::put($this->base.'/public/img/notes.txt', 'not an image');

        $this->assertThrows(fn () => $this->read('img/notes.txt'), VariantException::class);
    }

    /**
     * A broken deployment must not read as a missing image: VariantException is
     * answered with a 404, and these are not that.
     */
    #[Test]
    public function misconfiguration_is_not_reported_as_a_missing_source(): void
    {
        config(['image-variants.source.disk' => 'nonexistent']);

        $this->assertThrows(fn () => $this->read('img/photo.png'), VariantConfigurationException::class);

        config(['image-variants.source.disk' => null]);

        $this->assertThrows(fn () => $this->read('img/photo.png'), VariantConfigurationException::class);
    }

    #[Test]
    public function it_generates_from_a_disk_source_end_to_end(): void
    {
        $variant = app(VariantFactory::class)->make('img/photo.png', ['cover' => [8, 6]], 'webp');

        $this->get($variant->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->assertSame([8, 6], array_slice((array) getimagesize($variant->path()), 0, 2));
    }
}
