<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Example;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;

final class PhpArrayExampleTest extends TestCase
{
    public function testOperationWithQueryHeadersAndBody(): void
    {
        $example = (new PhpArrayExample())->operation(<<<'PHP'
            <?php return [
                'request' => 'post /prices',
                'query' => ['status' => 'active', 'id' => ['pri_1', 'pri_2'], 'filter' => ['store' => 'a b'], 'flag' => true],
                'headers' => ['Paddle-Version' => '1'],
                'body' => ['name' => "It's", 'unit_price' => ['amount' => '1000', 'currency_code' => 'EUR'], 'tags' => []],
                'response' => ['data' => ['id' => 'pri_1', 'ratio' => 1.0]],
            ];
            PHP, 'https://api.paddle.com/');

        $request = (new CurlParser())->parse((string) $example->curl);
        self::assertSame('POST', $request->method);
        self::assertSame("It's", json_decode((string) $request->body, true)['name']);
        self::assertStringContainsString("'https://api.paddle.com/prices?status=active&id=pri_1%2Cpri_2&filter[store]=a%20b&flag=true'", (string) $example->curl);
        self::assertStringContainsString("-H 'Paddle-Version: 1'", (string) $example->curl);
        self::assertStringContainsString("-H 'Content-Type: application/json'", (string) $example->curl);
        self::assertSame(['data' => ['id' => 'pri_1', 'ratio' => 1.0]], json_decode((string) $example->response, true));
        self::assertStringContainsString('"ratio": 1.0', (string) $example->response);
    }

    public function testRequestWithAFullUrlNeedsNoBaseUri(): void
    {
        $example = (new PhpArrayExample())->operation("<?php return ['request' => 'GET https://api.example.com/v1/things'];", null);

        self::assertSame("curl 'https://api.example.com/v1/things'\n", $example->curl);
        self::assertNull($example->response);
    }

    public function testResponseOnly(): void
    {
        $example = (new PhpArrayExample())->operation("<?php return ['response' => ['a' => 1]];", null);

        self::assertNull($example->curl);
        self::assertSame("{\n    \"a\": 1\n}\n", $example->response);
    }

    public function testBareDocumentIsItsData(): void
    {
        self::assertSame(['a' => [1]], (new PhpArrayExample())->document("<?php return ['a' => [1]];"));
        self::assertSame([], (new PhpArrayExample())->document('<?php return [];'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'path without base URI' => ["<?php return ['request' => 'GET /prices'];", 'no base URI yet'];
        yield 'bad request' => ["<?php return ['request' => 'prices'];", '"METHOD path"'];
        yield 'unknown key' => ["<?php return ['request' => 'GET https://a.b/c', 'bodie' => []];", 'found bodie'];
        yield 'a list' => ["<?php return [1, 2];", 'keys request'];
        yield 'code' => ["<?php return ['request' => strtoupper('get')];", 'read, not run'];
        yield 'not an array' => ["<?php return 'x';", 'must return an array'];
        yield 'body not an array' => ["<?php return ['request' => 'POST https://a.b/c', 'body' => 'x'];", '"body" must be an array'];
        yield 'query not an array' => ["<?php return ['request' => 'GET https://a.b/c', 'query' => 'x'];", '"query" must be an array'];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatItCannotRead(string $php, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new PhpArrayExample())->operation($php, null);
    }
}
