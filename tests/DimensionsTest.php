<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Predicted dimensions, checked against what generating actually produces.
 *
 * A prediction that merely looks plausible is worse than none: it would put a
 * wrong aspect ratio on the tag and shift the page anyway. So every case here
 * generates the image too and compares.
 */
class DimensionsTest extends TestCase
{
    private string $source;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-dims-'.getmypid();
        $this->cache = sys_get_temp_dir().'/variant-dims-cache-'.getmypid();

        File::ensureDirectoryExists($this->source);

        imagepng(imagecreatetruecolor(200, 100), $this->source.'/wide.png');
        imagepng(imagecreatetruecolor(100, 200), $this->source.'/tall.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'prefix' => null],
            'image-variants.cache' => $this->cache,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function cases(): array
    {
        $both = [];

        $operations = [
            'cover' => ['cover' => '60,40'],
            'cover larger than source' => ['cover' => '300,300'],
            'contain' => ['contain' => '60,40,fff'],
            'crop' => ['crop' => '50,40'],
            'crop with offset' => ['crop' => '50,40,10,5'],
            'resize both' => ['resize' => '60,40'],
            'resize width only' => ['resize' => '60'],
            'resize height only' => ['resize' => ',40'],
            'resize larger' => ['resize' => '300,300'],
            'scale width' => ['scale' => '60'],
            'scale height' => ['scale' => ',50'],
            'scale box' => ['scale' => '60,40'],
            'scale box other axis' => ['scale' => '300,40'],
            'scale refuses to enlarge' => ['scale' => '1000'],
            'rotate 90' => ['rotate' => '90'],
            'rotate 180' => ['rotate' => '180'],
            'rotate 270' => ['rotate' => '270'],
            'flip' => ['flip' => 'h'],
            'grayscale only' => ['grayscale' => 1],
            'no operations at all' => [],
            'cover then rotate' => ['rotate' => '90', 'cover' => '60,40'],
            'rotate then scale' => ['rotate' => '90', 'scale' => '50'],
            'everything' => ['rotate' => '90', 'cover' => '80,60', 'grayscale' => 1, 'quality' => 70],
        ];

        foreach (['wide.png', 'tall.png'] as $src) {
            foreach ($operations as $label => $ops) {
                $both["{$label} on {$src}"] = [$src, $ops];
            }
        }

        return $both;
    }

    /**
     * @param  array<string, mixed>  $operations
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_predicts_what_generation_produces(string $src, array $operations): void
    {
        $variant = Variants::make($src, $operations, 'png');

        $predicted = $variant->dimensions();

        $this->assertNotNull($predicted, 'Expected a prediction.');

        $this->get($variant->url())->assertOk();

        $actual = getimagesize($variant->path());

        $this->assertNotFalse($actual);
        $this->assertSame(
            ['width' => $actual[0], 'height' => $actual[1]],
            $predicted,
        );
    }

    #[Test]
    public function it_predicts_every_entry_in_a_srcset(): void
    {
        foreach ([50, 100, 150] as $width) {
            $variant = Variants::make('wide.png', ['scale' => [$width, null]], 'png');

            $this->assertSame(['width' => $width, 'height' => $width / 2], $variant->dimensions());
        }
    }

    #[Test]
    public function it_admits_when_it_cannot_tell(): void
    {
        // Orientation depends on EXIF this has not read...
        $this->assertNull(Variants::dimensions('wide.png', ['orient' => 1], 'png'));

        // ...and an off-square rotation lands on a bounding box the encoder
        // rounds its own way.
        $this->assertNull(Variants::dimensions('wide.png', ['rotate' => '45'], 'png'));
    }

    #[Test]
    public function a_source_it_cannot_read_has_no_dimensions_rather_than_an_error(): void
    {
        // scale needs the source's aspect ratio, so there is nothing to answer
        // with — and a missing source is the generator's to complain about, not
        // something a template asking for a width should throw over.
        $this->assertNull(Variants::dimensions('missing.png', ['scale' => '60'], 'png'));
        $this->assertNull(Variants::dimensions('missing.png', [], 'png'));

        // cover, by contrast, is answerable without the source ever being read,
        // so it still says what it would have produced.
        $this->assertSame(
            ['width' => 60, 'height' => 40],
            Variants::dimensions('missing.png', ['cover' => '60,40'], 'png')
        );
    }

    #[Test]
    public function operations_that_state_their_own_size_never_touch_the_source(): void
    {
        // The reason a thumb preset costs nothing to measure: cover says what it
        // produces, so there is no source to go and look at.
        File::delete($this->source.'/wide.png');

        $this->assertSame(
            ['width' => 60, 'height' => 40],
            Variants::dimensions('wide.png', ['cover' => '60,40'], 'png')
        );
    }

    #[Test]
    public function a_remote_sources_measurements_are_remembered(): void
    {
        Cache::flush();

        $key = 'image-variants:dimensions:'.sha1("images\nwide.png");

        $this->assertNull(Cache::get($key));

        Variants::dimensions('wide.png', ['scale' => '60'], 'png');

        $this->assertSame([200, 100], Cache::get($key));
    }

    #[Test]
    public function measuring_can_be_left_uncached(): void
    {
        config(['image-variants.dimensions.ttl' => 0]);

        Cache::flush();

        Variants::dimensions('wide.png', ['scale' => '60'], 'png');

        $this->assertNull(Cache::get('image-variants:dimensions:'.sha1("images\nwide.png")));
    }
}
