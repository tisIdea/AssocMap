<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        if ($this->app->environment('local')) {
            $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event): void {
                \App\Http\Middleware\LocalRequestTiming::recordQuery($this->app['request'], (float) $event->time);
            });
        }

        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(60)->by('api-auth-ip:'.$request->ip()));
        RateLimiter::for('invitation-activation', function (Request $request) {
            return [
                Limit::perMinute(30)->by('activation-ip:'.$request->ip()),
                Limit::perMinute(10)->by('activation-session:'.hash('sha256', $request->session()->getId())),
            ];
        });
        RateLimiter::for('staff-login', function (Request $request) {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('login:'.hash('sha256', $identity.'|'.$request->ip())),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('user-invitations', function (Request $request) {
            $id = $request->attributes->get('current_user')?->id
                ?? $request->session()->get('user_profile_id') ?? $request->ip();

            return [
                Limit::perMinute(5)->by('invitations-minute:'.$id),
                Limit::perHour(30)->by('invitations-hour:'.$id),
            ];
        });
    }
}
