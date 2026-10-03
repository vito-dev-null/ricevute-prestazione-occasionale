(() => {
    const preview = document.querySelector('#calculation-preview');
    const grossInput = document.querySelector('[name="gross"]');
    const priorInput = document.querySelector('[name="inps_prior_income"]');
    if (!preview || !grossInput || !priorInput) return;

    const parseAmount = (value) => {
        const parsed = Number.parseFloat(String(value).replace(',', '.'));
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
    };
    const money = (amount) => new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR',
    }).format(amount);
    const percent = (key) => Number.parseFloat(preview.dataset[key] || '0') || 0;

    const update = () => {
        const gross = parseAmount(grossInput.value);
        const prior = parseAmount(priorInput.value);
        const withholding = Math.round(gross * percent('withholding')) / 100;
        const threshold = parseAmount(preview.dataset.threshold);
        const base = Math.max(0, prior + gross - threshold) - Math.max(0, prior - threshold);
        const inps = Math.round(base * percent('inps') * percent('worker') / 100) / 100;
        const stamp = gross >= parseAmount(preview.dataset.stampThreshold)
            ? parseAmount(preview.dataset.stamp)
            : 0;
        const stampToClient = preview.dataset.stampClient === '1';
        const net = gross - withholding - inps + (stampToClient ? stamp : 0);
        document.querySelector('#preview-gross').textContent = money(gross);
        document.querySelector('#preview-withholding').textContent = `− ${money(withholding)}`;
        document.querySelector('#preview-inps').textContent = `− ${money(inps)}`;
        document.querySelector('#preview-stamp').textContent = `${stampToClient ? '+ ' : ''}${money(stamp)}`;
        document.querySelector('#preview-net').textContent = money(net);
    };

    grossInput.addEventListener('input', update);
    priorInput.addEventListener('input', update);
    update();
})();
