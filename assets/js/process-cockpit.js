(function () {
	'use strict';

	function init() {
		document.documentElement.classList.add('cockpit-admin-page');
		initUtmBuilders();
		initLineCharts();
		initDonutCharts();
	}

	function initUtmBuilders() {
		if (!window.CockpitUtm) return;
		document.querySelectorAll('.Inputfield_utm_builder').forEach(function (builder) {
			var form = builder.closest('form');
			var target = form && form.querySelector('[name="target_url"]');
			var website = builder.querySelector('[name="utm_website_url"]');
			var preview = builder.querySelector('[data-utm-preview]');
			var message = builder.querySelector('[data-utm-message]');
			var apply = builder.querySelector('[data-utm-apply]');
			var copy = builder.querySelector('[data-utm-copy]');
			var clear = builder.querySelector('[data-utm-clear]');
			if (!form || !target || !website || !preview || !message || !apply || !copy || !clear) return;

			function inputs() {
				var result = {};
				window.CockpitUtm.parameters.forEach(function (name) {
					var input = builder.querySelector('[name="' + name + '"]');
					result[name] = input ? input.value : '';
				});
				return result;
			}

			function show(text, state) {
				message.textContent = text || '';
				message.setAttribute('data-state', state || '');
			}

			function render() {
				try {
					var result = window.CockpitUtm.validate(inputs());
					var generated = window.CockpitUtm.buildUrl(website.value, result.values);
					preview.textContent = generated;
					preview.setAttribute('title', generated);
					copy.disabled = false;
					if (!result.valid) {
						show('Source, medium, and campaign are required when UTM tags are used.', 'error');
						apply.disabled = true;
					} else {
						show(result.active ? 'Ready to apply to Target URL.' : 'Add campaign fields to build a tagged URL.', '');
						apply.disabled = false;
					}
					return generated;
				} catch (error) {
					preview.textContent = '—';
					preview.removeAttribute('title');
					copy.disabled = true;
					apply.disabled = true;
					show(error.message, website.value.trim() === '' ? '' : 'error');
					return '';
				}
			}

			function loadTarget() {
				if (target.value.trim() === '') {
					website.value = '';
					return render();
				}
				try {
					var parsed = window.CockpitUtm.parseUrl(target.value);
					website.value = parsed.websiteUrl;
					window.CockpitUtm.parameters.forEach(function (name) {
						var input = builder.querySelector('[name="' + name + '"]');
						if (input) input.value = parsed.values[name] || '';
					});
				} catch (error) {
					website.value = target.value;
				}
				render();
			}

			builder.addEventListener('input', function (event) {
				if (event.target.matches('[name="utm_website_url"], [name^="utm_"]')) render();
			});
			target.addEventListener('change', loadTarget);
			apply.addEventListener('click', function () {
				var generated = render();
				if (!generated || apply.disabled) return;
				target.value = generated;
				target.dispatchEvent(new Event('input', {bubbles: true}));
				show('Target URL updated. Save the link to publish this campaign URL.', 'success');
			});
			copy.addEventListener('click', function () {
				var generated = render();
				if (!generated || !navigator.clipboard) return;
				navigator.clipboard.writeText(generated).then(function () { show('Campaign URL copied.', 'success'); });
			});
			clear.addEventListener('click', function () {
				window.CockpitUtm.parameters.forEach(function (name) {
					var input = builder.querySelector('[name="' + name + '"]');
					if (input) input.value = '';
				});
				render();
			});

			loadTarget();
		});
	}

	function pointsFrom(canvas, attribute) {
		try {
			var points = JSON.parse(canvas.getAttribute(attribute || 'data-points') || '[]');
			return Array.isArray(points) ? points : [];
		} catch (error) {
			return [];
		}
	}

	function chartColors(canvas) {
		var styles = window.getComputedStyle(canvas);
		var names = ['--pw-main-color', '--pw-text-color', '--pw-muted-color', '--pw-border-color', '--pw-main-background', '--pw-error-inline-text-color', '--pw-alert-success', '--pw-alert-warning'];
		var signature = (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark|' : 'light|') + names.map(function (name) {
			return styles.getPropertyValue(name).trim();
		}).join('|');
		if (canvas._cockpitChartColors && canvas._cockpitChartColors.signature === signature) {
			return canvas._cockpitChartColors.colors;
		}
		var current = styles.color || '#111';
		var colors = {
			accent: resolveCssColor(canvas, '--pw-main-color', current),
			text: resolveCssColor(canvas, '--pw-text-color', current),
			muted: resolveCssColor(canvas, '--pw-muted-color', current),
			grid: resolveCssColor(canvas, '--pw-border-color', current),
			track: resolveCssColor(canvas, '--pw-main-background', 'transparent'),
			danger: resolveCssColor(canvas, '--pw-error-inline-text-color', current),
			success: resolveCssColor(canvas, '--pw-alert-success', current),
			warning: resolveCssColor(canvas, '--pw-alert-warning', current)
		};
		canvas._cockpitChartColors = {signature: signature, colors: colors};
		return colors;
	}

	function resolveCssColor(canvas, name, fallback) {
		var probe = document.createElement('span');
		probe.setAttribute('aria-hidden', 'true');
		probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;color:var(' + name + ', ' + fallback + ')';
		(canvas.parentElement || document.body).appendChild(probe);
		var resolved = window.getComputedStyle(probe).color;
		probe.remove();
		return resolved || fallback;
	}

	function chartPalette(canvas) {
		var colors = chartColors(canvas);
		return [colors.accent, colors.text, colors.muted, colors.danger, colors.success, colors.warning];
	}

	function setupCanvas(canvas) {
		var rect = canvas.getBoundingClientRect();
		var width = Math.max(1, Math.round(rect.width || canvas.parentElement.clientWidth || 640));
		var height = Math.max(1, Math.round(rect.height || canvas.parentElement.clientHeight || 320));
		var ratio = window.devicePixelRatio || 1;
		canvas.width = Math.round(width * ratio);
		canvas.height = Math.round(height * ratio);
		var context = canvas.getContext('2d');
		context.setTransform(ratio, 0, 0, ratio, 0, 0);
		context.clearRect(0, 0, width, height);
		return {context: context, width: width, height: height};
	}

	function initLineCharts() {
		document.querySelectorAll('.cockpit-line-chart').forEach(function (canvas) {
			var points = pointsFrom(canvas);
			var previousPoints = pointsFrom(canvas, 'data-previous-points');
			if (!points.length) return;
			var tooltip = canvas.parentElement.querySelector('.cockpit-chart-tooltip');
			var draw = function (pointerX) { drawLineChart(canvas, points, previousPoints, tooltip, pointerX); };
			draw(null);
			canvas.addEventListener('mousemove', function (event) {
				draw(event.clientX - canvas.getBoundingClientRect().left);
			});
			canvas.addEventListener('mouseleave', function () {
				if (tooltip) tooltip.hidden = true;
				draw(null);
			});
			window.addEventListener('resize', function () { draw(null); });
		});
	}

	function drawLineChart(canvas, points, previousPoints, tooltip, pointerX) {
		var surface = setupCanvas(canvas);
		var ctx = surface.context;
		var width = surface.width;
		var height = surface.height;
		var colors = chartColors(canvas);
		var pad = {top: 24, right: 18, bottom: 44, left: 46};
		var plotWidth = width - pad.left - pad.right;
		var plotHeight = height - pad.top - pad.bottom;
		var max = Math.max.apply(null, [1].concat(points, previousPoints).map(function (point) { return Number(point.value) || 0; }));
		var xFor = function (index, length) { return pad.left + (length > 1 ? plotWidth * index / (length - 1) : plotWidth / 2); };
		var yFor = function (value) { return pad.top + plotHeight - (Number(value) || 0) / max * plotHeight; };

		ctx.font = '12px sans-serif';
		ctx.fillStyle = colors.text;
		ctx.strokeStyle = colors.grid;
		ctx.lineWidth = 1;
		for (var line = 0; line <= 4; line++) {
			var y = pad.top + plotHeight * line / 4;
			ctx.beginPath();
			ctx.moveTo(pad.left, y);
			ctx.lineTo(width - pad.right, y);
			ctx.stroke();
			var value = Math.round(max * (4 - line) / 4);
			ctx.fillText(String(value), 4, y + 4);
		}

		var gradient = ctx.createLinearGradient(0, pad.top, 0, pad.top + plotHeight);
		gradient.addColorStop(0, hexWithAlpha(colors.accent, .24));
		gradient.addColorStop(1, hexWithAlpha(colors.accent, 0));
		ctx.beginPath();
		points.forEach(function (point, index) {
			var x = xFor(index, points.length);
			var y = yFor(point.value);
			if (index === 0) ctx.moveTo(x, y);
			else ctx.lineTo(x, y);
		});
		ctx.lineTo(xFor(points.length - 1, points.length), pad.top + plotHeight);
		ctx.lineTo(xFor(0, points.length), pad.top + plotHeight);
		ctx.closePath();
		ctx.fillStyle = gradient;
		ctx.fill();

		if (previousPoints.length) {
			ctx.beginPath();
			previousPoints.forEach(function (point, index) {
				var x = xFor(index, previousPoints.length);
				var y = yFor(point.value);
				if (index === 0) ctx.moveTo(x, y);
				else ctx.lineTo(x, y);
			});
			ctx.strokeStyle = colors.muted;
			ctx.lineWidth = 2;
			ctx.setLineDash([7, 6]);
			ctx.stroke();
			ctx.setLineDash([]);
		}

		ctx.beginPath();
		points.forEach(function (point, index) {
			var x = xFor(index, points.length);
			var y = yFor(point.value);
			if (index === 0) ctx.moveTo(x, y);
			else ctx.lineTo(x, y);
		});
		ctx.strokeStyle = colors.accent;
		ctx.lineWidth = 3;
		ctx.lineJoin = 'round';
		ctx.lineCap = 'round';
		ctx.stroke();

		var labelIndexes = [0, Math.floor((points.length - 1) / 2), points.length - 1].filter(function (value, index, list) {
			return list.indexOf(value) === index;
		});
		ctx.fillStyle = colors.text;
		ctx.font = '11px sans-serif';
		labelIndexes.forEach(function (index) {
			var label = String(points[index].label || '');
			var measured = ctx.measureText(label).width;
			var x = Math.max(0, Math.min(width - measured, xFor(index, points.length) - measured / 2));
			ctx.fillText(label, x, height - 12);
		});

		if (pointerX === null || pointerX === undefined) return;
		var selected = Math.max(0, Math.min(points.length - 1, Math.round((pointerX - pad.left) / Math.max(1, plotWidth) * (points.length - 1))));
		var point = points[selected];
		var pointX = xFor(selected, points.length);
		var pointY = yFor(point.value);
		ctx.fillStyle = colors.accent;
		ctx.beginPath();
		ctx.arc(pointX, pointY, 5, 0, Math.PI * 2);
		ctx.fill();
		var previousIndex = previousPoints.length > 1 ? Math.round(selected / Math.max(1, points.length - 1) * (previousPoints.length - 1)) : 0;
		var previous = previousPoints[previousIndex];
		var tooltipHtml = '<strong>' + escapeHtml(point.label) + '</strong><span>' + escapeHtml(point.value) + ' clicks</span>';
		if (previous) tooltipHtml += '<span>' + escapeHtml(previous.label) + ': ' + escapeHtml(previous.value) + ' clicks</span>';
		showTooltip(tooltip, tooltipHtml, pointX, Math.max(8, pointY - (previous ? 72 : 54)), width);
	}

	function initDonutCharts() {
		document.querySelectorAll('.cockpit-donut-chart').forEach(function (canvas) {
			var points = pointsFrom(canvas);
			if (!points.length) return;
			var tooltip = canvas.parentElement.querySelector('.cockpit-chart-tooltip');
			var draw = function (event) { drawDonutChart(canvas, points, tooltip, event || null); };
			draw(null);
			canvas.addEventListener('mousemove', draw);
			canvas.addEventListener('mouseleave', function () {
				if (tooltip) tooltip.hidden = true;
				draw(null);
			});
			window.addEventListener('resize', function () { draw(null); });
			var palette = chartPalette(canvas);
			canvas.closest('.cockpit-donut-layout').querySelectorAll('[data-color-index]').forEach(function (swatch) {
				swatch.style.backgroundColor = palette[Number(swatch.getAttribute('data-color-index')) % palette.length];
			});
		});
	}

	function drawDonutChart(canvas, points, tooltip, event) {
		var surface = setupCanvas(canvas);
		var ctx = surface.context;
		var width = surface.width;
		var height = surface.height;
		var total = points.reduce(function (sum, point) { return sum + (Number(point.value) || 0); }, 0);
		var palette = chartPalette(canvas);
		var centerX = width / 2;
		var centerY = height / 2;
		var radius = Math.max(45, Math.min(width, height) * .38);
		var innerRadius = radius * .62;
		var start = -Math.PI / 2;
		var hovered = -1;
		var pointerAngle = null;
		var pointerDistance = null;
		if (event) {
			var rect = canvas.getBoundingClientRect();
			var x = event.clientX - rect.left - centerX;
			var y = event.clientY - rect.top - centerY;
			pointerDistance = Math.sqrt(x * x + y * y);
			pointerAngle = Math.atan2(y, x);
			if (pointerAngle < -Math.PI / 2) pointerAngle += Math.PI * 2;
		}
		points.forEach(function (point, index) {
			var portion = total > 0 ? (Number(point.value) || 0) / total : 0;
			var end = start + portion * Math.PI * 2;
			if (pointerAngle !== null && pointerDistance >= innerRadius && pointerDistance <= radius && pointerAngle >= start && pointerAngle < end) hovered = index;
			ctx.beginPath();
			ctx.arc(centerX, centerY, hovered === index ? radius + 4 : radius, start, end);
			ctx.arc(centerX, centerY, innerRadius, end, start, true);
			ctx.closePath();
			ctx.fillStyle = palette[index % palette.length];
			ctx.fill();
			start = end;
		});
		ctx.fillStyle = chartColors(canvas).text;
		ctx.textAlign = 'center';
		ctx.font = '700 24px sans-serif';
		ctx.fillText(compactNumber(total), centerX, centerY - 2);
		ctx.font = '12px sans-serif';
		ctx.fillText('total clicks', centerX, centerY + 20);
		if (hovered >= 0 && event) {
			var selected = points[hovered];
			var rect = canvas.getBoundingClientRect();
			showTooltip(tooltip, '<strong>' + escapeHtml(selected.label) + '</strong><span>' + escapeHtml(selected.value) + ' clicks</span>', event.clientX - rect.left + 12, event.clientY - rect.top - 18, width);
		}
	}

	function showTooltip(tooltip, html, left, top, width) {
		if (!tooltip) return;
		tooltip.hidden = false;
		tooltip.innerHTML = html;
		tooltip.style.left = Math.max(6, Math.min(width - tooltip.offsetWidth - 6, left)) + 'px';
		tooltip.style.top = Math.max(6, top) + 'px';
	}

	function compactNumber(value) {
		if (value >= 1000000) return (value / 1000000).toFixed(1).replace('.0', '') + 'm';
		if (value >= 1000) return (value / 1000).toFixed(1).replace('.0', '') + 'k';
		return String(value);
	}

	function hexWithAlpha(color, alpha) {
		if (/^#[0-9a-f]{6}$/i.test(color)) {
			return color + Math.round(alpha * 255).toString(16).padStart(2, '0');
		}
		return color;
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (character) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-copy]');
		if (!button) return;
		var value = button.getAttribute('data-copy');
		if (!value || !navigator.clipboard) return;
		navigator.clipboard.writeText(value).then(function () {
			var message = button.getAttribute('data-copy-success') || 'Copied to clipboard';
			button.classList.add('is-copied');
			showCopyToast(message);
			window.setTimeout(function () { button.classList.remove('is-copied'); }, 1400);
		});
	});

	function showCopyToast(message) {
		var toast = document.querySelector('.cockpit-copy-toast');
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'cockpit-copy-toast';
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			document.body.appendChild(toast);
		}
		window.clearTimeout(toast._cockpitTimer);
		toast.textContent = message;
		toast.classList.add('is-visible');
		toast._cockpitTimer = window.setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 1800);
	}

	document.addEventListener('submit', function (event) {
		if (!event.target.matches('[data-confirm-delete]')) return;
		if (!window.confirm('Delete this short link and all of its click statistics?')) event.preventDefault();
	});

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
}());
