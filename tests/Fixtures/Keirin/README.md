Keirin fixture policy
=====================

`actual/` contains saved structures from public KEIRIN.JP pages for parser regression tests.

`actual/player_search_s_class.html` is a saved S-class S-group search response with 9 results and no rendered pagination control. It is retained as the real single-page pagination regression fixture.

`synthetic/` contains minimal or edited HTML used to exercise edge cases such as unavailable results, corrected results, cancellation, invalid payouts, and parser failures.

`synthetic/player_search_missing_pagination_20_of_10.html` is an anonymized, minimal player-search structure for the inconsistent case where 20 results are reported but only 10 players are present and no page count is rendered.

`synthetic/player_search_foreign_rider_page.html` is an anonymized, minimal reproduction of a player-search page containing both a normal domestic row and a foreign-rider row without `UNQ_orlabel_6`. It preserves the observed page 23/46 structure while excluding real rider identity data.

Some fixture contents may be minimized to avoid unnecessary personal data and to keep tests focused. Runtime scraping never falls back to fixtures.

Official race result detail pages have not yet been confirmed as full real-page fixtures in this repository, so current race result fixtures are synthetic.

The `synthetic/race-sync-*` fixtures are minimized from the key structures observed in the saved JSJ001, JSJ017, PJ0315, PJ0326, and PC0201 research responses. Encrypted parameters, rider identities, and meeting names are fictional. They cover six meeting days, 12 complete 6-car, 7-car, or 9-car fields, embedded race details, abnormal result statuses, all supported payout types, and the PC0201 cancellation/race-end/result-received flags used by the result status policy. Full research responses under `storage/app/private/research` are not fixtures and must not be committed.

Result-state variants for cancellation, section cancellation, under review, provisional, corrected, and undetermined states are synthetic test mutations. The confirmed-state flag combination mirrors the saved public PJ0326/PC0201 structure; the complete raw response remains excluded from fixtures.
