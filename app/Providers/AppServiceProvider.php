<?php

namespace App\Providers;

use App\Services\Iotec\HttpIotecClient;
use App\Services\Iotec\IotecClient;
use App\Services\Meta\GraphClient;
use App\Services\Meta\HttpGraphClient;
use App\Support\HomeRedirect;
use App\Support\TeamManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TeamManager::class);
        $this->app->bind(GraphClient::class, HttpGraphClient::class);
        $this->app->bind(IotecClient::class, HttpIotecClient::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // An already-signed-in visitor to a guest route (the invitation email
        // links to /login) must not be sent to route('dashboard') — it needs a
        // {team} segment they may not have (D-020).
        RedirectIfAuthenticated::redirectUsing(
            fn (Request $request): string => HomeRedirect::for($request->user()),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
