<p align="center"><img src="https://cdn.justpush.io/core/app%20icon_nobackground.svg" width="120" height="auto"></p>

# JustPush notification channel for Laravel

Send push notifications to iOS and Android through [JustPush](https://justpush.io) with Laravel's
notification system: `$user->notify(new OrderShipped($order))`.

It uses the official [JustPush PHP SDK](https://github.com/JustPush-io/justpush-sdk-php) to talk to the
JustPush API.

- Laravel 12 and 13, PHP 8.2 or newer
- Priorities, sounds, buttons, button groups, images, expiry and acknowledgements
- Per-user tokens, an app-wide token, or on-demand routes

## Installation

```bash
composer require justpush/laravel-notification-channel
```

The service provider registers itself. Add your API token to `config/services.php`:

```php
'justpush' => [
    'token' => env('JUSTPUSH_TOKEN'),
    // 'timeout' => 10,  // seconds
],
```

You'll find your API token in the JustPush app.

## Usage

```php
use Illuminate\Notifications\Notification;
use NotificationChannels\JustPush\JustPushChannel;
use NotificationChannels\JustPush\JustPushMessage;

class OrderShipped extends Notification
{
    public function __construct(private Order $order) {}

    public function via($notifiable): array
    {
        return [JustPushChannel::class]; // or ['justpush']
    }

    public function toJustPush($notifiable): JustPushMessage
    {
        return JustPushMessage::create("Order #{$this->order->number} is on its way")
            ->title('📦 Order shipped')
            ->topic('Orders')
            ->button('Track', $this->order->trackingUrl);
    }
}
```

`toJustPush()` can also return a plain string, which becomes the message text.

### Who receives it

JustPush tokens belong to a JustPush account, and the push goes to that account's devices.

1. **Per user:** add a route method to your notifiable model. Return `null` to fall back to the app token.

   ```php
   public function routeNotificationForJustPush($notification): ?string
   {
       return $this->justpush_token;
   }
   ```

2. **App-wide:** without a route, the channel uses `services.justpush.token`. That's handy for
   notifications to yourself or your team, e.g. `$admin->notify(new NewSignup($user))`.
3. **On demand:**

   ```php
   Notification::route('justpush', $token)->notify(new ServerDown($server));
   ```

If there's no token at all, the notification is skipped for this channel.

### Message options

```php
JustPushMessage::create('Water detected under the washing machine')
    ->title('💧 Leak')
    ->topic('Home')                       // topic name; created if it doesn't exist
    ->highestPriority()                   // or ->priority(2) / ->priority('highest')
    ->sound('siren')
    ->button('Open camera', 'https://example.com/cam', actionRequired: true)
    ->buttonGroup('Rooms', 'Cameras', [
        ['cta' => 'Kitchen', 'url' => 'https://example.com/cam/kitchen'],
        ['cta' => 'Hall', 'url' => 'https://example.com/cam/hall'],
    ])
    ->image('https://example.com/snapshot.jpg', 'Kitchen')   // or ->imageData($bytes) / ->imageFile($path)
    ->expiresIn(3600)
    ->acknowledge(retryEvery: 60, maxRetries: 10, callbackUrl: route('justpush.acknowledged'), callbackParams: ['leak' => 42]);
```

| Method | |
| --- | --- |
| `create($message)`, `message()`, `title()` | At least a message or a title is required. |
| `topic($name)` | The topic name. An existing topic with that name is used, or a new one is created. |
| `topicToken($token)` | Target a topic by its API token instead. |
| `priority()` | `-2`…`2` or `lowest`, `low`, `normal`, `high`, `highest`. Shortcuts: `lowestPriority()` … `highestPriority()`. |
| `sound($name)` | See `JustPushMessage::SOUNDS`. `none` is silent. |
| `button($cta, $url, $actionRequired)` | Up to 10. Labels over 25 characters are cut. |
| `buttonGroup($name, $cta, $buttons)` | Up to 4 groups of up to 10 buttons. |
| `image()`, `imageData()`, `imageFile()` | Up to 10. The first image becomes the notification banner. |
| `expiresIn($seconds)` | Hide the message after this many seconds. |
| `acknowledge(...)` | Ask for an acknowledgement. Optionally re-send every `retryEvery` seconds (10–65535) up to `maxRetries` times (0–255), and have JustPush POST `callbackParams` plus the acknowledging device to `callbackUrl`, which must be publicly reachable. |

Invalid values throw an `InvalidArgumentException` when you set them.

### The response and errors

The channel returns the API's response, which Laravel passes to the `NotificationSent` event as `$event->response`:

```php
['status' => 1, 'key' => 'abc123…', 'rate_limit' => ['limit' => 10000, 'remaining' => 9895, 'reset_seconds' => 234512]]
```

When JustPush can't be reached or rejects the message, the channel throws
`NotificationChannels\JustPush\Exceptions\CouldNotSendNotification`. `getStatus()` returns the HTTP
status, or `null` for connection errors, and `getErrors()` returns per-field messages for a 422.
Queue the notification (`implements ShouldQueue`) to get Laravel's retries.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
