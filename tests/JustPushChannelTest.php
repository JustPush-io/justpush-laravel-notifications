<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use NotificationChannels\JustPush\Exceptions\CouldNotSendNotification;
use NotificationChannels\JustPush\JustPushChannel;
use NotificationChannels\JustPush\JustPushMessage;

final class JustPushChannelTest extends TestCase
{
    private array $history = [];

    public function testSendsToTheApiWithTheNotifiablesToken(): void
    {
        $response = $this->channel($this->ok())->send(new User('user-token'), new OrderShipped());

        $this->assertSame(['status' => 1, 'key' => 'abc123', 'rate_limit' => ['limit' => 10000, 'remaining' => 9895, 'reset_seconds' => 234512]], $response);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.justpush.io/messages', (string) $request->getUri());
        $this->assertSame('Bearer user-token', $request->getHeaderLine('Authorization'));
        $this->assertSame(
            ['message' => 'Order #1042 is on its way', 'title' => 'Order shipped', 'topic' => 'Orders', 'priority' => 1, 'buttons' => [['cta' => 'Track', 'url' => 'https://example.com/track', 'action_required' => false]]],
            json_decode((string) $request->getBody(), true)
        );
    }

    public function testViaAliasAndNotificationSentEvent(): void
    {
        Event::fake([NotificationSent::class]);
        $this->app->instance(JustPushChannel::class, $this->channel($this->ok()));

        (new User('user-token'))->notify(new OrderShipped(['justpush']));

        $this->assertCount(1, $this->history);
        Event::assertDispatched(NotificationSent::class, static fn (NotificationSent $e): bool => 'justpush' === $e->channel && 'abc123' === $e->response['key']);
    }

    public function testFallsBackToTheConfigToken(): void
    {
        config(['services.justpush.token' => 'app-token']);

        $this->channel($this->ok())->send(new User(null), new OrderShipped());

        $this->assertSame('Bearer app-token', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testOnDemandRoute(): void
    {
        $this->app->instance(JustPushChannel::class, $this->channel($this->ok()));

        NotificationFacade::route('justpush', 'on-demand-token')->notify(new OrderShipped(['justpush']));

        $this->assertSame('Bearer on-demand-token', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testSkipsWithoutAToken(): void
    {
        $channel = $this->channel($this->ok());

        $this->assertNull($channel->send(new User(null), new OrderShipped()));
        $this->assertNull($channel->send(new User('  '), new OrderShipped()));
        $this->assertSame([], $this->history);
    }

    public function testStringMessageWithoutRateLimitHeaders(): void
    {
        $response = $this->channel(new Response(201, [], '{"status":1,"key":"k"}'))->send(new User('t'), new PlainText());

        $this->assertSame(['limit' => null, 'remaining' => null, 'reset_seconds' => null], $response['rate_limit']);
        $this->assertSame(['message' => 'Backup finished'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function testBaseUrlAndTimeoutFromConfig(): void
    {
        config(['services.justpush.base_url' => 'https://api.justpush.test/', 'services.justpush.timeout' => 3]);

        $client = (fn () => $this->client())->call(app(JustPushChannel::class));

        $this->assertSame('https://api.justpush.test', (string) $client->getConfig('base_uri'));
        $this->assertSame(3.0, $client->getConfig('timeout'));
    }

    public function testValidationErrorFromTheApi(): void
    {
        $channel = $this->channel(new Response(422, [], '{"message":"The sound is invalid.","errors":{"sound":["The sound is invalid."]}}'));

        try {
            $channel->send(new User('t'), new OrderShipped());
            $this->fail('No exception');
        } catch (CouldNotSendNotification $e) {
            $this->assertSame(422, $e->getStatus());
            $this->assertSame('JustPush responded with an error (422): The sound is invalid.', $e->getMessage());
            $this->assertSame(['sound' => ['The sound is invalid.']], $e->getErrors());
        }
    }

    public function testUnauthorized(): void
    {
        $this->expectExceptionMessage('JustPush responded with an error (401): Unauthenticated');
        $this->channel(new Response(401, [], '{"error":{"type":"unauthorized","message":"Unauthenticated"}}'))
            ->send(new User('bad'), new OrderShipped());
    }

    public function testConnectionError(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        $this->expectExceptionMessage('Could not reach JustPush: timed out');
        $this->channel(new ConnectException('timed out', new Request('POST', '/messages')))
            ->send(new User('t'), new OrderShipped());
    }

    public function testNotificationWithoutToJustPush(): void
    {
        $this->expectExceptionMessage('has no toJustPush() method');
        $this->channel()->send(new User('t'), new class extends Notification {});
    }

    public function testInvalidReturnValue(): void
    {
        $this->expectExceptionMessage('must return a JustPushMessage or a string');
        $this->channel()->send(new User('t'), new class extends Notification {
            public function toJustPush($notifiable): array
            {
                return [];
            }
        });
    }

    public function testEmptyMessageIsRejectedBeforeSending(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->channel($this->ok())->send(new User('t'), new class extends Notification {
                public function toJustPush($notifiable): JustPushMessage
                {
                    return JustPushMessage::create();
                }
            });
        } finally {
            $this->assertSame([], $this->history);
        }
    }

    private function ok(): Response
    {
        return new Response(201, [
            'X-Limit-App-Limit'     => '10000',
            'X-Limit-App-Remaining' => '9895',
            'X-Limit-App-Reset'     => '234512',
        ], '{"status":1,"key":"abc123"}');
    }

    private function channel(mixed ...$responses): JustPushChannel
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new JustPushChannel(config(), new Client(['handler' => $stack, 'base_uri' => 'https://api.justpush.io']));
    }
}

class User
{
    use Notifiable;

    public function __construct(public ?string $justPushToken) {}

    public function routeNotificationForJustPush(Notification $notification): ?string
    {
        return $this->justPushToken;
    }

    public function getKey(): int
    {
        return 1;
    }
}

class OrderShipped extends Notification
{
    public function __construct(private array $channels = [JustPushChannel::class]) {}

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function toJustPush($notifiable): JustPushMessage
    {
        return JustPushMessage::create('Order #1042 is on its way')
            ->title('Order shipped')
            ->topic('Orders')
            ->highPriority()
            ->button('Track', 'https://example.com/track');
    }
}

class PlainText extends Notification
{
    public function via($notifiable): array
    {
        return [JustPushChannel::class];
    }

    public function toJustPush($notifiable): string
    {
        return 'Backup finished';
    }
}
