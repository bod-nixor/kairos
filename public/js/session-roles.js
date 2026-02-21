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
