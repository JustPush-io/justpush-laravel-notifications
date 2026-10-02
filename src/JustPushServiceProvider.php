<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

class JustPushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JustPushChannel::class, static fn ($app) => new JustPushChannel($app['config']));

        // Lets notifications use via() => ['justpush'] as well as JustPushChannel::class.
        Notification::resolved(static function (ChannelManager $service): void {
            $service->extend('justpush', static fn ($app) => $app->make(JustPushChannel::class));
        });
    }
}
