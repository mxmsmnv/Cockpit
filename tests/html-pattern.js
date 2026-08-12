'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'ProcessCockpit.module.php'), 'utf8');
const pattern = String.raw`[A-Za-z0-9._~\/\-]+`;

assert.equal(source.split(pattern).length - 1, 2, 'both Path inputs must use the browser-safe pattern');

const browserPattern = new RegExp(`^(?:${pattern})$`, 'v');
for (const valid of ['campaign', 'campaign/summer', 'print/catalog-2026', 'social.instagram', 'docs_v2~draft']) {
	assert.equal(browserPattern.test(valid), true, `expected valid path: ${valid}`);
}
for (const invalid of ['', 'campaign summer', 'campaign?source=email', 'campaign#top', 'café']) {
	assert.equal(browserPattern.test(invalid), false, `expected invalid path: ${invalid}`);
}

console.log('HTML pattern tests passed');
