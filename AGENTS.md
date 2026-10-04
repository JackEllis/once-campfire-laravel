# once-campfire-laravel

Native Laravel 13 / PHP 8.4 implementation. `reference/` pins the public Rails source at 659f957; treat it as immutable. Use its models/controllers and `compat/rails_compat.json` as behavior oracles.

Preserve the SQLite schema, uploaded blob paths and Rails signed/encrypted cookie contracts. Document deliberate differences in README and update plans/contracts.json after verification. Do not claim browser, realtime or external-service parity from unit tests.

Build production with Docker. Run PHP tests using the production toolchain with dev dependencies installed, or pinned Composer image. Format PHP with `vendor/bin/pint`. Raw test/benchmark artifacts belong in ignored `tmp/`; never commit results.
