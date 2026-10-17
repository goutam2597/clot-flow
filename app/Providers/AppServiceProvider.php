<?php

namespace App\Providers;

use App\Support\Currency as CurrencyFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Use Bootstrap-style pagination views with our CSS overrides.
        Paginator::useTailwind();

        // Guard against accidental lazy loading in development.
        Model::preventLazyLoading(! app()->isProduction());

        // Blade directive: @currency($value) — formats with the default currency.
        Blade::directive('currency', function ($expression) {
            return "<?php echo e(\App\Support\Currency::format($expression)); ?>";
        });

        // Share the default currency symbol and the active language with all views.
        View::composer('*', function ($view) {
            $view->with('currencySymbol', CurrencyFormatter::symbol());
        });
    }
}

