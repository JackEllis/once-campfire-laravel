# Campfire in Laravel

ONCE Campfire implemented natively with Laravel 13 and PHP 8.4: existing SQLite schema and uploads, Rails-compatible login/form cookies, and the original interactive frontend. The immutable public Rails reference is pinned at `659f957`.

```sh
git submodule update --init
docker build -t once-campfire-laravel .
docker run --rm -p 8080:80 -e SECRET_KEY_BASE="$(openssl rand -hex 64)" -v campfire:/rails/storage once-campfire-laravel
```

Existing installs must reuse their `SECRET_KEY_BASE`, preserve `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` for existing push subscriptions, and mount existing storage at `/rails/storage`. The image runs FrankenPHP with Laravel Octane workers sharing an APCu message fragment cache, an asynchronous SQLite-backed queue worker and native Action Cable. `HTTP_PORT` changes the listening port, `WEB_CONCURRENCY` the Octane worker count (default twice the CPUs) and `MAX_REQUESTS` how many requests a worker serves before it is recycled.

Run the PHPUnit suite with `composer test` inside the pinned PHP image; native media tests require libvips. Compatibility and independent verification evidence lives in `plans/contracts.json`. Verification includes 26 independent browser assertions, actual Rails cookie continuity and live WebSocket privacy checks. Remaining checks are listed in the ledger.

## Benchmarks

Measured with 16 concurrent clients on an AMD Ryzen AI MAX+ 395,
with four hardware threads allocated to each app.

| HTTP workload (requests/sec) | Rails | [Django](https://github.com/basecamp/once-campfire-django) | [Laravel](https://github.com/basecamp/once-campfire-laravel) | [Express](https://github.com/basecamp/once-campfire-express) | [Elixir](https://github.com/basecamp/once-campfire-elixir) | [Go](https://github.com/basecamp/once-campfire-go) | [Rust](https://github.com/basecamp/once-campfire-rust) |
|---|---:|---:|---:|---:|---:|---:|---:|
| Room page | 241 | 170 | 164 | 559 | 722 | 3,860 | 36,260 |
| Messages page | 413 | 196 | 175 | 777 | 1,053 | 5,573 | 40,872 |
| Sidebar | 552 | 615 | 715 | 4,125 | 1,275 | 19,753 | 34,672 |
| Search | 435 | 315 | 305 | 1,294 | 1,156 | 7,053 | 33,299 |
| Post a message | 273 | 154 | 137 | 256 | 801 | 4,767 | 6,896 |

At 100 WebSocket connections and five messages/second, median delivery to every
connection was 24 ms for Rails, 70 ms for Django and 42 ms for Laravel. Every message
reached every connection in both runs.

## Known differences

Laravel transient request sessions live in encrypted cookies and queued jobs use native storage, both separate from Rails' tables. Installs add an index on `messages (room_id, created_at)` so room pages read the newest page instead of sorting a room's whole history; Rails ignores it. Message fragments are cached like Rails' `cached: true` collections, so a renamed mentioned user or room appears in old messages once they change, as in Rails; author renames apply immediately. Whole message lists (room pages, older-message pages and search results) are cached too, keyed on the same message and author versions, or for search on the versions of the viewer's rooms, which every message, boost and edit touches as in Rails. Writers queue on a lock file beside each SQLite database instead of polling SQLite's busy handler. Native media variants have a separate cache while retaining original blobs and signed URLs. Sidebar updates replace the member's sidebar frame rather than individual rows. The direct-room picker explicitly requests JSON, repairing an inherited browser fetch option. Legacy Marshal serialization is unsupported; JSON Rails cookies, signed identifiers, SGIDs and variations are supported. Do not replace an existing installation until the remaining ledger checks are verified.
