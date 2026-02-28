(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const LMS = window.KairosLMS;
    const params = new URLSearchParams(location.search);
    const COURSE_ID = params.get('course_id') || '';
    const COURSE_ID_INT = parseInt(COURSE_ID, 10);
    const DEBUG_MODULES = params.get('debug') === '1';

    let isAdmin = false;
    const expandedModules = new Set();
    let modulesData = []; // cached for reorder operations

    const TYPE_ICONS = { lesson: '📝', assignment: '📤', quiz: '🧪', file: '📄', video: '🎬', link: '🔗', resource: '📎', page: '📘', text: '📝' };
    const TYPE_ICON_CLASS = { lesson: 'lesson', assignment: 'assign', quiz: 'quiz', file: 'file', video: 'video', link: 'link', resource: 'resource', page: 'lesson', text: 'lesson' };

    function showEl(id) { const el = $(id); if (el) el.classList.remove('hidden'); }
    function hideEl(id) { const el = $(id); if (el) el.classList.add('hidden'); }


    function normalizeExternalUrl(raw) {
        const value = String(raw || '').trim();
        if (!value) return '';
        const withProtocol = /^https?:\/\//i.test(value) ? value : `https://${value}`;
        try {
            const parsed = new URL(withProtocol);
            if (!/^https?:$/i.test(parsed.protocol)) return '';
            return parsed.toString();
        } catch (_) {
            return '';
        }
    }


    function itemHref(item, mode = 'view') {
        const type = String(item.item_type || item.type || '').toLowerCase();
        const entityId = parseInt(item.entity_id || item.id || 0, 10);
        const external = normalizeExternalUrl(item.url || item.resource_url || item.external_url || '');

        const resourceViewerHref = () => {
            if (entityId > 0) {
                return `./resource-viewer.html?course_id=${encodeURIComponent(COURSE_ID)}&resource_id=${entityId}&mode=${mode === 'edit' ? 'edit' : 'view'}`;
            }
            if (external) return external;
            return `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}&debug=1`;
        };

        if (type === 'assignment') return `./assignment.html?course_id=${encodeURIComponent(COURSE_ID)}&assignment_id=${entityId}&mode=${mode === 'edit' ? 'edit' : 'view'}`;
        if (type === 'quiz') return `./quiz.html?course_id=${encodeURIComponent(COURSE_ID)}&quiz_id=${entityId}&mode=${mode === 'edit' ? 'edit' : 'view'}`;
        if (type === 'lesson') return `./lesson.html?course_id=${encodeURIComponent(COURSE_ID)}&lesson_id=${entityId}&mode=${mode === 'edit' ? 'edit' : 'view'}`;
        if (type === 'link') {
            if (mode === 'edit') {
                return resourceViewerHref();
            }
            if (external) return external;
        }
        if (type === 'file' || type === 'video' || type === 'resource' || type === 'link') return resourceViewerHref();
        if (entityId > 0) return resourceViewerHref();
        if (external) return external;
        return `./modules.html?course_id=${encodeURIComponent(COURSE_ID)}&debug=1`;
    }

    function isExternalHttpUrl(url) {
        try {
            const parsed = new URL(String(url || ''), window.location.origin);
            return /^https?:$/i.test(parsed.protocol) && parsed.origin !== window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function navigateToHref(href) {
        const url = String(href || '').trim();
        if (!url) return false;
        if (isExternalHttpUrl(url)) {
            LMS.confirm('Open external link', `${url} is being opened in a new tab. Proceed?`, () => {
                window.open(url, '_blank', 'noopener,noreferrer');
            }, { okLabel: 'Yes' });
            return true;
        }
        window.location.assign(url);
        return true;
    }

    /* =========================================================
       ADMIN: Edit / Delete Module Item Settings Modal
       ========================================================= */
    function openEditItemModal(item) {
        const mid = parseInt(item.module_item_id || 0, 10);
        const currentTitle = item.title || item.name || '';
        const currentPublished = parseInt(item.published_flag ?? 1, 10);
        const currentRequired = parseInt(item.required_flag ?? 0, 10);

        const body = `
          <div class="k-form-field">
            <label for="kEditItemTitle">Title</label>
            <input id="kEditItemTitle" type="text" value="${LMS.escHtml(currentTitle)}" required>
          </div>
          <div class="k-form-field" style="display:flex;gap:16px;margin-top:12px">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
              <input id="kEditItemPublished" type="checkbox" ${currentPublished ? 'checked' : ''}> Published
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
              <input id="kEditItemRequired" type="checkbox" ${currentRequired ? 'checked' : ''}> Required
            </label>
          </div>`;

        LMS.openModal({
            title: 'Edit Module Item',
            body,
            narrow: true,
            actions: [
                { id: 'cancel', label: 'Cancel', class: 'btn-ghost', onClick: () => LMS.closeModal() },
                {
                    id: 'save', label: 'Save', class: 'btn-primary', onClick: async (btn) => {
                        btn.disabled = true;
                        const title = document.getElementById('kEditItemTitle')?.value?.trim() || '';
                        const published = document.getElementById('kEditItemPublished')?.checked ? 1 : 0;
                        const required = document.getElementById('kEditItemRequired')?.checked ? 1 : 0;
                        if (!title) { LMS.toast('Title cannot be empty', 'error'); btn.disabled = false; return; }
                        const res = await LMS.api('POST', './api/lms/module_items/update.php', {
                            module_item_id: mid,
                            course_id: COURSE_ID_INT,
                            title,
                            published,
                            required,
                        });
                        if (res.ok) {
                            LMS.toast('Item updated', 'success');
                            LMS.closeModal();
                            await loadPage();
                        } else {
                            LMS.toast(res.data?.error?.message || 'Update failed', 'error');
                            btn.disabled = false;
                        }
                    }
                },
            ],
        });
    }

    function confirmDeleteItem(item) {
        const mid = parseInt(item.module_item_id || 0, 10);
        const title = item.title || item.name || 'this item';
        LMS.confirm('Delete Module Item', `Are you sure you want to remove "${title}" from this module? The underlying content will not be deleted.`, async () => {
            const res = await LMS.api('POST', './api/lms/module_items/delete.php', {
                module_item_id: mid,
                course_id: COURSE_ID_INT,
            });
            if (res.ok) {
                LMS.toast('Item removed', 'success');
                await loadPage();
            } else {
                LMS.toast(res.data?.error?.message || 'Delete failed', 'error');
            }
        }, { okLabel: 'Delete', okClass: 'btn-danger' });
    }

    /* =========================================================
       ADMIN: Edit Module (Section) Name Modal
       ========================================================= */
    function openEditModuleModal(mod) {
        const sectionId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
        const currentTitle = mod.name || mod.title || '';
        const currentDesc = mod.description || '';

        const body = `
          <div class="k-form-field">
            <label for="kEditModTitle">Module Title</label>
            <input id="kEditModTitle" type="text" value="${LMS.escHtml(currentTitle)}" required>
          </div>
          <div class="k-form-field" style="margin-top:12px">
            <label for="kEditModDesc">Description</label>
            <textarea id="kEditModDesc" rows="3">${LMS.escHtml(currentDesc)}</textarea>
          </div>`;

        LMS.openModal({
            title: 'Edit Module',
            body,
            narrow: true,
            actions: [
                { id: 'cancel', label: 'Cancel', class: 'btn-ghost', onClick: () => LMS.closeModal() },
                {
                    id: 'save', label: 'Save', class: 'btn-primary', onClick: async (btn) => {
                        btn.disabled = true;
                        const title = document.getElementById('kEditModTitle')?.value?.trim() || '';
                        const description = document.getElementById('kEditModDesc')?.value?.trim() || '';
                        if (!title) { LMS.toast('Title cannot be empty', 'error'); btn.disabled = false; return; }
                        const res = await LMS.api('POST', './api/lms/sections/update.php', {
                            section_id: sectionId,
                            course_id: COURSE_ID_INT,
                            title,
                            description,
                        });
                        if (res.ok) {
                            LMS.toast('Module updated', 'success');
                            LMS.closeModal();
                            await loadPage();
                        } else {
                            LMS.toast(res.data?.error?.message || 'Update failed', 'error');
                            btn.disabled = false;
                        }
                    }
                },
            ],
        });
    }

    function confirmDeleteModule(mod) {
        const sectionId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
        const title = mod.name || mod.title || 'this module';
        LMS.confirm('Delete Module', `Are you sure you want to delete "${title}"? All items inside will be unlinked.`, async () => {
            const res = await LMS.api('POST', './api/lms/sections/delete.php', {
                section_id: sectionId,
            });
            if (res.ok) {
                LMS.toast('Module deleted', 'success');
                expandedModules.delete(sectionId);
                await loadPage();
            } else {
                LMS.toast(res.data?.error?.message || 'Delete failed', 'error');
            }
        }, { okLabel: 'Delete', okClass: 'btn-danger' });
    }

    /* =========================================================
       Render: Module Item Row
       ========================================================= */
    function renderModuleItem(item) {
        const typeKey = String(item.type || item.item_type || '').toLowerCase();
        const iconClass = `k-module-item__icon--${LMS.escHtml(TYPE_ICON_CLASS[typeKey] || 'default')}`;
        const icon = TYPE_ICONS[typeKey] || '📌';
        const locked = item.locked;
        const done = item.completed;
        const metaParts = [];
        if (item.due_date) metaParts.push(`Due ${LMS.fmtDate(item.due_date)}`);
        if (item.points) metaParts.push(`${item.points} pts`);
        if (item.duration_min) metaParts.push(`${item.duration_min} min`);

        const isDraft = item.status === 'draft' || item.published === false || item.published === 0;
        const isMandatory = item.mandatory === true || item.mandatory === 1 || item.is_mandatory === true || item.is_mandatory === 1;
        const statusBadges = [];
        if (isDraft) statusBadges.push('<span class="k-badge k-badge--draft" title="Draft">Draft</span>');
        if (isMandatory) statusBadges.push('<span class="k-badge k-badge--mandatory" title="Mandatory">Required</span>');

        const mid = parseInt(item.module_item_id || 0, 10);
        const adminBtns = isAdmin ? `
          <span class="k-module-item__admin-actions">
            <button class="k-btn-icon" title="Edit settings" data-action="edit-item-settings" data-mid="${mid}">
              ⚙️
            </button>
            <button class="k-btn-icon" title="Edit content" data-action="edit-item" data-href="${LMS.escHtml(itemHref(item, 'edit'))}">
              ✏️
            </button>
            <button class="k-btn-icon k-btn-icon--danger" title="Remove from module" data-action="delete-item" data-mid="${mid}">
              🗑️
            </button>
          </span>` : '';

        const dragHandle = isAdmin ? `<span class="k-drag-handle k-drag-handle--item" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>` : '';

        const titleText = LMS.escHtml(item.name || item.title || 'Untitled Module');
        const titleMarkup = `<span>${titleText}</span>`;

        return `
      <div
         data-module-item-id="${mid}"
         ${!locked ? `data-href="${LMS.escHtml(itemHref(item, 'view'))}" tabindex="0"` : ''}
         class="k-module-item${locked ? ' k-module-item--locked' : ''}${done ? ' k-module-item--completed' : ''}${isDraft ? ' k-module-item--draft' : ''}${!locked ? ' k-module-item--interactive' : ''}"
         aria-disabled="${locked ? 'true' : 'false'}"
         role="button"
         style="${!locked ? 'cursor:pointer;' : ''}">
        ${dragHandle}
        <div class="k-module-item__icon ${iconClass}" aria-hidden="true">${done ? '✅' : icon}</div>
        <div class="k-module-item__body">
          <div class="k-module-item__title">
            ${titleMarkup}
            ${statusBadges.join(' ')}
          </div>
          ${metaParts.length ? `<div class="k-module-item__meta">${LMS.escHtml(metaParts.join(' · '))}</div>` : ''}
        </div>
        <div class="k-module-item__right">
          ${adminBtns}
          ${done ? '<span class="k-status k-status--success" aria-label="Completed">✓</span>' : ''}
          ${locked ? '<span class="k-module-item__lock" aria-label="Locked">🔒</span>' : ''}
        </div>
      </div>`;
    }


    function logModuleDebug(modules) {
        if (!DEBUG_MODULES) return;
        const list = Array.isArray(modules) ? modules : [];
        const summary = list.reduce((acc, mod) => {
            const parsedModuleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
            const moduleId = Number.isFinite(parsedModuleId) ? parsedModuleId : 0;
            const count = Array.isArray(mod.items) ? mod.items.length : 0;
            acc.items_count += count;
            acc.module_item_counts[moduleId] = (acc.module_item_counts[moduleId] || 0) + count;
            return acc;
        }, { items_count: 0, module_item_counts: {} });

        console.debug('[modules] debug', {
            course_id: COURSE_ID_INT,
            modules_count: list.length,
            items_count: summary.items_count,
            module_item_counts: summary.module_item_counts,
        });
    }

    /* =========================================================
       Render: Module Section
       ========================================================= */
    function renderModuleHtml(mod, isExpanded) {
        const moduleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
        const bodyId = `mod-items-${moduleId}`;
        const hdrId = `mod-hdr-${moduleId}`;
        const items = Array.isArray(mod.items) ? mod.items : [];
        const itemsHtml = items.length
            ? items.map(renderModuleItem).join('')
            : '<div class="k-empty" style="padding:20px"><p class="k-empty__desc">No items in this module.</p></div>';

        const dragHandle = isAdmin ? `<span class="k-drag-handle k-drag-handle--module" draggable="true" title="Drag to reorder module" aria-label="Drag to reorder module">⋮⋮</span>` : '';

        const adminHeaderBtns = isAdmin ? `
          <button type="button" class="k-admin-btn k-admin-btn--sm" data-action="open-add-item" data-module-id="${moduleId}">+ Add Item</button>
          <button type="button" class="k-btn-icon" title="Edit module" data-action="edit-module" data-module-id="${moduleId}">✏️</button>
          <button type="button" class="k-btn-icon k-btn-icon--danger" title="Delete module" data-action="delete-module" data-module-id="${moduleId}">🗑️</button>
        ` : '';

        const headerHtml = `<div class="k-module__header" tabindex="0" role="button" aria-expanded="${isExpanded ? 'true' : 'false'}" aria-controls="${bodyId}" id="${hdrId}">${dragHandle}<span class="k-module__chevron" aria-hidden="true">▶</span><h2 class="k-module__title">${LMS.escHtml(mod.name || mod.title || 'Untitled Module')}</h2><div class="k-module__meta">${adminHeaderBtns}</div></div>`;
        const itemsWrapHtml = `<div class="k-module__items" id="${bodyId}" role="list" aria-labelledby="${hdrId}" ${isExpanded ? '' : 'style="display:none"'}>${itemsHtml}</div>`;

        return `<section class="k-module${isExpanded ? ' is-open' : ''}" data-module-id="${moduleId}">${headerHtml}${itemsWrapHtml}</section>`;
    }

    /* =========================================================
       DRAG & DROP: Modules
       ========================================================= */
    let draggedModuleEl = null;

    function setupModuleDragDrop(container) {
        if (!isAdmin) return;
        container.querySelectorAll('.k-drag-handle--module').forEach(handle => {
            handle.addEventListener('dragstart', (e) => {
                draggedModuleEl = handle.closest('.k-module');
                if (!draggedModuleEl) return;
                draggedModuleEl.classList.add('k-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', draggedModuleEl.dataset.moduleId);
                // Stop the event from reaching the header's click toggle
                e.stopPropagation();
            });
            handle.addEventListener('dragend', () => {
                if (draggedModuleEl) draggedModuleEl.classList.remove('k-dragging');
                container.querySelectorAll('.k-module').forEach(m => m.classList.remove('k-drag-over'));
                draggedModuleEl = null;
            });
        });

        container.querySelectorAll('.k-module').forEach(moduleEl => {
            moduleEl.addEventListener('dragover', (e) => {
                if (!draggedModuleEl || draggedModuleEl === moduleEl) return;
                // Only accept module drags (not item drags)
                if (!draggedModuleEl.classList.contains('k-module')) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                moduleEl.classList.add('k-drag-over');
            });
            moduleEl.addEventListener('dragleave', (e) => {
                // Only remove if we're actually leaving the module element
                if (!moduleEl.contains(e.relatedTarget)) {
                    moduleEl.classList.remove('k-drag-over');
                }
            });
            moduleEl.addEventListener('drop', async (e) => {
                e.preventDefault();
                moduleEl.classList.remove('k-drag-over');
                if (!draggedModuleEl || draggedModuleEl === moduleEl) return;
                // Reorder in DOM
                const rect = moduleEl.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                if (e.clientY < midY) {
                    container.insertBefore(draggedModuleEl, moduleEl);
                } else {
                    container.insertBefore(draggedModuleEl, moduleEl.nextSibling);
                }
                draggedModuleEl.classList.remove('k-dragging');
                // Persist the new order
                const newOrder = Array.from(container.querySelectorAll('.k-module[data-module-id]'))
                    .map(el => parseInt(el.dataset.moduleId, 10))
                    .filter(id => id > 0);
                const res = await LMS.api('POST', './api/lms/sections/reorder.php', {
                    course_id: COURSE_ID_INT,
                    section_ids: newOrder,
                });
                if (res.ok) {
                    LMS.toast('Modules reordered', 'success');
                } else {
                    LMS.toast(res.data?.error?.message || 'Reorder failed', 'error');
                    await loadPage(); // revert
                }
            });
        });
    }

    /* =========================================================
       DRAG & DROP: Module Items (within a section)
       ========================================================= */
    let draggedItemEl = null;

    function setupItemDragDrop(container) {
        if (!isAdmin) return;
        container.querySelectorAll('.k-drag-handle--item').forEach(handle => {
            handle.addEventListener('dragstart', (e) => {
                draggedItemEl = handle.closest('.k-module-item');
                if (!draggedItemEl) return;
                draggedItemEl.classList.add('k-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', draggedItemEl.dataset.moduleItemId || '');
                e.stopPropagation();
            });
            handle.addEventListener('dragend', () => {
                if (draggedItemEl) draggedItemEl.classList.remove('k-dragging');
                container.querySelectorAll('.k-module-item').forEach(m => m.classList.remove('k-drag-over'));
                draggedItemEl = null;
            });
        });

        container.querySelectorAll('.k-module__items').forEach(itemsContainer => {
            const sectionEl = itemsContainer.closest('.k-module[data-module-id]');
            const sectionId = parseInt(sectionEl?.dataset.moduleId || '0', 10);

            itemsContainer.addEventListener('dragover', (e) => {
                if (!draggedItemEl) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const afterEl = getDragAfterElement(itemsContainer, e.clientY);
                if (afterEl) {
                    afterEl.classList.add('k-drag-over');
                }
            });
            itemsContainer.addEventListener('dragleave', (e) => {
                if (!itemsContainer.contains(e.relatedTarget)) {
                    itemsContainer.querySelectorAll('.k-module-item').forEach(m => m.classList.remove('k-drag-over'));
                }
            });
            itemsContainer.addEventListener('drop', async (e) => {
                e.preventDefault();
                itemsContainer.querySelectorAll('.k-module-item').forEach(m => m.classList.remove('k-drag-over'));
                if (!draggedItemEl) return;

                const originalSectionEl = draggedItemEl.closest('.k-module[data-module-id]');
                const originalSectionId = parseInt(originalSectionEl?.dataset.moduleId || '0', 10);

                if (originalSectionId !== sectionId) {
                    draggedItemEl.classList.remove('k-dragging');
                    LMS.toast('Moving items between modules is not supported yet', 'warning');
                    return;
                }

                const afterEl = getDragAfterElement(itemsContainer, e.clientY);
                if (afterEl) {
                    itemsContainer.insertBefore(draggedItemEl, afterEl);
                } else {
                    itemsContainer.appendChild(draggedItemEl);
                }
                draggedItemEl.classList.remove('k-dragging');

                // Persist
                const newOrder = Array.from(itemsContainer.querySelectorAll('.k-module-item[data-module-item-id]'))
                    .map(el => parseInt(el.dataset.moduleItemId, 10))
                    .filter(id => id > 0);
                const res = await LMS.api('POST', './api/lms/module_items/reorder.php', {
                    course_id: COURSE_ID_INT,
                    section_id: sectionId,
                    module_item_ids: newOrder,
                });
                if (res.ok) {
                    LMS.toast('Items reordered', 'success');
                } else {
                    LMS.toast(res.data?.error?.message || 'Reorder failed', 'error');
                    await loadPage();
                }
            });
        });
    }

    function getDragAfterElement(container, y) {
        const items = [...container.querySelectorAll('.k-module-item:not(.k-dragging)')];
        let closest = null;
        let closestOffset = Number.NEGATIVE_INFINITY;
        items.forEach(child => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closest = child;
            }
        });
        return closest;
    }


    /* =========================================================
       Render: Full Module List
       ========================================================= */
    function renderModules(modules) {
        const container = $('moduleList');
        if (!container) return;

        container.innerHTML = modules.map((mod) => {
            const moduleId = parseInt(mod.section_id ?? mod.id ?? 0, 10);
            const isExpanded = expandedModules.has(moduleId);
            return renderModuleHtml(mod, isExpanded);
        }).join('');

        // Expand/collapse toggle
        container.querySelectorAll('.k-module__header').forEach(header => {
            const toggle = (e) => {
                // Don't toggle when clicking admin buttons or drag handles
                if (e.target.closest('[data-action]') || e.target.closest('.k-drag-handle') || e.target.closest('.k-btn-icon') || e.target.closest('.k-admin-btn')) return;
                const moduleEl = header.closest('[data-module-id]');
                const moduleId = parseInt(moduleEl?.dataset.moduleId || '0', 10);
                const expanded = header.getAttribute('aria-expanded') === 'true';
                header.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                const panel = container.querySelector(`#${header.getAttribute('aria-controls')}`);
                if (panel) panel.style.display = expanded ? 'none' : '';
                if (moduleEl) moduleEl.classList.toggle('is-open', !expanded);
                if (expanded) expandedModules.delete(moduleId); else expandedModules.add(moduleId);
            };
            header.addEventListener('click', toggle);
            header.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(e); }
            });
        });

        // Admin: Add Item, Edit Module, Delete Module
        if (isAdmin) {
            container.querySelectorAll('[data-action="open-add-item"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openCreateModal('module_item', btn.dataset.moduleId || '');
                });
            });

            container.querySelectorAll('[data-action="edit-module"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const moduleId = parseInt(btn.dataset.moduleId || '0', 10);
                    const mod = modulesData.find(m => parseInt(m.section_id ?? m.id ?? 0, 10) === moduleId);
                    if (mod) openEditModuleModal(mod);
                });
            });

            container.querySelectorAll('[data-action="delete-module"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const moduleId = parseInt(btn.dataset.moduleId || '0', 10);
                    const mod = modulesData.find(m => parseInt(m.section_id ?? m.id ?? 0, 10) === moduleId);
                    if (mod) confirmDeleteModule(mod);
                });
            });

            // Item-level: edit settings, delete
            container.querySelectorAll('[data-action="edit-item-settings"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const mid = parseInt(btn.dataset.mid || '0', 10);
                    const item = findItemById(mid);
                    if (item) openEditItemModal(item);
                });
            });

            container.querySelectorAll('[data-action="delete-item"]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const mid = parseInt(btn.dataset.mid || '0', 10);
                    const item = findItemById(mid);
                    if (item) confirmDeleteItem(item);
                });
            });
        }

        // Setup drag-and-drop after rendering
        setupModuleDragDrop(container);
        setupItemDragDrop(container);
    }

    function findItemById(moduleItemId) {
        for (const mod of modulesData) {
            const items = Array.isArray(mod.items) ? mod.items : [];
            for (const item of items) {
                if (parseInt(item.module_item_id || 0, 10) === moduleItemId) return item;
            }
        }
        return null;
    }

    /* =========================================================
       CREATE MODAL (existing logic, kept intact)
       ========================================================= */
    const MODAL_CONFIG = {
        module: {
            title: 'Create New Module',
            fields: '<div class="k-form-field"><label for="kf-title">Module Title *</label><input id="kf-title" name="title" required></div><div class="k-form-field"><label for="kf-desc">Description</label><textarea id="kf-desc" name="description"></textarea></div>',
            api: './api/lms/sections/create.php',
            payload: (fd) => ({ course_id: COURSE_ID_INT, title: fd.get('title'), description: fd.get('description') || null }),
            successMsg: 'Module created!'
        },
        module_item: {
            title: 'Add Module Item',
            fields: `<div class="k-form-field"><label for="kf-item-type">Item Type *</label><select id="kf-item-type" name="item_type"><option value="lesson">Lesson</option><option value="file">File / PDF</option><option value="video">Video Embed</option><option value="link">External Link</option><option value="assignment">Assignment</option><option value="quiz">Quiz</option></select></div><div class="k-form-field"><label for="kf-title">Title *</label><input id="kf-title" name="title" required></div><div id="kTypeSpecificFields"></div>`,
            api: './api/lms/module_items/create.php',
            payload: (fd, sectionId) => {
                const rawSection = typeof sectionId === 'string' ? sectionId.trim() : '';
                const parsedSectionId = rawSection !== '' ? parseInt(rawSection, 10) : null;
                return {
                    course_id: COURSE_ID_INT,
                    section_id: Number.isFinite(parsedSectionId) ? parsedSectionId : null,
                    item_type: fd.get('item_type'),
                    title: fd.get('title'),
                    html_content: fd.get('html_content') || null,
                    url: fd.get('url') ? normalizeExternalUrl(fd.get('url')) : null,
                    assignment_id: fd.get('assignment_id') || null,
                    quiz_id: fd.get('quiz_id') || null,
                };
            },
            successMsg: 'Module item added!'
        }
    };

    let activeModalType = null;
    let activeModalSectionId = null;

    function renderTypeSpecificFields() {
        const sel = $('kf-item-type');
        const wrap = $('kTypeSpecificFields');
        if (!sel || !wrap) return;
        const t = sel.value;
        if (t === 'lesson') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-html">Lesson Content *</label><textarea id="kf-html" name="html_content" rows="8" placeholder="Use lesson editor page for richer editing."></textarea></div>';
        } else if (t === 'file' || t === 'video' || t === 'link') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-url">URL *</label><input id="kf-url" name="url" required placeholder="https://..."></div>';
        } else if (t === 'assignment') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-assignment-id">Existing Assignment ID (optional)</label><input id="kf-assignment-id" name="assignment_id" type="number" min="1"></div>';
        } else if (t === 'quiz') {
            wrap.innerHTML = '<div class="k-form-field"><label for="kf-quiz-id">Existing Quiz ID (optional)</label><input id="kf-quiz-id" name="quiz_id" type="number" min="1"></div>';
        } else {
            wrap.innerHTML = '';
        }
    }

    function openCreateModal(type, sectionId) {
        const config = MODAL_CONFIG[type];
        if (!config) return;
        activeModalType = type;
        activeModalSectionId = sectionId || '';
        $('kCreateModalTitle').textContent = config.title;
        $('kCreateModalBody').innerHTML = config.fields;
        $('kCreateModalSubmit').textContent = type === 'module' ? 'Create Module' : 'Create Item';
        $('kCreateModal').showModal();
        if (type === 'module_item') {
            $('kf-item-type')?.addEventListener('change', renderTypeSpecificFields);
            renderTypeSpecificFields();
        }
    }

    function closeCreateModal() { $('kCreateModal').close(); activeModalType = null; activeModalSectionId = ''; }

    function setupCreateModal() {
        const modal = $('kCreateModal');
        const form = $('kCreateForm');
        if (!modal || !form) return;
        $('kCreateModalClose').addEventListener('click', closeCreateModal);
        $('kCreateModalCancel').addEventListener('click', closeCreateModal);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (form.dataset.busy === '1') return;
            const config = MODAL_CONFIG[activeModalType];
            if (!config) return;
            const submitBtn = $('kCreateModalSubmit');
            form.dataset.busy = '1';
            if (submitBtn) submitBtn.disabled = true;
            try {
                const fd = new FormData(form);
                const payload = config.payload(fd, activeModalSectionId);
                const res = await LMS.api('POST', config.api, payload);
                if (res.ok) {
                    LMS.toast(config.successMsg, 'success');
                    closeCreateModal();
                    await loadPage();
                } else {
                    LMS.toast(res.data?.error?.message || 'Failed request', 'error');
                }
            } finally {
                form.dataset.busy = '0';
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    /* =========================================================
       LOAD PAGE
       ========================================================= */
    async function loadPage() {
        const [courseRes, modulesRes] = await Promise.all([
            LMS.api('GET', `./api/lms/courses.php?course_id=${encodeURIComponent(COURSE_ID)}`),
            LMS.api('GET', `./api/lms/modules.php?course_id=${encodeURIComponent(COURSE_ID)}`),
        ]);

        hideEl('modulesSkeleton');

        if (!courseRes.ok || !modulesRes.ok) {
            console.error('Failed to load modules page', { courseRes, modulesRes });
            hideEl('moduleList');
            showEl('modulesEmpty');
            const desc = document.querySelector('#modulesEmpty .k-empty__desc');
            if (desc) desc.textContent = 'Failed to load modules. Please try again.';
            return;
        }

        const course = courseRes.data?.data || courseRes.data || {};
        LMS.nav.setCourseContext(COURSE_ID, course.name || course.code || 'Course');
        LMS.nav.setActive('modules');
        const modules = modulesRes.data?.data || modulesRes.data || [];

        if (!expandedModules.size && Array.isArray(modules) && modules.length > 0) {
            const firstId = parseInt(modules[0].section_id ?? modules[0].id ?? 0, 10);
            if (firstId > 0) expandedModules.add(firstId);
        }

        modules.sort((a, b) => ((a.position || 0) - (b.position || 0)) || (parseInt(a.section_id || 0, 10) - parseInt(b.section_id || 0, 10)));
        modulesData = modules; // cache for data lookups

        $('modulesSubtitle') && ($('modulesSubtitle').textContent = `${course.name || ''} · ${course.code || ''}`);
        const role = String(course.my_role || '').toLowerCase();
        if (role === 'ta' || role === 'manager' || role === 'admin') { $('kNavGrading') && $('kNavGrading').classList.remove('hidden'); }
        if (role === 'manager' || role === 'admin') { $('kNavAnalytics') && $('kNavAnalytics').classList.remove('hidden'); }
        $('kBreadCourse') && ($('kBreadCourse').textContent = course.name || 'Course');
        document.querySelectorAll('[data-course-href]').forEach(el => el.href = `${el.dataset.courseHref}?course_id=${encodeURIComponent(COURSE_ID)}`);

        // Progress
        let totalItems = 0, completedItems = 0;
        modules.forEach(m => {
            totalItems += (m.total_items || 0);
            completedItems += (m.completed_items || 0);
        });
        const pct = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;
        const fill = $('modulesProgressFill');
        const txt = $('modulesProgressText');
        if (fill) fill.style.width = pct + '%';
        if (txt) txt.textContent = `${pct}% complete`;

        if (!modules.length) {
            showEl('modulesEmpty');
            hideEl('moduleList');
            return;
        }

        hideEl('modulesEmpty');
        showEl('moduleList');
        logModuleDebug(modules);
        renderModules(modules);
    }

    /* =========================================================
       INIT
       ========================================================= */
    document.addEventListener('DOMContentLoaded', async () => {
        if (!Number.isInteger(COURSE_ID_INT) || COURSE_ID_INT <= 0) {
            hideEl('modulesSkeleton');
            hideEl('moduleList');
            showEl('modulesEmpty');
            const desc = document.querySelector('#modulesEmpty .k-empty__desc');
            if (desc) desc.textContent = 'Missing or invalid course id.';
            LMS.toast('Missing or invalid course id.', 'error');
            return;
        }

        const session = await LMS.boot();
        if (!session) return;
        LMS.nav.updateUserBar(session.me);
        const roles = session.caps?.roles || {};
        isAdmin = !!(roles.admin || roles.manager);
        if (isAdmin) {
            showEl('addModuleBtn');
            $('addModuleBtn')?.addEventListener('click', () => openCreateModal('module'));
        }
        setupCreateModal();

        // Setup singleton delegated handlers
        const container = $('moduleList');
        if (container) {
            container.addEventListener('click', (e) => {
                const editBtn = e.target.closest('[data-action="edit-item"]');
                if (editBtn) {
                    const href = editBtn.dataset.href || '';
                    if (navigateToHref(href)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    return;
                }
                // Don't navigate when clicking other admin buttons
                if (e.target.closest('[data-action]') || e.target.closest('.k-drag-handle')) return;

                const target = e.target.closest('[data-href], a');
                if (target && target.dataset.href) {
                    if (navigateToHref(target.dataset.href)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                } else if (target && target.tagName === 'A') {
                    const href = target.getAttribute('href') || '';
                    if (isExternalHttpUrl(href)) {
                        if (navigateToHref(href)) {
                            e.preventDefault();
                            e.stopPropagation();
                        }
                    } else {
                        e.stopPropagation();
                    }
                }
            });

            container.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    const editBtn = e.target.closest('[data-action="edit-item"]');
                    const target = e.target.closest('[data-href]');
                    const href = (editBtn && editBtn.dataset.href) || (target && target.dataset.href) || '';
                    if (href && navigateToHref(href)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                }
            });
        }

        await loadPage();
    });
})();
