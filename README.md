# dataset-kit

Internal plumbing for the farzai dataset pipelines. It is public so the
secret-free fetch and build jobs can install it without a token; it is not a
supported product, has no stability promise outside those pipelines, and takes
no feature requests. Licensed under the MIT licence (see `LICENSE`).

Plain functions shared by the dataset pipelines (`thailand-address-data`,
`geonames-data`). A library, not a framework: each dataset keeps its own fetch,
parse, build and gates, and calls these for the steps that must be identical.

| Piece | What it does |
|---|---|
| `Json`, `Envelope` | the two JSON encodings, and the `{schema_version, snapshot, data}` envelope with one row per line |
| `Manifest` | a file's `sha256`/`bytes`/`content_type` entry, UTC timestamps |
| `Snapshots` | snapshot ids (`YYYY-MM-DD[.n]`, never reused), `snapshots.json` append, `latest.json` pointer |
| `Gate\SchemaValidator`, `Gate\GateReport`, `Gate\Failure` | validate against a `$defs` entry; the verdict bound to the manifest's sha256; thresholds that can be approved by name and hard gates that cannot |
| `Publish\ScopedBucket` | every Publisher wraps its bucket in one: a key outside the layout's root folder, or one with a `..`, `.` or empty segment, is refused before it reaches the bucket, because datasets share a bucket and a write token |
| `Publish\Publisher` (concurrency and resume) | the first file of the layout's order is the claim, written alone; every other object goes up in parallel (24 at a time by default, transient failures retried with backoff); the manifest, `snapshots.json` and `latest.json` only after every object is written or verified. A rerun finds the claim already there, asks what is stored (HEAD, md5 ETag and size) and sends only what is missing; an object that exists with other bytes stops the publish, and nothing is ever overwritten. `PreviousSnapshot` treats a directory with a claim but no manifest as stranded, so a NEW build takes the next id |
| `Publish\Publisher` | create-only upload (`If-None-Match: *`) of the files, then `snapshots.json` (`If-Match`), then `latest.json`, then a cache purge; refuses a build the gates did not pass for this exact manifest |
| `Publish\S3Bucket`, `SigV4`, `DirectoryBucket`, `CloudflarePurger`, `Config` | R2 over curl with SigV4; a local directory for `--dry-run`; credentials from the environment only, never in a message |
| `Publish\PreviousSnapshot` | reads the published index and last snapshot back from the CDN, each file checked against its manifest |
| `Layout` | where a dataset lives in the bucket and what its snapshots contain; the only per-dataset input the publisher needs |

```
composer test
```

Not a dataset registry and not a plugin system: a change here must be one that
both pipelines need.
