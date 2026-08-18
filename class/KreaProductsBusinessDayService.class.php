<?php
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

/**
 * Resolves the immutable value date assigned to a physical count.
 */
class KreaProductsBusinessDayService
{
	/**
	 * @param int             $entryTimestamp Entry timestamp
	 * @param DateTimeZone    $timezone       Entity/user timezone
	 * @param string          $inventoryTime  Configured inventory value time
	 * @param string          $entryCutoff    Start of the next counting window
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveInventoryValueTimestamp($entryTimestamp, DateTimeZone $timezone, $inventoryTime = '10:30', $entryCutoff = '20:00')
	{
		$inventoryTime = $this->normalizeConfiguredTime($inventoryTime, 'inventory time');
		$entryCutoff = $this->normalizeConfiguredTime($entryCutoff, 'entry cutoff');
		$entry = (new DateTimeImmutable('@'.((int) $entryTimestamp)))->setTimezone($timezone);
		$targetDate = $entry->format('Y-m-d');

		if ($entry->format('H:i:s') >= $entryCutoff) {
			$targetDate = $entry->modify('+1 day')->format('Y-m-d');
		}

		$valueDate = new DateTimeImmutable($targetDate.' '.$inventoryTime, $timezone);
		return $valueDate->getTimestamp();
	}

	/**
	 * Return the mandatory next-window inventory timestamp when entry occurs at
	 * or after the cutoff. A pre-cutoff entry has no mandatory minimum.
	 *
	 * @param int          $entryTimestamp Entry timestamp
	 * @param DateTimeZone $timezone       Business timezone
	 * @param string       $inventoryTime  Configured inventory value time
	 * @param string       $entryCutoff    Start of the next counting window
	 * @return int Zero before cutoff, otherwise the mandatory next-window timestamp
	 * @throws InvalidArgumentException
	 */
	public function resolvePostCutoffMinimumValueTimestamp($entryTimestamp, DateTimeZone $timezone, $inventoryTime = '10:30', $entryCutoff = '20:00')
	{
		$entryCutoff = $this->normalizeConfiguredTime($entryCutoff, 'entry cutoff');
		$entry = (new DateTimeImmutable('@'.((int) $entryTimestamp)))->setTimezone($timezone);
		if ($entry->format('H:i:s') < $entryCutoff) {
			return 0;
		}

		return $this->resolveInventoryValueTimestamp(
			(int) $entryTimestamp,
			$timezone,
			(string) $inventoryTime,
			(string) $entryCutoff
		);
	}

	/**
	 * Resolve a configured clock time on a database calendar date.
	 *
	 * @param string       $calendarDate Calendar date in YYYY-MM-DD format
	 * @param DateTimeZone $timezone     Business timezone
	 * @param string       $time         Configured time
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveDateTimestamp($calendarDate, DateTimeZone $timezone, $time)
	{
		$calendarDate = trim((string) $calendarDate);
		$time = $this->normalizeConfiguredTime($time, 'configured time');
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $calendarDate.' '.$time, $timezone);
		$dateErrors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
			throw new InvalidArgumentException('Invalid calendar date. Expected YYYY-MM-DD.');
		}
		return $date->getTimestamp();
	}

	/**
	 * Resolve an authoritative invoice datetime in the business timezone.
	 *
	 * @param string       $invoiceDateTime Invoice datetime in YYYY-MM-DD HH:MM:SS format
	 * @param DateTimeZone $timezone        Business timezone
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveInvoiceDateTimeTimestamp($invoiceDateTime, DateTimeZone $timezone)
	{
		$invoiceDateTime = trim((string) $invoiceDateTime);
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $invoiceDateTime, $timezone);
		$dateErrors = DateTimeImmutable::getLastErrors();
		if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
			throw new InvalidArgumentException('Invalid invoice datetime. Expected YYYY-MM-DD HH:MM:SS.');
		}

		return $date->getTimestamp();
	}

	/**
	 * Resolve the moment when an initiated inventory becomes due for automatic closure.
	 *
	 * @param int          $valueTimestamp Inventory value timestamp
	 * @param DateTimeZone $timezone       Business timezone
	 * @param string       $autoCloseTime Configured automatic closure time
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveInventoryAutoCloseTimestamp($valueTimestamp, DateTimeZone $timezone, $autoCloseTime = '19:45')
	{
		$autoCloseTime = $this->normalizeConfiguredTime($autoCloseTime, 'inventory automatic close time');
		$valueDate = (new DateTimeImmutable('@'.((int) $valueTimestamp)))->setTimezone($timezone);
		$automaticClose = new DateTimeImmutable($valueDate->format('Y-m-d').' '.$autoCloseTime, $timezone);
		return $automaticClose->getTimestamp();
	}

	/**
	 * Resolve the daily interval during which inventory operations are read-only.
	 *
	 * The interval starts at the configured automatic closure time and ends at
	 * the configured entry cutoff. The end is exclusive, so writes resume at
	 * the exact cutoff time for the new counting window.
	 *
	 * @param int          $timestamp   Timestamp to evaluate
	 * @param DateTimeZone $timezone    Business timezone
	 * @param string       $lockStart   Configured automatic closure and lock start time
	 * @param string       $entryCutoff Start of the next counting window
	 * @return array{active:bool,start:int,end:int}
	 * @throws InvalidArgumentException
	 */
	public function resolveInventoryMutationLockWindow($timestamp, DateTimeZone $timezone, $lockStart = '19:45', $entryCutoff = '20:00')
	{
		$lockStart = $this->normalizeConfiguredTime($lockStart, 'inventory automatic close time');
		$entryCutoff = $this->normalizeConfiguredTime($entryCutoff, 'entry cutoff');
		if ($lockStart >= $entryCutoff) {
			throw new InvalidArgumentException('Inventory automatic close time must be earlier than the entry cutoff.');
		}
		$current = (new DateTimeImmutable('@'.((int) $timestamp)))->setTimezone($timezone);
		$nextCutoff = new DateTimeImmutable($current->format('Y-m-d').' '.$entryCutoff, $timezone);
		if ($current >= $nextCutoff) {
			$nextCutoff = $nextCutoff->modify('+1 day');
		}
		$lockStartDateTime = new DateTimeImmutable($nextCutoff->format('Y-m-d').' '.$lockStart, $timezone);
		if ($lockStartDateTime >= $nextCutoff) {
			$lockStartDateTime = $lockStartDateTime->modify('-1 day');
		}

		return array(
			'active' => $current >= $lockStartDateTime && $current < $nextCutoff,
			'start' => $lockStartDateTime->getTimestamp(),
			'end' => $nextCutoff->getTimestamp(),
		);
	}

	/**
	 * @param string $time  Time value
	 * @param string $label Parameter label
	 * @return string
	 * @throws InvalidArgumentException
	 */
	public function normalizeConfiguredTime($time, $label = 'configured time')
	{
		$time = trim((string) $time);
		if (!preg_match('/^(0?[0-9]|1[0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $time, $matches)) {
			throw new InvalidArgumentException('Invalid '.$label.'. Expected HH:MM or HH:MM:SS.');
		}
		return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : 0);
	}
}
