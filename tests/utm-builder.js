'use strict';

const assert = require('node:assert/strict');
const utm = require('../assets/js/cockpit-utm.js');

const built = utm.buildUrl('https://example.com/landing?ref=print#details', {
	utm_source: 'newsletter',
	utm_medium: 'email',
	utm_campaign: 'summer sale',
	utm_content: 'hero-button'
});
assert.equal(
	built,
	'https://example.com/landing?ref=print&utm_source=newsletter&utm_medium=email&utm_campaign=summer+sale&utm_content=hero-button#details'
);

const replaced = utm.buildUrl('https://example.com/?utm_source=old&utm_source=duplicate&utm_medium=old&keep=1', {
	utm_source: 'instagram',
	utm_medium: 'social',
	utm_campaign: 'launch',
	utm_id: 'launch-01',
	utm_source_platform: 'meta'
});
assert.equal(
	replaced,
	'https://example.com/?keep=1&utm_source=instagram&utm_medium=social&utm_campaign=launch&utm_id=launch-01&utm_source_platform=meta'
);

const parsed = utm.parseUrl('https://example.com/page?keep=1&utm_source=google&utm_medium=cpc&utm_campaign=sale&utm_term=wine#buy');
assert.equal(parsed.websiteUrl, 'https://example.com/page?keep=1#buy');
assert.equal(parsed.values.utm_source, 'google');
assert.equal(parsed.values.utm_medium, 'cpc');
assert.equal(parsed.values.utm_campaign, 'sale');
assert.equal(parsed.values.utm_term, 'wine');

assert.deepEqual(utm.validate({utm_source: 'google'}).missing, ['utm_medium', 'utm_campaign']);
assert.equal(utm.validate({utm_source: 'google', utm_medium: 'cpc', utm_campaign: 'sale'}).valid, true);
assert.equal(utm.validate({}).valid, true);
assert.throws(() => utm.buildUrl('mailto:test@example.com', {}), /HTTP or HTTPS/);
assert.throws(() => utm.parseUrl('not a url'), /valid absolute website URL/);

console.log('UTM builder tests passed');
