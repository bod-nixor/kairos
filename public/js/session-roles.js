/**
 * session-roles.js — Shared session role normalizer
 * Transforms the LMS session_capabilities.php response format
 * into the { student, ta, manager, admin } boolean shape.
 *
 * Usage: normalizeSessionRoles(rawApiResponse)
 * Loaded BEFORE admin.js, manager.js, projector.js.
 */
function normalizeSessionRoles(raw) {
    // LMS format: { ok: true, data: { user: { role: 'admin' } } }
    if (raw && raw.ok === true && raw.data && raw.data.user) {
        var role = String(raw.data.user.role || 'student').toLowerCase();
        return {
            student: true,
            ta: role === 'ta' || role === 'manager' || role === 'admin',
            manager: role === 'manager' || role === 'admin',
            admin: role === 'admin',
        };
    }
    // Old format: { roles: { admin: true, ... } }
    if (raw && raw.roles) return raw.roles;
    // Fallback: explicit boolean defaults
    return { student: false, ta: false, manager: false, admin: false };
}
// Expose globally for ES module scripts (admin.js, projector.js)
window.normalizeSessionRoles = normalizeSessionRoles;

function updateSidebarRoleLinks(roles) {
    const isLogged = !!roles && (roles.student || roles.ta || roles.manager || roles.admin);
    const kNavRoles = document.getElementById('kNavRoles');
    if (kNavRoles) {
        const hasAnyRole = isLogged && (roles.ta || roles.manager || roles.admin);
        kNavRoles.classList.toggle('hidden', !hasAnyRole);
    }
    const sidebarRoleMap = [
        ['navTA', 'ta'],
        ['navManager', 'manager'],
        ['navAdmin', 'admin'],
    ];
    // Determine the current page key
    const pathname = window.location.pathname.toLowerCase();
    let currentPage = null;
    if (pathname.includes('/ta.html') || pathname.endsWith('/ta')) currentPage = 'ta';
    else if (pathname.includes('/manager.html') || pathname.endsWith('/manager')) currentPage = 'manager';
    else if (pathname.includes('/admin.html') || pathname.endsWith('/admin')) currentPage = 'admin';

    sidebarRoleMap.forEach(([id, role]) => {
        const el = document.getElementById(id);
        if (!el) return;
        const allowed = isLogged && !!roles[role];
        el.classList.toggle('hidden', !allowed);

        // Match standard links or button wrappers
        if (allowed) {
            if (role === currentPage) {
                el.classList.add('is-active');
                el.setAttribute('aria-current', 'page');
            } else {
                el.classList.remove('is-active');
                el.removeAttribute('aria-current');
            }
        }
    });

    // Update sidebar role text
    const sidebarRole = document.getElementById('kSidebarRole') || document.getElementById('email') || document.getElementById('taEmail');
    if (sidebarRole && isLogged) {
        const roleDisplay = roles.admin ? 'Admin' :
            roles.manager ? 'Manager' :
                roles.ta ? 'TA' : 'Student';
        sidebarRole.textContent = roleDisplay;
    }
}
window.updateSidebarRoleLinks = updateSidebarRoleLinks;
