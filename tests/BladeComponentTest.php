<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\ImageVariantsServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class BladeComponentTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-blade-'.getmypid();

        File::ensureDirectoryExists($this->source);

        imagepng(imagecreatetruecolor(200, 100), $this->source.'/bg.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'prefix' => null],
            'image-variants.presets.thumb' => ['cover' => [60, 40], 'quality' => 80],
            'image-variants.presets.photo' => ['scale' => [150, null], 'quality' => 80],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    private function render(string $template): string
    {
        return trim(Blade::render($template));
    }

    #[Test]
    public function it_renders_an_img_with_dimensions_worked_out_for_it(): void
    {
        $rendered = $this->render('<x-variant src="bg.png" preset="thumb" alt="A thumbnail" />');

        $this->assertStringContainsString('src="'.e(Variants::url('bg.png', 'thumb')).'"', $rendered);
        $this->assertStringContainsString('width="60"', $rendered);
        $this->assertStringContainsString('height="40"', $rendered);
        $this->assertStringContainsString('alt="A thumbnail"', $rendered);
    }

    #[Test]
    public function it_measures_a_preset_that_depends_on_the_source(): void
    {
        // photo scales to 150 wide; the source is 200x100, so 150x75.
        $rendered = $this->render('<x-variant src="bg.png" preset="photo" />');

        $this->assertStringContainsString('width="150"', $rendered);
        $this->assertStringContainsString('height="75"', $rendered);
    }

    #[Test]
    public function it_builds_a_srcset_and_falls_back_to_the_largest_width(): void
    {
        $rendered = $this->render(
            '<x-variant src="bg.png" :widths="[50, 100]" sizes="100vw" format="webp" alt="" />'
        );

        $this->assertStringContainsString('srcset="', $rendered);
        $this->assertStringContainsString('50w', $rendered);
        $this->assertStringContainsString('100w', $rendered);
        $this->assertStringContainsString('sizes="100vw"', $rendered);

        // src is the largest of the widths, and the dimensions describe it.
        $this->assertStringContainsString('src="'.e(Variants::url('bg.png', ['scale' => [100, null]], 'webp')).'"', $rendered);
        $this->assertStringContainsString('width="100"', $rendered);
        $this->assertStringContainsString('height="50"', $rendered);
    }

    #[Test]
    public function sizes_is_left_off_when_there_is_no_srcset(): void
    {
        $rendered = $this->render('<x-variant src="bg.png" preset="thumb" sizes="100vw" />');

        $this->assertStringNotContainsString('sizes=', $rendered);
        $this->assertStringNotContainsString('srcset=', $rendered);
    }

    #[Test]
    public function it_takes_ad_hoc_operations(): void
    {
        $rendered = $this->render('<x-variant src="bg.png" :preset="[\'cover\' => [30, 30]]" format="webp" />');

        $this->assertStringContainsString('width="30"', $rendered);
        $this->assertStringContainsString('height="30"', $rendered);
        $this->assertStringContainsString('.webp', $rendered);
    }

    #[Test]
    public function it_always_emits_an_alt_so_the_tag_is_valid(): void
    {
        $this->assertStringContainsString('alt=""', $this->render('<x-variant src="bg.png" preset="thumb" />'));
    }

    #[Test]
    public function anything_else_on_the_tag_passes_through(): void
    {
        $rendered = $this->render(
            '<x-variant src="bg.png" preset="thumb" class="rounded" loading="lazy" decoding="async" id="hero" />'
        );

        $this->assertStringContainsString('class="rounded"', $rendered);
        $this->assertStringContainsString('loading="lazy"', $rendered);
        $this->assertStringContainsString('decoding="async"', $rendered);
        $this->assertStringContainsString('id="hero"', $rendered);
    }

    #[Test]
    public function it_leaves_the_dimensions_off_when_it_cannot_measure_them(): void
    {
        $rendered = $this->render('<x-variant src="bg.png" :preset="[\'orient\' => 1]" />');

        $this->assertStringNotContainsString('width=', $rendered);
        $this->assertStringNotContainsString('height=', $rendered);
        $this->assertStringContainsString('src=', $rendered);
    }

    #[Test]
    public function the_component_name_is_configurable(): void
    {
        config(['image-variants.blade.component' => 'fruitcake-image']);

        $app = $this->app;

        $this->assertNotNull($app);

        // Re-register under the new name, as a fresh application boot would.
        $app->register(ImageVariantsServiceProvider::class, true);

        $this->assertStringContainsString(
            'width="60"',
            $this->render('<x-fruitcake-image src="bg.png" preset="thumb" />')
        );
    }
}
