<?php

namespace App\Providers;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
        Event::listen(function (Login $event) {
            Audit::record('auth.login', "{$event->user->email} hat sich angemeldet", $event->user, userId: $event->user->getKey());
        });

        Event::listen(function (Logout $event) {
            if ($event->user) {
                Audit::record('auth.logout', "{$event->user->email} hat sich abgemeldet", $event->user, userId: $event->user->getKey());
            }
        });

        Event::listen(function (Failed $event) {
            $email = $event->credentials['email'] ?? 'unbekannt';
            Audit::record('auth.login_failed', "Fehlgeschlagener Anmeldeversuch für {$email}", userId: null);
        });
    }
}
