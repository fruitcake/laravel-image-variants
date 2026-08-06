<?php

namespace Fruitcake\ImageVariants;

use Fruitcake\ImageVariants\Console\CleanupCommand;
use Fruitcake\ImageVariants\Http\VariantController;
use Fruitcake\ImageVariants\View\Components\VariantImage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ImageVariantsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/image-variants.php', 'image-variants');

        $this->app->singleton(VariantFactory::class);
        $this->app->singleton(VariantGenerator::class);
        $this->app->singleton(VariantDimensions::class);
        $this->app->alias(VariantFactory::class, 'image-variants');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/image-variants.php' => config_path('image-variants.php'),
            ], 'image-variants-config');

            $this->commands([CleanupCommand::class]);
        }

        $this->registerRoute();
        $this->registerComponent();
    }

    /**
     * Register the <x-variant> component, under whatever name the application
     * wants it — the obvious one is short enough to be worth claiming, and
     * short enough that an application may already have claimed it.
     */
    protected function registerComponent(): void
    {
        $alias = config('image-variants.blade.component', 'variant');

        if (! is_string($alias) || $alias === '') {
            return;
        }

        Blade::component(VariantImage::class, $alias);
    }

    /**
     * Register the endpoint outside any middleware group.
     *
     * Session middleware in particular would attach a Set-Cookie and a private
     * Cache-Control to the one response a real visitor gets from PHP, undoing the
     * long-lived caching this whole scheme exists for.
     */
    protected function registerRoute(): void
    {
        $prefix = trim((string) config('image-variants.route.prefix', 'storage/variants'), '/');
        $length = (int) config('image-variants.hash_length', 10);

        Route::middleware((array) config('image-variants.route.middleware', []))
            ->get($prefix.'/{preset}/{hash}/{name}', VariantController::class)
            ->where('preset', '[a-z0-9][a-z0-9_-]*')
            ->where('hash', '[a-f0-9]{'.$length.'}')
            ->where('name', '[A-Za-z0-9][A-Za-z0-9._-]*')
            ->name('image-variants.show');
    }
}
