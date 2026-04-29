window.BrowseBox = {
    confirmDelete(form) {
        return window.confirm('Delete this item?');
    },

    updateUploadState() {
        const inputs = Array.from(document.querySelectorAll('input[type="file"][data-picker-kind]'));
        const submit = document.querySelector('[data-upload-submit]');

        if (!(submit instanceof HTMLButtonElement)) {
            return;
        }

        const dropPaths = document.querySelector('[data-dropped-relative-paths]');
        const dropzoneText = document.querySelector('[data-upload-dropzone-text]');
        const activeInput = inputs.find((input) => input.files && input.files.length > 0);

        if (!activeInput) {
            submit.disabled = true;
            submit.textContent = 'Upload Selected Files or Folder';

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

            if (dropzoneText instanceof HTMLElement && dropped) {
                dropzoneText.textContent = `Ready to upload folder contents (${activeInput.files.length} file${activeInput.files.length === 1 ? '' : 's'}). Click the upload button to continue.`;
            }

            return;
        }

        submit.textContent = activeInput.files.length === 1
            ? 'Upload Selected File'
            : 'Upload Selected Files';

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

    moveDragItemPath: null,
    moveActiveTarget: null,

    clearMoveHighlights() {
        window.BrowseBox.moveActiveTarget = null;

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
    },

    submitMove(itemPath, destinationPath) {
        const form = document.querySelector('[data-move-form]');
        const itemInput = document.querySelector('[data-move-form-item-path]');
        const destinationInput = document.querySelector('[data-move-form-destination-path]');

        if (!(form instanceof HTMLFormElement) || !(itemInput instanceof HTMLInputElement) || !(destinationInput instanceof HTMLInputElement)) {
            return;
        }

        itemInput.value = itemPath;
        destinationInput.value = destinationPath;
        form.submit();
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

        const items = Array.from(event.dataTransfer?.items ?? []);

        if (items.length === 0) {
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
        return;
    }

    status.textContent = input.files.length === 1
        ? input.files[0].name
        : `${input.files.length} files selected`;

    window.BrowseBox.updateUploadState();
});

document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.querySelector('[data-upload-dropzone]');

    if (dropzone instanceof HTMLElement) {
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach((eventName) => {
            dropzone.addEventListener(eventName, () => {
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', (event) => {
            window.BrowseBox.handleDrop(event).catch(() => {
                dropzone.classList.remove('is-dragover');
            });
        });
    }

    window.BrowseBox.initMoveUI();
    window.BrowseBox.updateUploadState();
});
