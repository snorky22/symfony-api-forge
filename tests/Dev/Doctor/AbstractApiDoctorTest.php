<?php

namespace Snorky22\ApiForge\Tests\Dev\Doctor;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Doctor\AbstractApiDoctor;
use Snorky22\ApiForge\Dev\Doctor\Check;

final class AbstractApiDoctorTest extends TestCase
{
    private static function host(string $baseUri): string
    {
        $doctor = new class([], $baseUri) extends AbstractApiDoctor {
            public const API = 'shop';
            protected const BASE_URI = 'https://api.shop.test/';
            protected const BASE_URI_ENV = 'SHOP_BASE_URI';
            protected const ENVIRONMENTS = ['sandbox' => 'https://sandbox-api.shop.test/'];
        };
        $check = $doctor->diagnose()[0];

        return $check->level.': '.$check->message;
    }

    public function testHostInUse(): void
    {
        self::assertSame('ok: Host: https://sandbox-api.shop.test/ (sandbox, from SHOP_BASE_URI).', self::host('https://sandbox-api.shop.test'));
        self::assertSame('ok: Host: https://api.shop.test/ (live, from SHOP_BASE_URI).', self::host('https://api.shop.test/'));
        self::assertSame(Check::WARNING.': Host: https://shop.example.com/ (from SHOP_BASE_URI) is not a known host of the API: https://api.shop.test/, https://sandbox-api.shop.test/.', self::host('https://shop.example.com/'));
    }

    public function testRegisteredEventsAsNamesOrObjects(): void
    {
        $doctor = new class([], 'https://api.shop.test/') extends AbstractApiDoctor {
            public const API = 'shop';
            protected const BASE_URI = 'https://api.shop.test/';

            public function read(array $response, string $events): array
            {
                return $this->registrations($response, 'data', 'destination', $events, 'id');
            }
        };
        $response = ['data' => [
            ['id' => 'ntfset_1', 'destination' => 'https://x.test/webhook/shop', 'names' => ['a.created', 'a.updated'], 'subscribed_events' => [['name' => 'b.created', 'description' => 'B'], ['name' => 'b.paid']]],
            ['id' => 'ntfset_2', 'destination' => 'https://y.test'],
        ]];

        self::assertSame(['a.created', 'a.updated'], $doctor->read($response, 'names')[0]['events']);
        self::assertSame(['b.created', 'b.paid'], $doctor->read($response, 'subscribed_events[].name')[0]['events']);
        self::assertNull($doctor->read($response, 'subscribed_events[].name')[1]['events']);
    }
}
