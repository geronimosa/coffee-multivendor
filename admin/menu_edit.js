let variants = [];

function addVariant(label = '', price = '') {
    const container = document.getElementById('variant-container');

    const row = document.createElement('div');
    row.className = 'variant-row';
    row.innerHTML = `
        <input type="text" placeholder="Label" value="${label}" onchange="updateVariants()" class="variant-label">
        <input type="number" placeholder="Price" value="${price}" onchange="updateVariants()" class="variant-price" step="0.01">
        <button class="button secondary" type="button" aria-label="Remove option" onclick="this.parentNode.remove(); updateVariants()">Remove</button>
    `;
    container.appendChild(row);
    updateVariants();
}

function updateVariants() {
    const labels = document.querySelectorAll('.variant-label');
    const prices = document.querySelectorAll('.variant-price');
    variants = [];

    for (let i = 0; i < labels.length; i++) {
        const label = labels[i].value.trim();
        const price = parseFloat(prices[i].value);
        if (label && !isNaN(price)) {
            variants.push({ label, price });
        }
    }

    document.getElementById('variant_options').value = JSON.stringify(variants);
}

window.onload = function () {
    let data = [];

    // Use existingVariants if defined globally, otherwise fall back to inline variant loading
    if (typeof existingVariants !== 'undefined' && Array.isArray(existingVariants)) {
        data = existingVariants;
    } else if (typeof existing !== 'undefined' && Array.isArray(existing)) {
        data = existing;
    }

    data.forEach(v => addVariant(v.label, v.price));
};
