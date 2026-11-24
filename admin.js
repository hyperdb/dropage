(() => {
    const zone = document.getElementById('dropage-zone');
    const input = document.getElementById('dropage-archive');

    if (zone && input) {
        zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('is-dragging');
        });
        zone.addEventListener('dragleave', (event) => {
            if (!zone.contains(event.relatedTarget)) {
                zone.classList.remove('is-dragging');
            }
        });
        zone.addEventListener('drop', (event) => {
            event.preventDefault();
            zone.classList.remove('is-dragging');
            const files = event.dataTransfer.files;
            if (files.length !== 1 || !/\.zip$/i.test(files[0].name)) {
                window.alert('ZIPファイルを1つドロップしてください。');
                return;
            }
            input.files = files;
            input.form.requestSubmit();
        });
    }

    document.querySelectorAll('.dropage-delete').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('このページを削除しますか？')) {
                event.preventDefault();
            }
        });
    });
})();
