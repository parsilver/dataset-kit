<?php

declare(strict_types=1);

use Farzai\DatasetKit\Envelope;
use Farzai\DatasetKit\Gate\Failure;
use Farzai\DatasetKit\Gate\GateReport;
use Farzai\DatasetKit\Json;
use Farzai\DatasetKit\Layout;
use Farzai\DatasetKit\Manifest;
use Farzai\DatasetKit\Snapshots;

function demoLayout(): Layout
{
    return new Layout(
        root: 'demo',
        schemaVersion: '1.0.0',
        order: ['items.json', 'notes.csv', 'schema.json'],
        required: ['items.json'],
        previous: ['items.json'],
        docs: ['llms.txt' => 'text/plain; charset=utf-8', 'index.html' => 'text/html; charset=utf-8'],
        userAgent: 'farzai/dataset-kit-test',
    );
}

/**
 * A small gated build under $out in the layout's key shape, the way a pipeline
 * writes one: files, manifest, snapshots.json, latest.json, gate report.
 *
 * @param list<Failure> $failures
 */
function demoBuild(string $out, string $snapshot = '2026-10-14', array $failures = [], ?array $index = null): void
{
    $layout = demoLayout();
    $dir = "{$out}/" . $layout->prefix() . "/{$snapshot}";
    mkdir("{$dir}/by", 0o755, true);
    $files = [
        'items.json' => Envelope::encode('1.0.0', $snapshot, [['id' => 1], ['id' => 2]]),
        'notes.csv' => "id,note\n1,a\n",
        'schema.json' => '{}' . "\n",
        'by/x.json' => Envelope::encode('1.0.0', $snapshot, [['id' => 1]]),
        'by/a.json' => Envelope::encode('1.0.0', $snapshot, [['id' => 2]]),
    ];
    $listed = [];
    foreach ($files as $name => $content) {
        file_put_contents("{$dir}/{$name}", $content);
        $listed[$name] = Manifest::entry($name, $content);
    }
    $manifestJson = Json::pretty(['schema_version' => '1.0.0', 'snapshot' => $snapshot, 'files' => $listed]);
    file_put_contents("{$dir}/manifest.json", $manifestJson);
    $sha = hash('sha256', $manifestJson);
    $index = Snapshots::append($index ?? Snapshots::emptyIndex('1.0.0'), $snapshot, '2026-10-14T16:00:00Z', $sha);
    file_put_contents("{$out}/" . $layout->prefix() . '/snapshots.json', Json::pretty($index));
    file_put_contents("{$out}/" . $layout->prefix() . '/latest.json', Json::pretty(Snapshots::latest('1.0.0', $snapshot, $sha)));
    file_put_contents(GateReport::path($out, $snapshot), Json::pretty((new GateReport($snapshot, $sha, $failures, [], '2026-10-14T16:00:01Z'))->toArray()));
}

function tmpDir(string $name): string
{
    return sys_get_temp_dir() . "/dskit-{$name}-" . bin2hex(random_bytes(4));
}
