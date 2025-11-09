# Kairos Deployment Notes

## Summary of updates
- `public/api/queues.php`, `public/script.js`, and `public/css/style.css` implement atomic/idempotent queue join/leave handling with guarded UI states.
- Role helpers in `public/api/_helpers.php` and `public/api/ta/common.php` now enforce the manager/admin hierarchy for TA tools and course access.
- Branding assets and copy across the HTML/CSS files (`public/index.html`, `public/room.html`, `public/ta.html`, `public/manager.html`, `public/admin.html`, shared styles, and room styles) point to the Kairos logos and titles.
- `ws_server.py` description matches the Kairos name.

## Reverting the branding
1. Replace visible "Kairos" strings with "Signoff" (or your previous brand) in the HTML templates above.
2. Swap the `<img>` references back to the prior iconography (e.g., remove the `<img>` tags inside `.logo` wrappers or adjust them to the previous emoji/icon).
3. Remove the new favicon `<link>` elements if you want to fall back to the original behaviour.
4. Restore any custom styles added for `.app-brand`, `.signin-logo`, `.admin-brand`, and `.brand-lockup` if the old layout is preferred.

Application logic (queue fixes and role hierarchy) can remain in place when reverting the visual branding.
