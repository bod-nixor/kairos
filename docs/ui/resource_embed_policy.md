# Resource Embed Policy

Kairos embeds only providers with an explicit URL normalizer and iframe policy. Unsupported external URLs are links,
not generic iframes. MySQL remains authoritative for resource ownership and Kairos RBAC always runs before a resource
viewer receives metadata.

| Provider | Canonical form | Sandbox | Permissions | Fallback |
|---|---|---|---|---|
| YouTube | `youtube-nocookie.com/embed/<id>` | none | encrypted media, picture-in-picture, fullscreen | original watch URL |
| Vimeo | `player.vimeo.com/video/<id>` | none | picture-in-picture, fullscreen | original Vimeo URL |
| Google Docs/Sheets | `/preview` | none | none | original Google URL |
| Google Slides | `/embed?start=false&loop=false` | none | none | original Slides URL |
| Google Drive file | `/file/d/<id>/preview` | none | none | original Drive URL |
| Microsoft Office | `view.officeapps.live.com/op/embed.aspx` | none | none | original file URL |
| Kairos-managed PDF | authenticated same-origin preview endpoint | `allow-same-origin` | none | authenticated download endpoint |

All generated iframes require a meaningful title, `loading="lazy"`, and
`referrerpolicy="strict-origin-when-cross-origin"`. Do not combine `allow-scripts` with `allow-same-origin`; that
combination removes the practical isolation benefit when embedded content is same-origin. Do not add autoplay,
clipboard-write, accelerometer, or gyroscope unless a reviewed feature explicitly needs them.

The resource viewer always exposes an original-resource or authenticated-download fallback. A provider refusing to
frame, requiring sign-in, or failing its own browser checks must not strand the user.

## Expected Third-Party Console Noise

The following messages may come from provider internals and are not Kairos defects when the preview remains usable:

- Google Docs filesystem/font warnings.
- Google Slides `target-densitydpi` warnings.
- Blocked `play.google.com/log` requests.
- YouTube `log_event` or `generate_204` requests blocked by privacy tooling.

Do not weaken CSP or iframe permissions merely to silence optional telemetry. Investigate when the frame is blank,
the fallback is missing, Kairos emits a CSP violation for a required provider, or the browser reports the
`allow-scripts` plus `allow-same-origin` sandbox warning.
