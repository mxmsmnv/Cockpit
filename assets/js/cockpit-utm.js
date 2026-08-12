(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) module.exports = api;
	if (root) root.CockpitUtm = api;
}(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	var parameters = [
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_id',
		'utm_source_platform',
		'utm_term',
		'utm_content'
	];
	var required = ['utm_source', 'utm_medium', 'utm_campaign'];

	function url(value) {
		var parsed;
		try {
			parsed = new URL(String(value || '').trim());
		} catch (error) {
			throw new Error('Enter a valid absolute website URL.');
		}
		if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
			throw new Error('Website URL must use HTTP or HTTPS.');
		}
		return parsed;
	}

	function values(input) {
		var normalized = {};
		parameters.forEach(function (name) {
			normalized[name] = String((input && input[name]) || '').trim();
		});
		return normalized;
	}

	function validate(input) {
		var normalized = values(input);
		var active = parameters.some(function (name) { return normalized[name] !== ''; });
		var missing = active ? required.filter(function (name) { return normalized[name] === ''; }) : [];
		return {valid: missing.length === 0, active: active, missing: missing, values: normalized};
	}

	function parseUrl(input) {
		var parsed = url(input);
		var extracted = {};
		parameters.forEach(function (name) {
			extracted[name] = parsed.searchParams.get(name) || '';
			parsed.searchParams.delete(name);
		});
		return {websiteUrl: parsed.toString(), values: extracted};
	}

	function buildUrl(websiteUrl, input) {
		var parsed = url(websiteUrl);
		var normalized = values(input);
		parameters.forEach(function (name) {
			parsed.searchParams.delete(name);
			if (normalized[name] !== '') parsed.searchParams.append(name, normalized[name]);
		});
		return parsed.toString();
	}

	return {
		parameters: parameters.slice(),
		required: required.slice(),
		validate: validate,
		parseUrl: parseUrl,
		buildUrl: buildUrl
	};
}));
