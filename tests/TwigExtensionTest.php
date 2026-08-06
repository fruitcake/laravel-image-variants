<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\Twig\VariantExtension;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TwigExtensionTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-twig-'.getmypid();

        File::ensureDirectoryExists($this->source);

        imagepng(imagecreatetruecolor(200, 100), $this->source.'/bg.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'prefix' => null],
            'image-variants.presets.hero' => ['scale' => [1600, null], 'quality' => 80],
            'image-variants.presets.thumb' => ['cover' => [60, 40], 'quality' => 80],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    private function render(string $template): string
    {
        $twig = new Environment(new ArrayLoader(['page' => $template]));

        $twig->addExtension(app(VariantExtension::class));

        return $twig->render('page');
    }

    #[Test]
    public function variant_renders_a_url(): void
    {
        $this->assertStringStartsWith(
            '/storage/variants/hero/',
            $this->render("{{ variant('bg.png', 'hero') }}")
        );
    }

    #[Test]
    public function variant_takes_ad_hoc_operations_as_a_twig_hash(): void
    {
        $rendered = $this->render("{{ variant('bg.png', {cover: [60, 40], quality: 80}, 'webp') }}");

        $this->assertStringContainsString('/storage/variants/custom/', $rendered);
        $this->assertStringContainsString('cover=60,40', $rendered);

        // Twig escapes the separators, which is what an attribute wants; the URL
        // a browser resolves from it is the one that was built.
        $this->assertStringEndsWith('.webp?src=bg.png&amp;cover=60,40&amp;quality=80', $rendered);
        $this->assertSame(
            Variants::url('bg.png', ['cover' => [60, 40], 'quality' => 80], 'webp'),
            html_entity_decode($rendered)
        );
    }

    #[Test]
    public function variant_takes_a_name(): void
    {
        $this->assertStringContainsString(
            '/team-photo.webp',
            $this->render("{{ variant('bg.png', 'thumb', 'webp', 'Team Photo') }}")
        );
    }

    #[Test]
    public function srcset_renders_every_width(): void
    {
        $rendered = $this->render("{{ srcset('bg.png', [40, 80], 'webp') }}");

        $this->assertStringContainsString('scale=40', $rendered);
        $this->assertStringContainsString(' 40w,', $rendered);
        $this->assertStringEndsWith(' 80w', $rendered);
    }

    #[Test]
    public function variant_size_renders_dimensions(): void
    {
        $this->assertSame(
            'width="60" height="40"',
            $this->render(
                "{% set s = variant_size('bg.png', 'thumb') %}".
                'width="{{ s.width }}" height="{{ s.height }}"'
            )
        );
    }

    #[Test]
    public function variant_size_is_falsy_when_it_cannot_tell(): void
    {
        $this->assertSame(
            'unknown',
            $this->render(
                "{% set s = variant_size('bg.png', {orient: 1}) %}".
                '{% if s %}{{ s.width }}{% else %}unknown{% endif %}'
            )
        );
    }
}
