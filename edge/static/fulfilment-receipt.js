document.addEventListener('click', function (event) {
    var close = event.target.closest('[data-receipt-close]');
    if (close) { close.closest('dialog').close(); return; }
    var trigger = event.target.closest('[data-receipt-open]');
    if (!trigger || event.target.closest('form,button,a')) return;
    var dialog = document.getElementById(trigger.dataset.receiptOpen);
    if (dialog) dialog.showModal();
});
document.addEventListener('keydown', function (event) {
    var trigger = event.target.closest('[data-receipt-open]');
    if (!trigger || !['Enter', ' '].includes(event.key) || event.target.closest('form,button,a')) return;
    event.preventDefault();
    var dialog = document.getElementById(trigger.dataset.receiptOpen);
    if (dialog) dialog.showModal();
});
document.addEventListener('click', function (event) {
    if (event.target.tagName === 'DIALOG') event.target.close();
});
