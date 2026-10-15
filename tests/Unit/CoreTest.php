<?php

declare(strict_types=1);

use Farzai\DatasetKit\Envelope;
use Farzai\DatasetKit\Json;
use Farzai\DatasetKit\Manifest;
use Farzai\DatasetKit\Snapshots;

test('an envelope puts one row per line and decodes back', function () {
    $json = Envelope::encode('1.0.0', '2026-10-14', [['code' => 1, 'name' => ['th' => 'กรุงเทพ']], ['code' => 2, 'url' => 'a/b']]);
    $this->assertSame("{\"schema_version\":\"1.0.0\",\"snapshot\":\"2026-10-14\",\"data\":[\n{\"code\":1,\"name\":{\"th\":\"กรุงเทพ\"}},\n{\"code\":2,\"url\":\"a/b\"}\n]}\n", $json);
    $this->assertSame(2, count(json_decode($json, true)['data']));
});

test('an empty envelope is still valid JSON with an empty data list', function () {
    $this->assertSame("{\"schema_version\":\"1.0.0\",\"snapshot\":\"2026-10-14\",\"data\":[]}\n", Envelope::encode('1.0.0', '2026-10-14', []));
});

test('pretty json ends with a newline and keeps unicode and slashes', function () {
    $this->assertSame("{\n    \"a\": \"ไทย/x\"\n}\n", Json::pretty(['a' => 'ไทย/x']));
});

test('a manifest entry carries the sha256, the size and the content type', function () {
    $this->assertSame(['sha256' => hash('sha256', 'abc'), 'bytes' => 3, 'content_type' => 'application/json; charset=utf-8'], Manifest::entry('a.json', 'abc'));
    $this->assertSame('text/csv; charset=utf-8', Manifest::entry('a.csv', '')['content_type']);
});

test('timestamps are UTC to the second', function () {
    $this->assertSame('2026-10-14T16:00:00Z', Manifest::timestamp(new DateTimeImmutable('2026-10-14T23:00:00+07:00')));
});

test('snapshot ids are a date with an optional .n suffix', function () {
    foreach (['2026-10-14', '2026-10-14.1', '2026-10-14.12'] as $ok) {
        $this->assertTrue(Snapshots::validId($ok), $ok);
    }
    foreach (['2026-10-14.0', '2026-10-14.', '26-10-14', '2026-10-14/x', '../2026-10-14', ''] as $bad) {
        $this->assertFalse(Snapshots::validId($bad), $bad);
    }
});

test('the next id is the date, then .1, .2, skipping withdrawn and stranded ids', function () {
    $index = ['snapshots' => [['id' => '2026-10-14'], ['id' => '2026-10-14.1']]];
    $this->assertSame('2026-10-15', Snapshots::nextId('2026-10-15', $index));
    $this->assertSame('2026-10-14.2', Snapshots::nextId('2026-10-14', $index));
    $this->assertSame('2026-10-14.3', Snapshots::nextId('2026-10-14', $index, ['2026-10-14.2']));
    $this->assertSame('2026-10-14', Snapshots::nextId('2026-10-14', null));
});

test('appending refuses an id already in the index, and a bad one', function () {
    $index = Snapshots::append(Snapshots::emptyIndex('1.0.0'), '2026-10-14', '2026-10-14T16:00:00Z', str_repeat('b', 64));
    $this->assertSame([['id' => '2026-10-14', 'status' => 'published', 'published_at' => '2026-10-14T16:00:00Z', 'manifest_sha256' => str_repeat('b', 64)]], $index['snapshots']);
    expect(fn () => Snapshots::append($index, '2026-10-14', 'x', 'y'))->toThrow(RuntimeException::class, 'never reused');
    expect(fn () => Snapshots::append($index, 'nope', 'x', 'y'))->toThrow(InvalidArgumentException::class);
});

test('the last published snapshot skips withdrawn ones', function () {
    $index = ['snapshots' => [['id' => 'a', 'status' => 'published'], ['id' => 'b', 'status' => 'withdrawn']]];
    $this->assertSame('a', Snapshots::lastPublished($index));
    $this->assertNull(Snapshots::lastPublished(['snapshots' => []]));
});

test('latest.json points at a snapshot and its manifest', function () {
    $this->assertSame(['schema_version' => '1.0.0', 'snapshot' => '2026-10-14', 'manifest_sha256' => 'x'], Snapshots::latest('1.0.0', '2026-10-14', 'x'));
});
