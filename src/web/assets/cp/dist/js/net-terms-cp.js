(function () {
	// Post secondary actions without a form, since a CP page can't nest one inside its main form
	document.addEventListener('click', (event) => {
		const button = event.target.closest('[data-nt-action]');

		if (!button) {
			return;
		}

		event.preventDefault();

		if (button.dataset.ntConfirm && !window.confirm(Craft.t('net-terms', button.dataset.ntConfirm))) {
			return;
		}

		const formData = new FormData();
		const params = JSON.parse(button.dataset.ntParams);

		Object.keys(params).forEach((name) => {
			formData.append(name, params[name]);
		});

		if (button.dataset.ntFields) {
			document.querySelector(button.dataset.ntFields).querySelectorAll('input, select, textarea').forEach((input) => {
				formData.append(input.name, input.value);
			});
		}

		button.classList.add('loading');

		Craft.sendActionRequest('POST', button.dataset.ntAction, {data: formData})
			.then((response) => {
				if (response.data.redirect) {
					window.location.href = response.data.redirect;
				} else {
					window.location.reload();
				}
			})
			.catch(({response}) => {
				// A network failure has no response to read a message from
				Craft.cp.displayError(response?.data?.message ?? Craft.t('app', 'A server error occurred.'));
			})
			.finally(() => {
				button.classList.remove('loading');
			});
	});

	// Open a form in a Craft modal and reload once it saves, since the page's figures depend on it
	document.addEventListener('click', (event) => {
		const button = event.target.closest('[data-nt-modal]');

		if (!button) {
			return;
		}

		event.preventDefault();

		const modal = new Craft.CpModal(button.dataset.ntModal, {params: JSON.parse(button.dataset.ntParams)});
		modal.on('submit', () => window.location.reload());
	});

	// Read and write money inputs in each field's own locale, since they show localized numbers
	const localeOf = (input) => input.closest('.money-container, .flex, td, .field')?.querySelector('input[name$="[locale]"]')?.value || document.documentElement.lang || 'en-US';

	const readAmount = (input) => {
		if (!input || input.value.trim() === '') {
			return 0;
		}

		const parts = new Intl.NumberFormat(localeOf(input)).formatToParts(1000.1);
		const group = parts.find((part) => part.type === 'group')?.value ?? ',';
		const decimal = parts.find((part) => part.type === 'decimal')?.value ?? '.';
		const amount = parseFloat(input.value.split(group).join('').replace(decimal, '.'));

		return Number.isNaN(amount) ? 0 : amount;
	};

	// Round to the payment currency's minor unit, since a store's currency may have zero or three decimals
	const currencyCode = () => document.querySelector('[data-nt-currency]').dataset.ntCurrency;
	const fractionDigits = () => new Intl.NumberFormat('en', {style: 'currency', currency: currencyCode()}).resolvedOptions().maximumFractionDigits;
	const roundToMinorUnit = (amount) => Math.round(amount * 10 ** fractionDigits()) / 10 ** fractionDigits();

	const writeAmount = (input, amount) => {
		input.value = amount > 0 ? new Intl.NumberFormat(localeOf(input), {minimumFractionDigits: fractionDigits(), maximumFractionDigits: fractionDigits()}).format(amount) : '';
		// Restyle the field ourselves, since a value set from script skips the money field's own placeholder styling
		input.classList.toggle('money-placeholder', input.value === '');
		input.closest('.money-container')?.querySelector('.clear-btn')?.classList.toggle('hidden', input.value === '');
		input.dispatchEvent(new Event('input', {bubbles: true}));
		input.dispatchEvent(new Event('change', {bubbles: true}));
	};

	const amountInputOf = (row) => row.querySelector('input[name^="amounts["]:not([type="hidden"])');

	const updateSummary = () => {
		const summary = document.querySelector('[data-nt-summary]');

		if (!summary) {
			return;
		}

		const received = readAmount(document.querySelector('#amount'));
		const applied = [...document.querySelectorAll('[data-nt-target]')].reduce((total, row) => total + readAmount(amountInputOf(row)), 0);
		const format = (amount) => new Intl.NumberFormat(localeOf(document.querySelector('#amount')), {style: 'currency', currency: currencyCode()}).format(amount);

		summary.querySelector('[data-nt-summary-received]').textContent = format(received);
		summary.querySelector('[data-nt-summary-applied]').textContent = format(applied);
		summary.querySelector('[data-nt-summary-unapplied]').textContent = format(Math.max(received - applied, 0));
		summary.querySelector('[data-nt-summary-over]').classList.toggle('hidden', roundToMinorUnit(applied) <= roundToMinorUnit(received));
	};

	// Spread an amount across the open items in their listed order, oldest due first
	const allocate = (amount) => {
		let remaining = amount;

		document.querySelectorAll('[data-nt-target]').forEach((row) => {
			const share = Math.min(parseFloat(row.dataset.ntBalance), remaining);
			writeAmount(amountInputOf(row), roundToMinorUnit(share));
			remaining = roundToMinorUnit(remaining - share);
		});
	};

	document.addEventListener('click', (event) => {
		const payInFull = event.target.closest('[data-nt-pay-in-full]');
		const clear = event.target.closest('[data-nt-clear-allocations]');

		if (payInFull) {
			const row = payInFull.closest('[data-nt-target]');
			writeAmount(amountInputOf(row), parseFloat(row.dataset.ntBalance));
			updateSummary();
		}

		if (clear) {
			document.querySelectorAll('[data-nt-target]').forEach((row) => writeAmount(amountInputOf(row), 0));
			updateSummary();
		}
	});

	document.addEventListener('change', (event) => {
		const receivedFull = event.target.closest('[data-nt-received-full]');

		if (receivedFull?.checked) {
			const total = parseFloat(receivedFull.dataset.ntReceivedFull);
			writeAmount(document.querySelector('#amount'), total);
			allocate(total);
		}

		updateSummary();
	});

	// Also refresh after focus leaves, since the money field settles a typed value only on blur
	['input', 'keyup', 'focusout'].forEach((eventName) => {
		document.addEventListener(eventName, (event) => {
			if (event.target.closest('#amount, [data-nt-target]')) {
				setTimeout(updateSummary, 0);
			}
		});
	});

	updateSummary();

	// Reload the page for the chosen value, since the fields after the select depend on it
	document.addEventListener('change', (event) => {
		const select = event.target.closest('[data-nt-reload-param]');

		if (!select) {
			return;
		}

		const url = new URL(window.location.href);
		url.searchParams.set(select.dataset.ntReloadParam, select.value);
		window.location.href = url.toString();
	});
})();
