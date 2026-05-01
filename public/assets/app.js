window.BrowseBox = {
    uploadInProgress: false,

    getUploadInputs() {
        return Array.from(document.querySelectorAll('input[type="file"][data-picker-kind]'));
    },

    getActiveUploadInput() {
        return window.BrowseBox.getUploadInputs().find((input) => input.files && input.files.length > 0) ?? null;
    },

    getSelectedUploadItems() {
        const activeInput = window.BrowseBox.getActiveUploadInput();
        const dropPaths = document.querySelector('[data-dropped-relative-paths]');

        if (!(activeInput instanceof HTMLInputElement) || !activeInput.files || activeInput.files.length === 0) {
            return [];
        }

        const droppedPaths = dropPaths instanceof HTMLInputElement && dropPaths.value !== ''
            ? JSON.parse(dropPaths.value)
            : [];

        return Array.from(activeInput.files).map((file, index) => {
            const droppedPath = Array.isArray(droppedPaths) ? droppedPaths[index] : null;
            const relativePath = typeof droppedPath === 'string' && droppedPath !== ''
                ? droppedPath
                : (typeof file.webkitRelativePath === 'string' && file.webkitRelativePath !== '' ? file.webkitRelativePath : file.name);

            return {
                name: file.name,
                relativePath,
                size: file.size,
            };
        });
    },

    clearUploadSelection() {
        const fileInput = document.getElementById('file_upload');
        const folderInput = document.getElementById('folder_upload');
        const fileStatus = document.querySelector('[data-picker-status="file_upload"]');
        const folderStatus = document.querySelector('[data-picker-status="folder_upload"]');

        window.BrowseBox.clearFileInput(fileInput);
        window.BrowseBox.clearFileInput(folderInput);
        window.BrowseBox.clearDroppedMetadata();

        if (fileInput instanceof HTMLInputElement) {
            fileInput.dataset.pickerKind = 'file';
        }

        if (folderInput instanceof HTMLInputElement) {
            folderInput.dataset.pickerKind = 'folder';
        }

        if (fileStatus instanceof HTMLElement) {
            fileStatus.textContent = 'No file chosen';
        }

        if (folderStatus instanceof HTMLElement) {
            folderStatus.textContent = 'No folder chosen';
        }

        window.BrowseBox.updateUploadState();
    },

    updateUploadState() {
        const inputs = window.BrowseBox.getUploadInputs();
        const submit = document.querySelector('[data-upload-submit]');
        const subtitle = document.querySelector('[data-upload-modal-subtitle]');

        if (!(submit instanceof HTMLButtonElement)) {
            return;
        }

        const dropPaths = document.querySelector('[data-dropped-relative-paths]');
        const dropzoneText = document.querySelector('[data-upload-dropzone-text]');
        const activeInput = inputs.find((input) => input.files && input.files.length > 0);

        if (!activeInput) {
            submit.disabled = true;
            submit.textContent = 'Upload Selected Files or Folder';

            if (subtitle instanceof HTMLElement) {
                subtitle.textContent = 'Review the selected files before starting the upload.';
            }

            if (dropzoneText instanceof HTMLElement) {
                dropzoneText.textContent = 'Desktop drag and drop works here for files and folders.';
            }

            return;
        }

        submit.disabled = false;

        const dropped = dropPaths instanceof HTMLInputElement && dropPaths.value !== '';
        const isFolder = activeInput.dataset.pickerKind === 'folder'
            || (dropped && activeInput.files.length > 0 && Array.from(activeInput.files).some((file) => {
                return typeof file.webkitRelativePath === 'string' && file.webkitRelativePath.includes('/');
            }));

        if (isFolder) {
            submit.textContent = 'Upload Selected Folder';

            if (subtitle instanceof HTMLElement) {
                subtitle.textContent = `Ready to upload folder contents (${activeInput.files.length} file${activeInput.files.length === 1 ? '' : 's'}).`;
            }

            if (dropzoneText instanceof HTMLElement && dropped) {
                dropzoneText.textContent = `Ready to upload folder contents (${activeInput.files.length} file${activeInput.files.length === 1 ? '' : 's'}). Click the upload button to continue.`;
            }

            return;
        }

        submit.textContent = activeInput.files.length === 1
            ? 'Upload Selected File'
            : 'Upload Selected Files';

        if (subtitle instanceof HTMLElement) {
            subtitle.textContent = activeInput.files.length === 1
                ? 'Ready to upload the selected file.'
                : `Ready to upload ${activeInput.files.length} selected files.`;
        }

        if (dropzoneText instanceof HTMLElement && dropped) {
            dropzoneText.textContent = activeInput.files.length === 1
                ? 'Ready to upload dropped file. Click the upload button to continue.'
                : `Ready to upload ${activeInput.files.length} dropped files. Click the upload button to continue.`;
        }
    },

    clearDroppedMetadata() {
        const dropPaths = document.querySelector('[data-dropped-relative-paths]');

        if (dropPaths instanceof HTMLInputElement) {
            dropPaths.value = '';
        }
    },

    clearFileInput(input) {
        if (input instanceof HTMLInputElement) {
            input.value = '';
        }
    },

    isExternalUploadDrag(event) {
        if (window.BrowseBox.moveDragItemPath) {
            return false;
        }

        const dataTransfer = event.dataTransfer;

        if (!dataTransfer) {
            return false;
        }

        const types = Array.from(dataTransfer.types ?? []);

        return types.includes('Files');
    },

    moveDragItemPath: null,
    moveActiveTarget: null,
    moveHoverTimer: null,

    clearMoveHighlights() {
        window.BrowseBox.moveActiveTarget = null;

        if (window.BrowseBox.moveHoverTimer) {
            window.clearTimeout(window.BrowseBox.moveHoverTimer);
            window.BrowseBox.moveHoverTimer = null;
        }

        document.querySelectorAll('[data-move-target].is-dragover').forEach((target) => {
            if (target instanceof HTMLElement) {
                target.classList.remove('is-dragover');
            }
        });
    },

    resetMoveState() {
        window.BrowseBox.moveDragItemPath = null;
        window.BrowseBox.clearMoveHighlights();
    },

    initMoveUI() {
        const moveItems = Array.from(document.querySelectorAll('[data-move-item]'));
        const openTreeItem = (item) => {
            if (!(item instanceof HTMLElement) || item.classList.contains('is-open')) {
                return;
            }

            item.classList.add('is-open');

            const toggle = item.querySelector('[data-tree-toggle]');
            const childrenId = toggle instanceof HTMLElement ? toggle.getAttribute('aria-controls') : null;

            if (toggle instanceof HTMLElement) {
                toggle.setAttribute('aria-expanded', 'true');
            }

            if (typeof childrenId === 'string' && childrenId !== '') {
                const children = document.getElementById(childrenId);

                if (children instanceof HTMLElement) {
                    children.hidden = false;
                }
            }
        };

        const resolveMoveTarget = (rawTarget) => {
            if (rawTarget instanceof Element) {
                return rawTarget.closest('[data-move-target]');
            }

            if (rawTarget instanceof Node && rawTarget.parentElement instanceof Element) {
                return rawTarget.parentElement.closest('[data-move-target]');
            }

            return null;
        };

        const activateMoveTarget = (target) => {
            if (!(target instanceof HTMLElement)) {
                window.BrowseBox.clearMoveHighlights();
                return;
            }

            if (window.BrowseBox.moveActiveTarget === target) {
                return;
            }

            window.BrowseBox.clearMoveHighlights();
            window.BrowseBox.moveActiveTarget = target;
            target.classList.add('is-dragover');

            if (target.dataset.moveExpand === '1') {
                const treeItem = target.closest('[data-tree-item]');

                if (treeItem instanceof HTMLElement && !treeItem.classList.contains('is-open')) {
                    window.BrowseBox.moveHoverTimer = window.setTimeout(() => {
                        openTreeItem(treeItem);
                        window.BrowseBox.moveHoverTimer = null;
                    }, 500);
                }
            }
        };

        moveItems.forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            item.addEventListener('dragstart', (event) => {
                const path = item.dataset.moveItem;

                if (!path || !event.dataTransfer) {
                    return;
                }

                window.BrowseBox.moveDragItemPath = path;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', path);
                item.classList.add('is-dragging');
            });

            item.addEventListener('dragend', () => {
                item.classList.remove('is-dragging');
                window.BrowseBox.resetMoveState();
            });
        });

        document.addEventListener('dragover', (event) => {
            if (!window.BrowseBox.moveDragItemPath) {
                return;
            }

            const target = resolveMoveTarget(event.target);

            if (!(target instanceof HTMLElement)) {
                window.BrowseBox.clearMoveHighlights();
                return;
            }

            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }

            activateMoveTarget(target);
        });

        document.addEventListener('drop', (event) => {
            if (!window.BrowseBox.moveDragItemPath) {
                return;
            }

            const target = resolveMoveTarget(event.target);

            if (!(target instanceof HTMLElement)) {
                window.BrowseBox.clearMoveHighlights();
                return;
            }

            event.preventDefault();

            const itemPath = window.BrowseBox.moveDragItemPath;
            const destinationPath = target.dataset.moveTarget ?? '';
            window.BrowseBox.resetMoveState();
            window.BrowseBox.submitMove(itemPath, destinationPath);
        });

        document.addEventListener('dragleave', (event) => {
            if (!window.BrowseBox.moveDragItemPath) {
                return;
            }

            const relatedTarget = event.relatedTarget;

            if (relatedTarget instanceof Node && document.body.contains(relatedTarget)) {
                return;
            }

            window.BrowseBox.clearMoveHighlights();
        });

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const toggle = target.closest('[data-tree-toggle]');

            if (!(toggle instanceof HTMLElement)) {
                return;
            }

            const treeItem = toggle.closest('[data-tree-item]');
            const childrenId = toggle.getAttribute('aria-controls');

            if (!(treeItem instanceof HTMLElement) || typeof childrenId !== 'string' || childrenId === '') {
                return;
            }

            event.preventDefault();

            const children = document.getElementById(childrenId);

            if (!(children instanceof HTMLElement)) {
                return;
            }

            const isOpen = treeItem.classList.contains('is-open');
            treeItem.classList.toggle('is-open', !isOpen);
            children.hidden = isOpen;
            toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    },

    submitTransfer(action, itemPath, destinationPath) {
        const form = document.querySelector('[data-transfer-form]');
        const actionInput = document.querySelector('[data-transfer-form-action]');
        const itemInput = document.querySelector('[data-transfer-form-item-path]');
        const destinationInput = document.querySelector('[data-transfer-form-destination-path]');

        if (
            !(form instanceof HTMLFormElement)
            || !(actionInput instanceof HTMLInputElement)
            || !(itemInput instanceof HTMLInputElement)
            || !(destinationInput instanceof HTMLInputElement)
        ) {
            return;
        }

        actionInput.value = action;
        itemInput.value = itemPath;
        destinationInput.value = destinationPath;
        form.submit();
    },

    submitMove(itemPath, destinationPath) {
        window.BrowseBox.submitTransfer('move', itemPath, destinationPath);
    },

    submitCopy(itemPath, destinationPath) {
        window.BrowseBox.submitTransfer('copy', itemPath, destinationPath);
    },

    submitDelete(itemPath) {
        const form = document.querySelector('[data-delete-form]');
        const itemInput = document.querySelector('[data-delete-form-item-path]');

        if (!(form instanceof HTMLFormElement) || !(itemInput instanceof HTMLInputElement)) {
            return;
        }

        itemInput.value = itemPath;
        form.submit();
    },

    initPublicViewToggle() {
        const toggles = Array.from(document.querySelectorAll('[data-public-view-toggle]'));

        if (toggles.length === 0) {
            return;
        }

        toggles.forEach((toggle) => {
            if (!(toggle instanceof HTMLButtonElement)) {
                return;
            }

            toggle.addEventListener('click', () => {
                const viewMode = toggle.dataset.publicViewToggle;

                if (viewMode !== 'list' && viewMode !== 'icons') {
                    return;
                }

                document.cookie = `browsebox_public_view=${encodeURIComponent(viewMode)}; Max-Age=31536000; Path=/; SameSite=Strict`;
                window.location.reload();
            });
        });
    },

    initSortableTables() {
        const tables = Array.from(document.querySelectorAll('[data-sortable-table]'));

        tables.forEach((table) => {
            if (!(table instanceof HTMLTableElement)) {
                return;
            }

            const tbody = table.tBodies[0];
            const headerButtons = Array.from(table.querySelectorAll('.browsebox-sort-button[data-sort-key]'));

            if (!(tbody instanceof HTMLTableSectionElement) || headerButtons.length === 0) {
                return;
            }

            const rows = Array.from(tbody.rows);

            rows.forEach((row, index) => {
                row.dataset.sortIndex = String(index);
            });

            const readSortValue = (row, key) => {
                const directValue = row.dataset[`sort${key.charAt(0).toUpperCase()}${key.slice(1)}`];

                if (typeof directValue === 'string' && directValue !== '') {
                    return directValue;
                }

                const cell = row.querySelector(`[data-sort-value][data-label="${key === 'name' ? 'Name' : key === 'size' ? 'Size' : 'Modified'}"]`);

                if (cell instanceof HTMLElement) {
                    return cell.dataset.sortValue ?? '';
                }

                return '';
            };

            const updateHeaderState = (activeKey, direction) => {
                headerButtons.forEach((button) => {
                    const header = button.closest('th');
                    const isActive = button.dataset.sortKey === activeKey;

                    button.classList.toggle('is-active', isActive);
                    button.dataset.sortDirection = isActive ? direction : '';

                    if (header instanceof HTMLTableCellElement) {
                        header.setAttribute('aria-sort', !isActive ? 'none' : (direction === 'asc' ? 'ascending' : 'descending'));
                    }
                });
            };

            const applySort = (key, direction) => {
                const sortedRows = Array.from(tbody.rows).sort((rowA, rowB) => {
                    const typeRankA = Number.parseInt(rowA.dataset.sortTypeRank ?? '0', 10);
                    const typeRankB = Number.parseInt(rowB.dataset.sortTypeRank ?? '0', 10);

                    if (typeRankA !== typeRankB) {
                        return typeRankA - typeRankB;
                    }

                    const rawValueA = readSortValue(rowA, key);
                    const rawValueB = readSortValue(rowB, key);
                    const numericKey = key === 'size' || key === 'modified';

                    let comparison = 0;

                    if (numericKey) {
                        comparison = Number.parseInt(rawValueA || '0', 10) - Number.parseInt(rawValueB || '0', 10);
                    } else {
                        comparison = rawValueA.localeCompare(rawValueB, undefined, { numeric: true, sensitivity: 'base' });
                    }

                    if (comparison === 0 && key !== 'name') {
                        comparison = readSortValue(rowA, 'name').localeCompare(readSortValue(rowB, 'name'), undefined, { numeric: true, sensitivity: 'base' });
                    }

                    if (comparison === 0) {
                        comparison = Number.parseInt(rowA.dataset.sortIndex ?? '0', 10) - Number.parseInt(rowB.dataset.sortIndex ?? '0', 10);
                    }

                    return direction === 'desc' ? comparison * -1 : comparison;
                });

                sortedRows.forEach((row) => {
                    tbody.appendChild(row);
                });

                table.dataset.activeSortKey = key;
                table.dataset.activeSortDirection = direction;
                updateHeaderState(key, direction);
            };

            headerButtons.forEach((button) => {
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                button.addEventListener('click', () => {
                    const key = button.dataset.sortKey;

                    if (key !== 'name' && key !== 'size' && key !== 'modified') {
                        return;
                    }

                    const currentKey = table.dataset.activeSortKey;
                    const currentDirection = table.dataset.activeSortDirection === 'desc' ? 'desc' : 'asc';
                    const nextDirection = currentKey === key && currentDirection === 'asc' ? 'desc' : 'asc';

                    applySort(key, nextDirection);
                });
            });

            const defaultKey = table.dataset.defaultSortKey;
            const defaultDirection = table.dataset.defaultSortDirection === 'desc' ? 'desc' : 'asc';

            if (defaultKey === 'name' || defaultKey === 'size' || defaultKey === 'modified') {
                applySort(defaultKey, defaultDirection);
            }
        });
    },

    initRenameUI() {
        const rows = Array.from(document.querySelectorAll('[data-rename-row]'));

        const closeRenameRow = (row, resetInput = true) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            const view = row.querySelector('[data-rename-view]');
            const form = row.querySelector('[data-rename-form]');
            const input = row.querySelector('[data-rename-input]');

            if (view instanceof HTMLElement) {
                view.classList.remove('d-none');
            }

            if (form instanceof HTMLElement) {
                form.classList.add('d-none');
            }

            if (input instanceof HTMLInputElement && resetInput) {
                input.value = input.dataset.originalName ?? input.value;
            }

            row.classList.remove('is-renaming');
            row.draggable = true;
        };

        const closeOtherRenameRows = (activeRow) => {
            rows.forEach((row) => {
                if (row !== activeRow) {
                    closeRenameRow(row);
                }
            });
        };

        const openRenameRow = (row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            closeOtherRenameRows(row);

            const view = row.querySelector('[data-rename-view]');
            const form = row.querySelector('[data-rename-form]');
            const input = row.querySelector('[data-rename-input]');

            if (view instanceof HTMLElement) {
                view.classList.add('d-none');
            }

            if (form instanceof HTMLElement) {
                form.classList.remove('d-none');
            }

            row.classList.add('is-renaming');
            row.draggable = false;

            if (input instanceof HTMLInputElement) {
                window.setTimeout(() => {
                    input.focus();
                    input.select();
                }, 0);
            }
        };

        window.BrowseBox.openRenameRow = (rowOrPath) => {
            if (rowOrPath instanceof HTMLElement) {
                openRenameRow(rowOrPath);
                return;
            }

            if (typeof rowOrPath !== 'string' || rowOrPath === '') {
                return;
            }

            const row = document.querySelector(`[data-rename-row][data-item-path="${CSS.escape(rowOrPath)}"]`);

            if (row instanceof HTMLElement) {
                openRenameRow(row);
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const renameToggle = target.closest('[data-rename-toggle]');

            if (renameToggle instanceof HTMLElement) {
                const row = renameToggle.closest('[data-rename-row]');

                if (row instanceof HTMLElement) {
                    event.preventDefault();
                    openRenameRow(row);
                }

                return;
            }

            const renameCancel = target.closest('[data-rename-cancel]');

            if (renameCancel instanceof HTMLElement) {
                const row = renameCancel.closest('[data-rename-row]');

                if (row instanceof HTMLElement) {
                    event.preventDefault();
                    closeRenameRow(row);
                }
            }
        });

        document.addEventListener('keydown', (event) => {
            const target = event.target;

            if (event.key !== 'Escape' || !(target instanceof HTMLInputElement) || !target.hasAttribute('data-rename-input')) {
                return;
            }

            const row = target.closest('[data-rename-row]');

            if (row instanceof HTMLElement) {
                event.preventDefault();
                closeRenameRow(row);
            }
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-rename-form')) {
                return;
            }

            const row = form.closest('[data-rename-row]');

            if (row instanceof HTMLElement) {
                row.draggable = false;
            }
        });
    },

    initConditionalSticky() {
        const panes = Array.from(document.querySelectorAll('[data-conditional-sticky]'));

        if (panes.length === 0) {
            return;
        }

        const update = () => {
            panes.forEach((pane) => {
                if (!(pane instanceof HTMLElement)) {
                    return;
                }

                if (window.innerWidth < 992) {
                    pane.classList.remove('is-sticky-enabled');
                    return;
                }

                const viewportAllowance = window.innerHeight - 32;
                const paneHeight = pane.offsetHeight;
                pane.classList.toggle('is-sticky-enabled', paneHeight <= viewportAllowance);
            });
        };

        update();
        window.addEventListener('resize', update, { passive: true });
    },

    initPendingConflictModal() {
        const modal = document.querySelector('[data-pending-modal]');

        if (!(modal instanceof HTMLDialogElement)) {
            return;
        }

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-pending-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
            }
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });

        if (modal.hasAttribute('data-pending-modal-autoshow')) {
            modal.showModal();
        }
    },

    initSettingsModal() {
        const modal = document.querySelector('[data-settings-modal]');
        const openButton = document.querySelector('[data-settings-modal-open]');

        if (!(modal instanceof HTMLDialogElement) || !(openButton instanceof HTMLElement)) {
            return;
        }

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        openButton.addEventListener('click', (event) => {
            event.preventDefault();
            if (!modal.open) {
                modal.showModal();
            }
        });

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-settings-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
            }
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });
    },

    initContextMenu() {
        const menu = document.querySelector('[data-context-menu]');

        if (!(menu instanceof HTMLElement)) {
            return;
        }

        let activeRow = null;
        let activeItem = null;

        const actions = {
            open: menu.querySelector('[data-context-action="open"]'),
            createFolder: menu.querySelector('[data-context-action="create_folder"]'),
            createSubfolder: menu.querySelector('[data-context-action="create_subfolder"]'),
            rename: menu.querySelector('[data-context-action="rename"]'),
            delete: menu.querySelector('[data-context-action="delete"]'),
            move: menu.querySelector('[data-context-action="move"]'),
            copy: menu.querySelector('[data-context-action="copy"]'),
            download: menu.querySelector('[data-context-action="download"]'),
            downloadZip: menu.querySelector('[data-context-action="download_zip"]'),
            openSeparator: menu.querySelector('[data-context-separator="open"]'),
        };

        const hideMenu = () => {
            if (activeRow instanceof HTMLElement) {
                activeRow.classList.remove('is-context-active');
            }

            activeRow = null;
            activeItem = null;
            menu.hidden = true;
            menu.classList.remove('is-visible');
        };

        const openLink = (href) => {
            if (typeof href !== 'string' || href === '') {
                return;
            }

            window.open(href, '_blank', 'noopener');
        };

        const navigateTo = (href) => {
            if (typeof href !== 'string' || href === '') {
                return;
            }

            window.location.href = href;
        };

        const itemFromRow = (row) => ({
            scope: row.dataset.contextScope ?? 'list',
            path: row.dataset.itemPath ?? '',
            parentPath: row.dataset.itemParentPath ?? '',
            name: row.dataset.itemName ?? '',
            type: row.dataset.itemType ?? 'file',
            openUrl: row.dataset.itemOpenUrl ?? '',
            downloadUrl: row.dataset.itemDownloadUrl ?? '',
            downloadZipUrl: row.dataset.itemDownloadZipUrl ?? '',
        });

        const positionMenu = (clientX, clientY) => {
            menu.hidden = false;
            menu.classList.add('is-visible');

            const menuRect = menu.getBoundingClientRect();
            const maxLeft = Math.max(8, window.innerWidth - menuRect.width - 8);
            const maxTop = Math.max(8, window.innerHeight - menuRect.height - 8);
            const left = Math.min(clientX, maxLeft);
            const top = Math.min(clientY, maxTop);

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        };

        const updateMenuState = () => {
            if (!activeItem) {
                return;
            }

            const isTreeScope = activeItem.scope === 'tree';
            const isCurrentScope = activeItem.scope === 'current';
            const isRoot = activeItem.path === '';
            const isDirectory = activeItem.type === 'dir';

            const setVisible = (node, visible) => {
                if (node instanceof HTMLElement) {
                    node.hidden = !visible;
                }
            };

            setVisible(actions.open, true);
            setVisible(actions.openSeparator, !isTreeScope);
            setVisible(actions.createFolder, isCurrentScope && isDirectory);
            setVisible(actions.createSubfolder, isTreeScope && isDirectory);
            setVisible(actions.rename, !isTreeScope);
            setVisible(actions.delete, !isTreeScope);
            setVisible(actions.download, !isTreeScope);

            if (actions.download instanceof HTMLButtonElement) {
                actions.download.disabled = activeItem.downloadUrl === '';
            }

            if (actions.downloadZip instanceof HTMLButtonElement) {
                actions.downloadZip.disabled = activeItem.downloadZipUrl === '' || !isDirectory || isRoot;
            }

            if (actions.move instanceof HTMLButtonElement) {
                actions.move.disabled = isRoot;
            }

            if (actions.copy instanceof HTMLButtonElement) {
                actions.copy.disabled = isRoot;
            }

            if (actions.open instanceof HTMLButtonElement) {
                actions.open.disabled = activeItem.openUrl === '';
            }
        };

        const openMenu = (row, clientX, clientY) => {
            hideMenu();
            activeRow = row;
            activeItem = itemFromRow(row);
            row.classList.add('is-context-active');
            updateMenuState();
            positionMenu(clientX, clientY);
        };

        document.addEventListener('contextmenu', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            if (target.closest('[data-rename-form]')) {
                return;
            }

            const row = target.closest('[data-context-row]');

            if (!(row instanceof HTMLElement)) {
                hideMenu();
                return;
            }

            event.preventDefault();
            openMenu(row, event.clientX, event.clientY);
        });

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const menuButton = target.closest('[data-context-menu-button]');

            if (menuButton instanceof HTMLElement) {
                const row = menuButton.closest('[data-context-row]');

                if (row instanceof HTMLElement) {
                    event.preventDefault();
                    const rect = menuButton.getBoundingClientRect();
                    openMenu(row, rect.right - 6, rect.bottom + 6);
                }

                return;
            }

            const actionButton = target.closest('[data-context-action]');

            if (!(actionButton instanceof HTMLButtonElement) || !activeItem) {
                if (!target.closest('[data-context-menu]')) {
                    hideMenu();
                }

                return;
            }

            event.preventDefault();
            const action = actionButton.dataset.contextAction ?? '';
            const item = activeItem;
            hideMenu();

            switch (action) {
                case 'open':
                    navigateTo(item.openUrl);
                    break;
                case 'create_folder':
                    window.BrowseBox.openCreateSubfolderModal(item, {
                        title: 'Create Folder',
                        subtitle: 'Create a new folder in the current directory.',
                    });
                    break;
                case 'create_subfolder':
                    window.BrowseBox.openCreateSubfolderModal(item, {
                        title: 'Create Sub Folder',
                        subtitle: 'Create a new folder inside the selected parent folder.',
                    });
                    break;
                case 'rename':
                    window.BrowseBox.openRenameRow(item.path);
                    break;
                case 'delete':
                    window.BrowseBox.openDeleteModal(item);
                    break;
                case 'move':
                    window.BrowseBox.openDestinationModal('move', item);
                    break;
                case 'copy':
                    window.BrowseBox.openDestinationModal('copy', item);
                    break;
                case 'download':
                    openLink(item.downloadUrl);
                    break;
                case 'download_zip':
                    openLink(item.downloadZipUrl);
                    break;
                default:
                    break;
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideMenu();
            }
        });

        window.addEventListener('resize', hideMenu, { passive: true });
        window.addEventListener('scroll', hideMenu, { passive: true });
    },

    initDestinationModal() {
        const modal = document.querySelector('[data-destination-modal]');
        const title = document.querySelector('[data-destination-modal-title]');
        const subtitle = document.querySelector('[data-destination-modal-subtitle]');
        const itemLabel = document.querySelector('[data-destination-modal-item]');
        const selection = document.querySelector('[data-destination-modal-selection]');
        const error = document.querySelector('[data-destination-modal-error]');
        const confirm = document.querySelector('[data-destination-modal-confirm]');

        if (
            !(modal instanceof HTMLDialogElement)
            || !(title instanceof HTMLElement)
            || !(subtitle instanceof HTMLElement)
            || !(itemLabel instanceof HTMLElement)
            || !(selection instanceof HTMLElement)
            || !(confirm instanceof HTMLButtonElement)
        ) {
            return;
        }

        let pendingAction = '';
        let pendingItem = null;
        let selectedDestination = null;

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        const clearSelection = () => {
            selectedDestination = null;
            confirm.disabled = true;
            confirm.textContent = 'Choose Destination';
            selection.textContent = 'Choose a folder below.';
            document.querySelectorAll('[data-destination-option].is-selected').forEach((option) => {
                if (option instanceof HTMLElement) {
                    option.classList.remove('is-selected');
                }
            });

            if (error instanceof HTMLElement) {
                error.classList.add('d-none');
                error.textContent = '';
            }
        };

        window.BrowseBox.openDestinationModal = (action, item) => {
            pendingAction = action;
            pendingItem = item;
            clearSelection();

            const verb = action === 'copy' ? 'Copy' : 'Move';
            title.textContent = `${verb} To…`;
            subtitle.textContent = `Browse to another folder, then confirm the ${action}.`;
            itemLabel.textContent = `${item.name}${item.type === 'dir' ? '/' : ''}`;
            confirm.textContent = action === 'copy' ? 'Copy To Selected Folder' : 'Move To Selected Folder';

            if (!modal.open) {
                modal.showModal();
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-destination-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
                return;
            }

            const option = target.closest('[data-destination-option]');

            if (!(option instanceof HTMLButtonElement)) {
                return;
            }

            event.preventDefault();

            if (!pendingItem) {
                return;
            }

            document.querySelectorAll('[data-destination-option].is-selected').forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.classList.remove('is-selected');
                }
            });

            option.classList.add('is-selected');
            selectedDestination = option.dataset.destinationOption ?? '';
            const selectedLabel = option.dataset.destinationLabel ?? '/';
            selection.textContent = selectedLabel;

            const isSameFolder = selectedDestination === pendingItem.parentPath;
            confirm.disabled = isSameFolder;

            if (error instanceof HTMLElement) {
                if (isSameFolder) {
                    error.textContent = 'Choose a different folder.';
                    error.classList.remove('d-none');
                } else {
                    error.classList.add('d-none');
                    error.textContent = '';
                }
            }
        });

        confirm.addEventListener('click', (event) => {
            event.preventDefault();

            if (!pendingItem || selectedDestination === null || selectedDestination === pendingItem.parentPath) {
                return;
            }

            closeModal();

            if (pendingAction === 'copy') {
                window.BrowseBox.submitCopy(pendingItem.path, selectedDestination);
                return;
            }

            window.BrowseBox.submitMove(pendingItem.path, selectedDestination);
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });
    },

    initDeleteModal() {
        const modal = document.querySelector('[data-delete-modal]');
        const itemLabel = document.querySelector('[data-delete-modal-item-label]');
        const confirm = document.querySelector('[data-delete-modal-confirm]');

        if (!(modal instanceof HTMLDialogElement) || !(itemLabel instanceof HTMLElement) || !(confirm instanceof HTMLButtonElement)) {
            return;
        }

        let pendingItem = null;

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        window.BrowseBox.openDeleteModal = (item) => {
            pendingItem = item;
            itemLabel.textContent = `${item.name}${item.type === 'dir' ? '/' : ''}`;

            if (!modal.open) {
                modal.showModal();
            }
        };

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-delete-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
            }
        });

        confirm.addEventListener('click', (event) => {
            event.preventDefault();

            if (!pendingItem) {
                return;
            }

            closeModal();
            window.BrowseBox.submitDelete(pendingItem.path);
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });
    },

    initCreateSubfolderModal() {
        const modal = document.querySelector('[data-create-subfolder-modal]');
        const parentLabel = document.querySelector('[data-create-subfolder-parent-label]');
        const parentPath = document.querySelector('[data-create-subfolder-parent-path]');
        const input = document.querySelector('[data-create-subfolder-input]');

        if (
            !(modal instanceof HTMLDialogElement)
            || !(parentLabel instanceof HTMLElement)
            || !(parentPath instanceof HTMLInputElement)
            || !(input instanceof HTMLInputElement)
        ) {
            return;
        }

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        const modalTitle = modal.querySelector('.browsebox-modal-header h3');
        const modalSubtitle = modal.querySelector('.browsebox-modal-header .small');

        window.BrowseBox.openCreateSubfolderModal = (item, options = {}) => {
            const label = item.path === "" ? "/" : `/${item.path}`;
            parentLabel.textContent = label;
            parentPath.value = item.path;
            input.value = "";

            if (modalTitle instanceof HTMLElement && typeof options.title === 'string' && options.title !== '') {
                modalTitle.textContent = options.title;
            }

            if (modalSubtitle instanceof HTMLElement && typeof options.subtitle === 'string' && options.subtitle !== '') {
                modalSubtitle.textContent = options.subtitle;
            }

            if (!modal.open) {
                modal.showModal();
            }

            window.setTimeout(() => {
                input.focus();
                input.select();
            }, 0);
        };

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-create-subfolder-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
            }
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });
    },

    initTooltips() {
        const triggers = Array.from(document.querySelectorAll('[data-browsebox-tooltip]'));

        if (triggers.length === 0) {
            return;
        }

        let activeTrigger = null;
        let tooltip = null;

        const removeTooltip = () => {
            activeTrigger = null;

            if (tooltip instanceof HTMLElement) {
                tooltip.remove();
            }

            tooltip = null;
        };

        const positionTooltip = () => {
            if (!(activeTrigger instanceof HTMLElement) || !(tooltip instanceof HTMLElement)) {
                return;
            }

            const rect = activeTrigger.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const top = Math.max(8, rect.top + window.scrollY - tooltipRect.height - 10);
            const left = Math.min(
                window.scrollX + window.innerWidth - tooltipRect.width - 8,
                Math.max(8, rect.left + window.scrollX + (rect.width / 2) - (tooltipRect.width / 2))
            );

            tooltip.style.top = `${top}px`;
            tooltip.style.left = `${left}px`;
        };

        const showTooltip = (trigger) => {
            const message = trigger.dataset.browseboxTooltip ?? '';

            if (message === '') {
                return;
            }

            removeTooltip();
            activeTrigger = trigger;
            tooltip = document.createElement('div');
            tooltip.className = 'browsebox-tooltip';
            tooltip.textContent = message;
            document.body.appendChild(tooltip);
            positionTooltip();
        };

        triggers.forEach((trigger) => {
            if (!(trigger instanceof HTMLElement)) {
                return;
            }

            trigger.addEventListener('mouseenter', () => showTooltip(trigger));
            trigger.addEventListener('focus', () => showTooltip(trigger));
            trigger.addEventListener('mouseleave', removeTooltip);
            trigger.addEventListener('blur', removeTooltip);
        });

        window.addEventListener('scroll', positionTooltip, { passive: true });
        window.addEventListener('resize', positionTooltip, { passive: true });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                removeTooltip();
            }
        });
    },

    initActionsModal() {
        const modal = document.querySelector('[data-actions-modal]');
        const openButton = document.querySelector('[data-actions-modal-open]');

        if (!(modal instanceof HTMLDialogElement) || !(openButton instanceof HTMLElement)) {
            return;
        }

        const closeModal = () => {
            if (modal.open) {
                modal.close();
            }
        };

        openButton.addEventListener('click', (event) => {
            event.preventDefault();

            if (!modal.open) {
                modal.showModal();
            }
        });

        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeButton = target.closest('[data-actions-modal-close]');

            if (closeButton instanceof HTMLElement) {
                event.preventDefault();
                closeModal();
            }
        });

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });
    },

    initUploadModal() {
        const modal = document.querySelector('[data-upload-modal]');
        const form = document.querySelector('[data-upload-form]');
        const selection = document.querySelector('[data-upload-modal-selection]');
        const error = document.querySelector('[data-upload-modal-error]');
        const progress = document.querySelector('[data-upload-modal-progress]');
        const submit = document.querySelector('[data-upload-submit]');
        const cancel = document.querySelector('[data-upload-modal-cancel]');
        const closeButtons = Array.from(document.querySelectorAll('[data-upload-modal-close]'));
        const targetFrame = document.querySelector('[data-upload-target-frame]');

        if (!(modal instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement) || !(selection instanceof HTMLElement) || !(submit instanceof HTMLButtonElement) || !(targetFrame instanceof HTMLIFrameElement)) {
            return;
        }

        const resetModalState = () => {
            window.BrowseBox.uploadInProgress = false;

            if (error instanceof HTMLElement) {
                error.classList.add('d-none');
                error.textContent = '';
            }

            if (progress instanceof HTMLElement) {
                progress.classList.add('d-none');
            }

            submit.disabled = window.BrowseBox.getSelectedUploadItems().length === 0;

            if (cancel instanceof HTMLButtonElement) {
                cancel.disabled = false;
            }

            closeButtons.forEach((button) => {
                if (button instanceof HTMLButtonElement || button instanceof HTMLElement) {
                    button.removeAttribute('disabled');
                }
            });
        };

        const closeModal = () => {
            if (window.BrowseBox.uploadInProgress) {
                return;
            }

            if (modal.open) {
                modal.close();
            }
        };

        const renderSelection = () => {
            const items = window.BrowseBox.getSelectedUploadItems();

            if (items.length === 0) {
                selection.innerHTML = '<div class="text-secondary small">No files selected yet.</div>';
                resetModalState();
                return;
            }

            const html = items.map((item) => {
                const size = typeof item.size === 'number' ? ` <span class="browsebox-upload-selection-size">(${window.BrowseBox.formatBytes(item.size)})</span>` : '';
                return `<div class="browsebox-upload-selection-item"><span class="browsebox-upload-selection-path">${window.BrowseBox.escapeHtml(item.relativePath)}</span>${size}</div>`;
            }).join('');

            selection.innerHTML = `<div class="browsebox-upload-selection-list">${html}</div>`;
            resetModalState();
        };

        window.BrowseBox.openUploadModal = () => {
            const actionsModal = document.querySelector('[data-actions-modal]');
            renderSelection();

            if (window.BrowseBox.getSelectedUploadItems().length === 0) {
                return;
            }

            if (actionsModal instanceof HTMLDialogElement && actionsModal.open) {
                actionsModal.close();
            }

            if (!modal.open) {
                modal.showModal();
            }
        };

        window.BrowseBox.closeUploadModal = closeModal;

        closeButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                closeModal();
            });
        });

        if (cancel instanceof HTMLButtonElement) {
            cancel.addEventListener('click', (event) => {
                event.preventDefault();

                if (window.BrowseBox.uploadInProgress) {
                    return;
                }

                window.BrowseBox.clearUploadSelection();
                closeModal();
            });
        }

        modal.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeModal();
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (window.BrowseBox.uploadInProgress || window.BrowseBox.getSelectedUploadItems().length === 0) {
                return;
            }

            renderSelection();
            window.BrowseBox.uploadInProgress = true;

            if (error instanceof HTMLElement) {
                error.classList.add('d-none');
                error.textContent = '';
            }

            if (progress instanceof HTMLElement) {
                progress.classList.remove('d-none');
            }

            submit.disabled = true;
            submit.textContent = 'Uploading...';

            if (cancel instanceof HTMLButtonElement) {
                cancel.disabled = true;
            }

            closeButtons.forEach((button) => {
                if (button instanceof HTMLButtonElement || button instanceof HTMLElement) {
                    button.setAttribute('disabled', 'disabled');
                }
            });

            const handleFrameLoad = () => {
                let nextLocation = '';

                try {
                    const frameWindow = targetFrame.contentWindow;
                    nextLocation = frameWindow?.location?.href ?? '';
                } catch (frameError) {
                    nextLocation = '';
                }

                if (nextLocation === '' || nextLocation === 'about:blank') {
                    return;
                }

                targetFrame.removeEventListener('load', handleFrameLoad);
                targetFrame.removeEventListener('error', handleFrameError);
                window.location.href = nextLocation;
            };

            const handleFrameError = () => {
                window.BrowseBox.uploadInProgress = false;
                targetFrame.removeEventListener('load', handleFrameLoad);
                targetFrame.removeEventListener('error', handleFrameError);
                window.BrowseBox.updateUploadState();

                if (progress instanceof HTMLElement) {
                    progress.classList.add('d-none');
                }

                if (error instanceof HTMLElement) {
                    error.textContent = 'Upload failed before BrowseBox could finish the transfer.';
                    error.classList.remove('d-none');
                }

                if (cancel instanceof HTMLButtonElement) {
                    cancel.disabled = false;
                }

                closeButtons.forEach((button) => {
                    if (button instanceof HTMLButtonElement || button instanceof HTMLElement) {
                        button.removeAttribute('disabled');
                    }
                });
            };

            targetFrame.src = 'about:blank';
            targetFrame.addEventListener('load', handleFrameLoad);
            targetFrame.addEventListener('error', handleFrameError);
            form.target = 'browsebox-upload-target';
            form.submit();
        });
    },

    formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes < 1024) {
            return `${Math.max(0, Math.round(bytes))} B`;
        }

        const units = ['KB', 'MB', 'GB', 'TB'];
        let value = bytes / 1024;
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }

        return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unitIndex]}`;
    },

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    },

    async handleDrop(event) {
        event.preventDefault();

        const dropzone = event.currentTarget;

        if (!(dropzone instanceof HTMLElement)) {
            return;
        }

        dropzone.classList.remove('is-dragover');

        const fileInput = document.getElementById('file_upload');
        const folderInput = document.getElementById('folder_upload');
        const status = document.querySelector('[data-picker-status="file_upload"]');
        const folderStatus = document.querySelector('[data-picker-status="folder_upload"]');
        const hiddenPaths = document.querySelector('[data-dropped-relative-paths]');

        if (!(fileInput instanceof HTMLInputElement) || !(hiddenPaths instanceof HTMLInputElement)) {
            return;
        }

        const dataTransfer = event.dataTransfer;
        const items = Array.from(dataTransfer?.items ?? []);
        const droppedFiles = Array.from(dataTransfer?.files ?? []);

        if (items.length === 0 && droppedFiles.length === 0) {
            return;
        }

        const collected = [];
        let hasDirectories = false;

        const readEntry = async (entry, prefix = '') => {
            if (!entry) {
                return;
            }

            if (entry.isFile) {
                const file = await new Promise((resolve, reject) => {
                    entry.file(resolve, reject);
                });

                collected.push({
                    file,
                    relativePath: prefix + file.name,
                });
                return;
            }

            if (!entry.isDirectory) {
                return;
            }

            hasDirectories = true;
            const reader = entry.createReader();
            const directoryPrefix = prefix + entry.name + '/';

            while (true) {
                const batch = await new Promise((resolve, reject) => {
                    reader.readEntries(resolve, reject);
                });

                if (!batch.length) {
                    break;
                }

                for (const child of batch) {
                    await readEntry(child, directoryPrefix);
                }
            }
        };

        const entries = items
            .map((item) => typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null)
            .filter((entry) => entry);

        const containsDirectoryEntry = entries.some((entry) => entry.isDirectory);

        if (!containsDirectoryEntry && droppedFiles.length > 0) {
            for (const file of droppedFiles) {
                collected.push({
                    file,
                    relativePath: file.name,
                });
            }
        } else {
            for (const item of items) {
            const entry = typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null;

                if (entry) {
                    await readEntry(entry);
                    continue;
                }

                const file = item.getAsFile ? item.getAsFile() : null;

                if (file) {
                    collected.push({
                        file,
                        relativePath: file.name,
                    });
                }
            }
        }

        if (collected.length === 0) {
            return;
        }

        const transfer = new DataTransfer();

        for (const item of collected) {
            transfer.items.add(item.file);
        }

        fileInput.files = transfer.files;
        window.BrowseBox.clearFileInput(folderInput);
        hiddenPaths.value = JSON.stringify(collected.map((item) => item.relativePath));

        if (status instanceof HTMLElement) {
            status.textContent = hasDirectories
                ? `${collected[0].relativePath.split('/')[0]} (${collected.length} file${collected.length === 1 ? '' : 's'})`
                : (collected.length === 1 ? collected[0].file.name : `${collected.length} files selected`);
        }

        if (folderStatus instanceof HTMLElement) {
            folderStatus.textContent = 'No folder chosen';
        }

        fileInput.dataset.pickerKind = hasDirectories ? 'folder' : 'file';
        window.BrowseBox.updateUploadState();
        window.BrowseBox.openUploadModal?.();
    },
};

document.addEventListener('change', (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.id) {
        return;
    }

    input.dataset.pickerKind = input.id === 'folder_upload' ? 'folder' : 'file';
    window.BrowseBox.clearDroppedMetadata();

    const otherInput = document.getElementById(input.id === 'folder_upload' ? 'file_upload' : 'folder_upload');
    const otherStatus = document.querySelector(`[data-picker-status="${input.id === 'folder_upload' ? 'file_upload' : 'folder_upload'}"]`);

    if (otherInput instanceof HTMLInputElement) {
        otherInput.value = '';
        otherInput.dataset.pickerKind = otherInput.id === 'folder_upload' ? 'folder' : 'file';
    }

    if (otherStatus instanceof HTMLElement) {
        otherStatus.textContent = otherInput instanceof HTMLInputElement && otherInput.id === 'folder_upload'
            ? 'No folder chosen'
            : 'No file chosen';
    }

    const status = document.querySelector(`[data-picker-status="${input.id}"]`);

    if (!(status instanceof HTMLElement)) {
        return;
    }

    if (!input.files || input.files.length === 0) {
        status.textContent = input.dataset.pickerKind === 'folder' ? 'No folder chosen' : 'No file chosen';
        window.BrowseBox.updateUploadState();
        return;
    }

    if (input.dataset.pickerKind === 'folder') {
        const firstFile = input.files[0];
        const relativePath = typeof firstFile.webkitRelativePath === 'string' ? firstFile.webkitRelativePath : '';
        const folderName = relativePath.includes('/') ? relativePath.split('/')[0] : firstFile.name;
        status.textContent = `${folderName} (${input.files.length} file${input.files.length === 1 ? '' : 's'})`;
        window.BrowseBox.updateUploadState();
        window.BrowseBox.openUploadModal?.();
        return;
    }

    status.textContent = input.files.length === 1
        ? input.files[0].name
        : `${input.files.length} files selected`;

    window.BrowseBox.updateUploadState();
    window.BrowseBox.openUploadModal?.();
});

document.addEventListener('DOMContentLoaded', () => {
    const dropzones = Array.from(document.querySelectorAll('[data-upload-dropzone]'));

    dropzones.forEach((dropzone) => {
        if (!(dropzone instanceof HTMLElement)) {
            return;
        }

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                if (!window.BrowseBox.isExternalUploadDrag(event)) {
                    return;
                }

                event.preventDefault();

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }

                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                if (eventName === 'dragleave') {
                    const relatedTarget = event.relatedTarget;

                    if (relatedTarget instanceof Node && dropzone.contains(relatedTarget)) {
                        return;
                    }
                }

                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', (event) => {
            if (!window.BrowseBox.isExternalUploadDrag(event)) {
                return;
            }

            window.BrowseBox.handleDrop(event).catch(() => {
                dropzone.classList.remove('is-dragover');
            });
        });
    });

    window.BrowseBox.initMoveUI();
    window.BrowseBox.initPublicViewToggle();
    window.BrowseBox.initSortableTables();
    window.BrowseBox.initRenameUI();
    window.BrowseBox.initConditionalSticky();
    window.BrowseBox.initContextMenu();
    window.BrowseBox.initDestinationModal();
    window.BrowseBox.initDeleteModal();
    window.BrowseBox.initCreateSubfolderModal();
    window.BrowseBox.initTooltips();
    window.BrowseBox.initActionsModal();
    window.BrowseBox.initSettingsModal();
    window.BrowseBox.initPendingConflictModal();
    window.BrowseBox.initUploadModal();
    window.BrowseBox.updateUploadState();
});
