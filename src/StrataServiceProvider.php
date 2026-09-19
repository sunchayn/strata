<?php

declare(strict_types=1);

namespace Sunchayn\Strata;

use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Override;
use Sunchayn\Strata\Console\Commands\PruneStaleCacheTagsCommand;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\StrataStore;

class StrataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/strata.php', 'strata');

        $this->app->singleton(
            TagsManager::class,
            fn (Container $app): TagsManager => new TagsManager(
                filesystem: $app->make(Filesystem::class),
                directory: config('strata.directory').'/meta/tags',
                filePermission: (int) config('strata.file_permission'),
            ),
        );

        $this->app->singleton(
            StrataStore::class,
            fn (Container $app): StrataStore => new StrataStore(
                filesystem: $app->make(Filesystem::class),
                cacheDir: config('strata.directory').'/data',
                filePermission: (int) config('strata.file_permission'),
                tagsManager: $app->make(TagsManager::class),
                lockDirectory: config('strata.directory').'/locks',
                serializableClasses: config('cache.serializable_classes'),
            ),
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerCacheDriver();

        $this->commands([
            PruneStaleCacheTagsCommand::class,
        ]);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes(
            paths: [__DIR__.'/../config/strata.php' => config_path('strata.php')],
            groups: ['strata', 'strata-config'],
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function registerCacheDriver(): void
    {
        $this->app->make(CacheManager::class)->extend(
            driver: 'strata',
            callback: fn ($app) => $app->make('cache')->repository($app->make(StrataStore::class)),
        );
    }
}
