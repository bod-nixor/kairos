const fs = require('fs');
const files = fs.readdirSync('public').filter(f => f.endsWith('.html'));

for (const f of files) {
    const path = 'public/' + f;
    let html = fs.readFileSync(path, 'utf8');
    let changed = false;

    html = html.replace(/<img([^>]*)src="[^"]*?\/images\/logo(?:-full)?\.png"([^>]*)>/gi, (match, p1, p2) => {
        if (match.includes('k-brand-badge')) {
            return match;
        }
        changed = true;
        if (!match.includes('class=')) {
            return match.replace('<img ', '<img class="k-brand-badge" ');
        } else {
            return match.replace(/class="/, 'class="k-brand-badge ');
        }
    });

    if (changed) {
        fs.writeFileSync(path, html);
        console.log('Added badge to', path);
    }
}
