# Kairos BookStack Documentation

BookStack shelf: [Kairos](https://docs.nixorcorporate.com/shelves/kairos)

Create the books and pages in the numbered order shown in this folder:

1. Start Here
2. Student Guide
3. TA Guide
4. Manager Guide
5. Admin Guide

Each Markdown file is one BookStack page. Upload the referenced image from `screenshots/` when publishing the page in BookStack.

Screenshots use example names and course data. Regenerate them after a user-visible Kairos change:

```bash
tools/generate_bookstack_screenshots.sh
node tools/tests/bookstack_docs_contract_test.mjs
```

Before publishing, verify any sentence marked **Confirm in Kairos before publishing** against the deployed Kairos interface.
