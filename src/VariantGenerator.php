<?php

namespace Fruitcake\ImageVariants;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Image\Image as ProcessedImage;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;

/**
 * Turns a Variant into a file on disk.
 *
 * Sources are read off a filesystem disk, so they can live anywhere Laravel can
 * reach. Generated variants always go to a local path, because the whole scheme
 * rests on the web server finding that file where the browser asked for it.
 */
class VariantGenerator
{
    /**
     * Generate the variant unless it is already cached, and return its path.
     *
     * @throws VariantException
     * @throws VariantConfigurationException
     * @throws ImageException
     */
    public function generate(Variant $variant): string
    {
        $target = $variant->path();

        if (File::exists($target)) {
            return $target;
        }

        $lock = $this->lock($target);

        if ($lock === null) {
            return $this->write($variant, $target);
        }

        try {
            $lock->block((int) config('image-variants.lock.wait', 10));
        } catch (LockTimeoutException) {
            // Waited long enough that either the holder is wedged or this image
            // is genuinely slow. Answering the request matters more than the
            // duplicated work, so go ahead unlocked.
            return $this->write($variant, $target);
        }

        try {
            return $this->write($variant, $target);
        } finally {
            $lock->release();
        }
    }

    /**
     * Serialise generation of one variant across workers.
     *
     * Without this, N requests arriving for the same uncached URL each decode,
     * resize and encode the same image at the same time — a page of cold images
     * costs what it should, but one cold URL under load costs N times over.
     *
     * @throws VariantConfigurationException
     */
    protected function lock(string $target): ?Lock
    {
        if (! config('image-variants.lock.enabled', true)) {
            return null;
        }

        $name = config('image-variants.cache_store');

        try {
            $repository = Cache::store($name);
        } catch (InvalidArgumentException $e) {
            throw new VariantConfigurationException(
                "The cache store [{$name}] set in [image-variants.cache_store] is not defined.", 0, $e
            );
        }

        $store = $repository instanceof Repository ? $repository->getStore() : null;

        // Every store Laravel ships bar `null` can do this, so reaching here
        // means a deliberate choice worth pointing at rather than working around.
        if (! $store instanceof LockProvider) {
            throw new VariantConfigurationException(
                'The cache store used for image variant locks does not support locking. '.
                'Point [image-variants.cache_store] at one that does, or set [image-variants.lock.enabled] to false.'
            );
        }

        return $store->lock('image-variants:'.sha1($target), (int) config('image-variants.lock.ttl', 30));
    }

    /**
     * @throws VariantException
     * @throws VariantConfigurationException
     * @throws ImageException
     */
    protected function write(Variant $variant, string $target): string
    {
        // The authoritative check, rather than the caller's. Whoever we queued
        // behind has almost certainly just written this, which is the entire
        // point of having waited — and on the path where waiting timed out, it
        // is the last chance to notice they finished after all.
        if (File::exists($target)) {
            return $target;
        }

        $image = Operations::apply($variant->operations, $this->read($variant));

        $bytes = $this->encode($image, $variant->format())->toBytes();

        File::ensureDirectoryExists(dirname($target));

        // Write out of the way first and rename into place, so a request arriving
        // while we generate can never be handed a partially written file. The
        // name is unique per write rather than per process, because two requests
        // for the same variant can be in flight in one process under Octane, and
        // because the lock above is an optimisation rather than a guarantee.
        $temporary = $target.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        File::put($temporary, $bytes);
        File::move($temporary, $target);

        return $target;
    }

    /**
     * Open the source image.
     *
     * @throws VariantException
     * @throws VariantConfigurationException
     */
    public function read(Variant $variant): ProcessedImage
    {
        $contents = $this->contents($variant);

        $this->guardSourceDimensions($contents);

        return Image::fromBytes($contents);
    }

    /**
     * Refuse a source whose pixel count would cost more to decode than it is
     * worth, however small the file is.
     *
     * The signature bounds what a URL may *ask* for, which is no protection at
     * all here: what a source costs to decode is decided by the source, not by
     * the operations. A solid-colour 10000x10000 PNG is 300KB on disk and ~380MB
     * once decoded, and asking it for a 60x40 thumbnail still pays that in full.
     * With the default source disk being where uploads land, anyone who can
     * upload an avatar could otherwise exhaust a worker on every variant of it.
     *
     * The header is read on its own, so nothing is decoded to find this out.
     *
     * @throws VariantException
     */
    protected function guardSourceDimensions(string $contents): void
    {
        $limit = (int) config('image-variants.max_source_megapixels', 24);

        if ($limit <= 0) {
            return;
        }

        $size = @getimagesizefromstring($contents);

        // A format whose header this PHP build cannot read is left to the
        // decoder, which reports it as an unreadable image.
        if ($size === false) {
            return;
        }

        $megapixels = ($size[0] * $size[1]) / 1_000_000;

        if ($megapixels > $limit) {
            throw new VariantException(sprintf(
                'Source is %.1f megapixels (%dx%d), over the %d megapixel limit.',
                $megapixels, $size[0], $size[1], $limit
            ));
        }
    }

    /**
     * Read the source off the configured disk.
     *
     * Containment is guardRelativePath() first — a src that climbs at all is
     * refused before the disk sees it — with Flysystem's own traversal check
     * behind that. Symlinks inside the disk root are followed, so a disk rooted
     * at public_path() reaches public/storage the way you would expect.
     *
     * The hash already guarantees the URL came from us, so all of this is the
     * second line of defence rather than the first.
     *
     * @throws VariantException
     * @throws VariantConfigurationException
     */
    public function contents(Variant $variant): string
    {
        $src = $variant->src;

        $this->guardRelativePath($src);
        $this->guardSourceFormat($src);

        $disk = $this->disk();

        try {
            $filesystem = Storage::disk($disk);
        } catch (InvalidArgumentException $e) {
            throw new VariantConfigurationException(
                "The configured source disk [{$disk}] is not defined in [filesystems.disks].", 0, $e
            );
        }

        try {
            // One read rather than an exists() and then a get(): a disk answers
            // both from the same place, and on a remote one that is a round trip
            // per call. A disk hands back null for anything it cannot read;
            // one configured with `throw` raises instead.
            $contents = $filesystem->get($this->prefixed($src));
        } catch (FilesystemException $e) {
            throw new VariantException("Source [{$src}] could not be read: {$e->getMessage()}", 0, $e);
        }

        if ($contents === null || $contents === '') {
            throw new VariantException("Source [{$src}] does not exist.");
        }

        return $contents;
    }

    /**
     * The source's path on the local filesystem, or null if it does not have
     * one — a remote disk has only a key.
     *
     * Lets a caller that needs no more than the header avoid fetching the file.
     *
     * @throws VariantException
     * @throws VariantConfigurationException
     */
    public function localPath(Variant $variant): ?string
    {
        $this->guardRelativePath($variant->src);
        $this->guardSourceFormat($variant->src);

        $path = Storage::disk($this->disk())->path($this->prefixed($variant->src));

        return is_file($path) ? $path : null;
    }

    /**
     * @throws VariantConfigurationException
     */
    public function disk(): string
    {
        $disk = config('image-variants.source.disk');

        if (! is_string($disk) || $disk === '') {
            throw new VariantConfigurationException(
                'Set [image-variants.source.disk] to a disk from [filesystems.disks].'
            );
        }

        return $disk;
    }

    /**
     * Place the source below the configured prefix, if there is one.
     */
    public function prefixed(string $src): string
    {
        $prefix = config('image-variants.source.prefix');

        if (! is_string($prefix) || trim($prefix, '/') === '') {
            return $src;
        }

        return trim($prefix, '/').'/'.$src;
    }

    /**
     * @throws VariantException
     */
    protected function guardRelativePath(string $src): void
    {
        if ($src === '' || str_contains($src, "\0") || str_contains($src, '..') || str_starts_with($src, '/')) {
            throw new VariantException("Source [{$src}] is not a valid relative path.");
        }
    }

    /**
     * @throws VariantException
     */
    protected function guardSourceFormat(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, (array) config('image-variants.source_formats'), true)) {
            throw new VariantException("Source [{$path}] is not a supported image format.");
        }
    }

    protected function encode(ProcessedImage $image, string $format): ProcessedImage
    {
        return match ($format) {
            'jpg' => $image->toJpg(),
            'jpeg' => $image->toJpeg(),
            'png' => $image->toPng(),
            'gif' => $image->toGif(),
            'webp' => $image->toWebp(),
            'avif' => $image->toAvif(),
            'bmp' => $image->toBmp(),
            default => throw new VariantException("Unsupported output format [{$format}]."),
        };
    }
}
