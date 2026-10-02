<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush\Exceptions;

use Exception;
use Illuminate\Notifications\Notification;
use JustPush\Exceptions\JustPushApiException;
use Throwable;

class CouldNotSendNotification extends Exception
{
    protected ?int $status = null;
    protected array $errors = [];
    protected ?array $body = null;

    public static function serviceRespondedWithAnError(JustPushApiException $previous): static
    {
        // The SDK's message reads "Failed to create message: <reason>".
        $reason = preg_replace('/^Failed to [^:]+: /', '', $previous->getMessage());

        $exception         = new static("JustPush responded with an error ({$previous->getStatus()}): {$reason}", $previous->getStatus(), $previous);
        $exception->status = $previous->getStatus();
        $exception->body   = $previous->getBody();
        $exception->errors = $previous->getErrors();

        return $exception;
    }

    public static function couldNotCommunicate(Throwable $previous): static
    {
        $reason = preg_replace('/^Failed to [^:]+: /', '', $previous->getMessage());

        return new static('Could not reach JustPush: ' . $reason, 0, $previous);
    }

    public static function missingToJustPush(Notification $notification): static
    {
        return new static('Notification ' . $notification::class . ' is sent via JustPush but has no toJustPush() method.');
    }

    public static function invalidMessage(Notification $notification): static
    {
        return new static($notification::class . '::toJustPush() must return a JustPushMessage or a string.');
    }

    /**
     * The HTTP status JustPush answered with, or null when it couldn't be reached.
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * Per-field validation messages for a 422, e.g. ['sound' => ['The selected sound is invalid.']].
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getBody(): ?array
    {
        return $this->body;
    }
}
