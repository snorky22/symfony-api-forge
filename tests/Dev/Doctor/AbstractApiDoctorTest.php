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
}
