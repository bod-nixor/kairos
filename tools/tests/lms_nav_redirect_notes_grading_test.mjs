import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

// Helper to run code in context
function executeInContext(files, contextPatches = {}) {
    const context = {
        URL,
        URLSearchParams,
        Promise,
        Math,
        Date,
        console,
        window: {},
        document: {
            getElementById() { return null; },
            querySelectorAll() { return []; },
            addEventListener() {},
            dispatchEvent() {},
        },
        location: { pathname: '/signoff/assignment.html', search: '?course_id=2&assignment_id=8', hash: '' },
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

// Test 1: login redirect and returnUrl validation
{
    const context = executeInContext(['public/js/lms-core.js']);
    const nav = context.window.KairosLMS.nav;
    
    // Valid relative path starting with /signoff/
    assert.equal(
        nav.validateReturnUrl('/signoff/assignment.html?course_id=2&assignment_id=8'),
        '/signoff/assignment.html?course_id=2&assignment_id=8'
    );
    
    // Invalid: absolute URL to external site
    assert.equal(nav.validateReturnUrl('https://evil.com/signoff/'), null);
    
    // Invalid: protocol relative
    assert.equal(nav.validateReturnUrl('//evil.com/signoff/'), null);
    
    // Invalid: backslash bypass
    assert.equal(nav.validateReturnUrl('/signoff/\\\\evil.com'), null);
    assert.equal(nav.validateReturnUrl('\\\\evil.com'), null);
    assert.equal(nav.validateReturnUrl('/signoff/%5cevil.com'), null);
    assert.equal(nav.validateReturnUrl('/signoff/%5Cevil.com'), null);
    
    // Invalid: doesn't start with /signoff/
    assert.equal(nav.validateReturnUrl('/other/path'), null);
}

// Test 2: Nav/capability and sidebar role links
{
    // Setup mock elements
    const elements = {
        kNavRoles: { classList: { toggle: (cls, show) => { elements.kNavRoles.hidden = show; } }, hidden: true },
        navTA: { classList: { toggle: (cls, show) => { elements.navTA.hidden = show; }, add: (cls) => { elements.navTA.active = true; }, remove: (cls) => { elements.navTA.active = false; } }, setAttribute: (k, v) => {}, removeAttribute: (k) => {}, hidden: true, active: false },
        navManager: { classList: { toggle: (cls, show) => { elements.navManager.hidden = show; }, add: (cls) => { elements.navManager.active = true; }, remove: (cls) => { elements.navManager.active = false; } }, setAttribute: (k, v) => {}, removeAttribute: (k) => {}, hidden: true, active: false },
        navAdmin: { classList: { toggle: (cls, show) => { elements.navAdmin.hidden = show; }, add: (cls) => { elements.navAdmin.active = true; }, remove: (cls) => { elements.navAdmin.active = false; } }, setAttribute: (k, v) => {}, removeAttribute: (k) => {}, hidden: true, active: false },
        kSidebarRole: { textContent: '' }
    };
    
    const context = executeInContext(['public/js/session-roles.js'], {
        document: {
            getElementById(id) {
                return elements[id] || null;
            },
            querySelectorAll() { return []; }
        },
        location: { pathname: '/signoff/admin.html', search: '', hash: '' }
    });
    
    const normalize = context.window.normalizeSessionRoles;
    const update = context.window.updateSidebarRoleLinks;
    
    // Check normalizeSessionRoles for Admin role
    const adminRoles = normalize({ ok: true, data: { user: { role: 'admin' } } });
    assert.equal(adminRoles.student, true);
    assert.equal(adminRoles.ta, true);
    assert.equal(adminRoles.manager, true);
    assert.equal(adminRoles.admin, true);
    
    // Check normalizeSessionRoles for TA role
    const taRoles = normalize({ ok: true, data: { user: { role: 'ta' } } });
    assert.equal(taRoles.student, true);
    assert.equal(taRoles.ta, true);
    assert.equal(taRoles.manager, false);
    assert.equal(taRoles.admin, false);
    
    // Check updating sidebar role links for Admin on admin.html page
    update(adminRoles);
    
    // Admin should see all links: TA, Manager, Admin
    // Since location pathname is /signoff/admin.html, navAdmin should be active
    assert.equal(elements.kNavRoles.hidden, false, 'kNavRoles should not be hidden for Admin');
    assert.equal(elements.navTA.hidden, false, 'navTA should be visible for Admin');
    assert.equal(elements.navManager.hidden, false, 'navManager should be visible for Admin');
    assert.equal(elements.navAdmin.hidden, false, 'navAdmin should be visible for Admin');
    assert.equal(elements.navAdmin.active, true, 'navAdmin should be active on admin.html page');
    assert.equal(elements.navManager.active, false, 'navManager should not be active on admin.html page');
    assert.equal(elements.kSidebarRole.textContent, 'Admin', 'Sidebar role text should display Admin');
    
    // Check updating sidebar role links for TA on admin.html page (should hide Admin/Manager links)
    update(taRoles);
    assert.equal(elements.navTA.hidden, false, 'navTA should be visible for TA');
    assert.equal(elements.navManager.hidden, true, 'navManager should be hidden for TA');
    assert.equal(elements.navAdmin.hidden, true, 'navAdmin should be hidden for TA');
}

console.log('LMS nav and redirect unit tests passed');
