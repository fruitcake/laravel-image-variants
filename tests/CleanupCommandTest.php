<?php

namespace Fruitcake\ImageVariants\Tests;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class CleanupCommandTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = sys_get_temp_dir().'/variant-cleanup-'.getmypid();

        config(['image-variants.cache' => $this->cache, 'image-variants.cleanup.days' => 30]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    /**
     * Write a variant into the cache, aged $days days.
     */
    private function variant(string $path, int $days): string
    {
        $file = $this->cache.'/'.$path;

        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'x');

        touch($file, time() - $days * 86400);

        return $file;
    }

    #[Test]
    public function it_deletes_only_variants_older_than_the_given_age(): void
    {
        $old = $this->variant('thumb/aaaaaaaaaa/photo.webp', 45);
        $fresh = $this->variant('hero/bbbbbbbbbb/photo.webp', 5);

        $this->command('image-variants:cleanup', ['--days' => 30])
            ->expectsOutputToContain('Deleted 1 variant')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }

    #[Test]
    public function it_falls_back_to_the_configured_age(): void
    {
        config(['image-variants.cleanup.days' => 1]);

        $old = $this->variant('thumb/aaaaaaaaaa/photo.webp', 3);

        $this->command('image-variants:cleanup')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
    }

    #[Test]
    public function accessed_ages_files_by_when_they_were_last_read(): void
    {
        $file = $this->variant('thumb/aaaaaaaaaa/photo.webp', 45);

        // Written long ago, but read yesterday: --accessed keeps it, the default
        // (which only knows when it was written) does not.
        touch($file, time() - 45 * 86400, time() - 86400);

        if (fileatime($file) < time() - 2 * 86400) {
            $this->markTestSkipped('This filesystem does not record access times.');
        }

        $this->command('image-variants:cleanup', ['--days' => 30, '--accessed' => true])->assertSuccessful();

        $this->assertFileExists($file);

        $this->command('image-variants:cleanup', ['--days' => 30])->assertSuccessful();

        $this->assertFileDoesNotExist($file);
    }

    #[Test]
    public function it_removes_the_directories_it_empties_but_not_the_cache_root(): void
    {
        $this->variant('thumb/aaaaaaaaaa/photo.webp', 45);

        $this->command('image-variants:cleanup', ['--days' => 30])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->cache.'/thumb');
        $this->assertDirectoryExists($this->cache);
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $old = $this->variant('thumb/aaaaaaaaaa/photo.webp', 45);

        $this->command('image-variants:cleanup', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 variant')
            ->assertSuccessful();

        $this->assertFileExists($old);
    }

    #[Test]
    public function it_rejects_a_nonsensical_age(): void
    {
        $old = $this->variant('thumb/aaaaaaaaaa/photo.webp', 45);

        $this->command('image-variants:cleanup', ['--days' => '-1'])->assertFailed();
        $this->command('image-variants:cleanup', ['--days' => 'yesterday'])->assertFailed();

        $this->assertFileExists($old);
    }

    #[Test]
    public function it_shrugs_off_a_cache_directory_that_was_never_created(): void
    {
        $this->command('image-variants:cleanup')
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();
    }
}
