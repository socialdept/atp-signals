# Changelog

All notable changes to `socialdept/atp-signals` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-08-04

### Added

- **Obelisk mode** — consume the event log of an Obelisk archive, a self-hostable AT Protocol record store. Two directions over the same log, usable together:
  - **Push.** `SocialDept\AtpSignals\Obelisk\ObeliskWebhookController` receives batched, HMAC-signed deliveries. Auto-registered when `OBELISK_ENABLED=true`. Obelisk owns the cursor and advances it only on a 2xx, so a rejected or failed delivery is redelivered rather than lost. Signature verification fails closed when no secret is configured.
  - **Pull.** `ObeliskConsumer` polls `getEvents` from a stored cursor — no inbound URL needed. Wired into `signal:consume` as `SIGNAL_MODE=obelisk`, with `signal:obelisk:pull` for scheduled catch-up runs.
  - `ObeliskClient` — client for the archive's XRPC surface: events, webhooks, watched DIDs, repo backfills, and record queries, plus `query()`/`procedure()` escape hatches.
  - `ProcessObeliskBatchJob` — one job per delivery rather than per event, so events keep their cursor order through the queue.
  - `ObeliskBatchProcessor` and `ObeliskEventNormalizer` — shared by push and pull; a malformed event is skipped and logged rather than stranding its batch.
  - New artisan commands: `signal:obelisk:subscribe`, `signal:obelisk:status`, `signal:obelisk:rewind`, `signal:obelisk:pull`. `subscribe` and `rewind` are dry-run by default and take `--execute`.
  - `docs/obelisk.md` — mode guide (setup, delivery guarantees, replay, troubleshooting).
- **`SignalEvent::$backfill`** — new optional `?bool $backfill = null` constructor parameter. `null` for Jetstream/Firehose, `true`/`false` for Obelisk events based on the `live` flag. Serialized in `toArray()` only when set.
- **`SignalEvent::$cursor`** — new optional `?string $cursor = null` constructor parameter carrying the Obelisk event id. `null` for Jetstream/Firehose. Serialized in `toArray()` only when set.
- **Namespaced cursor stores** — `DatabaseCursorStore`, `RedisCursorStore`, and `FileCursorStore` accept an optional key so consumer modes keep independent positions, and `CursorStoreFactory::make()` builds the configured driver. Behavior is unchanged when no key is passed.
- **`SignalServiceProvider`** auto-discovers signals in the configured directory in addition to those listed in config (was previously config-only).

### Changed

- `SignalManager::start()` resolves the Obelisk consumer for `mode=obelisk`; its unknown-mode error names the three valid modes.
- `README.md` and `docs/{installation,modes,configuration,signals}.md` updated to cover the three consumption modes (Jetstream, Firehose, Obelisk).

### Removed

- **Tap mode**, which was built but never released. The `src/Tap` subsystem, the `signal:tap:*` commands, the `bin/tap-batcher` Bun proxy, and the `tap` config block are gone. Obelisk covers the same webhook-delivery use case with a durable, replayable log behind it, and Tap's per-event delivery flooded receiving apps during backfill. Recoverable from git history.

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
