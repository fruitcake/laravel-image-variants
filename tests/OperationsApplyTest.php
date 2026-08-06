<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Operations;
use Illuminate\Support\Facades\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every arm of Operations::apply() and VariantGenerator::encode(), run against a
 * real image.
 *
 * The rest of the suite proves the grammar normalises and hashes correctly, all
 * of which it does without ever touching an image. These are the tests that
 * would catch a transposed argument in the one line that finally does.
 */
class OperationsApplyTest extends TestCase
{
    /**
     * A 200x100 source, with a distinct top-left corner so orientation-changing
     * operations can be told apart from no-ops.
     */
    private function source(): string
    {
        $canvas = imagecreatetruecolor(200, 100);

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 200, 50, 50));
        imagefilledrectangle($canvas, 0, 0, 19, 19, (int) imagecolorallocate($canvas, 0, 0, 255));

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    /**
     * @param  array<string, mixed>  $operations
     * @return array{int, int}
     */
    private function apply(array $operations): array
    {
        $image = Operations::apply(Operations::normalize($operations), Image::fromBytes($this->source()));

        $size = getimagesizefromstring($image->toPng()->toBytes());

        $this->assertNotFalse($size);

        return [$size[0], $size[1]];
    }

    /**
     * @return array<string, array{array<string, mixed>, int, int}>
     */
    public static function operations(): array
    {
        return [
            'orient' => [['orient' => 1], 200, 100],
            'rotate square' => [['rotate' => '90,ffffff'], 100, 200],
            'rotate dominant' => [['rotate' => '90,dominant'], 100, 200],
            'flip vertically' => [['flip' => 'v'], 200, 100],
            'flip horizontally' => [['flip' => 'h'], 200, 100],
            'crop' => [['crop' => '50,40'], 50, 40],
            'crop offset' => [['crop' => '50,40,10,5'], 50, 40],
            'cover' => [['cover' => '60,40'], 60, 40],
            'contain' => [['contain' => '60,40,fff'], 60, 40],
            'contain dominant' => [['contain' => '60,40,dominant'], 60, 40],
            'resize both' => [['resize' => '60,40'], 60, 40],
            'resize width' => [['resize' => '60'], 60, 100],
            'scale width' => [['scale' => '60'], 60, 30],
            'scale height' => [['scale' => ',50'], 100, 50],
            'grayscale' => [['grayscale' => 1], 200, 100],
            'blur' => [['blur' => '5'], 200, 100],
            'sharpen' => [['sharpen' => '10'], 200, 100],
            'quality' => [['quality' => '80'], 200, 100],
            'combined, out of order' => [['quality' => 80, 'cover' => '60,40', 'grayscale' => 1], 60, 40],
        ];
    }

    /**
     * @param  array<string, mixed>  $operations
     */
    #[Test]
    #[DataProvider('operations')]
    public function it_applies(array $operations, int $width, int $height): void
    {
        $this->assertSame([$width, $height], $this->apply($operations));
    }

    #[Test]
    public function scale_never_enlarges_past_the_source(): void
    {
        // scale is the srcset workhorse, so a 2000w entry on a 200w source must
        // not produce a 2000px file — that would be a page weight regression
        // dressed up as responsiveness.
        $this->assertSame([200, 100], $this->apply(['scale' => '2000']));
    }

    #[Test]
    public function grayscale_actually_removes_the_colour(): void
    {
        $image = Operations::apply(
            Operations::normalize(['grayscale' => 1]),
            Image::fromBytes($this->source())
        );

        $pixel = $this->pixel($image->toPng()->toBytes(), 150, 50);

        $this->assertSame($pixel['red'], $pixel['green']);
        $this->assertSame($pixel['green'], $pixel['blue']);
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function pixel(string $png, int $x, int $y): array
    {
        $gd = imagecreatefromstring($png);

        $this->assertNotFalse($gd);

        $index = imagecolorat($gd, $x, $y);

        $this->assertNotFalse($index);

        return imagecolorsforindex($gd, $index);
    }

    #[Test]
    public function flipping_moves_the_marked_corner(): void
    {
        $corner = function (array $operations, int $x, int $y): int {
            $image = Operations::apply(Operations::normalize($operations), Image::fromBytes($this->source()));

            return $this->pixel($image->toPng()->toBytes(), $x, $y)['blue'];
        };

        // The blue marker starts top-left.
        $this->assertSame(255, $corner([], 5, 5));
        $this->assertSame(255, $corner(['flip' => 'h'], 194, 5));
        $this->assertSame(255, $corner(['flip' => 'v'], 5, 94));
    }

    /**
     * Every format in the shipped output_formats, with the GD function whose
     * presence decides whether this build can produce it.
     *
     * @return list<array{string, string, string}>
     */
    public static function formats(): array
    {
        return [
            ['jpg', 'image/jpeg', 'imagejpeg'],
            ['jpeg', 'image/jpeg', 'imagejpeg'],
            ['png', 'image/png', 'imagepng'],
            ['gif', 'image/gif', 'imagegif'],
            ['webp', 'image/webp', 'imagewebp'],
            ['avif', 'image/avif', 'imageavif'],
        ];
    }

    #[Test]
    #[DataProvider('formats')]
    public function it_encodes_every_configured_output_format(string $format, string $mime, string $encoder): void
    {
        // webp and avif are only encodable if the GD build was compiled for
        // them; the package permits both, the environment decides.
        if (! function_exists($encoder)) {
            $this->markTestSkipped("This PHP build cannot encode {$format} (no {$encoder}).");
        }

        $image = Image::fromBytes($this->source());

        $encoded = match ($format) {
            'jpg' => $image->toJpg(),
            'jpeg' => $image->toJpeg(),
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            'webp' => $image->toWebp(),
            'avif' => $image->toAvif(),
            default => $this->fail("No encoder case for [{$format}]."),
        };

        $bytes = $encoded->toBytes();

        $this->assertNotSame('', $bytes);
        $this->assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
    }
}
