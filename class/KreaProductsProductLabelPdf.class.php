<?php
/* Copyright (C) 2026       Kreativitat             <mail@kreativitat.com>
 *
 * This program is dual-licensed under the GNU General Public License (GPL) v3.0 and a proprietary license.
 *
 * GPL-3.0 License:
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Proprietary License:
 * For commercial use, support, or if you prefer not to disclose your source code modifications,
 * please contact Kreativitat at <mail@kreativitat.com> for information on purchasing a proprietary license.
 *
 * For more information, visit <https://www.kreativitat.com>.
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/printsheet/doc/pdf_tcpdflabel.class.php';

/**
 * PDF generator for KreaProducts product labels.
 */
class KreaProductsProductLabelPdf extends pdf_tcpdflabel
{
	/**
	 * Draw a single product label.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $param       Label payload
	 * @return void
	 */
	public function addSticker(&$pdf, $outputlangs, $param)
	{
		if ($this->_COUNTX == 0 && $this->_COUNTY == 0 && $this->_First != 1) {
			$pdf->AddPage();
		}
		$this->_First = 0;

		$originX = $this->_Margin_Left + ($this->_COUNTX * ($this->_Width + $this->_X_Space));
		$originY = $this->_Margin_Top + ($this->_COUNTY * ($this->_Height + $this->_Y_Space));

		if (!empty($param['template_width_mm']) && !empty($param['template_height_mm'])) {
			$renderedFromSvg = false;
			if (!empty($param['template_svg']) && is_string($param['template_svg'])) {
				$renderedFromSvg = $this->renderTemplateStickerFromSvg($pdf, $param, $originX, $originY);
			}

			if (!$renderedFromSvg && !empty($param['template_blocks']) && is_array($param['template_blocks'])) {
				$this->renderTemplateSticker($pdf, $outputlangs, $param, $originX, $originY);
				$this->moveCursorToNextPosition();
				return;
			}

			if ($renderedFromSvg) {
				// TCPDF SVG parser may skip embedded <image> hrefs depending on format/source.
				// Always re-render image blocks natively to guarantee output parity for assets.
				$this->renderTemplateImageOverlay($pdf, $outputlangs, $param, $originX, $originY);
				$this->moveCursorToNextPosition();
				return;
			}
		}

		$paddingX = min(2.2, max(1.0, $this->_Width * 0.05));
		$paddingY = min(2.0, max(1.0, $this->_Height * 0.05));
		$contentX = $originX + $paddingX;
		$contentY = $originY + $paddingY;
		$contentWidth = max(8.0, $this->_Width - (2 * $paddingX));
		$contentHeight = max(8.0, $this->_Height - (2 * $paddingY));
		$isCompact = ($this->_Height <= 28);
		$lines = (!empty($param['lines']) && is_array($param['lines']) ? $param['lines'] : array());
		$hasBarcode = !empty($param['barcode_value']) && !empty($param['barcode_encoding']);
		$barcodeIs2d = !empty($param['barcode_is_2d']);

		$pdf->SetDrawColor(210, 210, 210);
		$pdf->Rect($originX, $originY, $this->_Width, $this->_Height);
		$pdf->SetDrawColor(0, 0, 0);

		$styleMap = array(
			'ref' => array('style' => 'B', 'size' => ($isCompact ? 8 : 10), 'lineheight' => ($isCompact ? 3.6 : 4.5)),
			'label' => array('style' => '', 'size' => ($isCompact ? 7 : 8.5), 'lineheight' => ($isCompact ? 3.1 : 3.8)),
			'price' => array('style' => 'B', 'size' => ($isCompact ? 7 : 8), 'lineheight' => ($isCompact ? 3.0 : 3.6)),
			'meta' => array('style' => '', 'size' => ($isCompact ? 6 : 7), 'lineheight' => ($isCompact ? 2.8 : 3.2)),
		);
		$lineGap = ($isCompact ? 0.1 : 0.2);
		$barcodeGap = ($hasBarcode ? ($isCompact ? 0.4 : 0.8) : 0.0);
		$barcodeBlockHeight = 0.0;
		if ($hasBarcode) {
			$barcodeBlockHeight = $barcodeIs2d ? min(16.0, max(8.5, $contentHeight * 0.45)) : min(14.0, max(7.0, $contentHeight * 0.34));
		}

		$fixedTextHeight = 0.0;
		$labelLineIndex = -1;
		foreach ($lines as $index => $line) {
			$type = (!empty($line['type']) && isset($styleMap[$line['type']]) ? $line['type'] : 'meta');
			if ($type === 'label' && $labelLineIndex < 0) {
				$labelLineIndex = $index;
				continue;
			}
			$fixedTextHeight += $styleMap[$type]['lineheight'] + $lineGap;
		}

		$availableTextHeight = max(4.0, $contentHeight - $barcodeBlockHeight - $barcodeGap);
		$labelMaxHeight = max(($isCompact ? 3.5 : 4.5), $availableTextHeight - $fixedTextHeight);

		$currentY = $contentY;
		foreach ($lines as $index => $line) {
			$text = trim((string) (!empty($line['text']) ? $line['text'] : ''));
			if ($text === '') {
				continue;
			}

			$type = (!empty($line['type']) && isset($styleMap[$line['type']]) ? $line['type'] : 'meta');
			$style = $styleMap[$type];
			$blockHeight = $style['lineheight'];
			if ($index === $labelLineIndex) {
				$blockHeight = $labelMaxHeight;
			}

			$pdf->SetFont('', $style['style'], $style['size']);
			$pdf->SetXY($contentX, $currentY);
			$pdf->MultiCell(
				$contentWidth,
				$style['lineheight'],
				$outputlangs->convToOutputCharset($text),
				0,
				'L',
				false,
				1,
				$contentX,
				$currentY,
				true,
				0,
				false,
				true,
				$blockHeight,
				'T',
				false
			);

			$currentY = max($currentY + $style['lineheight'], $pdf->GetY()) + $lineGap;
			if ($currentY >= ($contentY + $availableTextHeight)) {
				break;
			}
		}

		if ($hasBarcode) {
			$barcodeValue = (string) $param['barcode_value'];
			$barcodeEncoding = (string) $param['barcode_encoding'];
			$barcodeY = $originY + $this->_Height - $paddingY - $barcodeBlockHeight;
			$this->renderBarcode($pdf, $contentX, $barcodeY, $contentWidth, $barcodeBlockHeight, $barcodeValue, $barcodeEncoding, $barcodeIs2d, $isCompact, $outputlangs, true);
		}

		$this->moveCursorToNextPosition();
	}

	/**
	 * Render a template-driven sticker using the same blocks as the SVG preview.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $param       Template payload
	 * @param float     $originX     Label origin X
	 * @param float     $originY     Label origin Y
	 * @return void
	 */
	private function renderTemplateSticker(&$pdf, $outputlangs, $param, $originX, $originY)
	{
		$geometry = $this->computeTemplateRenderGeometry($param, $originX, $originY);
		$scale = $geometry['scale'];
		$renderWidth = $geometry['render_width'];
		$renderHeight = $geometry['render_height'];
		$offsetX = $geometry['offset_x'];
		$offsetY = $geometry['offset_y'];

		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetDrawColor(207, 214, 223);
		$pdf->Rect($offsetX, $offsetY, $renderWidth, $renderHeight, 'DF');
		$pdf->SetDrawColor(0, 0, 0);
		$pdf->SetTextColor(16, 24, 40);

		foreach ($param['template_blocks'] as $block) {
			if (empty($block['type'])) {
				continue;
			}

			if ($block['type'] === 'text') {
				$this->renderTemplateTextBlock($pdf, $outputlangs, $block, $offsetX, $offsetY, $scale);
			} elseif ($block['type'] === 'rect') {
				$this->renderTemplateRectBlock($pdf, $block, $offsetX, $offsetY, $scale);
			} elseif ($block['type'] === 'image') {
				$this->renderTemplateImageBlock($pdf, $outputlangs, $block, $offsetX, $offsetY, $scale);
			} elseif ($block['type'] === 'barcode') {
				$this->renderTemplateBarcodeBlock($pdf, $outputlangs, $block, $offsetX, $offsetY, $scale);
			}
		}

		$pdf->SetTextColor(0, 0, 0);
	}

	/**
	 * Render template page from inline SVG so PDF output matches preview exactly.
	 *
	 * @param TCPDF $pdf     PDF reference
	 * @param array $param   Template payload
	 * @param float $originX Label origin X
	 * @param float $originY Label origin Y
	 * @return bool
	 */
	private function renderTemplateStickerFromSvg(&$pdf, $param, $originX, $originY)
	{
		$svg = trim((string) (!empty($param['template_svg']) ? $param['template_svg'] : ''));
		if ($svg === '' || stripos($svg, '<svg') === false) {
			return false;
		}

		$geometry = $this->computeTemplateRenderGeometry($param, $originX, $originY);
		$renderWidth = $geometry['render_width'];
		$renderHeight = $geometry['render_height'];
		$offsetX = $geometry['offset_x'];
		$offsetY = $geometry['offset_y'];

		try {
			$pdf->ImageSVG('@' . $svg, $offsetX, $offsetY, $renderWidth, $renderHeight, '', '', '', 0, false);
			return true;
		} catch (Exception $e) {
			dol_syslog(__METHOD__ . ' fallback to block renderer: ' . $e->getMessage(), LOG_WARNING);
			return false;
		}
	}

	/**
	 * Compute common template render geometry inside current label slot.
	 *
	 * @param array $param   Template payload
	 * @param float $originX Label origin X
	 * @param float $originY Label origin Y
	 * @return array
	 */
	private function computeTemplateRenderGeometry($param, $originX, $originY)
	{
		$templateWidth = max(1.0, (float) (!empty($param['template_width_mm']) ? $param['template_width_mm'] : 1.0));
		$templateHeight = max(1.0, (float) (!empty($param['template_height_mm']) ? $param['template_height_mm'] : 1.0));
		$scale = min($this->_Width / $templateWidth, $this->_Height / $templateHeight);
		$renderWidth = $templateWidth * $scale;
		$renderHeight = $templateHeight * $scale;
		$offsetX = $originX + max(0, ($this->_Width - $renderWidth) / 2);
		$offsetY = $originY + max(0, ($this->_Height - $renderHeight) / 2);

		return array(
			'scale' => $scale,
			'render_width' => $renderWidth,
			'render_height' => $renderHeight,
			'offset_x' => $offsetX,
			'offset_y' => $offsetY,
		);
	}

	/**
	 * Overlay image blocks using native TCPDF image rendering.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $param       Template payload
	 * @param float     $originX     Label origin X
	 * @param float     $originY     Label origin Y
	 * @return void
	 */
	private function renderTemplateImageOverlay(&$pdf, $outputlangs, $param, $originX, $originY)
	{
		if (empty($param['template_blocks']) || !is_array($param['template_blocks'])) {
			return;
		}

		$geometry = $this->computeTemplateRenderGeometry($param, $originX, $originY);
		foreach ($param['template_blocks'] as $block) {
			if (!is_array($block) || empty($block['type']) || (string) $block['type'] !== 'image') {
				continue;
			}

			$this->renderTemplateImageBlock($pdf, $outputlangs, $block, $geometry['offset_x'], $geometry['offset_y'], $geometry['scale']);
		}
	}

	/**
	 * Render a text block from a template.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $block       Block definition
	 * @param float     $offsetX     Template offset X
	 * @param float     $offsetY     Template offset Y
	 * @param float     $scale       Template scale
	 * @return void
	 */
	private function renderTemplateTextBlock(&$pdf, $outputlangs, $block, $offsetX, $offsetY, $scale)
	{
		$x = $offsetX + ((float) (!empty($block['x_mm']) ? $block['x_mm'] : 0) * $scale);
		$y = $offsetY + ((float) (!empty($block['y_mm']) ? $block['y_mm'] : 0) * $scale);
		$w = max(0.8, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 0.8) * $scale);
		$h = max(0.8, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 0.8) * $scale);
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		$fontFamily = strtolower(!empty($style['font_family']) ? (string) $style['font_family'] : 'helvetica');
		if ($fontFamily !== 'helvetica' && $fontFamily !== 'times' && $fontFamily !== 'courier') {
			$fontFamily = 'helvetica';
		}
		$fontStyle = (!empty($style['font_weight']) && strtolower((string) $style['font_weight']) === 'bold' ? 'B' : '');
		$fontSizePt = max(4.0, ((float) (!empty($style['font_size_pt']) ? $style['font_size_pt'] : 7.0)) * $scale);
		$maxFontSizePtByHeight = max(2.8, (($h * 0.86) / 0.352778));
		if ($fontSizePt > $maxFontSizePtByHeight) {
			$fontSizePt = $maxFontSizePtByHeight;
		}
		$align = 'L';
		if (!empty($style['align'])) {
			$alignValue = strtolower((string) $style['align']);
			if ($alignValue === 'center') {
				$align = 'C';
			} elseif ($alignValue === 'right') {
				$align = 'R';
			}
		}
		$textValue = (string) (!empty($block['value']) ? $block['value'] : '');
		if (trim($textValue) === '') {
			return;
		}
		$autoFit = $this->parseTemplateBooleanFlag(!empty($style['auto_fit']) ? $style['auto_fit'] : false, false);
		$minFontSizePt = max(2.8, ((float) (!empty($style['min_font_size_pt']) ? $style['min_font_size_pt'] : 3.8)) * $scale);
		if ($autoFit) {
			for ($guard = 0; $guard < 32; $guard++) {
				$fontMmCandidate = max(1.0, $fontSizePt * 0.352778);
				$lineHeightCandidate = max(0.8, ($fontSizePt * 0.352778) * 1.16);
				if ($lineHeightCandidate > ($h * 0.95)) {
					$lineHeightCandidate = ($h * 0.95);
				}
				$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeightCandidate)));
				$candidateLines = $this->wrapTemplateTextLines($textValue, $fontMmCandidate, $w, 0, false);
				if (count($candidateLines) <= $maxLinesByHeight || $fontSizePt <= ($minFontSizePt + 0.01)) {
					break;
				}
				$fontSizePt = max($minFontSizePt, $fontSizePt - 0.25);
			}
		}
		$lineHeightMm = max(0.8, ($fontSizePt * 0.352778) * 1.16);
		if ($lineHeightMm > ($h * 0.95)) {
			$lineHeightMm = ($h * 0.95);
		}
		$fontMm = max(1.0, $fontSizePt * 0.352778);
		$shouldTruncate = $this->shouldTruncateTemplateTextBlock($block);
		if ($shouldTruncate) {
			$hardFloorMm = 0.85;
			for ($guard = 0; $guard < 48; $guard++) {
				$lineHeightCandidate = max(0.8, $fontMm * 1.16);
				$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeightCandidate)));
				$candidateLines = $this->wrapTemplateTextLines($textValue, $fontMm, $w, 0, false);
				if (count($candidateLines) <= $maxLinesByHeight || $fontMm <= ($hardFloorMm + 0.001)) {
					break;
				}
				$fontMm = max($hardFloorMm, $fontMm * 0.95);
				$fontSizePt = max(2.2, $fontMm / 0.352778);
			}

			$lineHeightMm = max(0.8, $fontMm * 1.16);
			if ($lineHeightMm > ($h * 0.95)) {
				$lineHeightMm = ($h * 0.95);
			}
			$maxLinesByHeight = max(1, (int) floor($h / max(1.0, $lineHeightMm)));
			$candidateLines = $this->wrapTemplateTextLines($textValue, $fontMm, $w, 0, false);
			if (count($candidateLines) > $maxLinesByHeight) {
				$shouldTruncate = false;
			}
		}
		$maxLines = ($shouldTruncate ? max(1, (int) floor($h / max(1.0, $lineHeightMm))) : 0);
		$lines = $this->wrapTemplateTextLines($textValue, $fontMm, $w, $maxLines, $shouldTruncate);
		$printText = $outputlangs->convToOutputCharset(implode("\n", $lines));

		$pdf->SetFont($fontFamily, $fontStyle, $fontSizePt);
		$pdf->setCellPaddings(0, 0, 0, 0);
		$pdf->SetXY($x, $y);
		$pdf->MultiCell(
			$w,
			$lineHeightMm,
			$printText,
			0,
			$align,
			false,
			1,
			$x,
			$y,
			true,
			0,
			false,
			false,
			($shouldTruncate ? $h : 0),
			'T',
			true
		);
	}

	/**
	 * Wrap text for one bounded template block using the same approximation as SVG preview.
	 *
	 * @param string $text       Source text
	 * @param float  $fontSizeMm Font size in mm
	 * @param float  $maxWidthMm Block width in mm
	 * @param int    $maxLines   Maximum lines (0 = unlimited)
	 * @param bool   $truncate   Add ellipsis when clipping
	 * @return array
	 */
	private function wrapTemplateTextLines($text, $fontSizeMm, $maxWidthMm, $maxLines, $truncate = true)
	{
		$text = trim((string) $text);
		if ($text === '') {
			return array('');
		}

		$estimatedCharsPerLine = max(4, (int) floor((float) $maxWidthMm / max(0.9, (float) $fontSizeMm * 0.5)));
		$wrapped = preg_split('/\r\n|\r|\n/', wordwrap($text, $estimatedCharsPerLine, "\n", true));
		if (!is_array($wrapped) || empty($wrapped)) {
			$wrapped = array($text);
		}

		$lines = array_values(array_filter(array_map('trim', $wrapped), 'strlen'));
		if (empty($lines)) {
			$lines = array($text);
		}

		$maxLines = (int) $maxLines;
		if ($maxLines > 0 && count($lines) > $maxLines) {
			$lines = array_slice($lines, 0, $maxLines);
			if ($truncate) {
				$lastIndex = count($lines) - 1;
				$lines[$lastIndex] = $this->truncateTextWithEllipsis($lines[$lastIndex], max(1, $estimatedCharsPerLine - 1));
			}
		}

		return $lines;
	}

	/**
	 * Tell if one template text block should be clipped to its declared height.
	 *
	 * @param array $block Block definition
	 * @return bool
	 */
	private function shouldTruncateTemplateTextBlock($block)
	{
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		if (array_key_exists('truncate', $style)) {
			return $this->parseTemplateBooleanFlag($style['truncate'], true);
		}

		$source = trim((string) (!empty($block['source']) ? $block['source'] : ''));
		if ($source === 'label.ingredients_section') {
			return false;
		}

		return true;
	}

	/**
	 * Parse a loose template boolean flag value.
	 *
	 * @param mixed $value   Raw value
	 * @param bool  $default Default value
	 * @return bool
	 */
	private function parseTemplateBooleanFlag($value, $default = false)
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return ((int) $value) !== 0;
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
				return true;
			}
			if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
				return false;
			}
		}

		return (bool) $default;
	}

	/**
	 * Truncate one line and append ellipsis.
	 *
	 * @param string $text  Source text
	 * @param int    $limit Character limit
	 * @return string
	 */
	private function truncateTextWithEllipsis($text, $limit)
	{
		$text = trim((string) $text);
		$limit = max(1, (int) $limit);
		if ($text === '') {
			return '';
		}

		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($text, 'UTF-8') <= $limit) {
				return $text;
			}
			return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
		}

		if (strlen($text) <= $limit) {
			return $text;
		}

		return rtrim(substr($text, 0, $limit)) . '...';
	}

	/**
	 * Render a rectangle block from a template.
	 *
	 * @param TCPDF $pdf     PDF reference
	 * @param array $block   Block definition
	 * @param float $offsetX Template offset X
	 * @param float $offsetY Template offset Y
	 * @param float $scale   Template scale
	 * @return void
	 */
	private function renderTemplateRectBlock(&$pdf, $block, $offsetX, $offsetY, $scale)
	{
		$x = $offsetX + ((float) (!empty($block['x_mm']) ? $block['x_mm'] : 0) * $scale);
		$y = $offsetY + ((float) (!empty($block['y_mm']) ? $block['y_mm'] : 0) * $scale);
		$w = max(0.5, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 0.5) * $scale);
		$h = max(0.5, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 0.5) * $scale);
		$style = (!empty($block['style']) && is_array($block['style']) ? $block['style'] : array());
		$strokeWidth = max(0.1, ((float) (!empty($style['stroke_width_mm']) ? $style['stroke_width_mm'] : 0.25)) * $scale);

		$pdf->SetLineWidth($strokeWidth);
		$pdf->Rect($x, $y, $w, $h);
		$pdf->SetLineWidth(0.2);
	}

	/**
	 * Render an image block from a template (real image or fallback placeholder).
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $block       Block definition
	 * @param float     $offsetX     Template offset X
	 * @param float     $offsetY     Template offset Y
	 * @param float     $scale       Template scale
	 * @return void
	 */
	private function renderTemplateImageBlock(&$pdf, $outputlangs, $block, $offsetX, $offsetY, $scale)
	{
		$x = $offsetX + ((float) (!empty($block['x_mm']) ? $block['x_mm'] : 0) * $scale);
		$y = $offsetY + ((float) (!empty($block['y_mm']) ? $block['y_mm'] : 0) * $scale);
		$w = max(1.0, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 1.0) * $scale);
		$h = max(1.0, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 1.0) * $scale);
		$fontSizePt = max(4.0, min(8.0, 5.6 * $scale));
		$text = (string) (!empty($block['value']) ? $block['value'] : '');
		$imagePath = $this->resolveTemplateImagePath($text);
		if ($imagePath !== '') {
			try {
				$pdf->Image($imagePath, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, false, false, false);
				return;
			} catch (Exception $e) {
				dol_syslog(__METHOD__ . ' image render fallback: ' . $e->getMessage(), LOG_WARNING);
			}
		}

		$pdf->SetFillColor(248, 250, 252);
		$pdf->SetDrawColor(152, 162, 179);
		$pdf->Rect($x, $y, $w, $h, 'DF');
		$pdf->SetDrawColor(203, 213, 225);
		$pdf->Line($x, $y, $x + $w, $y + $h);
		$pdf->Line($x + $w, $y, $x, $y + $h);
		$pdf->SetDrawColor(0, 0, 0);
		$pdf->SetTextColor(102, 112, 133);
		$pdf->SetFont('helvetica', '', $fontSizePt);
		$pdf->SetXY($x, $y + max(0, ($h / 2) - (($fontSizePt * 0.352778) / 2)));
		$pdf->MultiCell($w, max(1.2, ($fontSizePt * 0.352778)), $outputlangs->convToOutputCharset($text), 0, 'C', false, 1);
		$pdf->SetTextColor(16, 24, 40);
	}

	/**
	 * Resolve an image reference into a local path or remote URL for TCPDF.
	 *
	 * @param string $value Image reference
	 * @return string
	 */
	private function resolveTemplateImagePath($value)
	{
		global $conf;

		$value = trim((string) $value);
		if ($value === '' || strpos($value, '..') !== false) {
			return '';
		}
		if (preg_match('#^https?://#i', $value)) {
			return $value;
		}
		if ($value[0] === '/' && is_readable($value)) {
			return $value;
		}

		$relative = ltrim(str_replace('\\', '/', $value), '/');
		$entityId = (int) (!empty($conf->entity) ? $conf->entity : 1);
		$candidate = DOL_DATA_ROOT . '/kreaproducts/' . $entityId . '/labels/' . $relative;
		if (is_readable($candidate)) {
			return $candidate;
		}

		if (strpos($relative, 'templates/assets/') === 0) {
			$fileName = basename(substr($relative, strlen('templates/assets/')));
			$safeName = dol_sanitizeFileName($fileName);
			if ($safeName !== '' && $safeName === $fileName) {
				$bundledCandidate = dirname(__DIR__) . '/labels/' . $safeName;
				if (is_readable($bundledCandidate)) {
					return $bundledCandidate;
				}
			}
		}

		return '';
	}

	/**
	 * Render a barcode block from a template.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param Translate $outputlangs Output language
	 * @param array     $block       Block definition
	 * @param float     $offsetX     Template offset X
	 * @param float     $offsetY     Template offset Y
	 * @param float     $scale       Template scale
	 * @return void
	 */
	private function renderTemplateBarcodeBlock(&$pdf, $outputlangs, $block, $offsetX, $offsetY, $scale)
	{
		$x = $offsetX + ((float) (!empty($block['x_mm']) ? $block['x_mm'] : 0) * $scale);
		$y = $offsetY + ((float) (!empty($block['y_mm']) ? $block['y_mm'] : 0) * $scale);
		$w = max(1.2, (float) (!empty($block['w_mm']) ? $block['w_mm'] : 1.2) * $scale);
		$h = max(1.2, (float) (!empty($block['h_mm']) ? $block['h_mm'] : 1.2) * $scale);
		$value = (string) (!empty($block['value']) ? $block['value'] : '');
		$barcodeConfig = $this->mapTemplateBarcodeSymbology(!empty($block['symbology']) ? (string) $block['symbology'] : 'C128');

		if ($value === '') {
			return;
		}

		$this->renderBarcode($pdf, $x, $y, $w, $h, $value, $barcodeConfig['encoding'], $barcodeConfig['is2d'], ($h <= 9), $outputlangs, !empty($block['show_human_readable']));
	}

	/**
	 * Map template symbology to TCPDF barcode settings.
	 *
	 * @param string $symbology Barcode symbology
	 * @return array
	 */
	private function mapTemplateBarcodeSymbology($symbology)
	{
		$symbology = strtoupper(trim((string) $symbology));
		$map = array(
			'EAN13' => array('encoding' => 'EAN13', 'is2d' => false),
			'EAN8' => array('encoding' => 'EAN8', 'is2d' => false),
			'UPC' => array('encoding' => 'UPC-A', 'is2d' => false),
			'UPC-A' => array('encoding' => 'UPC-A', 'is2d' => false),
			'UPC-E' => array('encoding' => 'UPC-E', 'is2d' => false),
			'C39' => array('encoding' => 'C39', 'is2d' => false),
			'CODE39' => array('encoding' => 'C39', 'is2d' => false),
			'C128' => array('encoding' => 'C128', 'is2d' => false),
			'CODE128' => array('encoding' => 'C128', 'is2d' => false),
			'QRCODE' => array('encoding' => 'QRCODE,H', 'is2d' => true),
			'DATAMATRIX' => array('encoding' => 'DATAMATRIX', 'is2d' => true),
		);

		return (!empty($map[$symbology]) ? $map[$symbology] : $map['C128']);
	}

	/**
	 * Render the barcode block with fallback to Code128 if needed.
	 *
	 * @param TCPDF     $pdf         PDF reference
	 * @param float     $x           X position
	 * @param float     $y           Y position
	 * @param float     $w           Width
	 * @param float     $h           Height
	 * @param string    $value       Barcode value
	 * @param string    $encoding    Barcode type
	 * @param bool      $is2d        Use 2D barcode
	 * @param bool      $isCompact   Compact label mode
	 * @param Translate $outputlangs Output language
	 * @param bool      $showHumanReadable Show barcode text
	 * @return void
	 */
	private function renderBarcode(&$pdf, $x, $y, $w, $h, $value, $encoding, $is2d, $isCompact, $outputlangs, $showHumanReadable)
	{
		$style1d = array(
			'position' => '',
			'align' => 'C',
			'stretch' => false,
			'fitwidth' => true,
			'cellfitalign' => '',
			'border' => false,
			'hpadding' => 'auto',
			'vpadding' => 'auto',
			'fgcolor' => array(0, 0, 0),
			'bgcolor' => false,
			'text' => (bool) $showHumanReadable,
			'font' => 'helvetica',
			'fontsize' => ($isCompact ? 6 : 7),
			'stretchtext' => 4,
		);
		$style2d = array(
			'border' => false,
			'vpadding' => 'auto',
			'hpadding' => 'auto',
			'fgcolor' => array(0, 0, 0),
			'bgcolor' => false,
			'module_width' => 1,
			'module_height' => 1,
		);

		try {
			if ($is2d) {
				$size = min($w, $h);
				$x2d = $x + max(0, ($w - $size) / 2);
				$pdf->write2DBarcode($value, $encoding, $x2d, $y, $size, $h, $style2d, 'N');
				return;
			}

			$pdf->write1DBarcode($value, $encoding, $x, $y, $w, $h, 0.4, $style1d);
		} catch (Exception $e) {
			try {
				$pdf->write1DBarcode($value, 'C128', $x, $y, $w, $h, 0.4, $style1d);
			} catch (Exception $fallbackException) {
				$pdf->SetFont('', '', ($isCompact ? 6 : 7));
				$pdf->SetXY($x, $y + max(0, ($h / 2) - 1.5));
				$pdf->MultiCell($w, 3, $outputlangs->convToOutputCharset($value), 0, 'C', false, 1);
			}
		}
	}

	/**
	 * Move to next label position.
	 *
	 * @return void
	 */
	private function moveCursorToNextPosition()
	{
		$this->_COUNTY++;

		if ($this->_COUNTY == $this->_Y_Number) {
			$this->_COUNTX++;
			$this->_COUNTY = 0;
		}

		if ($this->_COUNTX == $this->_X_Number) {
			$this->_COUNTX = 0;
			$this->_COUNTY = 0;
		}
	}
}
