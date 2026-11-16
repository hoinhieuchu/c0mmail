(() => {
    const editor = document.querySelector('[data-rich-editor]');
    const source = document.querySelector('[data-rich-source]');
    if (!editor || !source) return;

    const syncSource = () => {
        source.value = editor.innerHTML;
    };

    document.querySelectorAll('[data-rich-command]').forEach((button) => {
        button.addEventListener('click', () => {
            editor.focus();
            document.execCommand(button.dataset.richCommand, false, null);
            syncSource();
        });
    });

    const linkButton = document.querySelector('[data-rich-link]');
    if (linkButton) {
        linkButton.addEventListener('click', () => {
            const href = window.prompt('Enter an https:// or mailto: link');
            if (!href || !/^(https:\/\/|http:\/\/|mailto:)/i.test(href.trim())) return;
            editor.focus();
            document.execCommand('createLink', false, href.trim());
            syncSource();
        });
    }

    editor.addEventListener('input', syncSource);
    const form = editor.closest('form');
    if (form) form.addEventListener('submit', syncSource);
})();
