const fs = require('fs');
const path = require('path');

const dir = path.join(__dirname, 'public');
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html')).map(f => path.join(dir, f));

files.forEach(file => {
    let content = fs.readFileSync(file, 'utf8');

    // Remove avatar button everywhere
    content = content.replace(/<button class=\"k-avatar-btn\" id=\"kAvatarBtn\"[\s\S]*?<\/button>/g, '');

    // Fix theme toggle class
    content = content.replace(/<button class=\"k-topbar__icon-btn\" id=\"themeToggle\"/g, '<button class=\"k-topbar__icon-btn theme-toggle\" id=\"themeToggle\"');

    fs.writeFileSync(file, content);
    console.log(`Processed ${file}`);
});
