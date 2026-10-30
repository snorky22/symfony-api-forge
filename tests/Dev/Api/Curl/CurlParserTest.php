<?php

namespace App\Tests\Dev\Api\Curl;

use App\Dev\Api\Curl\CurlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/';

    public function testParsesGetExample(): void
    {
        $request = (new CurlParser())->parse(file_get_contents(self::FIXTURES.'curl-example-2'));

        self::assertSame('GET', $request->method);
        self::assertSame('https://api.lemonsqueezy.com/v1/orders?filter[user_email]=johndoe@example.com', $request->url);
        self::assertSame([
            'Accept' => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
            'Authorization' => 'Bearer {api_key}',
        ], $request->headers);
        self::assertNull($request->body);
    }

    public function testParsesPostExampleWithAnsiCQuotedBody(): void
    {
        $request = (new CurlParser())->parse(file_get_contents(self::FIXTURES.'curl-example-3'));

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.lemonsqueezy.com/v1/checkouts', $request->url);
        $body = json_decode($request->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('checkouts', $body['data']['type']);
        self::assertSame('#7047EB', $body['data']['attributes']['checkout_options']['button_color']);
    }

    public static function commandProvider(): iterable
    {
        yield 'data implies POST' => ["curl https://x.test/a -d 'a=1'", 'POST', 'https://x.test/a', 'a=1'];
        yield 'attached method' => ['curl -XDELETE https://x.test/a/1', 'DELETE', 'https://x.test/a/1', null];
        yield 'long options with =' => ['curl --request=PUT --url=https://x.test/a --data-raw={}', 'PUT', 'https://x.test/a', '{}'];
        yield 'get moves data to query' => ['curl -G https://x.test/a -d a=1 -d b=2', 'GET', 'https://x.test/a?a=1&b=2', null];
        yield 'prompt and continuation' => ["\$ curl \\\n  https://x.test/a", 'GET', 'https://x.test/a', null];
        yield 'double quote escapes' => ['curl https://x.test/a -d "{\"a\": \"\$b\"}"', 'POST', 'https://x.test/a', '{"a": "$b"}'];
        yield 'ansi-c escapes' => ["curl https://x.test/a -d \$'it\\'s\\t\\x41\\u00e9'", 'POST', 'https://x.test/a', "it's\tAé"];
        yield 'concatenated quotes' => ["curl 'https://x.test/'\"a\"", 'GET', 'https://x.test/a', null];
    }

    #[DataProvider('commandProvider')]
    public function testParsesCommands(string $command, string $method, string $url, ?string $body): void
    {
        $request = (new CurlParser())->parse($command);

        self::assertSame([$method, $url, $body], [$request->method, $request->url, $request->body]);
    }

    public function testJsonOptionSetsHeaders(): void
    {
        $request = (new CurlParser())->parse("curl --json '{\"a\":1}' https://x.test/a");

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->header('content-type'));
    }

    public function testCollectsUnknownOptions(): void
    {
        self::assertSame(['--retry'], (new CurlParser())->parse('curl -s --retry https://x.test/a')->unknownOptions);
    }

    public function testRejectsNonCurlCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CurlParser())->parse('wget https://x.test/a');
    }

    public function testRejectsUnterminatedQuote(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CurlParser())->parse("curl 'https://x.test/a");
    }
}
