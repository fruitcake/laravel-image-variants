<?php

namespace Fruitcake\ImageVariants\Tests;

use Fruitcake\ImageVariants\Facades\Variants;
use Fruitcake\ImageVariants\Variant;
use Fruitcake\ImageVariants\VariantConfigurationException;
use Fruitcake\ImageVariants\VariantGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * Generation is serialised per variant, so that a burst of requests for one
 * uncached URL does the work once rather than once each.
 */
class LockTest extends TestCase
{
    private string $source;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = sys_get_temp_dir().'/variant-lock-'.getmypid();
        $this->cache = sys_get_temp_dir().'/variant-lock-cache-'.getmypid();

        File::ensureDirectoryExists($this->source);

        imagepng(imagecreatetruecolor(200, 100), $this->source.'/photo.png');

        config([
            'filesystems.disks.images' => ['driver' => 'local', 'root' => $this->source],
            'image-variants.source' => ['disk' => 'images', 'prefix' => null],
            'image-variants.cache' => $this->cache,
            'image-variants.presets.thumb' => ['cover' => [60, 40], 'quality' => 80],
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    private function variant(): Variant
    {
        return Variants::make('photo.png', 'thumb', 'webp');
    }

    private function lockFor(Variant $variant): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock('image-variants:'.sha1($variant->path()), 30);
    }

    #[Test]
    public function it_generates_normally_when_nothing_is_contending(): void
    {
        $variant = $this->variant();

        $this->get($variant->url())->assertOk();

        $this->assertFileExists($variant->path());
    }

    #[Test]
    public function it_releases_the_lock_afterwards(): void
    {
        $variant = $this->variant();

        $this->get($variant->url())->assertOk();

        // Nothing else should be left holding it.
        $lock = $this->lockFor($variant);

        $this->assertTrue($lock->get());

        $lock->release();
    }

    #[Test]
    public function a_request_that_waits_serves_what_the_holder_wrote_rather_than_regenerating(): void
    {
        $variant = $this->variant();

        // Stand in for a worker that holds the lock and has just finished: the
        // file is there, so the queued request must serve it untouched.
        $lock = $this->lockFor($variant);
        $this->assertTrue($lock->get());

        File::ensureDirectoryExists(dirname($variant->path()));
        File::put($variant->path(), 'written by the holder');

        $lock->release();

        $this->assertSame('written by the holder', $this->get($variant->url())->streamedContent());
    }

    #[Test]
    public function it_gives_up_waiting_rather_than_failing_the_request(): void
    {
        config(['image-variants.lock.wait' => 1]);

        $variant = $this->variant();

        // Held by someone who never finishes. The request should wait its second
        // and then do the work itself: a slow image beats a broken one.
        $lock = $this->lockFor($variant);
        $this->assertTrue($lock->get());

        $started = microtime(true);

        $this->get($variant->url())->assertOk();

        $waited = microtime(true) - $started;

        // block() gives up one sleep interval short of its budget, so a one
        // second wait bails at around 0.75s. What matters is that it waited at
        // all and then served the image rather than erroring.
        $this->assertGreaterThan(0.5, $waited);
        $this->assertLessThan(3.0, $waited);
        $this->assertFileExists($variant->path());

        $lock->release();
    }

    #[Test]
    public function locking_can_be_turned_off(): void
    {
        config(['image-variants.lock.enabled' => false]);

        $variant = $this->variant();

        // Held by someone else, and ignored entirely.
        $lock = $this->lockFor($variant);
        $this->assertTrue($lock->get());

        $started = microtime(true);

        $this->get($variant->url())->assertOk();

        $this->assertLessThan(1.0, microtime(true) - $started);

        $lock->release();
    }

    #[Test]
    public function a_cache_store_that_cannot_lock_is_a_configuration_error(): void
    {
        // Every store Laravel ships can lock — even the null driver, which
        // implements it as a no-op — so this takes a custom one to reach.
        Cache::extend('lockless', fn () => Cache::repository(new LocklessStore));

        config([
            'cache.stores.lockless' => ['driver' => 'lockless'],
            'image-variants.cache_store' => 'lockless',
        ]);

        // Not answered with a 404: the URL is fine, the deployment is not.
        $this->assertThrows(
            fn () => app(VariantGenerator::class)->generate($this->variant()),
            VariantConfigurationException::class
        );
    }

    #[Test]
    public function a_cache_store_that_does_not_exist_is_a_configuration_error(): void
    {
        config(['image-variants.cache_store' => 'nonexistent']);

        $this->assertThrows(
            fn () => app(VariantGenerator::class)->generate($this->variant()),
            VariantConfigurationException::class
        );
    }
}

/**
 * A cache store with no lock support, which no Laravel driver actually is —
 * they all implement LockProvider, the null driver included. Custom and
 * third-party stores need not, so the generator says so plainly instead of
 * letting a BadMethodCallException surface from somewhere in the cache.
 */
class LocklessStore implements \Illuminate\Contracts\Cache\Store
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        return array_combine($keys, array_map(fn ($key) => $this->get($key), $keys));
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = $value;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[$key] = ((int) ($this->items[$key] ?? 0)) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }

    public function touch($key, $ttl = null)
    {
        return array_key_exists($key, $this->items);
    }
}
