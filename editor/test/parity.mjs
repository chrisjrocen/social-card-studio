/**
 * JavaScript side of the layout parity check.
 *
 * Runs every fixture through editor/src/layout.js and writes the results as JSON on
 * stdout. tests/Unit/LayoutParityTest.php runs the same fixtures through the PHP
 * engine and compares, per the acceptance criteria of M3 and SPEC §2.3.
 *
 * Usage: node editor/test/parity.mjs ../tests/fixtures/layout-fixtures.json
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import { layout, specFromFixture, tableMetrics } from '../src/layout.js';

const here = dirname(fileURLToPath(import.meta.url));
const fixturePath = process.argv[2]
	? resolve(process.cwd(), process.argv[2])
	: resolve(here, '../../tests/fixtures/layout-fixtures.json');

const fixtures = JSON.parse(readFileSync(fixturePath, 'utf8'));
const metrics = tableMetrics(fixtures.metrics);
const font = { family: fixtures.font.family, weight: fixtures.font.weight };

const results = {};

for (const testCase of fixtures.cases) {
	const spec = specFromFixture(testCase.spec);

	try {
		const result = layout(testCase.text, spec, font, metrics, false);

		results[testCase.id] = {
			size: result.size,
			lineCount: result.lines.length,
			lines: result.lines.map((l) => l.text),
			collapsed: result.collapsed,
			truncated: result.truncated,
		};
	} catch (error) {
		results[testCase.id] = { error: error.name };
	}
}

process.stdout.write(JSON.stringify(results, null, 1));
