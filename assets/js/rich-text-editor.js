document.querySelectorAll('[data-rich-text]').forEach((field) => {
    const source = field.querySelector('textarea');
    const editor = field.querySelector('[contenteditable]');
    const toolbar = field.querySelector('[data-toolbar]');
    if (!source || !editor || !toolbar) return;

    editor.innerHTML = source.value;
    source.hidden = true;
    editor.hidden = false;
    toolbar.hidden = false;

    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) return;
        event.preventDefault();
        editor.focus();
        const command = button.dataset.command;
        if (command === 'createLink') {
            const url = window.prompt('Enter an HTTPS, HTTP, email, or site-relative link:');
            if (!url) return;
            if (!/^(https?:\/\/|mailto:|\/(?!\/))/i.test(url.trim())) {
                window.alert('Use an HTTPS, HTTP, email, or site-relative link.');
                return;
            }
            document.execCommand(command, false, url);
            return;
        }
        document.execCommand(command, false, button.dataset.value || null);
    });

    editor.addEventListener('paste', (event) => {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    });
    editor.addEventListener('drop', (event) => event.preventDefault());

    field.closest('form').addEventListener('submit', () => {
        source.value = editor.innerHTML;
    });
});
