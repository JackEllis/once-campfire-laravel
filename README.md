# Campfire in Laravel

ONCE Campfire implemented natively with Laravel 13 and PHP 8.4: existing SQLite schema and uploads, Rails-compatible login/form cookies, and the original interactive frontend. The immutable public Rails reference is pinned at `659f957`.

```sh
git submodule update --init
docker build -t once-campfire-laravel .
docker run --rm -p 8080:80 -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -v campfire:/rails/storage once-campfire-laravel
```

Existing installs must reuse their `SECRET_KEY_BASE`, preserve `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` for existing push subscriptions, and mount existing storage at `/rails/storage`. The image runs nginx with gzip, eight PHP-FPM workers, an asynchronous SQLite-backed queue worker and native Action Cable. `HTTP_PORT` changes the listening port.

Run the PHPUnit suite with `composer test` inside the pinned PHP image; native media tests require libvips. Compatibility and independent verification evidence lives in `plans/contracts.json`. Verification includes 26 independent browser assertions, actual Rails cookie continuity and live WebSocket privacy checks. Remaining checks are listed in the ledger.

## Benchmarks

Measured with 16 concurrent clients on an AMD Ryzen AI MAX+ 395,
with four hardware threads allocated to each app.

| Requests/second | Ruby | Django | Laravel |
|---|---:|---:|---:|
| Room | 242 | 170 | 164 |
| Messages | 402 | 196 | 175 |
| Sidebar | 541 | 615 | 715 |
| Search | 424 | 315 | 305 |
| Post message | 225 | 154 | 137 |

At 100 WebSocket connections and five messages/second, median delivery to every
connection was 24 ms for Ruby, 70 ms for Django and 42 ms for Laravel. Every message
reached every connection in both runs.

## Known differences

Laravel transient request sessions and queued jobs use native storage separate from Rails' tables. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
