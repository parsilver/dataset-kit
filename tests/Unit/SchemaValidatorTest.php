<?php

declare(strict_types=1);

use Farzai\DatasetKit\Gate\SchemaValidator;

const DEMO_SCHEMA = '{"$id":"https://data.example/demo/v1/{snapshot}/schema.json","$defs":{"row":{"type":"object","required":["id"],"properties":{"id":{"type":"integer"}},"additionalProperties":true}}}';
const DEMO_ID = 'https://data.example/demo/v1/0000-00-00/schema.json';

test('a value that matches the def has no errors', function () {
    $this->assertSame([], (new SchemaValidator(DEMO_SCHEMA, DEMO_ID))->errors(['id' => 1, 'extra' => 'ignored'], 'row'));
});

test('a value that does not match names the path and the problem', function () {
    $errors = (new SchemaValidator(DEMO_SCHEMA, DEMO_ID))->errors(['id' => 'x'], 'row');
    $this->assertNotSame([], $errors);
    $this->assertStringContainsString('/id', $errors[0]);
});

test('at most five errors are reported for one value', function () {
    $schema = '{"$id":"https://data.example/demo/v1/{snapshot}/schema.json","$defs":{"row":{"type":"object","required":["a","b","c","d","e","f","g"]}}}';
    $this->assertLessThanOrEqual(5, count((new SchemaValidator($schema, DEMO_ID))->errors([], 'row')));
});
