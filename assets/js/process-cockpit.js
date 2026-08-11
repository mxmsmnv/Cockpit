(function () {
	'use strict';

	function init() {
		document.documentElement.classList.add('cockpit-admin-page');
		initLineCharts();
		initDonutCharts();
	}

	function pointsFrom(canvas) {
		try {
			var points = JSON.parse(canvas.getAttribute('data-points') || '[]');
			return Array.isArray(points) ? points : [];
		} catch (error) {
			return [];
		}
	}

	function chartColors(canvas) {
		var styles = window.getComputedStyle(canvas);
		var css = function (name, fallback) {
			var value = styles.getPropertyValue(name).trim();
			return value && value.indexOf('var(') === -1 ? value : fallback;
		};
		return {
			accent: css('--pw-main-color', 'currentColor'),
			text: css('--pw-text-color', 'currentColor'),
			muted: css('--pw-muted-color', 'currentColor'),
			grid: css('--pw-border-color', 'currentColor'),
			track: css('--pw-main-background', 'transparent'),
			danger: css('--pw-error-inline-text-color', 'currentColor'),
			success: css('--pw-alert-success', 'currentColor'),
			warning: css('--pw-alert-warning', 'currentColor')
		};
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
			if (!points.length) return;
			var tooltip = canvas.parentElement.querySelector('.cockpit-chart-tooltip');
			var draw = function (pointerX) { drawLineChart(canvas, points, tooltip, pointerX); };
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

	function drawLineChart(canvas, points, tooltip, pointerX) {
		var surface = setupCanvas(canvas);
		var ctx = surface.context;
		var width = surface.width;
		var height = surface.height;
		var colors = chartColors(canvas);
		var pad = {top: 24, right: 18, bottom: 44, left: 46};
		var plotWidth = width - pad.left - pad.right;
		var plotHeight = height - pad.top - pad.bottom;
		var max = Math.max.apply(null, [1].concat(points.map(function (point) { return Number(point.value) || 0; })));
		var xFor = function (index) { return pad.left + (points.length > 1 ? plotWidth * index / (points.length - 1) : plotWidth / 2); };
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
			var x = xFor(index);
			var y = yFor(point.value);
			if (index === 0) ctx.moveTo(x, y);
			else ctx.lineTo(x, y);
		});
		ctx.lineTo(xFor(points.length - 1), pad.top + plotHeight);
		ctx.lineTo(xFor(0), pad.top + plotHeight);
		ctx.closePath();
		ctx.fillStyle = gradient;
		ctx.fill();

		ctx.beginPath();
		points.forEach(function (point, index) {
			var x = xFor(index);
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
			var x = Math.max(0, Math.min(width - measured, xFor(index) - measured / 2));
			ctx.fillText(label, x, height - 12);
		});

		if (pointerX === null || pointerX === undefined) return;
		var selected = Math.max(0, Math.min(points.length - 1, Math.round((pointerX - pad.left) / Math.max(1, plotWidth) * (points.length - 1))));
		var point = points[selected];
		var pointX = xFor(selected);
		var pointY = yFor(point.value);
		ctx.fillStyle = colors.accent;
		ctx.beginPath();
		ctx.arc(pointX, pointY, 5, 0, Math.PI * 2);
		ctx.fill();
		showTooltip(tooltip, '<strong>' + escapeHtml(point.label) + '</strong><span>' + escapeHtml(point.value) + ' clicks</span>', pointX, Math.max(8, pointY - 54), width);
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
			var original = button.textContent;
			button.textContent = 'Copied';
			window.setTimeout(function () { button.textContent = original; }, 1400);
		});
	});

	document.addEventListener('submit', function (event) {
		if (!event.target.matches('[data-confirm-delete]')) return;
		if (!window.confirm('Delete this short link and all of its click statistics?')) event.preventDefault();
	});

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
}());
