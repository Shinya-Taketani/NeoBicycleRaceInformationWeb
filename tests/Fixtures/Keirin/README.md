Keirin fixture policy
=====================

`actual/` contains saved structures from public KEIRIN.JP pages for parser regression tests.

`actual/player_search_s_class.html` is a saved S-class S-group search response with 9 results and no rendered pagination control. It is retained as the real single-page pagination regression fixture.

`synthetic/` contains minimal or edited HTML used to exercise edge cases such as unavailable results, corrected results, cancellation, invalid payouts, and parser failures.

`synthetic/player_search_missing_pagination_20_of_10.html` is an anonymized, minimal player-search structure for the inconsistent case where 20 results are reported but only 10 players are present and no page count is rendered.

`synthetic/player_search_foreign_rider_page.html` is an anonymized, minimal reproduction of a player-search page containing both a normal domestic row and a foreign-rider row without `UNQ_orlabel_6`. It preserves the observed page 23/46 structure while excluding real rider identity data.

Some fixture contents may be minimized to avoid unnecessary personal data and to keep tests focused. Runtime scraping never falls back to fixtures.

Official race result detail pages have not yet been confirmed as full real-page fixtures in this repository, so current race result fixtures are synthetic.

The `synthetic/race-sync-*` fixtures are minimized from the key structures observed in the saved JSJ001, JSJ017, PJ0315, PJ0326, and PC0201 research responses. Encrypted parameters, rider identities, and meeting names are fictional. They cover six meeting days, 12 complete 6-car, 7-car, 8-car, or 9-car fields, embedded race details, abnormal result statuses, all supported payout types, and the PC0201 cancellation/race-end/result-received flags used by the result status policy. Full research responses under `storage/app/private/research` are not fixtures and must not be committed.

`synthetic/race-sync-jsj017-postponed.json` is a minimal synthetic reproduction of the public JSJ017 postponed response observed for Kochi on 2026-06-02. It retains only the three fields required to distinguish a postponed race day and excludes request parameters and other unnecessary response data.

`synthetic/race-sync-jsj017-cancelled.json` is a minimal synthetic reproduction of the public JSJ017 race-day cancellation structure observed at Ito and Komatsushima. Its track code and date are adapted to the synthetic meeting used by tests. It retains the request context needed to reject a cancellation response returned for the wrong track or race day.

`synthetic/race-sync-pj0301-meeting-cancelled.html` is a minimal synthetic reproduction based on the saved public PJ0301 structure for the cancelled Omiya meeting from 2025-12-03 through 2025-12-05. Meeting names and encrypted parameters are fictional; the three-day schedule, cancellation flags, zero race count, cancellation message, and missing `C0201race` shape needed for regression coverage are retained.

Result-state variants for cancellation, section cancellation, under review, provisional, corrected, and undetermined states are synthetic test mutations. The confirmed-state flag combination mirrors the saved public PJ0326/PC0201 structure; the complete raw response remains excluded from fixtures.
