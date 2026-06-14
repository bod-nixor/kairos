import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const docsRoot = path.join(root, 'docs', 'bookstack', 'kairos');
const expectedBooks = {
  'start-here': [
    '01-what-is-kairos.md',
    '02-logging-in.md',
    '03-account-activation-and-password-reset.md',
    '04-dashboard-overview.md',
    '05-settings-and-notifications.md',
    '06-common-issues.md',
  ],
  'student-guide': [
    '01-viewing-courses.md',
    '02-using-modules-and-resources.md',
    '03-submitting-assignments.md',
    '04-adding-assignment-notes.md',
    '05-taking-quizzes.md',
    '06-viewing-grades-and-feedback.md',
  ],
  'ta-guide': [
    '01-ta-dashboard-overview.md',
    '02-viewing-assigned-courses.md',
    '03-reviewing-submissions.md',
    '04-using-rubrics.md',
    '05-adding-private-grading-notes.md',
    '06-releasing-or-viewing-feedback.md',
  ],
  'manager-guide': [
    '01-manager-dashboard-overview.md',
    '02-creating-and-editing-courses.md',
    '03-managing-modules-and-resources.md',
    '04-creating-assignments.md',
    '05-creating-quizzes.md',
    '06-assigning-rubrics.md',
    '07-viewing-analytics.md',
  ],
  'admin-guide': [
    '01-admin-dashboard-overview.md',
    '02-creating-users.md',
    '03-inviting-local-password-users.md',
    '04-resending-activation-links.md',
    '05-managing-roles.md',
    '06-basic-troubleshooting.md',
  ],
};

const requiredHeadings = [
  '## What this is for',
  '## How to do it',
  '## Screenshot',
  '## Notes',
];
const forbiddenTerms = [
  /\bdatabase tables?\b/i,
  /\bmigrations?\b/i,
  /\bimplementation classes?\b/i,
  /\bOAuth tokens?\b/i,
  /\bSQL\b/i,
];

let failures = 0;
function fail(message) {
  failures += 1;
  console.error(`FAIL: ${message}`);
}

for (const [book, pages] of Object.entries(expectedBooks)) {
  for (const page of pages) {
    const file = path.join(docsRoot, book, page);
    if (!fs.existsSync(file)) {
      fail(`Missing required page: ${path.relative(root, file)}`);
      continue;
    }

    const markdown = fs.readFileSync(file, 'utf8');
    if (!markdown.startsWith('# ')) fail(`${page} must start with a page title`);
    for (const heading of requiredHeadings) {
      if (!markdown.includes(heading)) fail(`${page} is missing ${heading}`);
    }
    if (!/^\d+\.\s/m.test(markdown)) fail(`${page} needs numbered task steps`);

    for (const term of forbiddenTerms) {
      if (term.test(markdown)) fail(`${page} contains forbidden internal terminology: ${term}`);
    }

    const imageMatches = [...markdown.matchAll(/!\[[^\]]+\]\(([^)]+)\)/g)];
    if (imageMatches.length === 0) {
      fail(`${page} needs a screenshot`);
      continue;
    }
    for (const match of imageMatches) {
      const imagePath = path.resolve(path.dirname(file), match[1]);
      if (!fs.existsSync(imagePath)) {
        fail(`${page} references missing screenshot: ${match[1]}`);
      }
    }
  }
}

if (failures > 0) {
  console.error(`BookStack documentation contract failed with ${failures} issue(s).`);
  process.exit(1);
}

const pageCount = Object.values(expectedBooks).flat().length;
console.log(`BookStack documentation contract passed for ${pageCount} pages.`);
