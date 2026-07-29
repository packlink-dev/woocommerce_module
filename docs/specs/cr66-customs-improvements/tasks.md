# Tasks: CR-SET-66 customs feedback improvements

Registered in the session task system (IDs #1–#4). Waves: #1–#3 parallel (disjoint files, subagents,
no commits), #4 after all (cleanup + gate + one commit per task in order T1 → T3 → T2 → T4, authored
`Implementator`).

| ID | Task | Files | blockedBy |
|---|---|---|---|
| #1 | T1 Mapping service option lists (constants, namespacing, country-of-origin select, attribute scan, curated customer keys) | `src/Components/Services/class-customs-mapping-service.php`, `src/tests/test-customs-mapping-service.php` | — |
| #2 | T2 Handler/plugin (profile-only capture, seed fix, upgrade rename) | `src/Components/Customs/class-customs-handler.php`, `src/class-plugin.php`, `src/upgrade/upgrade-4.3.0.php`, `src/tests/test-customs-customer-fields.php`, `src/tests/test-customs-migration.php` | — |
| #3 | T3 Order-sync resolution (namespaced helpers, country mapped step, profile fallback) | `src/Components/Order/class-shop-order-service.php`, `src/tests/test-shop-order-service-customs.php` | — |
| #4 | T4 Cleanup + DESIGN.md + gate + commits | cleanup, `DESIGN.md`, commits | #1 #2 #3 |
