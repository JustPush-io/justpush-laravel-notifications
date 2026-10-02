<?php

declare(strict_types=1);

namespace NotificationChannels\JustPush\Tests;

use InvalidArgumentException;
use NotificationChannels\JustPush\JustPushMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JustPushMessageTest extends TestCase
{
    public function testMinimalMessage(): void
    {
        $this->assertSame(['message' => 'Hi'], JustPushMessage::create('Hi')->toArray());
        $this->assertSame(['title' => 'T'], JustPushMessage::create()->title('T')->toArray());
    }

    public function testFullMessageUsesTheFieldNamesTheApiAccepts(): void
    {
        $payload = JustPushMessage::create('Water detected')
            ->title('Leak')
            ->topic('Home')
            ->priority('HIGHEST')
            ->sound('SIREN')
            ->expiresIn(60)
            ->button('Open', 'https://example.com', true)
            ->buttonGroup('Links', 'More', [['cta' => 'A', 'url' => 'https://a.example', 'extra' => 'dropped']])
            ->image('https://example.com/a.jpg', 'Cam')
            ->imageData("\x89PNG")
            ->acknowledge(retryEvery: 30, maxRetries: 5, callbackUrl: 'https://example.com/cb', callbackParams: ['a' => 1])
            ->toArray();

        $this->assertSameSorted([
            'title'         => 'Leak',
            'message'       => 'Water detected',
            'topic'         => 'Home',
            'priority'      => 2,
            'sound'         => 'siren',
            'expiry_ttl'    => 60,
            'buttons'       => [['cta' => 'Open', 'url' => 'https://example.com', 'action_required' => true]],
            'button_groups' => [[
                'name' => 'Links', 'cta' => 'More', 'action_required' => false,
                'buttons' => [['cta' => 'A', 'url' => 'https://a.example']],
            ]],
            'images' => [
                ['url' => 'https://example.com/a.jpg', 'caption' => 'Cam'],
                ['body' => 'iVBORw=='],
            ],
            'requires_acknowledgement' => true,
            'acknowledgement'          => [
                'requires_retry' => true,
                'interval'       => 30,
                'max_retries'    => 5,
                'callback'       => ['required' => true, 'url' => 'https://example.com/cb', 'params' => '{"a":1}'],
            ],
        ], $payload);
    }

    private function assertSameSorted(array $expected, array $actual): void
    {
        $sort = static function (array $a) use (&$sort): array {
            if (!array_is_list($a)) {
                ksort($a);
            }

            return array_map(static fn ($v) => is_array($v) ? $sort($v) : $v, $a);
        };

        $this->assertSame($sort($expected), $sort($actual));
    }

    public function testAcknowledgeDefaultsAndPlainAcknowledgement(): void
    {
        $this->assertSame(['message' => 'x', 'requires_acknowledgement' => true], JustPushMessage::create('x')->acknowledge()->toArray());
        $this->assertSame(
            ['requires_retry' => true, 'interval' => 60, 'max_retries' => 10],
            JustPushMessage::create('x')->acknowledge(maxRetries: 10)->toArray()['acknowledgement']
        );
    }

    public function testTopicTokenReplacesTopic(): void
    {
        $this->assertSame(['message' => 'x', 'topic_token' => 'tok'], JustPushMessage::create('x')->topic('Home')->topicToken('tok')->toArray());
    }

    public function testImageFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jp');
        file_put_contents($path, "\x89PNG");
        $this->assertSame([['body' => 'iVBORw==']], JustPushMessage::create('x')->imageFile($path)->toArray()['images']);
        unlink($path);
    }

    #[DataProvider('priorities')]
    public function testPriorities(int|string $input, int $expected): void
    {
        $this->assertSame($expected, JustPushMessage::create('x')->priority($input)->toArray()['priority']);
    }

    public static function priorities(): array
    {
        return [[2, 2], ['highest', 2], ['Low', -1], [' normal ', 0], ['-2', -2]];
    }

    public function testShortcutPriorities(): void
    {
        $this->assertSame(-2, JustPushMessage::create('x')->lowestPriority()->toArray()['priority']);
        $this->assertSame(1, JustPushMessage::create('x')->highPriority()->toArray()['priority']);
    }

    #[DataProvider('invalid')]
    public function testValidation(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build(JustPushMessage::create('x'))->toArray();
    }

    public static function invalid(): array
    {
        return [
            'priority 3'        => [static fn ($m) => $m->priority(3)],
            'priority word'     => [static fn ($m) => $m->priority('loud')],
            'unknown sound'     => [static fn ($m) => $m->sound('kazoo')],
            'negative expiry'   => [static fn ($m) => $m->expiresIn(-1)],
            '11 buttons'        => [static function ($m) { for ($i = 0; $i < 11; ++$i) { $m->button('b', 'https://x'); } return $m; }],
            '5 groups'          => [static function ($m) { for ($i = 0; $i < 5; ++$i) { $m->buttonGroup('g', 'c', []); } return $m; }],
            'big group'         => [static fn ($m) => $m->buttonGroup('g', 'c', array_fill(0, 11, ['cta' => 'b', 'url' => 'https://x']))],
            '11 images'         => [static function ($m) { for ($i = 0; $i < 11; ++$i) { $m->image('https://x'); } return $m; }],
            'short interval'    => [static fn ($m) => $m->acknowledge(retryEvery: 5)],
            'too many retries'  => [static fn ($m) => $m->acknowledge(maxRetries: 256)],
            'missing file'      => [static fn ($m) => $m->imageFile('/nope/missing.png')],
            'empty message'     => [static fn ($m) => $m->message('')],
        ];
    }
}
