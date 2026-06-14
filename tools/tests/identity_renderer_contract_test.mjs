import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function executeInContext(files, contextPatches = {}) {
    const context = {
        URL,
        URLSearchParams,
        Promise,
        Math,
        Date,
        console,
        window: {},
        addEventListener() {},
        removeEventListener() {},
        document: {
            getElementById() { return null; },
            querySelectorAll() { return []; },
            addEventListener() {},
            dispatchEvent() {},
        },
        location: { pathname: '/signoff/assignment.html', search: '?course_id=2&assignment_id=8', hash: '' },
        localStorage: {
            getItem() { return null; },
            setItem() {},
            removeItem() {}
        },
        sessionStorage: {
            getItem() { return null; },
            setItem() {},
            removeItem() {}
        },
        ...contextPatches,
    };
    context.window = context;
    vm.createContext(context);
    
    for (const file of files) {
        const source = fs.readFileSync(path.join(root, file), 'utf8');
        vm.runInContext(source, context, { filename: file });
    }
    return context;
}

// Helper to create mock elements
function createMockElements() {
    return {
        kSidebarAvatar: { src: '', alt: '', onerror: null },
        kSidebarName: { textContent: '' },
        kSidebarRole: { textContent: '' },
        kLogoutBtn: { listeners: [], addEventListener(ev, cb) { this.listeners.push(cb); } },
        kSidebarUser: { classList: { classes: new Set(), add(c) { this.classes.add(c); }, remove(c) { this.classes.delete(c); }, contains(c) { return this.classes.has(c); } } }
    };
}

// Test 1: KairosIdentity is exposed and loads correctly
{
    const mockElements = createMockElements();
    const context = executeInContext(['public/js/theme.js'], {
        document: {
            getElementById(id) {
                return mockElements[id] || null;
            },
            querySelectorAll() { return []; },
            addEventListener() {},
            querySelector(sel) {
                if (sel === '.k-sidebar__user') return mockElements.kSidebarUser;
                return null;
            }
        },
        matchMedia() { return { matches: false, addEventListener() {} }; }
    });

    const identity = context.window.KairosIdentity;
    assert.ok(identity, 'KairosIdentity must be exposed globally');
    assert.equal(identity.loading, true, 'Initially, loading must be true');
}

// Test 2: Session success renders profile name, avatar, and role
{
    const mockElements = createMockElements();
    const context = executeInContext(['public/js/theme.js'], {
        document: {
            getElementById(id) {
                return mockElements[id] || null;
            },
            querySelectorAll() { return []; },
            addEventListener() {},
            querySelector(sel) {
                if (sel === '.k-sidebar__user') return mockElements.kSidebarUser;
                return null;
            }
        },
        matchMedia() { return { matches: false, addEventListener() {} }; }
    });

    const identity = context.window.KairosIdentity;
    identity.me = {
        name: 'Jane Doe',
        email: 'janedoe@nixorcollege.edu.pk',
        picture_url: 'https://lh3.googleusercontent.com/a/avatar123'
    };
    identity.caps = {
        student: true,
        ta: true,
        manager: false,
        admin: false
    };
    identity.loading = false;
    identity.render();

    assert.equal(mockElements.kSidebarName.textContent, 'Jane Doe');
    assert.equal(mockElements.kSidebarAvatar.src, 'https://lh3.googleusercontent.com/a/avatar123');
    assert.equal(mockElements.kSidebarRole.textContent, 'TA');
    assert.equal(mockElements.kSidebarUser.classList.contains('is-loading'), false);
}

// Test 3: Avatar failure shows initials fallback and missing name falls back safely
{
    const mockElements = createMockElements();
    const context = executeInContext(['public/js/theme.js'], {
        document: {
            getElementById(id) {
                return mockElements[id] || null;
            },
            querySelectorAll() { return []; },
            addEventListener() {},
            querySelector(sel) {
                if (sel === '.k-sidebar__user') return mockElements.kSidebarUser;
                return null;
            }
        },
        matchMedia() { return { matches: false, addEventListener() {} }; }
    });

    const identity = context.window.KairosIdentity;
    // Missing name -> fallback to email prefix
    identity.me = {
        email: 'testuser@nixorcollege.edu.pk',
        picture_url: '' // empty avatar
    };
    identity.caps = {
        student: true,
        ta: false,
        manager: false,
        admin: false
    };
    identity.loading = false;
    identity.render();

    // Verification of name fallback (uses email prefix if name is missing)
    assert.equal(mockElements.kSidebarName.textContent, 'testuser@nixorcollege.edu.pk');
    
    // Initials calculation verification
    const initials = identity.getInitials(identity.me.name, identity.me.email);
    assert.equal(initials, 'TE', 'Initials for testuser@nixorcollege.edu.pk should be TE');

    // Verification that fallback SVG is generated and sets correctly
    assert.ok(mockElements.kSidebarAvatar.src.startsWith('data:image/svg+xml,'));
    assert.ok(mockElements.kSidebarAvatar.src.includes('TE'));

    // Test onerror handler triggers fallback
    mockElements.kSidebarAvatar.src = 'https://broken-link.com/avatar.jpg';
    if (mockElements.kSidebarAvatar.onerror) {
        mockElements.kSidebarAvatar.onerror();
    }
    assert.ok(mockElements.kSidebarAvatar.src.startsWith('data:image/svg+xml,'));
    assert.ok(mockElements.kSidebarAvatar.src.includes('TE'));
}

// Test 4: Return URL redirects and open-redirect prevention
{
    const mockElements = createMockElements();
    const context = executeInContext(['public/js/theme.js'], {
        document: {
            getElementById(id) {
                return mockElements[id] || null;
            },
            querySelectorAll() { return []; },
            addEventListener() {},
            querySelector(sel) {
                if (sel === '.k-sidebar__user') return mockElements.kSidebarUser;
                return null;
            }
        },
        matchMedia() { return { matches: false, addEventListener() {} }; }
    });

    const identity = context.window.KairosIdentity;
    
    // Valid relative path
    assert.equal(
        identity.validateReturnUrl('/signoff/assignment.html?course_id=2'),
        '/signoff/assignment.html?course_id=2'
    );

    // Invalid redirects
    assert.equal(identity.validateReturnUrl('https://evil.com/signoff/'), null);
    assert.equal(identity.validateReturnUrl('//evil.com/signoff/'), null);
    assert.equal(identity.validateReturnUrl('/signoff/\\\\evil.com'), null);
    assert.equal(identity.validateReturnUrl('/signoff/%5cevil.com'), null);
    assert.equal(identity.validateReturnUrl('/other/path'), null);
}

console.log('KairosIdentity shared identity renderer contract tests passed successfully.');
