# Track context fixtures

- `actual/seibuen-structure.html`: actual minimal structural table (`table.hyo3`), without the neighbouring record, rider or results content. Official URL: https://www.keirin-saitama.jp/seibuen/lp/ . Retrieved 2026-09-23. Raw SHA-256: `1a2b17049aac0c967712dc937ed35812f0f93f48d1ccdc0de14d02be5a2d4022`. Only the outer indentation/final newline is adjusted. Source ledger: `resources/data/keirin/track-context/v1/sources.json`.
- `Tests\Support\TrackContextFixture` creates **synthetic** manifests, evidence, layout periods and numeric variants under an isolated temporary test directory. They are not real track histories and are never added to the real master.
- Inline malformed HTML, numeric/status inputs and dates in the unit tests are **synthetic**.
