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
	 * @param string          $closingTime    Business-day close marker
	 * @param string          $entryCutoff    Start of the next counting window
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveInventoryValueTimestamp($entryTimestamp, DateTimeZone $timezone, $closingTime = '06:00', $entryCutoff = '20:00')
	{
		$closingTime = $this->normalizeTime($closingTime, 'closing time');
		$entryCutoff = $this->normalizeTime($entryCutoff, 'entry cutoff');
		$entry = (new DateTimeImmutable('@'.((int) $entryTimestamp)))->setTimezone($timezone);
		$targetDate = $entry->format('Y-m-d');

		if ($entry->format('H:i:s') >= $entryCutoff) {
			$targetDate = $entry->modify('+1 day')->format('Y-m-d');
		}

		$valueDate = new DateTimeImmutable($targetDate.' '.$closingTime, $timezone);
		return $valueDate->modify('+1 minute')->getTimestamp();
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
		$time = $this->normalizeTime($time, 'configured time');
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
	 * @param string       $entryCutoff    Start of the next counting window
	 * @param int          $leadMinutes    Minutes before cutoff
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public function resolveInventoryAutoCloseTimestamp($valueTimestamp, DateTimeZone $timezone, $entryCutoff = '20:00', $leadMinutes = 15)
	{
		$entryCutoff = $this->normalizeTime($entryCutoff, 'entry cutoff');
		$leadMinutes = (int) $leadMinutes;
		if ($leadMinutes < 1 || $leadMinutes > 1440) {
			throw new InvalidArgumentException('Invalid automatic closure lead time.');
		}

		$valueDate = (new DateTimeImmutable('@'.((int) $valueTimestamp)))->setTimezone($timezone);
		$cutoff = new DateTimeImmutable($valueDate->format('Y-m-d').' '.$entryCutoff, $timezone);
		return $cutoff->modify('-'.$leadMinutes.' minutes')->getTimestamp();
	}

	/**
	 * @param string $time  Time value
	 * @param string $label Parameter label
	 * @return string
	 * @throws InvalidArgumentException
	 */
	private function normalizeTime($time, $label)
	{
		$time = trim((string) $time);
		if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/', $time)) {
			throw new InvalidArgumentException('Invalid '.$label.'. Expected HH:MM or HH:MM:SS.');
		}
		return strlen($time) === 5 ? $time.':00' : $time;
	}
}
