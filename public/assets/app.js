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

        const activeInput = inputs.find((input) => input.files && input.files.length > 0);

        if (!activeInput) {
            submit.disabled = true;
            submit.textContent = 'Upload Selected Files or Folder';
            return;
        }

        submit.disabled = false;

        if (activeInput.dataset.pickerKind === 'folder') {
            submit.textContent = 'Upload Selected Folder';
            return;
        }

        submit.textContent = activeInput.files.length === 1
            ? 'Upload Selected File'
            : 'Upload Selected Files';
    }
};

document.addEventListener('change', (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.id) {
        return;
    }

    const status = document.querySelector(`[data-picker-status="${input.id}"]`);

    if (!status) {
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
    window.BrowseBox.updateUploadState();
});
