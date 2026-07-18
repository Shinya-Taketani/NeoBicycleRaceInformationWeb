Keirin fixture policy
=====================

`actual/` contains saved structures from public KEIRIN.JP pages for parser regression tests.

`actual/player_search_s_class.html` is a saved S-class S-group search response with 9 results and no rendered pagination control. It is retained as the real single-page pagination regression fixture.

`synthetic/` contains minimal or edited HTML used to exercise edge cases such as unavailable results, corrected results, cancellation, invalid payouts, and parser failures.

`synthetic/player_search_missing_pagination_20_of_10.html` is an anonymized, minimal player-search structure for the inconsistent case where 20 results are reported but only 10 players are present and no page count is rendered.

Some fixture contents may be minimized to avoid unnecessary personal data and to keep tests focused. Runtime scraping never falls back to fixtures.

Official race result detail pages have not yet been confirmed as full real-page fixtures in this repository, so current race result fixtures are synthetic.
