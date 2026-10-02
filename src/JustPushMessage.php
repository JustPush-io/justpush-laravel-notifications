<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush;

use InvalidArgumentException;
use JustPush\Resources\JustPushMessage as SdkMessage;

/**
 * A push notification for the JustPush channel, built fluently in a notification's toJustPush().
 *
 * A thin, Laravel-flavoured wrapper around the JustPush PHP SDK's message, which validates every
 * value as it's set (throwing an InvalidArgumentException) and sends it.
 */
class JustPushMessage
{
    public const PRIORITY_LOWEST  = -2;
    public const PRIORITY_LOW     = -1;
    public const PRIORITY_NORMAL  = 0;
    public const PRIORITY_HIGH    = 1;
    public const PRIORITY_HIGHEST = 2;

    public const SOUNDS = SdkMessage::SOUNDS;

    protected SdkMessage $message;

    public function __construct(string $message = '')
    {
        // The token is set by the channel when it sends.
        $this->message = new SdkMessage('');

        if ('' !== $message) {
            $this->message->message($message);
        }
    }

    public static function create(string $message = ''): static
    {
        return new static($message);
    }

    public function message(string $message): static
    {
        $this->message->message($message);

        return $this;
    }

    public function title(string $title): static
    {
        $this->message->title($title);

        return $this;
    }

    /**
     * The topic's name. An existing topic with that name is used, otherwise a new one is created.
     */
    public function topic(string $topic): static
    {
        $this->message->topic($topic);

        return $this;
    }

    /**
     * Target a topic by its API token instead of its name.
     */
    public function topicToken(string $topicToken): static
    {
        $this->message->topicToken($topicToken);

        return $this;
    }

    /**
     * -2 to 2, or "lowest", "low", "normal", "high", "highest".
     */
    public function priority(int|string $priority): static
    {
        $this->message->priority($priority);

        return $this;
    }

    public function lowestPriority(): static
    {
        return $this->priority(self::PRIORITY_LOWEST);
    }

    public function lowPriority(): static
    {
        return $this->priority(self::PRIORITY_LOW);
    }

    public function normalPriority(): static
    {
        return $this->priority(self::PRIORITY_NORMAL);
    }

    public function highPriority(): static
    {
        return $this->priority(self::PRIORITY_HIGH);
    }

    public function highestPriority(): static
    {
        return $this->priority(self::PRIORITY_HIGHEST);
    }

    /**
     * A sound name in any case, see self::SOUNDS. "none" is silent.
     */
    public function sound(string $sound): static
    {
        $this->message->sound($sound);

        return $this;
    }

    /**
     * Hide the message after this many seconds.
     */
    public function expiresIn(int $seconds): static
    {
        $this->message->expiry($seconds);

        return $this;
    }

    /**
     * Add a button (at most 10). With $actionRequired the message stays pending until it's tapped.
     */
    public function button(string $cta, string $url, bool $actionRequired = false): static
    {
        $this->message->button($cta, $url, $actionRequired);

        return $this;
    }

    /**
     * Add a button (at most 4 groups) that opens a named list of buttons (at most 10 each).
     *
     * @param array<array{cta: string, url: string}> $buttons
     */
    public function buttonGroup(string $name, string $cta, array $buttons, bool $actionRequired = false): static
    {
        $this->message->buttonGroup($name, $cta, $buttons, $actionRequired);

        return $this;
    }

    /**
     * Attach an image by its public URL. The first image becomes the notification banner.
     */
    public function image(string $url, ?string $caption = null): static
    {
        $this->message->image($url, $caption);

        return $this;
    }

    /**
     * Attach an image from its contents (JPEG, PNG, …).
     */
    public function imageData(string $contents, ?string $caption = null): static
    {
        $this->message->imageData($contents, $caption);

        return $this;
    }

    /**
     * Attach an image file from disk.
     */
    public function imageFile(string $path, ?string $caption = null): static
    {
        $this->message->imageFile($path, $caption);

        return $this;
    }

    /**
     * Ask for the message to be acknowledged on a device.
     *
     * - $retryEvery / $maxRetries: re-send every this many seconds (10–65535, default 60) until acknowledged,
     *   at most this many times (0–255, default 10).
     * - $callbackUrl: JustPush POSTs $callbackParams plus the acknowledging device to this URL once it's
     *   acknowledged. It must be publicly reachable.
     */
    public function acknowledge(
        ?int $retryEvery = null,
        ?int $maxRetries = null,
        ?string $callbackUrl = null,
        ?array $callbackParams = null,
    ): static {
        $retry = null !== $retryEvery || null !== $maxRetries;

        $this->message->acknowledge(
            requiresAcknowledgement: true,
            requiresRetry: $retry,
            retryInterval: $retryEvery ?? 0,
            maxRetries: $maxRetries ?? 0,
            callbackRequired: null !== $callbackUrl,
            callbackUrl: $callbackUrl,
            callbackParams: $callbackParams,
        );

        return $this;
    }

    /**
     * The request body for POST /messages.
     */
    public function toArray(): array
    {
        $params = $this->message->getMessageParams();

        if (empty($params['message']) && empty($params['title'])) {
            throw new InvalidArgumentException('A JustPush message needs a message or a title.');
        }

        return $params;
    }

    /**
     * The underlying SDK message, ready to send once the channel sets the token.
     */
    public function toSdkMessage(): SdkMessage
    {
        return $this->message;
    }
}
