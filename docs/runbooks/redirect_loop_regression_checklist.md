# Redirect Loop Regression Checklist

This checklist validates redirect behavior for Kairos pages under `/signoff/`.

## Preconditions
- Test both logged-out and logged-in browser sessions.
- Open DevTools `Network` tab with **Preserve log** enabled.

## Test Cases

1. **Root canonicalization**
   - Open `https://kairos.nixorcorporate.com/`.
   - Expect one redirect to `https://kairos.nixorcorporate.com/signoff/`.
   - Confirm no follow-up self-redirect loop on `/signoff/`.

2. **Logged out on signoff home**
   - Open `https://kairos.nixorcorporate.com/signoff/`.
   - Expect login UI to render and remain stable.
   - Confirm no repeated `document` navigations to the same URL.

3. **Logged out on protected page**
   - Open `https://kairos.nixorcorporate.com/signoff/course.html?course_id=3`.
   - Expect one redirect to `/signoff/`.
   - Confirm redirect halts after landing on `/signoff/`.

4. **Logged in on login/home entry**
   - Open `https://kairos.nixorcorporate.com/signoff/`.
   - Expect one transition into authenticated dashboard state.
   - Reload 3 times and verify no loop.

5. **Path normalization behavior**
   - Open these paths manually:
     - `/signoff`
     - `/signoff/`
     - `/signoff/index.html`
   - Expect stable rendering and no bouncing between forms.

## Notes
- If a redirect occurs, inspect `sessionStorage['kairos:lastRedirect']` to verify loop-sentinel behavior.
- Validate there are no new Cross-Origin-Opener-Policy errors caused by redirect handling.
