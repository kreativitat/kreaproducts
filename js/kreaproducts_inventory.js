/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

'use strict';

document.addEventListener('DOMContentLoaded', function () {
	document.querySelectorAll('.kps-category-card').forEach(function (form) {
		form.addEventListener('submit', function () {
			const button = form.querySelector('button[type="submit"]');
			if (!button) {
				return;
			}
			button.disabled = true;
			button.textContent = button.dataset.loadingLabel || 'Opening…';
		});
	});

	const statisticsProduct = document.querySelector('[data-kps-statistics-product]');
	if (statisticsProduct) {
		statisticsProduct.addEventListener('change', function () {
			if (statisticsProduct.form) {
				statisticsProduct.form.submit();
			}
		});
	}

	const countForm = document.getElementById('kps-inventory-count-form');
	if (!countForm) {
		return;
	}

	const inputs = Array.from(countForm.querySelectorAll('[data-kps-count]'));
	const valueDateInput = document.querySelector('[data-kps-value-date]');
	const saveButton = document.getElementById('kps-save-counts');
	const progress = document.getElementById('kps-count-progress');
	const search = document.getElementById('kps-product-search');
	const initialValues = new Map(inputs.map(function (input) {
		return [input.name, input.value];
	}));
	const initialValueDate = valueDateInput ? valueDateInput.value : '';
	let dirty = false;
	let submitting = false;

	function formatQuantity(value) {
		const normalized = Math.abs(value) < 0.00000001 ? 0 : value;
		return String(Number(normalized.toFixed(8)));
	}

	function updateDeviation(input, isCounted) {
		const row = input.closest('tr');
		if (!row) {
			return;
		}
		const absoluteCell = row.querySelector('[data-kps-absolute-deviation]');
		const relativeCell = row.querySelector('[data-kps-relative-deviation]');
		const normalizedCount = input.value.trim().replace(',', '.');
		const count = normalizedCount === '' ? NaN : Number(normalizedCount);
		const expected = Number(input.dataset.kpsExpectedQuantity);
		if (!isCounted || !Number.isFinite(count) || !Number.isFinite(expected)) {
			if (absoluteCell) {
				absoluteCell.textContent = '—';
			}
			if (relativeCell) {
				relativeCell.textContent = '—';
			}
			return;
		}

		const absolute = count - expected;
		if (absoluteCell) {
			absoluteCell.textContent = formatQuantity(absolute);
		}
		if (relativeCell) {
			relativeCell.textContent = Math.abs(expected) < 0.0001
				? '—'
				: ((absolute / Math.abs(expected)) * 100).toFixed(2) + '%';
		}
	}

	function updatePageState() {
		let counted = 0;
		dirty = false;
		inputs.forEach(function (input) {
			const isCounted = input.value.trim() !== '';
			counted += isCounted ? 1 : 0;
			dirty = dirty || input.value !== initialValues.get(input.name);
			const row = input.closest('tr');
			if (row) {
				row.classList.toggle('kps-counted-row', isCounted);
			}
			updateDeviation(input, isCounted);
		});
		if (valueDateInput) {
			dirty = dirty || valueDateInput.value !== initialValueDate;
		}

		if (progress && inputs.length > 0) {
			const template = progress.dataset.template || '{count}/{total}';
			progress.textContent = template
				.replace('{count}', String(counted))
				.replace('{total}', String(inputs.length));
		}
		if (saveButton) {
			saveButton.disabled = !dirty;
		}
	}

	inputs.forEach(function (input) {
		input.addEventListener('input', updatePageState);
	});
	if (valueDateInput) {
		valueDateInput.addEventListener('input', updatePageState);
		valueDateInput.addEventListener('change', updatePageState);
	}

	if (search) {
		search.addEventListener('input', function () {
			const query = search.value.trim().toLocaleLowerCase();
			document.querySelectorAll('[data-kps-product-row]').forEach(function (row) {
				row.hidden = query !== '' && !row.dataset.searchText.includes(query);
			});
		});
	}

	countForm.addEventListener('submit', function () {
		submitting = true;
		if (saveButton) {
			saveButton.disabled = true;
			saveButton.textContent = saveButton.dataset.savingLabel || 'Saving…';
		}
	});

	window.addEventListener('beforeunload', function (event) {
		if (!dirty || submitting) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	});

	updatePageState();
});
