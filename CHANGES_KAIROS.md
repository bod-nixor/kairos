# Kairos Deployment Notes

## Summary of updates
- `public/api/queues.php`, `public/script.js`, and `public/css/style.css` implement atomic/idempotent queue join/leave handling with guarded UI states.
- Role helpers in `public/api/_helpers.php` and `public/api/ta/common.php` now enforce the manager/admin hierarchy for TA tools and course access.
- Branding assets and copy across the HTML/CSS files (`public/index.html`, `public/room.html`, `public/ta.html`, `public/manager.html`, `public/admin.html`, shared styles, and room styles) point to the Kairos logos and titles.
- `ws_server.py` description matches the Kairos name.
- `public/js/room.js` and `public/js/ta.js` consume WebSocket queue events for targeted DOM updates; polling was removed and optimistic button states were added.
- Queue helpers (`public/api/queue_helpers.php`, `public/api/queues.php`, `public/api/ta/queues.php`, `public/api/ta/accept.php`) now emit structured WebSocket payloads with queue snapshots, and a new TA stop endpoint (`public/api/ta/stop.php`) records audit data.
- `public/css/style.css` adds shared spinner/button helpers used by queue actions.

## Reverting the branding
1. Replace visible "Kairos" strings with "Signoff" (or your previous brand) in the HTML templates above.
2. Swap the `<img>` references back to the prior iconography (e.g., remove the `<img>` tags inside `.logo` wrappers or adjust them to the previous emoji/icon).
3. Remove the new favicon `<link>` elements if you want to fall back to the original behaviour.
4. Restore any custom styles added for `.app-brand`, `.signin-logo`, `.admin-brand`, and `.brand-lockup` if the old layout is preferred.

Application logic (queue fixes and role hierarchy) can remain in place when reverting the visual branding.

## Database updates

To persist TA stop-serving actions when a dedicated audit table is available, create the optional log table:

```
CREATE TABLE IF NOT EXISTS ta_audit_log (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  queue_id BIGINT UNSIGNED DEFAULT NULL,
  student_user_id BIGINT UNSIGNED DEFAULT NULL,
  meta_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
