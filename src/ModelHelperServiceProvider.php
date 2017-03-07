<?php

namespace Hbclare\ModelHelper;

use Illuminate\Support\ServiceProvider;

class ModelHelperServiceProvider extends ServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     *
     * @return void
     */
    public function boot()
    {

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->commands('Hbclare\ModelHelper\Console\MakeEachModelCommand');
        $this->commands('Hbclare\ModelHelper\Console\MakeRepositoryCommand');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

}