const fs = require('fs');

let styleCss = fs.readFileSync('public/css/style.css', 'utf8');

// First replace the \n literals from the previous mistake
styleCss = styleCss.replace(/\\n/g, '\n');

// Then add the k-brand-badge if not present
const badgeCSS = `
/* ── Global Brand Badge ───────────────────────────────────── */
.k-brand-badge {
    background: #ffffff;
    border-radius: 50%;
    padding: 6px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    box-sizing: border-box;
}
img.k-brand-badge {
    object-fit: contain;
}
`;

if (!styleCss.includes('.k-brand-badge')) {
    styleCss += badgeCSS;
}

fs.writeFileSync('public/css/style.css', styleCss);
console.log('Fixed style.css newline literals and added k-brand-badge.');
