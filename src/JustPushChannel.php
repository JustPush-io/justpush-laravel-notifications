<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush;

use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Notifications\Notification;
use JustPush\Exceptions\JustPushApiException;
use JustPush\Exceptions\JustPushConnectionException;
use NotificationChannels\JustPush\Exceptions\CouldNotSendNotification;

class JustPushChannel
{
    public function __construct(
        protected Config $config,
        protected ?Client $client = null,
    ) {}

    /**
     * Send the notification through the JustPush API. Returns the API response (with the message
     * "key") plus the plan's monthly allowance under "rate_limit", or null when there's no token.
     *
     * @throws CouldNotSendNotification
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        $token = $this->token($notifiable, $notification);

        if (null === $token) {
            return null;
        }

        if (!method_exists($notification, 'toJustPush')) {
            throw CouldNotSendNotification::missingToJustPush($notification);
        }

        $message = $notification->toJustPush($notifiable);

        if (is_string($message)) {
            $message = JustPushMessage::create($message);
        }

        if (!$message instanceof JustPushMessage) {
            throw CouldNotSendNotification::invalidMessage($notification);
        }

        $message->toArray(); // throws when there's no message or title

        $sdkMessage = $message->toSdkMessage()->setToken($token)->withClient($this->client());

        try {
            $sdkMessage->create();
        } catch (JustPushApiException $e) {
            throw CouldNotSendNotification::serviceRespondedWithAnError($e);
        } catch (JustPushConnectionException $e) {
            throw CouldNotSendNotification::couldNotCommunicate($e);
        }

        $headers = $sdkMessage->responseHeaders() ?? [];

        return array_merge($sdkMessage->result() ?? [], [
            'rate_limit' => [
                'limit'         => $this->number($headers['X-Limit-App-Limit'] ?? null),
                'remaining'     => $this->number($headers['X-Limit-App-Remaining'] ?? null),
                'reset_seconds' => $this->number($headers['X-Limit-App-Reset'] ?? null),
            ],
        ]);
    }

    /**
     * The notifiable's own token (routeNotificationForJustPush), or the app's token from config.
     */
    protected function token(mixed $notifiable, Notification $notification): ?string
    {
        $token = is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('justpush', $notification)
            : null;

        $token = is_string($token) && '' !== trim($token)
            ? $token
            : $this->config->get('services.justpush.token');

        return is_string($token) && '' !== trim($token) ? trim($token) : null;
    }

    /**
     * The Guzzle client the SDK sends with: the injected one, or one built from config.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client([
            'base_uri' => rtrim((string) $this->config->get('services.justpush.base_url', 'https://api.justpush.io'), '/'),
            'timeout'  => (float) $this->config->get('services.justpush.timeout', 10),
        ]);
    }

    protected function number(mixed $header): ?int
    {
        $value = is_array($header) ? ($header[0] ?? null) : $header;

        return is_numeric($value) ? (int) $value : null;
    }
}
