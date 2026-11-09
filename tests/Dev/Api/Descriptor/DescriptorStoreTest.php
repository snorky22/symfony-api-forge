<?php

namespace Snorky22\ApiForge\Tests\Dev\Api\Descriptor;

use PHPUnit\Framework\TestCase;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Symfony\Component\Filesystem\Filesystem;

final class DescriptorStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/descriptor-store-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRemovesReviewNotesWhereverTheyAre(): void
    {
        $store = new DescriptorStore($this->dir);
        $api = $store->saveApi('shop', ['base_uri' => 'https://x.test/', 'review' => ['api note', 'keep me'], 'doctor' => ['probe' => 'a.list', 'review' => ['probe note']]]);
        $resource = $store->saveResource('shop', 'prices', [
            'resource' => 'prices',
            'review' => ['resource note'],
            'object' => ['shape' => 'array{}', 'review' => ['object note']],
            'operations' => [
                'list' => ['method' => 'GET', 'path' => 'prices', 'review' => ['response: "mixed" marks values (data[].a)', 'second']],
                'get' => ['method' => 'GET', 'path' => 'prices/{id}', 'review' => ['only one']],
            ],
        ]);

        $removed = $store->removeReviewNotes('shop', [
            $api.': api note',
            $api.': doctor: probe note',
            $resource.': resource note',
            $resource.': object: object note',
            $resource.': operation "list": response: "mixed" marks values (data[].a)',
            $resource.': operation "get": only one',
            $resource.': operation "get": not there',
            '/elsewhere/file.yaml: api note',
        ], null);

        self::assertSame(6, $removed);
        $api = $store->loadApi('shop');
        self::assertSame(['keep me'], $api['review']);
        self::assertArrayNotHasKey('review', $api['doctor']);
        $prices = $store->loadResource('shop', 'prices');
        self::assertArrayNotHasKey('review', $prices);
        self::assertArrayNotHasKey('review', $prices['object']);
        self::assertSame(['second'], $prices['operations']['list']['review']);
        self::assertArrayNotHasKey('review', $prices['operations']['get']);
    }

    public function testRemovesNothingWhenNoNoteMatches(): void
    {
        $store = new DescriptorStore($this->dir);
        $api = $store->saveApi('shop', ['base_uri' => 'https://x.test/', 'review' => ['a']]);
        $before = file_get_contents($api);

        self::assertSame(0, $store->removeReviewNotes('shop', [$api.': b']));
        self::assertSame($before, file_get_contents($api));
    }
}
