/**
 * Shared text layout engine — JavaScript twin.
 *
 * Implements SPEC.md §6.3, step for step identical to src/Render/TextLayout.php.
 * The DOM preview in the editor is a preview (SPEC §2.3): it must agree with the
 * server on line counts and chosen font sizes, or the author is shown a card that is
 * not the card that ships.
 *
 * Anything that could differ between the two languages is pinned here:
 *   - the autofit search runs over integer step indices, never floats
 *   - integer division is explicit (Math.floor), not implied
 *   - grapheme segmentation uses Intl.Segmenter, matching PHP's grapheme_* functions
 *
 * The parity fixtures in tests/fixtures/layout-fixtures.json drive both engines
 * through the same table of advance widths, so a mismatch means the algorithms have
 * drifted rather than the rasterisers.
 */

export const SIZE_STEP = 0.5;
export const ELLIPSIS = '…';

const EMOJI_PATTERN =
	/[\u{1F000}-\u{1FAFF}\u{2190}-\u{2BFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{1F1E6}-\u{1F1FF}\u{200D}\u{20E3}]/gu;

const SCRIPT_RANGES = {
	latin: [[0x41, 0x5a], [0x61, 0x7a], [0xc0, 0x24f], [0x1e00, 0x1eff], [0x2c60, 0x2c7f], [0xa720, 0xa7ff]],
	greek: [[0x370, 0x3ff], [0x1f00, 0x1fff]],
	cyrillic: [[0x400, 0x4ff], [0x500, 0x52f]],
	hebrew: [[0x590, 0x5ff], [0xfb1d, 0xfb4f]],
	arabic: [[0x600, 0x6ff], [0x750, 0x77f], [0x8a0, 0x8ff], [0xfb50, 0xfdff], [0xfe70, 0xfeff]],
	devanagari: [[0x900, 0x97f], [0xa8e0, 0xa8ff]],
	ethiopic: [[0x1200, 0x137f], [0x1380, 0x139f], [0x2d80, 0x2ddf]],
	thai: [[0xe00, 0xe7f]],
	han: [[0x4e00, 0x9fff], [0x3400, 0x4dbf], [0xf900, 0xfaff]],
	kana: [[0x3040, 0x309f], [0x30a0, 0x30ff]],
	hangul: [[0xac00, 0xd7af], [0x1100, 0x11ff]],
};

const NEEDS_SHAPING = ['arabic', 'devanagari', 'thai'];
const RTL = ['arabic', 'hebrew'];

/** Raised when text needs shaping the environment cannot perform (SPEC §6.2). */
export class UnsupportedScriptError extends Error {
	constructor(script) {
		super(`Text in the ${script} script cannot be rendered: complex shaping is unavailable`);
		this.name = 'UnsupportedScriptError';
		this.script = script;
	}
}

/** Splits a string into grapheme clusters, matching PHP's grapheme_substr. */
export function graphemes(text) {
	if (text === '') {
		return [];
	}

	if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
		const segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
		return Array.from(segmenter.segment(text), (s) => s.segment);
	}

	return Array.from(text);
}

function scriptOf(codepoint) {
	for (const [script, ranges] of Object.entries(SCRIPT_RANGES)) {
		for (const [low, high] of ranges) {
			if (codepoint >= low && codepoint <= high) {
				return script;
			}
		}
	}
	return 'common';
}

/** Counts characters per script, ignoring punctuation and spaces. */
export function scriptCounts(text) {
	const counts = {};
	for (const character of Array.from(text)) {
		const script = scriptOf(character.codePointAt(0));
		if (script === 'common') {
			continue;
		}
		counts[script] = (counts[script] || 0) + 1;
	}
	return counts;
}

/** Returns the dominant script, matching Script::detect(). */
export function detectScript(text) {
	const counts = scriptCounts(text);
	const entries = Object.entries(counts);
	if (entries.length === 0) {
		return 'common';
	}
	// Highest count wins; ties keep insertion order, as PHP's arsort does.
	entries.sort((a, b) => b[1] - a[1]);
	return entries[0][0];
}

export function needsShaping(text) {
	return Object.keys(scriptCounts(text)).some((s) => NEEDS_SHAPING.includes(s));
}

export function isRtl(text) {
	return RTL.includes(detectScript(text));
}

export function reverseLogical(text) {
	return graphemes(text).reverse().join('');
}

/** Extra width from letter spacing, matching TextLayout::tracking_width(). */
export function trackingWidth(text, size, tracking) {
	if (tracking === 0 || text === '') {
		return 0;
	}
	const count = graphemes(text).length;
	return count > 1 ? (count - 1) * size * tracking : 0;
}

/**
 * Measures from a table of advance widths — the parity counterpart of
 * PHP's TableMetrics.
 */
export function tableMetrics(data) {
	const advances = new Map();
	for (const [codepoint, advance] of Object.entries(data.advances || {})) {
		advances.set(Number(codepoint), Number(advance));
	}
	const unitsPerEm = Number(data.unitsPerEm || 1000);
	const defaultAdvance = Number(data.defaultAdvance || 0);

	return {
		id: () => 'table',
		width(text, font, size, tracking = 0) {
			if (text === '') {
				return 0;
			}
			let units = 0;
			for (const character of Array.from(text)) {
				const codepoint = character.codePointAt(0);
				units += advances.has(codepoint) ? advances.get(codepoint) : defaultAdvance;
			}
			return (units / unitsPerEm) * size + trackingWidth(text, size, tracking);
		},
		vertical(font, size) {
			return {
				ascender: Number(data.ascenderRatio || 0.8) * size,
				descender: Number(data.descenderRatio || 0.2) * size,
			};
		},
	};
}

/**
 * Correction applied to canvas widths so the preview agrees with the server.
 *
 * Measured, not guessed. Across 240 samples spanning four faces and six sizes,
 * Imagick's textWidth came out a median 1.0045x the browser's measureText for the
 * same string — partly side bearing, partly Imagick quantising to whole pixels.
 * Uncorrected, the preview fits slightly MORE text per line than the real card does,
 * which is the wrong direction to be wrong in: an author would see a headline on two
 * lines and get three.
 *
 * So the measured bias is applied and the result rounded up, matching Imagick's own
 * quantisation and erring toward breaking earlier. Across the fixture set this lifts
 * agreement with the server from 89.5% to 94.1% of exact line sets. It cannot reach
 * 100% — two different rasterisers do not measure identically — which is exactly why
 * SPEC §2.3 has Generate throw the preview away and show the real render.
 *
 * Only canvas measurement is corrected. tableMetrics stays exact, because that is
 * what the PHP-versus-JavaScript parity test compares.
 *
 * Calibrated against Imagick, which is no longer shipped. GD measures a median
 * 0.9715x the font's exact advances, so against GD this constant errs further toward
 * breaking early — the safe direction, but it needs re-measuring against a browser.
 */
const CANVAS_WIDTH_CORRECTION = 1.005;

/** Measures with a browser canvas — the editor's real provider. */
export function canvasMetrics(canvas) {
	const context = (canvas || document.createElement('canvas')).getContext('2d');

	return {
		id: () => 'canvas',
		width(text, font, size, tracking = 0) {
			if (text === '') {
				return 0;
			}
			context.font = `${font.weight || 400} ${size}px '${font.family}'`;
			const measured = Math.ceil(context.measureText(text).width * CANVAS_WIDTH_CORRECTION);
			return measured + trackingWidth(text, size, tracking);
		},
		vertical(font, size) {
			context.font = `${font.weight || 400} ${size}px '${font.family}'`;
			const m = context.measureText('Hg');
			return {
				ascender: m.actualBoundingBoxAscent || size * 0.8,
				descender: m.actualBoundingBoxDescent || size * 0.2,
			};
		},
	};
}

function normalizeText(text, spec, keepEmoji) {
	let out = String(text)
		.replace(/\[\/?[^\]]*\]/g, '')
		.replace(/<[^>]*>/g, '');

	// Entity decoding, matching html_entity_decode for the entities that reach a card.
	const entities = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'", '&#39;': "'", '&nbsp;': ' ', '&hellip;': '…', '&mdash;': '—', '&ndash;': '–', '&rsquo;': '’', '&lsquo;': '‘', '&ldquo;': '“', '&rdquo;': '”' };
	out = out.replace(/&[a-z#0-9]+;/gi, (m) => (entities[m] !== undefined ? entities[m] : m));

	if (!keepEmoji) {
		out = out.replace(EMOJI_PATTERN, '');
	}

	out = out.replace(/[ ​]/g, ' ').replace(/\s+/gu, ' ').trim();

	if (spec.transform === 'uppercase') {
		out = out.toUpperCase();
	} else if (spec.transform === 'lowercase') {
		out = out.toLowerCase();
	}

	return out;
}

function wrap(text, spec, font, size, metrics) {
	const maxWidth = spec.box.w;
	const lines = [];
	let current = '';

	for (const word of text.split(' ')) {
		if (word === '') {
			continue;
		}

		const candidate = current === '' ? word : `${current} ${word}`;

		if (metrics.width(candidate, font, size, spec.tracking) <= maxWidth) {
			current = candidate;
			continue;
		}

		if (current !== '') {
			lines.push(current);
			current = '';
		}

		if (metrics.width(word, font, size, spec.tracking) > maxWidth) {
			const pieces = breakWord(word, spec, font, size, metrics);
			const last = pieces.pop();
			for (const piece of pieces) {
				lines.push(piece);
			}
			current = last === undefined ? '' : last;
			continue;
		}

		current = word;
	}

	if (current !== '') {
		lines.push(current);
	}

	return lines.length === 0 ? [''] : lines;
}

function breakWord(word, spec, font, size, metrics) {
	const maxWidth = spec.box.w;
	const pieces = [];
	let current = '';

	for (const grapheme of graphemes(word)) {
		const candidate = current + grapheme;
		if (current !== '' && metrics.width(candidate, font, size, spec.tracking) > maxWidth) {
			pieces.push(current);
			current = grapheme;
			continue;
		}
		current = candidate;
	}

	if (current !== '') {
		pieces.push(current);
	}

	return pieces.length === 0 ? [word] : pieces;
}

function fits(text, spec, font, size, metrics) {
	const lines = wrap(text, spec, font, size, metrics);
	if (lines.length > spec.maxLines) {
		return false;
	}
	return lines.length * size * spec.lineHeight <= spec.box.h;
}

function chooseSize(text, spec, font, metrics) {
	if (!spec.autofit || spec.sizeMax <= spec.sizeMin) {
		return spec.sizeMax <= spec.sizeMin ? spec.sizeMin : spec.sizeMax;
	}

	const steps = Math.floor((spec.sizeMax - spec.sizeMin) / SIZE_STEP);
	let low = 0;
	let high = steps;
	let best = -1;

	while (low <= high) {
		// Explicit floor: JavaScript division is float, PHP's intdiv is not.
		const mid = Math.floor((low + high) / 2);
		const size = spec.sizeMin + mid * SIZE_STEP;

		if (fits(text, spec, font, size, metrics)) {
			best = mid;
			low = mid + 1;
		} else {
			high = mid - 1;
		}
	}

	return best < 0 ? spec.sizeMin : spec.sizeMin + best * SIZE_STEP;
}

function applyOverflow(lines, spec, font, size, metrics) {
	const kept = lines.slice(0, Math.max(1, spec.maxLines));

	if (spec.overflow === 'clip') {
		return kept;
	}

	const last = kept.pop();
	const clusters = graphemes(last === undefined ? '' : last);

	while (clusters.length > 0) {
		const candidate = clusters.join('').replace(/\s+$/, '') + ELLIPSIS;
		if (metrics.width(candidate, font, size, spec.tracking) <= spec.box.w) {
			kept.push(candidate);
			return kept;
		}
		clusters.pop();
	}

	kept.push(ELLIPSIS);
	return kept;
}

/**
 * Lays out one text layer.
 *
 * @param {string} text    Resolved text, before normalisation.
 * @param {object} spec    Layout inputs: box, sizeMin, sizeMax, autofit, lineHeight,
 *                         tracking, align, verticalAlign, maxLines, overflow, transform.
 * @param {object} font    Font descriptor: family, weight.
 * @param {object} metrics A metrics provider.
 * @param {boolean} keepEmoji Whether the emoji policy keeps emoji.
 * @return {object} Layout result mirroring LayoutResult.
 */
export function layout(text, spec, font, metrics, keepEmoji = false) {
	const normalized = normalizeText(text, spec, keepEmoji);

	if (normalized === '') {
		return { size: 0, lines: [], collapsed: true, truncated: false, height: 0, rtl: false };
	}

	if (needsShaping(normalized)) {
		throw new UnsupportedScriptError(detectScript(normalized));
	}

	const rtl = isRtl(normalized);
	const size = chooseSize(normalized, spec, font, metrics);
	let lines = wrap(normalized, spec, font, size, metrics);
	let truncated = false;

	if (lines.length > spec.maxLines) {
		lines = applyOverflow(lines, spec, font, size, metrics);
		truncated = true;
	}

	if (rtl) {
		lines = lines.map(reverseLogical);
	}

	const leading = size * spec.lineHeight;
	const total = lines.length * leading;
	const vertical = metrics.vertical(font, size);

	let top = spec.box.y;
	if (spec.verticalAlign === 'middle') {
		top = spec.box.y + (spec.box.h - total) / 2;
	} else if (spec.verticalAlign === 'bottom') {
		top = spec.box.y + (spec.box.h - total);
	}

	const halfLeading = (leading - (vertical.ascender + vertical.descender)) / 2;

	const positioned = lines.map((line, index) => {
		const width = metrics.width(line, font, size, spec.tracking);
		let x = spec.box.x;
		if (spec.align === 'center') {
			x = spec.box.x + (spec.box.w - width) / 2;
		} else if (spec.align === 'right') {
			x = spec.box.x + (spec.box.w - width);
		}

		return {
			text: line,
			x,
			baseline: top + index * leading + halfLeading + vertical.ascender,
			width,
		};
	});

	return { size, lines: positioned, collapsed: false, truncated, height: total, rtl };
}

/** Normalises a fixture spec into the shape layout() expects. */
export function specFromFixture(raw) {
	return {
		box: raw.box,
		sizeMin: raw.sizeMin,
		sizeMax: raw.sizeMax,
		autofit: raw.autofit !== false,
		lineHeight: raw.lineHeight,
		tracking: raw.tracking || 0,
		align: raw.align || 'left',
		verticalAlign: raw.verticalAlign || 'top',
		maxLines: raw.maxLines,
		overflow: raw.overflow || 'ellipsis',
		transform: raw.transform || 'none',
	};
}
