# Changelog

All notable changes to `socialdept/atp-signals` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-05-24

### Added

- **Tap mode** — Webhook-based event delivery via the `tap` Go binary from `bluesky-social/indigo`. No long-running PHP process, automatic backfilling of historical events for tracked DIDs.
  - `SocialDept\AtpSignals\Tap\TapClient` — HTTP client for the Tap admin API (`addRepo`, `removeRepo`, `health`).
  - `SocialDept\AtpSignals\Tap\TapWebhookController` and `TapBulkWebhookController` — receive single-event and batched payloads from Tap, normalize, and dispatch through `EventDispatcher`. Auto-registered when `TAP_ENABLED=true`.
  - `SocialDept\AtpSignals\Tap\TapEventNormalizer` — converts Tap's wire format to `SignalEvent`.
  - `SocialDept\AtpSignals\Tap\Models\TapRepo` and `TapRepoRecord` — read-only Eloquent models bound to the Tap SQLite database for repo/record introspection.
  - New artisan commands: `signal:tap:add`, `signal:tap:remove`, `signal:tap:status`, `signal:tap:restart`.
  - `bin/tap-batcher/` — Optional Bun HTTP proxy that sits between Tap and Laravel. Tap POSTs single events to the batcher; the batcher buffers and forwards batches to `TapBulkWebhookController` to keep per-event HTTP overhead down during backfills. Each Tap request stays open until its batch is delivered (2xx) or all retries fail (5xx) — Tap's `outbox_buffers` is the durable retry buffer, no in-memory events lost on restart. Configured via `atp-signals.tap.batcher.{enabled,host,port,path,batch_size,batch_timeout,insecure_tls}`.
- **`SignalEvent::$backfill`** — new optional `?bool $backfill = null` constructor parameter. `null` for Jetstream/Firehose, `true`/`false` for Tap events based on the `live` flag. Serialized in `toArray()` only when set.
- **`SignalServiceProvider`** auto-discovers signals in the configured directory in addition to those listed in config (was previously config-only).
- `docs/tap.md` — Tap mode guide (configuration, commands, batcher, troubleshooting).

### Changed

- `SignalManager::start()` now throws a helpful `InvalidArgumentException` when `mode=tap` is configured, explaining that Tap uses webhook delivery and has no consumer to start.
- `README.md` and `docs/{installation,modes,configuration}.md` updated to cover the three consumption modes (Jetstream, Firehose, Tap).

## [2.0.2] - 2026-04-29

### Changed

- Bump `socialdept/atp-cbor` to `^0.2`.
- Add Laravel 13 (`illuminate/* ^13.0`) support.

## [2.0.1] - 2026-02-07

### Changed

- README, CONTRIBUTING, and header image refresh.

## [2.0.0] - 2026-02-07

### Changed

- **BREAKING:** Config file renamed from `signal.php` to `atp-signals.php`. Update any references in your application.
- **BREAKING:** CBOR/CAR encoding extracted to the separate [`socialdept/atp-cbor`](https://packagist.org/packages/socialdept/atp-cbor) package. The Firehose consumer still uses CBOR internally but depends on `atp-cbor` rather than bundling it.
- Namespace consistency improvements across the `atp-*` package family.

### Fixed

- MST prefix calculation now uses the previous entry's key instead of parent prefix (Firehose).

## [1.2.5] and earlier

See git history for changes prior to the v2.0 namespace + extraction work:

- `1.2.x` — Jetstream third-party lexicon filtering fixes, `SIGNAL_DEBUG` env var, WebSocket error handling hardening.
- `1.1.x`, `1.0.x` — Initial stable releases.
- `0.x` — Pre-release iterations.

[2.1.0]: https://github.com/socialdept/atp-signals/compare/v2.0.2...v2.1.0
[2.0.2]: https://github.com/socialdept/atp-signals/compare/v2.0.1...v2.0.2
[2.0.1]: https://github.com/socialdept/atp-signals/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/socialdept/atp-signals/compare/v1.2.5...v2.0.0
