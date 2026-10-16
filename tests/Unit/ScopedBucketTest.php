<?php

declare(strict_types=1);

use Farzai\DatasetKit\Publish\DirectoryBucket;
use Farzai\DatasetKit\Publish\PublishFailed;
use Farzai\DatasetKit\Publish\ScopedBucket;

require_once dirname(__DIR__) . '/Support/build.php';

beforeEach(function () {
    $this->dir = tmpDir('scoped');
    $this->inner = new DirectoryBucket($this->dir);
    $this->scoped = new ScopedBucket($this->inner, 'geonames');
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

test('a key under the root is written and read', function () {
    $this->assertTrue($this->scoped->put('geonames/v1/latest.json', 'x', [], '*'));
    $this->assertSame('x', $this->scoped->get('geonames/v1/latest.json')['body']);
});

test('a key outside the root is refused on put and on get, and nothing is written', function (string $key) {
    expect(fn () => $this->scoped->put($key, 'x', [], '*'))->toThrow(PublishFailed::class, 'outside');
    expect(fn () => $this->scoped->get($key))->toThrow(PublishFailed::class, 'outside');
    $this->assertFileDoesNotExist("{$this->dir}/plan.jsonl");
})->with([
    'another dataset' => 'thailand-address/v1/latest.json',
    'a sibling that shares the prefix' => 'geonames-evil/v1/latest.json',
    'the root itself' => 'geonames',
    'traversal out' => 'geonames/../thailand-address/v1/latest.json',
    'traversal in the middle' => 'geonames/v1/../../thailand-address/x',
    'a dot segment' => 'geonames/./v1/x',
    'an empty segment' => 'geonames//v1/x',
    'a leading slash' => '/geonames/v1/x',
    'a backslash' => 'geonames\\..\\x',
    'a control character' => "geonames/v1/x\n",
]);

test('a root that is not one plain folder name is refused', function (string $root) {
    expect(fn () => new ScopedBucket($this->inner, $root))->toThrow(InvalidArgumentException::class);
})->with(['', '/', 'a/b', '..', '.', 'Geo Names', 'geonames/']);
