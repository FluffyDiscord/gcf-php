# gcf-php

A PHP implementation of [GCF (Graph Compact Format)](https://www.gcformat.com/) — a token-efficient
wire format for structured data designed for LLM agent loops. Lossless conversion to/from JSON,
50-92% fewer tokens depending on data shape.

GCF is specified and maintained by [blackwell-systems/gcf](https://github.com/blackwell-systems/gcf),
with reference implementations in Go, Rust, TypeScript, Python, Swift, and Kotlin. This is an
independent PHP port, targeting the **generic profile** of spec version 3.5.1 (see
[`spec/SPEC.md`](spec/SPEC.md), vendored from the source commit noted in
[`spec/SOURCE-COMMIT.txt`](spec/SOURCE-COMMIT.txt)). It was ported from
[gcf-python](https://github.com/blackwell-systems/gcf-python).

## Scope

**v1 covers the generic profile only**: encoding/decoding arbitrary PHP values (arrays, scalars)
to and from GCF text — tabular arrays, keyed maps, nested-object flattening, attachments, inline
schemas. This is the profile relevant to record-shaped data such as search results.

**Not yet ported** (planned for v2): delta encoding, the graph profile (symbols/edges), session
dedup, and streaming. These exist in the spec and in every other language's implementation, but
weren't needed for the motivating use case (compressing Meilisearch results for an AI agent) so
they were deliberately left out of v1 rather than half-implemented.

## Requirements

PHP 8.5+. Zero runtime dependencies — matching the other five official implementations'
permanent zero-dependency commitment.

## Usage

```php
use Gcf\Generic\Encoder;
use Gcf\Generic\Decoder;

$gcf = Encoder::encode($data);   // array|scalar|null -> GCF text
$data = Decoder::decode($gcf);   // GCF text -> array|scalar|null
```

## Conformance

`tests/conformance/` is vendored from the spec repo's shared fixture suite (JSON files with
`input`/`expected`/`operation` — the same fixtures used to validate the other 6 implementations),
filtered to the generic-profile subset. `Gcf\Tests\ConformanceTest` runs every fixture in that
directory.

## License

MIT. See [LICENSE](LICENSE). Format specification and prior art by Dayna Blackwell /
[blackwell-systems](https://github.com/blackwell-systems).
