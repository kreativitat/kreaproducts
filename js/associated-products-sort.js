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

(function () {
	'use strict';

	var textCollator = typeof Intl !== 'undefined' && typeof Intl.Collator === 'function'
		? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
		: null;

	function compareText(left, right) {
		if (textCollator) {
			return textCollator.compare(left, right);
		}

		return left < right ? -1 : (left > right ? 1 : 0);
	}

	function compareRows(left, right, key, type, direction) {
		var leftValue = left.getAttribute('data-krea-sort-' + key) || '';
		var rightValue = right.getAttribute('data-krea-sort-' + key) || '';
		var comparison;

		if (type === 'number') {
			comparison = (parseFloat(leftValue) || 0) - (parseFloat(rightValue) || 0);
		} else {
			comparison = compareText(leftValue, rightValue);
		}

		if (comparison !== 0) {
			return direction === 'ascending' ? comparison : -comparison;
		}

		return parseInt(left.getAttribute('data-krea-sort-position'), 10)
			- parseInt(right.getAttribute('data-krea-sort-position'), 10);
	}

	function updateHeaders(table, activeButton, direction) {
		var buttons = table.querySelectorAll('.krea-parent-sort-button');

		Array.prototype.forEach.call(buttons, function (button) {
			var isActive = button === activeButton;
			var header = button.closest('th');
			var indicator = button.querySelector('.krea-parent-sort-indicator');

			header.setAttribute('aria-sort', isActive ? direction : 'none');
			indicator.textContent = isActive ? (direction === 'ascending' ? '↑' : '↓') : '↕';
		});
	}

	function initializeTable(table) {
		var body = table.tBodies.length ? table.tBodies[0] : null;

		if (!body) {
			return;
		}

		table.addEventListener('click', function (event) {
			var button = event.target.closest('.krea-parent-sort-button');

			if (!button || !table.contains(button)) {
				return;
			}

			var key = button.getAttribute('data-krea-sort-key');
			var type = button.getAttribute('data-krea-sort-type');
			var currentDirection = button.closest('th').getAttribute('aria-sort');
			var direction = currentDirection === 'ascending' ? 'descending' : 'ascending';
			var rows = Array.prototype.slice.call(body.rows);

			rows.sort(function (left, right) {
				return compareRows(left, right, key, type, direction);
			});
			rows.forEach(function (row) {
				body.appendChild(row);
			});

			updateHeaders(table, button, direction);
		});
	}

	function initialize() {
		var table = document.getElementById('krea-parent-products-table');

		if (table) {
			initializeTable(table);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})();
