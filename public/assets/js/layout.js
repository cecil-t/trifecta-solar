/*
 * Site layout editor (views/presales/layout.php). Draws arrays of panels on the satellite view and
 * posts the drawing as JSON (App\Layout::fromInput checks it).
 *
 * Map coordinates are meters around the layout's origin point: x east, y south, as on screen. The
 * SVG's viewBox is in those meters, so tiles, panels and measurements share one space. An array is
 * a grid turned to its azimuth: local x runs along the eave, local y up the slope, and the up-slope
 * size of panels and row gaps is shortened by cos(pitch), since that is how a sloped roof looks
 * from above. Trackers are drawn flat, with the circle their table sweeps.
 */
(() => {
	'use strict';
	const app = document.getElementById('layout-app');
	if (!app) {
		return;
	}
	const cfg = JSON.parse(app.dataset.config);
	const NS = 'http://www.w3.org/2000/svg';
	const IN = 0.0254;
	const EARTH = 156543.03392804097;
	const RAD = Math.PI / 180;
	const $ = (id) => document.getElementById(id);
	const svg = $('layout-svg');
	const mapEl = $('layout-map');
	const L = {
		base: $('layer-base'), tiles: $('layer-tiles'), roof: $('layer-roof'), arrays: $('layer-arrays'),
		measures: $('layer-measures'), draft: $('layer-draft'), handles: $('layer-handles'),
	};

	// ---------- State ----------
	const saved = cfg.layout;
	const S = {
		origin: saved ? saved.origin : cfg.origin,
		modules: {},   // key => {name, watts, length, width}, from the build sheet's module lines
		gap: saved ? saved.gap : cfg.gap,
		arrays: saved ? saved.arrays : [],
		measures: saved ? saved.measures : [],
		roof: saved ? saved.roof : null,
		view: saved && saved.view ? saved.view : { cx: 0, cy: 0, mpp: 0.06 },
		maxz: saved ? (saved.maxz || 21) : 21,
		tool: 'select',
		sel: null,     // selected array key
		selM: null,    // selected measurement index
		face: null,    // highlighted Google roof face index
		showRoof: !(saved && saved.roof_shown === false),
		draft: null,
	};
	const notes = [];
	const firstMod = cfg.modules[0].key;
	for (const m of cfg.modules) {
		const was = saved && saved.modules ? saved.modules[m.key] : null;
		S.modules[m.key] = { name: m.name, watts: m.watts, length: m.length || (was ? was.length : null), width: m.width || (was ? was.width : null) };
	}
	if (saved && !saved.modules && saved.module && !S.modules[firstMod].length && saved.module.length) {
		S.modules[firstMod].length = saved.module.length;   // a layout from before modules had keys
		S.modules[firstMod].width = saved.module.width;
	}
	for (const a of S.arrays) {
		if (!a.mod || !S.modules[a.mod]) {
			if (a.mod && saved.modules && saved.modules[a.mod]) {
				notes.push(saved.modules[a.mod].name + ' is no longer on the build sheet; ' + a.name + ' is drawn with ' + S.modules[firstMod].name + ' now.');
			} else if (saved && !saved.modules && saved.module && saved.module.name && saved.module.name !== S.modules[firstMod].name) {
				notes.push('The build sheet\'s module is now ' + S.modules[firstMod].name + ' (the layout was drawn with ' + saved.module.name + ').');
			}
			a.mod = firstMod;
		}
		a.face = a.face ?? null;
	}

	// ---------- Small helpers ----------
	const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
	const norm = (az) => ((az % 360) + 360) % 360;
	const el = (name, attrs, parent) => {
		const n = document.createElementNS(NS, name);
		for (const k in attrs) {
			n.setAttribute(k, attrs[k]);
		}
		if (parent) {
			parent.appendChild(n);
		}
		return n;
	};
	const clear = (g) => {
		while (g.firstChild) {
			g.removeChild(g.firstChild);
		}
	};
	const html = (tag, cls, text) => {
		const n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (text !== undefined) {
			n.textContent = text;
		}
		return n;
	};
	const button = (cls, text, title, fn) => {
		const b = html('button', cls, text);
		b.type = 'button';
		if (title) {
			b.title = title;
		}
		b.addEventListener('click', fn);
		return b;
	};
	const ftInUp = (m) => {   // rounded up, for material lengths
		const total = Math.ceil(m / IN - 1e-6);
		return Math.floor(total / 12) + '\' ' + (total % 12) + '"';
	};
	const ftIn = (m) => {
		const total = Math.floor(m / IN + 1e-6);   // round down: never more room than there is
		const ft = Math.floor(total / 12);
		return ft ? ft + '\' ' + (total % 12) + '"' : (total % 12) + '"';
	};
	const deg1 = (d) => (Math.round(d * 10) / 10) + '°';
	const over12 = (deg) => (Math.round(12 * Math.tan(deg * RAD) * 10) / 10) + '/12';
	const fmtPitch = (deg, mount) => {
		if (mount === 'tracker') {
			return 'tracker';
		}
		if (deg < 0.05) {
			return 'flat';
		}
		const x = 12 * Math.tan(deg * RAD);
		const half = Math.round(x * 2) / 2;
		if (mount !== 'ground' && Math.abs(x - half) < 0.06 && half <= 24) {
			return half + '/12';
		}
		return deg1(deg);
	};
	// "6/12" is a pitch; a plain number is degrees (no degree sign needed)
	const parsePitch = (raw) => {
		const t = String(raw).trim().toLowerCase().replace(/°|deg(rees)?/g, '').trim();
		if (t === '') {
			return null;
		}
		if (t === 'flat') {
			return 0;
		}
		const m = t.match(/^(\d+(?:\.\d+)?)\s*(?:\/|:|in)\s*12$/);
		const deg = m ? Math.atan(parseFloat(m[1]) / 12) / RAD : (/^\d+(\.\d+)?$/.test(t) ? parseFloat(t) : NaN);
		return isFinite(deg) && deg >= 0 && deg <= 75 ? Math.round(deg * 100) / 100 : null;
	};
	const pitchNote = (deg, mount) => {
		if (mount === 'tracker' || deg === null) {
			return '';
		}
		if (deg < 0.05) {
			return 'Flat (0°).';
		}
		const base = deg1(deg) + ' = ' + over12(deg) + '.';
		return mount === 'roof' && deg < 9.5 ? base + ' That is nearly flat. For a 6 in 12 roof, type 6/12.' : base;
	};
	const COMPASS = ['North', 'Northeast', 'East', 'Southeast', 'South', 'Southwest', 'West', 'Northwest'];
	const SHORT = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
	const compass = (az) => COMPASS[Math.round(norm(az) / 45) % 8];
	const short = (az) => SHORT[Math.round(norm(az) / 45) % 8];
	const PREFIX = { roof: 'Roof array', ground: 'Ground array', tracker: 'Tracker' };
	const isDefaultName = (name) => /^(Roof array|Ground array|Tracker) \d+$/.test(name);
	const nextName = (mount) => {
		const p = PREFIX[mount] || 'Array';
		let n = 0;
		for (const a of S.arrays) {
			const m = a.name.match(/^(.*) (\d+)$/);
			if (m && m[1] === p) {
				n = Math.max(n, parseInt(m[2], 10));
			}
		}
		return p + ' ' + (n + 1);
	};
	const newKey = () => {
		let k;
		do {
			k = Math.random().toString(36).slice(2, 10);
		} while (S.arrays.some((a) => a.key === k));
		return k;
	};

	// ---------- Map projection (Web Mercator, as the tiles) ----------
	const lat0 = S.origin.lat;
	const res = (z) => EARTH * Math.cos(lat0 * RAD) / Math.pow(2, z);   // meters per tile pixel
	const world = (lat, lon) => {   // pixels at zoom 0
		const s = Math.sin(clamp(lat, -85.05, 85.05) * RAD);
		return [(lon + 180) / 360 * 256, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * 256];
	};
	const W0 = world(S.origin.lat, S.origin.lon);
	const toLocal = (lat, lon) => {
		const w = world(lat, lon);
		return [(w[0] - W0[0]) * res(0), (w[1] - W0[1]) * res(0)];
	};
	const toLatLon = (x, y) => {
		const wx = W0[0] + x / res(0);
		const wy = W0[1] + y / res(0);
		return [Math.atan(Math.sinh(Math.PI * (1 - 2 * wy / 256))) / RAD, wx / 256 * 360 - 180];
	};

	// ---------- Array geometry ----------
	const modOf = (a) => S.modules[a.mod] || S.modules[firstMod];
	const sizeOk = (key) => {
		const m = S.modules[key || firstMod];
		return m && m.length >= 0.3 && m.width >= 0.3;
	};
	const newMod = () => $('new-mod').value || firstMod;
	const grid = (a) => {
		const m = modOf(a);
		const along = a.orient === 'landscape' ? m.length : m.width;
		const slope = a.orient === 'landscape' ? m.width : m.length;
		const c = Math.cos((a.mount === 'tracker' ? 0 : a.pitch) * RAD);
		const v = slope * c;
		return { u: along, v: v, su: along + S.gap.panel, sv: v + S.gap.row * c };
	};
	const toMap = (a, lx, ly) => {
		const t = a.az * RAD;
		return [a.x + lx * Math.cos(t) - ly * Math.sin(t), a.y + lx * Math.sin(t) + ly * Math.cos(t)];
	};
	const toArr = (a, x, y) => {
		const t = a.az * RAD;
		const dx = x - a.x;
		const dy = y - a.y;
		return [dx * Math.cos(t) + dy * Math.sin(t), -dx * Math.sin(t) + dy * Math.cos(t)];
	};
	const cellBounds = (a) => {
		let r0 = Infinity; let r1 = -Infinity; let c0 = Infinity; let c1 = -Infinity;
		for (const [r, c] of a.cells) {
			r0 = Math.min(r0, r); r1 = Math.max(r1, r);
			c0 = Math.min(c0, c); c1 = Math.max(c1, c);
		}
		return { r0, r1, c0, c1 };
	};
	const localBox = (a) => {   // the panels' outer box in array coordinates
		const b = cellBounds(a);
		const g = grid(a);
		return { x0: b.c0 * g.su, x1: b.c1 * g.su + g.u, y0: b.r0 * g.sv, y1: b.r1 * g.sv + g.v };
	};
	const centerOf = (a) => {
		const b = localBox(a);
		return { lx: (b.x0 + b.x1) / 2, ly: (b.y0 + b.y1) / 2, map: toMap(a, (b.x0 + b.x1) / 2, (b.y0 + b.y1) / 2) };
	};
	const corners = (a) => {
		const g = grid(a);
		const out = [];
		for (const [r, c] of a.cells) {
			const x = c * g.su;
			const y = r * g.sv;
			out.push(toMap(a, x, y), toMap(a, x + g.u, y), toMap(a, x + g.u, y + g.v), toMap(a, x, y + g.v));
		}
		return out;
	};
	const sweep = (a) => {
		const pts = corners(a);
		const xs = pts.map((p) => p[0]);
		const ys = pts.map((p) => p[1]);
		const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
		const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
		return { cx, cy, r: Math.max(...pts.map((p) => Math.hypot(p[0] - cx, p[1] - cy))) };
	};
	const hasCell = (a, r, c) => a.cells.some((x) => x[0] === r && x[1] === c);
	const panelCount = () => S.arrays.reduce((n, a) => n + a.cells.length, 0);
	// Outer edge length of an array's panels on the roof (true length up the slope), for critter
	// guard and the like: every panel side with no panel next to it
	const perimeter = (a) => {
		const m = modOf(a);
		const along = a.orient === 'landscape' ? m.length : m.width;
		const slope = a.orient === 'landscape' ? m.width : m.length;
		const has = new Set(a.cells.map(([r, c]) => r + ',' + c));
		let len = 0;
		for (const [r, c] of a.cells) {
			len += (has.has(r + ',' + (c - 1)) ? 0 : slope) + (has.has(r + ',' + (c + 1)) ? 0 : slope)
				+ (has.has((r - 1) + ',' + c) ? 0 : along) + (has.has((r + 1) + ',' + c) ? 0 : along);
		}
		return len;
	};
	const kwOf = (a) => a.cells.length * (modOf(a).watts || 0) / 1000;
	const inArray = (a, x, y) => {
		const b = localBox(a);
		const [lx, ly] = toArr(a, x, y);
		return lx >= b.x0 && lx <= b.x1 && ly >= b.y0 && ly <= b.y1;
	};
	// Keep the panels where they are on the map while the array turns about its center
	const turnTo = (a, az) => {
		const ctr = centerOf(a);
		a.az = norm(az);
		const t = a.az * RAD;
		a.x = ctr.map[0] - (ctr.lx * Math.cos(t) - ctr.ly * Math.sin(t));
		a.y = ctr.map[1] - (ctr.lx * Math.sin(t) + ctr.ly * Math.cos(t));
	};
	// The same with a change that resizes the grid (pitch, orientation)
	const keepCenter = (a, change) => {
		const before = centerOf(a).map;
		change();
		const after = centerOf(a).map;
		a.x += before[0] - after[0];
		a.y += before[1] - after[1];
	};

	// ---------- Google roof faces ----------
	const faces = () => (S.roof ? S.roof.segments : []);
	const liveFaces = () => (S.showRoof ? faces() : []);   // hidden faces don't snap or set slopes
	const faceShape = (s) => {
		if (s.hull && s.hull.length >= 3) {
			return s.hull.map((p) => toLocal(p[0], p[1]));
		}
		const [x1, y2] = toLocal(s.box[0], s.box[1]);
		const [x2, y1] = toLocal(s.box[2], s.box[3]);
		return [[x1, y1], [x2, y1], [x2, y2], [x1, y2]];
	};
	const polyArea = (pts) => Math.abs(pts.reduce((sum, p, i) => {
		const q = pts[(i + 1) % pts.length];
		return sum + p[0] * q[1] - q[0] * p[1];
	}, 0) / 2);
	const inPoly = (x, y, pts) => {
		let inside = false;
		for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
			const [xi, yi] = pts[i];
			const [xj, yj] = pts[j];
			if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) {
				inside = !inside;
			}
		}
		return inside;
	};
	const faceAt = (x, y) => {   // the smallest shown face holding the point, or null
		let best = null;
		liveFaces().forEach((s, i) => {
			const pts = faceShape(s);
			if (inPoly(x, y, pts)) {
				const area = polyArea(pts);
				if (!best || area < best.area) {
					best = { i, area };
				}
			}
		});
		return best ? best.i : null;
	};
	const faceText = (i) => {
		const s = faces()[i];
		return '#' + (i + 1) + ' ' + over12(s.pitch) + ' (' + deg1(s.pitch) + '), facing ' + short(s.azimuth) + ' ' + Math.round(s.azimuth) + '°';
	};

	// ---------- Measurements ----------
	// A measurement keeps where its slope came from (an array, a Google face or flat) and follows it
	const slopeOf = (m) => {
		const src = m.src || '';
		if (src.startsWith('arr:')) {
			const a = S.arrays.find((x) => x.key === src.slice(4) && x.mount !== 'tracker');
			if (a) {
				return { pitch: a.pitch, az: a.az, label: a.name + ' (' + fmtPitch(a.pitch, a.mount) + ')' };
			}
		} else if (src.startsWith('face:')) {
			const s = faces()[parseInt(src.slice(5), 10)];
			if (s) {
				return { pitch: s.pitch, az: s.azimuth, label: 'Google face #' + (parseInt(src.slice(5), 10) + 1) + ' (' + over12(s.pitch) + ')' };
			}
		} else if (src === 'typed') {
			// The slope runs along the line: measuring straight up (or down) the roof
			return { pitch: m.pitch || 0, az: norm(Math.atan2(-(m.x2 - m.x1), m.y2 - m.y1) / RAD), label: 'a typed ' + fmtPitch(m.pitch || 0, 'roof') + ' (up the line)' };
		} else if (src === 'flat') {
			return { pitch: 0, az: 0, label: 'flat' };
		}
		return m.pitch ? { pitch: m.pitch, az: m.az, label: fmtPitch(m.pitch, 'roof') + ' slope' } : { pitch: 0, az: 0, label: 'flat' };
	};
	const slopeSourceFor = (x1, y1, x2, y2) => {
		const order = S.arrays.slice().sort((a, b) => (b.key === S.sel) - (a.key === S.sel));
		for (const a of order) {
			if (a.mount !== 'tracker' && (inArray(a, x1, y1) || inArray(a, x2, y2))) {
				return 'arr:' + a.key;
			}
		}
		const f = faceAt((x1 + x2) / 2, (y1 + y2) / 2) ?? faceAt(x1, y1) ?? faceAt(x2, y2);
		return f === null ? 'flat' : 'face:' + f;
	};
	const settle = (m) => {   // store the slope too, so the saved layout has it
		if (m.src === 'typed') {
			return m;
		}
		const s = slopeOf(m);
		m.pitch = Math.round(s.pitch * 100) / 100;
		m.az = Math.round(s.az * 100) / 100;
		return m;
	};
	// Sloped when the roof's slope makes it at least half an inch longer than it looks from above
	const isSloped = (m) => trueLength(m) - Math.hypot(m.x2 - m.x1, m.y2 - m.y1) >= IN / 2;
	const slopeText = (m) => {
		const s = slopeOf(m);
		if (!s.pitch) {
			return 'flat';
		}
		return isSloped(m) ? 'up the slope of ' + s.label : 'flat: across the slope of ' + s.label;
	};
	const trueLength = (m) => {
		const s = slopeOf(m);
		const dx = m.x2 - m.x1;
		const dy = m.y2 - m.y1;
		if (!s.pitch) {
			return Math.hypot(dx, dy);
		}
		const t = s.az * RAD;
		const across = dx * Math.cos(t) + dy * Math.sin(t);
		const up = -dx * Math.sin(t) + dy * Math.cos(t);
		return Math.hypot(across, up / Math.cos(s.pitch * RAD));
	};

	// ---------- View ----------
	let vb = { x: 0, y: 0, w: 1, h: 1 };
	const updateView = () => {
		const r = svg.getBoundingClientRect();
		const w = Math.max(1, r.width);
		const h = Math.max(1, r.height);
		vb = { x: S.view.cx - w * S.view.mpp / 2, y: S.view.cy - h * S.view.mpp / 2, w: w * S.view.mpp, h: h * S.view.mpp };
		svg.setAttribute('viewBox', vb.x + ' ' + vb.y + ' ' + vb.w + ' ' + vb.h);
		drawTiles();
		drawLabels();
		drawMeasures();
		drawRoof();
		drawHandles();
		drawDraft();
		drawScale();
		attribution();
	};
	const toPoint = (e) => {
		const r = svg.getBoundingClientRect();
		return { x: vb.x + (e.clientX - r.left) * S.view.mpp, y: vb.y + (e.clientY - r.top) * S.view.mpp };
	};
	const zoomAt = (factor, px, py) => {
		const mpp = clamp(S.view.mpp * factor, 0.004, 4);
		const f = mpp / S.view.mpp;
		S.view.cx = px - (px - S.view.cx) * f;
		S.view.cy = py - (py - S.view.cy) * f;
		S.view.mpp = mpp;
		updateView();
	};
	const centerOn = (x, y) => {
		S.view.cx = x;
		S.view.cy = y;
		updateView();
	};
	const fit = () => {
		const pts = S.arrays.flatMap(corners);
		const r = svg.getBoundingClientRect();
		if (!pts.length) {
			S.view = { cx: 0, cy: 0, mpp: 60 / Math.max(300, r.width) };
		} else {
			const xs = pts.map((p) => p[0]);
			const ys = pts.map((p) => p[1]);
			const w = Math.max(...xs) - Math.min(...xs);
			const h = Math.max(...ys) - Math.min(...ys);
			S.view = { cx: (Math.max(...xs) + Math.min(...xs)) / 2, cy: (Math.max(...ys) + Math.min(...ys)) / 2,
				mpp: clamp(Math.max(w / r.width, h / r.height) * 1.4, 0.008, 4) };
		}
		updateView();
	};

	// ---------- Tiles ----------
	const tileSets = { base: { z: null, imgs: new Map() }, tiles: { z: null, imgs: new Map() } };
	const failed = new Set();
	let tileTimer = null;
	const tileLayer = (name, g, z) => {
		const set = tileSets[name];
		if (set.z !== z) {
			clear(g);
			set.imgs.clear();
			set.z = z;
		}
		const k = res(z);
		const n = Math.pow(2, z);
		const wx = W0[0] * n;
		const wy = W0[1] * n;
		const tx1 = Math.floor((wx + vb.x / k) / 256);
		const tx2 = Math.floor((wx + (vb.x + vb.w) / k) / 256);
		const ty1 = Math.floor((wy + vb.y / k) / 256);
		const ty2 = Math.floor((wy + (vb.y + vb.h) / k) / 256);
		if ((tx2 - tx1 + 1) * (ty2 - ty1 + 1) > 400) {
			return;
		}
		const want = new Set();
		for (let tx = tx1; tx <= tx2; tx++) {
			for (let ty = ty1; ty <= ty2; ty++) {
				if (tx < 0 || ty < 0 || tx >= n || ty >= n) {
					continue;
				}
				const key = z + '/' + tx + '/' + ty;
				want.add(key);
				if (set.imgs.has(key) || failed.has(key)) {
					continue;
				}
				const img = el('image', {
					x: (tx * 256 - wx) * k, y: (ty * 256 - wy) * k, width: 256 * k * 1.002, height: 256 * k * 1.002,
					preserveAspectRatio: 'none', class: 'tile',
				}, g);
				img.addEventListener('error', () => {
					failed.add(key);
					img.remove();
					set.imgs.delete(key);
					// No imagery this deep here: step the detail layer back a zoom level
					if (name === 'tiles' && z === S.maxz && z > 15) {
						S.maxz = z - 1;
						drawTiles();
					}
				});
				img.setAttribute('href', cfg.tiles + key);
				set.imgs.set(key, img);
			}
		}
		for (const [key, img] of set.imgs) {
			if (!want.has(key)) {
				img.remove();
				set.imgs.delete(key);
			}
		}
	};
	const drawTiles = () => {
		clearTimeout(tileTimer);
		tileTimer = setTimeout(() => {
			const dpr = window.devicePixelRatio || 1;
			const z = clamp(Math.ceil(Math.log2(res(0) / (S.view.mpp / dpr)) - 0.25), 3, S.maxz);
			tileLayer('base', L.base, clamp(z - 3, 3, 19));
			tileLayer('tiles', L.tiles, z);
		}, 30);
	};
	let attribTimer = null;
	const attribution = () => {
		clearTimeout(attribTimer);
		attribTimer = setTimeout(async () => {
			const [n, w] = toLatLon(vb.x, vb.y);
			const [s, e] = toLatLon(vb.x + vb.w, vb.y + vb.h);
			const z = clamp(Math.round(Math.log2(res(0) / S.view.mpp)), 3, 22);
			try {
				const r = await fetch(cfg.viewport + '?' + new URLSearchParams({ z, n, s, e, w }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
				const j = await r.json();
				if (j.ok) {
					$('layout-attrib').textContent = j.copyright || '';   // the Google Maps logo sits bottom left
					if (j.max_zoom && z >= 17) {
						const mz = clamp(j.max_zoom, 15, 22);
						if (mz !== S.maxz) {
							S.maxz = mz;
							drawTiles();
						}
					}
				}
			} catch (err) {
				// Attribution is best-effort; the map works without it
			}
		}, 600);
	};
	const drawScale = () => {
		const opts = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500, 1000];
		let ft = opts[0];
		for (const o of opts) {
			if (o * 0.3048 / S.view.mpp <= 120) {
				ft = o;
			}
		}
		$('layout-scale-bar').style.width = Math.round(ft * 0.3048 / S.view.mpp) + 'px';
		$('layout-scale-text').textContent = ft + ' ft';
	};

	// ---------- Drawing ----------
	const drawPanels = (a, g) => {
		const gr = grid(a);
		for (const [r, c] of a.cells) {
			el('rect', { x: c * gr.su, y: r * gr.sv, width: gr.u, height: gr.v, class: 'panel' }, g);
		}
	};
	const drawArrays = () => {
		clear(L.arrays);
		for (const a of S.arrays) {
			const g = el('g', { class: 'arr' + (a.key === S.sel ? ' is-sel' : '') + (a.mount === 'tracker' ? ' is-tracker' : ''),
				transform: 'translate(' + a.x + ' ' + a.y + ') rotate(' + a.az + ')' }, L.arrays);
			g.dataset.key = a.key;
			drawPanels(a, g);
		}
		for (const a of S.arrays) {
			if (a.mount === 'tracker' && a.cells.length) {
				const sw = sweep(a);
				el('circle', { cx: sw.cx, cy: sw.cy, r: sw.r, class: 'sweep' }, L.arrays);
			}
		}
		drawLabels();
		drawHandles();
		drawMeasures();
		drawSide();
	};
	let labelLayer = null;
	const drawLabels = () => {
		if (labelLayer) {
			labelLayer.remove();
		}
		labelLayer = el('g', { class: 'labels' }, L.arrays);
		const fs = 12 * S.view.mpp;
		for (const a of S.arrays) {
			if (!a.cells.length) {
				continue;
			}
			const c = centerOf(a).map;
			const t = el('text', { x: c[0], y: c[1] + fs * 0.35, 'font-size': fs, class: 'arr-label' }, labelLayer);
			t.textContent = a.name + ' (' + a.cells.length + ')' + (a.mount === 'tracker' ? '' : ' · ' + fmtPitch(a.pitch, a.mount));
		}
	};
	const drawHandles = () => {
		clear(L.handles);
		const a = S.arrays.find((x) => x.key === S.sel);
		if (!a || !a.cells.length) {
			return;
		}
		const b = localBox(a);
		const px = S.view.mpp;
		const g = el('g', { transform: 'translate(' + a.x + ' ' + a.y + ') rotate(' + a.az + ')' }, L.handles);
		const pad = 3 * px;
		el('rect', { x: b.x0 - pad, y: b.y0 - pad, width: b.x1 - b.x0 + 2 * pad, height: b.y1 - b.y0 + 2 * pad, class: 'sel-outline' }, g);
		const mx = (b.x0 + b.x1) / 2;
		// Which way the panels face: an arrow off the eave side (down the slope)
		if (a.mount !== 'tracker') {
			el('path', { d: 'M' + (mx - 7 * px) + ' ' + (b.y0 - 8 * px) + ' L' + (mx + 7 * px) + ' ' + (b.y0 - 8 * px) + ' L' + mx + ' ' + (b.y0 - 18 * px) + ' Z', class: 'face-arrow' }, g);
		}
		// Turn handle beyond the up-slope edge
		el('line', { x1: mx, y1: b.y1 + pad, x2: mx, y2: b.y1 + 26 * px, class: 'rot-line' }, g);
		const h = el('circle', { cx: mx, cy: b.y1 + 26 * px, r: 7 * px, class: 'rot-handle' }, g);
		h.dataset.handle = 'rotate';
	};
	const drawRoof = () => {
		clear(L.roof);
		$('btn-roof-toggle').hidden = !S.roof;
		$('btn-roof-toggle').textContent = S.showRoof ? 'Hide faces' : 'Show faces';
		if (!S.showRoof) {
			return;
		}
		const fs = 11 * S.view.mpp;
		faces().forEach((s, i) => {
			const pts = faceShape(s);
			const poly = el('polygon', { points: pts.map((p) => p[0] + ',' + p[1]).join(' '), class: 'roof-face' + (i === S.face ? ' is-sel' : '') + (S.face !== null && i !== S.face ? ' is-dim' : '') }, L.roof);
			poly.dataset.face = String(i);
			const cx = pts.reduce((t, p) => t + p[0], 0) / pts.length;
			const cy = pts.reduce((t, p) => t + p[1], 0) / pts.length;
			const t = el('text', { x: cx, y: cy, 'font-size': fs, class: 'roof-label' + (i === S.face ? ' is-sel' : '') }, L.roof);
			t.textContent = '#' + (i + 1) + ' ' + over12(s.pitch) + ' ' + short(s.azimuth);
		});
	};
	const drawMeasure = (m, g, i) => {
		const px = S.view.mpp;
		const grp = el('g', { class: 'm' + (isSloped(m) ? ' is-sloped' : ' is-flat') + (i === S.selM ? ' is-sel' : '') + (i === undefined ? ' is-draft' : '') }, g);
		if (i !== undefined) {
			grp.dataset.m = String(i);
			el('line', { x1: m.x1, y1: m.y1, x2: m.x2, y2: m.y2, class: 'measure-hit' }, grp);
		}
		el('line', { x1: m.x1, y1: m.y1, x2: m.x2, y2: m.y2, class: 'measure' }, grp);
		[[m.x1, m.y1], [m.x2, m.y2]].forEach(([x, y], k) => {
			const c = el('circle', { cx: x, cy: y, r: (i === S.selM ? 5 : 3) * px, class: 'measure-end' }, grp);
			if (i !== undefined) {
				c.dataset.end = String(k + 1);
			}
		});
		const t = el('text', { x: (m.x1 + m.x2) / 2, y: (m.y1 + m.y2) / 2 - 6 * px, 'font-size': 13 * px, class: 'measure-label' }, grp);
		t.textContent = ftIn(trueLength(m));
	};
	const drawMeasures = () => {
		clear(L.measures);
		S.measures.forEach((m, i) => drawMeasure(m, L.measures, i));
	};

	// The frame a new box is drawn in: a Google face under the start (its direction and pitch), else
	// lined up with the selected or last array of the same mount, else facing south
	const frameAt = (p) => {
		const mount = $('new-mount').value;
		const typed = parsePitch($('new-pitch').value);
		const f = mount === 'roof' ? faceAt(p.x, p.y) : null;
		if (f !== null) {
			const s = faces()[f];
			return { az: norm(s.azimuth), pitch: Math.round(s.pitch * 100) / 100, face: f, src: 'on Google roof face #' + (f + 1) };
		}
		const same = S.arrays.filter((x) => x.mount === mount);
		const ref = same.find((x) => x.key === S.sel) || same[same.length - 1];
		const pitch = mount === 'tracker' ? 0 : (typed === null ? 0 : typed);
		if (ref) {
			return { az: ref.az, pitch, face: null, src: 'lined up with ' + ref.name };
		}
		return { az: 180, pitch, face: null, src: 'facing south' };
	};
	const draftBox = (d) => {
		const t = d.frame.az * RAD;
		const dx = d.cursor.x - d.start.x;
		const dy = d.cursor.y - d.start.y;
		const lx = dx * Math.cos(t) + dy * Math.sin(t);
		const ly = -dx * Math.sin(t) + dy * Math.cos(t);
		const mount = $('new-mount').value;
		const a = { key: '', name: '', mount, orient: $('new-orient').value, pitch: mount === 'tracker' ? 0 : d.frame.pitch,
			az: d.frame.az, x: d.start.x, y: d.start.y, mod: newMod(), face: mount === 'tracker' ? null : d.frame.face,
			material: null, shade: null, shade_note: null, cells: [] };
		const g = grid(a);
		const cols = Math.floor((Math.abs(lx) + S.gap.panel + 1e-9) / g.su);
		const rows = Math.floor((Math.abs(ly) + g.sv - g.v + 1e-9) / g.sv);
		for (let r = 0; r < rows && r < 300; r++) {
			for (let c = 0; c < cols && c < 300; c++) {
				a.cells.push([ly < 0 ? -1 - r : r, lx < 0 ? -1 - c : c]);
			}
		}
		return { a, lx, ly };
	};
	const drawDraft = () => {
		clear(L.draft);
		const d = S.draft;
		if (!d || !d.cursor) {
			return;
		}
		if (d.tool === 'measure') {
			const m = settle({ x1: d.p1.x, y1: d.p1.y, x2: d.cursor.x, y2: d.cursor.y, src: slopeSourceFor(d.p1.x, d.p1.y, d.cursor.x, d.cursor.y) });
			drawMeasure(m, L.draft);
			const s = slopeOf(m);
			hint((d.waiting ? 'Click the end point. ' : '') + ftIn(trueLength(m)) + (s.pitch ? ' along ' + s.label : ' flat (no array or Google face under it)') + '.');
			return;
		}
		const r = draftBox(d);
		const g = el('g', { class: 'arr is-draft', transform: 'translate(' + d.start.x + ' ' + d.start.y + ') rotate(' + d.frame.az + ')' }, L.draft);
		el('rect', { x: Math.min(0, r.lx), y: Math.min(0, r.ly), width: Math.abs(r.lx), height: Math.abs(r.ly), class: 'draft-box' }, g);
		drawPanels(r.a, g);
		if (r.a.mount === 'tracker') {
			hint('Tracker of ' + r.a.cells.length + ' panels: ' + ftIn(Math.abs(r.lx)) + ' by ' + ftIn(Math.abs(r.ly)) + '.');
			return;
		}
		hint(r.a.cells.length + ' panels: ' + ftIn(Math.abs(r.lx)) + ' along the eave by ' + ftIn(Math.abs(r.ly) / Math.cos(r.a.pitch * RAD)) + ' up the slope, at '
			+ fmtPitch(r.a.pitch, r.a.mount) + ', facing ' + compass(r.a.az) + ' ' + Math.round(r.a.az) + '° (' + d.frame.src + ').');
	};

	// ---------- Side panel ----------
	const hint = (text) => {
		$('layout-hint').textContent = text;
	};
	const toolHint = () => {
		const h = {
			select: 'Click an array or measurement to select it. Drag it to move; drag the round handle to turn. Drag empty map (or right-drag anywhere) to pan.',
			draw: sizeOk(newMod()) ? 'Click and drag a box; it fills with panels. Start on a Google roof face to line up with it and take its pitch.' : 'Type the module length and width (right) before drawing.',
			panels: 'Click or drag over panels to remove them, or over empty spots beside an array to add them.',
			measure: 'Drag from one point to the other, or click both.',
		};
		hint(h[S.tool]);
	};
	const selectArray = (key, center) => {
		S.sel = key;
		S.selM = null;
		drawArrays();
		if (center && key) {
			const c = centerOf(S.arrays.find((x) => x.key === key)).map;
			centerOn(c[0], c[1]);
		}
	};
	const selectMeasure = (i) => {
		S.selM = i;
		S.sel = null;
		drawArrays();
	};
	const selectFace = (i, center) => {
		S.face = S.face === i && !center ? null : i;
		drawRoof();
		drawSide();
		if (center && i !== null) {
			const pts = faceShape(faces()[i]);
			centerOn(pts.reduce((t, p) => t + p[0], 0) / pts.length, pts.reduce((t, p) => t + p[1], 0) / pts.length);
		}
		if (S.face !== null) {
			hint('Google roof face ' + faceText(S.face) + '. The outline is the area Google would put panels on.');
		}
	};
	const drawSide = () => {
		// Totals: a number and its label never split across lines
		const n = panelCount();
		const kw = S.arrays.reduce((t, a) => t + kwOf(a), 0);
		$('layout-totals').textContent = n + '\u00a0panels · ' + kw.toFixed(2) + '\u00a0kW\u00a0DC · ' + S.arrays.length + '\u00a0' + (S.arrays.length === 1 ? 'array' : 'arrays');
		const mc = $('layout-modcounts');
		mc.replaceChildren();
		const roofEdge = S.arrays.filter((a) => a.mount === 'roof').reduce((t, a) => t + perimeter(a), 0);
		if (S.arrays.length) {
			mc.append(html('li', '', 'Array perimeters: ' + ftInUp(S.arrays.reduce((t, a) => t + perimeter(a), 0))
				+ (roofEdge && roofEdge < S.arrays.reduce((t, a) => t + perimeter(a), 0) ? ' (roof arrays ' + ftInUp(roofEdge) + ')' : '')));
		}
		const keys = Object.keys(S.modules);
		if (keys.length > 1) {
			for (const [k, m] of Object.entries(S.modules)) {
				mc.append(html('li', '', m.name + ': ' + S.arrays.filter((a) => a.mod === k).reduce((t, a) => t + a.cells.length, 0) + '\u00a0panels'));
			}
		}
		const note = [...notes];
		keys.filter((k) => !sizeOk(k)).forEach((k) => note.push(S.modules[k].name + ' has no size in the Sales catalog. Type its length and width below to draw it.'));
		$('layout-module-note').textContent = note.join(' ');
		$('layout-module-note').hidden = !note.length;

		const list = $('array-list');
		list.replaceChildren();
		for (const a of S.arrays) {
			const li = html('li', a.key === S.sel ? 'is-sel' : '');
			li.append(button('linklike', a.name, '', () => selectArray(a.key, true)), html('span', 'small muted', ' ' + a.cells.length + ' panels, '
				+ (a.mount === 'tracker' ? 'tracker' : fmtPitch(a.pitch, a.mount) + ', facing ' + short(a.az) + ' ' + Math.round(a.az) + '°')));
			list.append(li);
		}
		if (!S.arrays.length) {
			list.append(html('li', 'small muted', 'None yet. Use Draw array.'));
		}

		const a = S.arrays.find((x) => x.key === S.sel);
		$('sel-none').hidden = !!a;
		$('sel-fields').hidden = !a;
		if (a) {
			const active = document.activeElement;
			const set = (id, v) => {
				if ($(id) !== active) {
					$(id).value = v;
				}
			};
			set('sel-name', a.name);
			set('sel-mount', a.mount);
			set('sel-orient', a.orient);
			$('sel-mod-wrap').hidden = Object.keys(S.modules).length < 2;
			fillSelect($('sel-mod'), Object.entries(S.modules).map(([k, m]) => [k, m.name]), a.mod);
			$('sel-face-wrap').hidden = !faces().length || a.mount === 'tracker';
			fillSelect($('sel-face'), [['', 'None (typed pitch)'], ...faces().map((f, i) => [String(i), faceText(i)])], a.face === null || a.face === undefined ? '' : String(a.face));
			$('sel-material-wrap').hidden = a.mount !== 'roof';
			fillSelect($('sel-material'), [['', 'Not set'], ...Object.entries(cfg.materials)], a.material || '');
			set('sel-shade', a.shade === null || a.shade === undefined ? '' : String(a.shade));
			set('sel-shade-note', a.shade_note || '');
			set('sel-pitch', a.mount === 'tracker' ? '' : fmtPitch(a.pitch, a.mount) === 'flat' ? '0' : fmtPitch(a.pitch, a.mount).replace('°', ''));
			set('sel-az', a.mount === 'tracker' ? '' : String(Math.round(a.az * 10) / 10));
			$('sel-pitch').disabled = $('sel-az').disabled = $('sel-flip').disabled = a.mount === 'tracker';
			$('sel-pitch-label').textContent = a.mount === 'roof' ? 'Pitch' : 'Tilt';
			$('sel-pitch-note').textContent = pitchNote(a.pitch, a.mount);
			let stats = a.cells.length + ' panels, ' + kwOf(a).toFixed(2) + ' kW';
			stats += a.mount === 'tracker' ? '. Sweep: ' + ftIn(sweep(a).r * 2) + ' across.' : ', facing ' + compass(a.az) + '.';
			stats += ' Perimeter ' + ftInUp(perimeter(a)) + '.';
			$('sel-stats').textContent = stats;
		}

		const ml = $('measure-list');
		ml.replaceChildren();
		S.measures.forEach((m, i) => {
			const li = html('li', (i === S.selM ? 'is-sel ' : '') + (isSloped(m) ? 'm-sloped' : 'm-flat'));
			li.append(html('span', 'm-key'), button('linklike', ftIn(trueLength(m)), 'Select this measurement', () => selectMeasure(i)),
				html('span', 'small muted', ' ' + slopeText(m)),
				button('btn btn-ghost btn-small', '×', 'Remove this measurement', () => removeMeasure(i)));
			li.lastChild.setAttribute('aria-label', 'Remove this measurement');
			ml.append(li);
		});
		if (!S.measures.length) {
			ml.append(html('li', 'small muted', 'None. Use Measure.'));
		}
		const m = S.measures[S.selM];
		$('msel').hidden = !m;
		if (m) {
			const sel = $('msel-slope');
			sel.replaceChildren();
			const opt = (value, text) => {
				const o = html('option', '', text);
				o.value = value;
				sel.append(o);
			};
			opt('flat', 'Flat (no slope)');
			opt('typed', 'A pitch I type (slope up the line)');
			S.arrays.filter((x) => x.mount !== 'tracker').forEach((x) => opt('arr:' + x.key, 'Same as ' + x.name + ' (' + fmtPitch(x.pitch, x.mount) + ', facing ' + short(x.az) + ')'));
			faces().forEach((s, i) => opt('face:' + i, 'Google face ' + faceText(i)));
			sel.value = m.src && [...sel.options].some((o) => o.value === m.src) ? m.src : 'flat';
			$('msel-pitch-wrap').hidden = sel.value !== 'typed';
			if (document.activeElement !== $('msel-pitch')) {
				$('msel-pitch').value = m.src === 'typed' && m.pitch ? fmtPitch(m.pitch, 'roof').replace('°', '') : '';
			}
		}

		const rl = $('roof-list');
		rl.replaceChildren();
		if (S.roof) {
			$('roof-info').textContent = 'Google: ' + buildings().map((b) => b.quality.toLowerCase() + ' quality, imagery from ' + b.date).join('; ') + '. ' + faces().length
				+ ' roof faces. Click one to highlight it. Arrays drawn starting on a face line up with it and take its pitch.';
			faces().forEach((s, i) => {
				const li = html('li', i === S.face ? 'is-sel' : '');
				li.append(button('linklike', '#' + (i + 1), 'Highlight this face on the map', () => selectFace(i, true)),
					html('span', 'small', ' ' + over12(s.pitch) + ' (' + deg1(s.pitch) + '), facing ' + short(s.azimuth) + ' ' + Math.round(s.azimuth) + '°, '
						+ Math.round(s.area_m2 * 10.7639).toLocaleString() + ' sq ft '),
					button('btn btn-ghost btn-small', 'Use for new arrays', 'Put this pitch in the toolbar for the next arrays you draw', () => usePitch(i)));
				rl.append(li);
			});
		}
	};
	// A face's pitch into the toolbar, for arrays drawn next (an existing array picks its face in its own settings)
	const usePitch = (i) => {
		const s = faces()[i];
		$('new-pitch').value = fmtPitch(s.pitch, 'roof').replace('°', '');
		$('new-mount').value = 'roof';
		$('new-pitch').closest('label').hidden = false;
		hint('New arrays: ' + pitchNote(s.pitch, 'roof') + ' (Google face #' + (i + 1) + '). To set an existing array, select it and pick the face under "Pitch from Google roof face".');
	};
	function fillSelect(sel, opts, value) {
		if (document.activeElement === sel && sel.options.length === opts.length) {
			return;
		}
		sel.replaceChildren(...opts.map(([v, t]) => {
			const o = html('option', '', t);
			o.value = v;
			return o;
		}));
		sel.value = value;
	}
	const drawModules = () => {
		const box = $('module-list');
		box.replaceChildren();
		for (const [k, m] of Object.entries(S.modules)) {
			const wrap = html('div', 'layout-fields layout-module');
			const name = html('p', 'small span-2', m.name + (m.watts && !m.name.includes(String(Math.round(m.watts))) ? ', ' + Math.round(m.watts) + ' W' : ''));
			const field = (label, prop) => {
				const l = html('label', '', label + ' ');
				const inp = html('input');
				inp.type = 'text';
				inp.inputMode = 'numeric';
				inp.value = m[prop] ? Math.round(m[prop] * 1000) : '';
				inp.dataset.mod = k;
				inp.dataset.prop = prop;
				inp.addEventListener('change', onModuleSize);
				l.append(inp);
				return l;
			};
			wrap.append(name, field('Length (mm)', 'length'), field('Width (mm)', 'width'));
			box.append(wrap);
		}
		const multi = Object.keys(S.modules).length > 1;
		$('new-mod-wrap').hidden = !multi;
		fillSelect($('new-mod'), Object.entries(S.modules).map(([k, m]) => [k, m.name]), $('new-mod').value || firstMod);
	};

	// ---------- History and saving ----------
	const snapshot = () => JSON.stringify({ arrays: S.arrays, measures: S.measures, modules: S.modules, gap: S.gap });
	const past = [snapshot()];
	const future = [];
	function serialize() {
		S.measures.forEach(settle);
		return { origin: S.origin, module: S.modules[firstMod], modules: S.modules, gap: S.gap, view: S.view, maxz: S.maxz, arrays: S.arrays, measures: S.measures, roof: S.roof, roof_shown: S.showRoof };
	}
	// What needs saving: the drawing, not where the map was moved to
	const drawingState = () => JSON.stringify({ modules: S.modules, gap: S.gap, arrays: S.arrays, measures: S.measures, shown: S.showRoof });
	const savedState = cfg.unsaved ? '' : drawingState();   // after a failed save the drawing is still unsaved
	let dirty = false;
	function markDirty() {
		dirty = drawingState() !== savedState;
		$('layout-dirty').hidden = !dirty;
	}
	const commit = () => {
		const snap = snapshot();
		if (snap !== past[past.length - 1]) {
			past.push(snap);
			if (past.length > 200) {
				past.shift();
			}
			future.length = 0;
		}
		updateUndo();
		markDirty();
	};
	const restore = (snap) => {
		const s = JSON.parse(snap);
		S.arrays = s.arrays;
		S.measures = s.measures;
		S.modules = s.modules;
		S.gap = s.gap;
		if (!S.arrays.some((a) => a.key === S.sel)) {
			S.sel = null;
		}
		if (S.selM !== null && !S.measures[S.selM]) {
			S.selM = null;
		}
		syncModuleFields();
		drawModules();
		drawArrays();
		updateUndo();
		markDirty();
	};
	const undo = () => {
		if (past.length > 1) {
			future.push(past.pop());
			restore(past[past.length - 1]);
		}
	};
	const redo = () => {
		if (future.length) {
			past.push(future.pop());
			restore(past[past.length - 1]);
		}
	};
	const updateUndo = () => {
		$('btn-undo').disabled = past.length <= 1;
		$('btn-redo').disabled = !future.length;
	};
	window.addEventListener('beforeunload', (e) => {
		if (dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
	$('layout-form').addEventListener('submit', (e) => {
		// Array names must differ: stop here (nothing lost) rather than at the server
		const seen = new Map();
		for (const a of S.arrays) {
			const k = a.name.trim().replace(/\s+/g, ' ').toLowerCase();
			if (seen.has(k)) {
				e.preventDefault();
				selectArray(a.key, true);
				$('sel-name').focus();
				hint('Two arrays are called ' + a.name + '. Rename this one, then save.');
				return;
			}
			seen.set(k, true);
		}
		$('layout-json').value = JSON.stringify(serialize());
		dirty = false;
	});

	// ---------- Editing ----------
	const selected = () => S.arrays.find((x) => x.key === S.sel);
	const removeSelected = () => {
		const a = selected();
		if (a) {
			S.arrays = S.arrays.filter((x) => x !== a);
			S.sel = null;
			commit();
			drawArrays();
		}
	};
	const removeMeasure = (i) => {
		S.measures.splice(i, 1);
		S.selM = null;
		commit();
		drawArrays();
	};
	const duplicate = () => {
		const a = selected();
		if (!a) {
			return;
		}
		const b = JSON.parse(JSON.stringify(a));
		b.key = newKey();
		b.name = isDefaultName(a.name) ? nextName(a.mount) : uniqueName(a.name + ' copy');
		// Beside it along the eave, a foot past its far edge (trackers: clear of its sweep)
		const box = localBox(a);
		const step = a.mount === 'tracker' ? 2 * sweep(a).r + 1 : (box.x1 - box.x0) + 0.3048;
		const t = a.az * RAD;
		b.x += step * Math.cos(t);
		b.y += step * Math.sin(t);
		S.arrays.push(b);
		S.sel = b.key;
		S.selM = null;
		commit();
		drawArrays();
	};
	const onField = (id, fn, live) => {
		$(id).addEventListener(live ? 'input' : 'change', () => {
			const a = selected();
			if (a) {
				fn(a, $(id).value);
				drawArrays();
				if (!live) {
					commit();
				}
			}
		});
	};
	const nameTaken = (name, except) => S.arrays.some((x) => x !== except && x.name.toLowerCase() === name.toLowerCase());
	const uniqueName = (base, except) => {
		let name = base;
		for (let i = 2; nameTaken(name, except); i++) {
			name = base + ' ' + i;
		}
		return name;
	};
	onField('sel-name', (a, v) => { a.name = v.trim().slice(0, 60) || a.name; }, true);
	$('sel-name').addEventListener('change', () => {
		const a = selected();
		if (a && nameTaken(a.name, a)) {
			const want = a.name;
			a.name = uniqueName(want, a);
			$('sel-name').value = a.name;
			drawArrays();
			hint('Another array is already called ' + want + ', so this one is ' + a.name + '. Array names must be different.');
		}
		commit();
	});
	onField('sel-mount', (a, v) => keepCenter(a, () => {
		const rename = isDefaultName(a.name);
		a.mount = v;
		if (v === 'tracker') {
			a.pitch = 0;
		}
		if (rename) {
			a.name = '';
			a.name = nextName(v);
		}
	}));
	onField('sel-orient', (a, v) => keepCenter(a, () => { a.orient = v; }));
	onField('sel-pitch', (a, v) => {
		const p = parsePitch(v);
		if (p === null) {
			hint('Type a pitch like 6/12, or a plain number of degrees from 0 to 75.');
			return;
		}
		keepCenter(a, () => {
			a.pitch = p;
			a.face = null;
		});
		hint(a.name + ': ' + pitchNote(p, a.mount));
	});
	onField('sel-mod', (a, v) => keepCenter(a, () => { a.mod = v; }));
	onField('sel-face', (a, v) => {
		const f = v === '' ? null : parseInt(v, 10);
		a.face = f;
		if (f !== null && faces()[f]) {
			keepCenter(a, () => { a.pitch = Math.round(faces()[f].pitch * 100) / 100; });
			hint(a.name + ': ' + pitchNote(a.pitch, a.mount) + ' from Google face #' + (f + 1) + '.');
		}
	});
	onField('sel-material', (a, v) => { a.material = v || null; });
	onField('sel-shade', (a, v) => {
		const t = v.trim();
		const n = parseFloat(t);
		if (t === '') {
			a.shade = null;
		} else if (isFinite(n) && n >= 0 && n <= 90) {
			a.shade = Math.round(n * 10) / 10;
		} else {
			hint('Shading is the percent of a year\'s production lost to shade, 0 to 90.');
		}
	});
	onField('sel-shade-note', (a, v) => { a.shade_note = v.trim().slice(0, 200) || null; });
	onField('sel-az', (a, v) => {
		const az = parseFloat(v);
		if (isFinite(az)) {
			turnTo(a, az);
		}
	});
	$('sel-delete').addEventListener('click', removeSelected);
	$('sel-copy').addEventListener('click', duplicate);
	$('sel-flip').addEventListener('click', () => {
		const a = selected();
		if (a && a.mount !== 'tracker') {
			turnTo(a, a.az + 180);
			commit();
			drawArrays();
			hint(a.name + ' now faces ' + compass(a.az) + ' ' + Math.round(a.az) + '°.');
		}
	});
	$('msel-slope').addEventListener('change', () => {
		const m = S.measures[S.selM];
		if (m) {
			m.src = $('msel-slope').value;
			if (m.src === 'typed') {
				m.pitch = parsePitch($('new-pitch').value) || 26.57;
			}
			settle(m);
			commit();
			drawArrays();
		}
	});
	$('msel-pitch').addEventListener('change', () => {
		const m = S.measures[S.selM];
		const p = parsePitch($('msel-pitch').value);
		if (!m) {
			return;
		}
		if (p === null) {
			hint('Type a pitch like 6/12, or a plain number of degrees.');
			return;
		}
		m.src = 'typed';
		m.pitch = p;
		commit();
		drawArrays();
	});
	$('msel-delete').addEventListener('click', () => {
		if (S.selM !== null) {
			removeMeasure(S.selM);
		}
	});
	$('new-pitch').addEventListener('change', () => {
		const p = parsePitch($('new-pitch').value);
		hint(p === null ? 'Type a pitch like 6/12, or a plain number of degrees.' : 'New arrays: ' + pitchNote(p, 'roof') + ' Arrays started on a Google roof face take its pitch instead.');
	});

	const syncModuleFields = () => {
		$('gap-panel').value = Math.round(S.gap.panel / IN * 100) / 100;
		$('gap-row').value = Math.round(S.gap.row / IN * 100) / 100;
	};
	// Each array keeps its center while the panels resize
	const resize = (change) => {
		const before = S.arrays.map((a) => (a.cells.length ? centerOf(a).map : null));
		change();
		S.arrays.forEach((a, i) => {
			if (!before[i]) {
				return;
			}
			const after = centerOf(a).map;
			a.x += before[i][0] - after[0];
			a.y += before[i][1] - after[1];
		});
		commit();
		drawArrays();
		toolHint();
	};
	function onModuleSize(e) {
		const k = e.target.dataset.mod;
		const inputs = [...document.querySelectorAll('#module-list input')].filter((i) => i.dataset.mod === k);
		const l = parseFloat(inputs[0].value);
		const w = parseFloat(inputs[1].value);
		if (!(l >= 300 && l <= 4000 && w >= 300 && w <= 4000)) {
			hint('Module length and width are in millimeters, e.g. 1722 by 1134.');
			return;
		}
		resize(() => {
			S.modules[k] = { ...S.modules[k], length: Math.max(l, w) / 1000, width: Math.min(l, w) / 1000 };
		});
	}
	const onGap = () => {
		const gp = parseFloat($('gap-panel').value);
		const gr = parseFloat($('gap-row').value);
		if (!(gp >= 0 && gp <= 12 && gr >= 0 && gr <= 120)) {
			hint('Gaps are in inches: up to 12 between panels, 120 between rows.');
			return;
		}
		resize(() => {
			S.gap = { panel: gp * IN, row: gr * IN };
		});
	};
	['gap-panel', 'gap-row'].forEach((id) => $(id).addEventListener('change', onGap));

	// ---------- Tools ----------
	const setTool = (t) => {
		if (t === 'draw' && !sizeOk(newMod())) {
			hint('Type the module length and width (right) before drawing.');
			return;
		}
		S.tool = t;
		S.draft = null;
		drawDraft();
		document.querySelectorAll('[data-tool]').forEach((b) => {
			b.classList.toggle('is-active', b.dataset.tool === t);
			b.setAttribute('aria-pressed', b.dataset.tool === t ? 'true' : 'false');
		});
		svg.dataset.tool = t;
		toolHint();
	};
	document.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => setTool(b.dataset.tool)));

	// A panel spot under a point: an existing panel (selected array first), else an empty spot next
	// to an array's panels (to add one)
	const spotAt = (p, only) => {
		const order = (only ? [only] : S.arrays.slice().sort((a, b) => (b.key === S.sel) - (a.key === S.sel)));
		let add = null;
		for (const a of order) {
			const g = grid(a);
			const [lx, ly] = toArr(a, p.x, p.y);
			const c = Math.floor(lx / g.su);
			const r = Math.floor(ly / g.sv);
			if (hasCell(a, r, c)) {
				return { a, r, c, has: true };
			}
			if (!add && a.cells.some(([rr, cc]) => Math.abs(rr - r) <= 1 && Math.abs(cc - c) <= 1)) {
				add = { a, r, c, has: false };
			}
		}
		return add;
	};
	const finishMeasure = (p) => {
		const d = S.draft;
		const m = settle({ x1: d.p1.x, y1: d.p1.y, x2: p.x, y2: p.y, src: slopeSourceFor(d.p1.x, d.p1.y, p.x, p.y) });
		S.draft = null;
		if (Math.hypot(m.x2 - m.x1, m.y2 - m.y1) > 0.02) {
			S.measures.push(m);
			S.selM = S.measures.length - 1;
			S.sel = null;
			commit();
			drawArrays();
			const s = slopeOf(m);
			hint(ftIn(trueLength(m)) + (s.pitch ? ' along ' + s.label : ' flat') + '. Change its slope in the list on the right if needed.');
		}
		drawDraft();
	};

	let drag = null;
	let spaceDown = false;
	svg.addEventListener('pointerdown', (e) => {
		svg.focus({ preventScroll: true });
		const p = toPoint(e);
		const target = e.target;
		const pan = () => {
			drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, cx: S.view.cx, cy: S.view.cy, moved: false };
		};
		if (e.button === 1 || e.button === 2 || spaceDown) {
			pan();
		} else if (target.closest('[data-handle]') && selected()) {
			// The turn handle works with any tool
			const a = selected();
			drag = { kind: 'rotate', a, center: centerOf(a).map };
		} else if (S.tool === 'select') {
			const handle = target.closest('[data-handle]');
			const g = target.closest('.arr');
			const mg = target.closest('[data-m]');
			const face = target.closest('[data-face]');
			if (handle && selected()) {
				const a = selected();
				drag = { kind: 'rotate', a, center: centerOf(a).map };
			} else if (mg) {
				const i = parseInt(mg.dataset.m, 10);
				if (S.selM !== i) {
					selectMeasure(i);
				}
				const m = S.measures[i];
				const end = target.closest('[data-end]');
				drag = { kind: end ? 'mend' : 'mmove', m, end: end ? end.dataset.end : null, sx: p.x, sy: p.y, o: { ...m }, moved: false };
			} else if (g && g.dataset.key) {
				const a = S.arrays.find((x) => x.key === g.dataset.key);
				if (S.sel !== a.key) {
					selectArray(a.key);
				}
				drag = { kind: 'move', a, sx: p.x, sy: p.y, ax: a.x, ay: a.y, moved: false };
			} else {
				pan();
				drag.deselect = true;
				drag.face = face ? parseInt(face.dataset.face, 10) : null;
			}
		} else if (S.tool === 'draw') {
			if (!sizeOk(newMod())) {
				hint('Type the module length and width (right) before drawing.');
				return;
			}
			S.draft = { tool: 'draw', start: p, cursor: p, frame: frameAt(p) };
			drag = { kind: 'draw' };
			drawDraft();
		} else if (S.tool === 'panels') {
			const s = spotAt(p);
			if (s) {
				drag = { kind: 'paint', a: s.a, mode: s.has ? 'remove' : 'add', changed: false };
				paint(s);
			}
		} else if (S.tool === 'measure') {
			if (S.draft && S.draft.waiting) {
				finishMeasure(p);
				return;
			}
			S.draft = { tool: 'measure', p1: p, cursor: p };
			drag = { kind: 'measure', sx: e.clientX, sy: e.clientY };
			drawDraft();
		}
		if (drag) {
			svg.setPointerCapture(e.pointerId);
		}
	});
	const paint = (s) => {
		const a = s.a;
		if (drag.mode === 'remove' && s.has) {
			a.cells = a.cells.filter(([r, c]) => !(r === s.r && c === s.c));
			drag.changed = true;
		} else if (drag.mode === 'add' && !s.has) {
			a.cells.push([s.r, s.c]);
			drag.changed = true;
		}
		if (!a.cells.length) {
			S.arrays = S.arrays.filter((x) => x !== a);
			if (S.sel === a.key) {
				S.sel = null;
			}
		}
		drawArrays();
	};
	svg.addEventListener('pointermove', (e) => {
		const p = toPoint(e);
		if (S.draft) {
			S.draft.cursor = p;
			drawDraft();
		}
		if (!drag) {
			return;
		}
		if (drag.kind === 'pan') {
			const dx = e.clientX - drag.sx;
			const dy = e.clientY - drag.sy;
			if (Math.abs(dx) + Math.abs(dy) > 3) {
				drag.moved = true;
			}
			S.view.cx = drag.cx - dx * S.view.mpp;
			S.view.cy = drag.cy - dy * S.view.mpp;
			updateView();
		} else if (drag.kind === 'move') {
			drag.a.x = drag.ax + (p.x - drag.sx);
			drag.a.y = drag.ay + (p.y - drag.sy);
			drag.moved = true;
			drawArrays();
		} else if (drag.kind === 'mmove' || drag.kind === 'mend') {
			const dx = p.x - drag.sx;
			const dy = p.y - drag.sy;
			const m = drag.m;
			if (drag.kind === 'mmove' || drag.end === '1') {
				m.x1 = drag.o.x1 + dx;
				m.y1 = drag.o.y1 + dy;
			}
			if (drag.kind === 'mmove' || drag.end === '2') {
				m.x2 = drag.o.x2 + dx;
				m.y2 = drag.o.y2 + dy;
			}
			drag.moved = true;
			drawMeasures();
			hint(ftIn(trueLength(m)) + (slopeOf(m).pitch ? ' along ' + slopeOf(m).label : ' flat') + '.');
		} else if (drag.kind === 'rotate') {
			// The handle sits up the slope, at azimuth + 90 degrees on screen
			let az = Math.atan2(p.y - drag.center[1], p.x - drag.center[0]) / RAD - 90;
			const stepDeg = e.shiftKey ? 5 : 0.5;
			az = Math.round(az / stepDeg) * stepDeg;
			turnTo(drag.a, az);
			drawArrays();
			hint('Facing ' + compass(drag.a.az) + ' ' + (Math.round(drag.a.az * 10) / 10) + '°. Hold Shift for 5° steps.');
		} else if (drag.kind === 'paint') {
			const s = spotAt(p, drag.a);
			if (s && (drag.mode === 'remove') === s.has && S.arrays.includes(drag.a)) {
				paint(s);
			}
		}
	});
	const endDrag = (e) => {
		if (!drag) {
			return;
		}
		const d = drag;
		drag = null;
		if (d.kind === 'pan') {
			if (d.deselect && !d.moved) {
				if (d.face !== null && d.face !== undefined) {
					selectFace(d.face, false);
				} else if (S.face !== null) {
					S.face = null;
					drawRoof();
					drawSide();
				}
				if (S.sel || S.selM !== null) {
					S.sel = null;
					S.selM = null;
					drawArrays();
				}
			}
		} else if (d.kind === 'draw') {
			const r = S.draft ? draftBox(S.draft) : null;
			S.draft = null;
			drawDraft();
			if (r && r.a.cells.length) {
				r.a.key = newKey();
				r.a.name = nextName(r.a.mount);
				S.arrays.push(r.a);
				S.sel = r.a.key;
				S.selM = null;
				commit();
				drawArrays();
				hint(r.a.name + ': ' + r.a.cells.length + ' panels' + (r.a.mount === 'tracker' ? '.' : ' at ' + fmtPitch(r.a.pitch, r.a.mount)
					+ ', facing ' + compass(r.a.az) + ' ' + Math.round(r.a.az) + '°. If it faces the wrong way, click Flip facing.'));
			} else {
				toolHint();
			}
		} else if (d.kind === 'measure') {
			const moved = e && Math.hypot(e.clientX - d.sx, e.clientY - d.sy) > 5;
			if (moved && S.draft) {
				finishMeasure(toPoint(e));
			} else if (S.draft) {
				S.draft.waiting = true;   // a click: the next click ends it
				drawDraft();
			}
		} else if ((d.kind === 'move' && d.moved) || d.kind === 'rotate' || (d.kind === 'paint' && d.changed) || ((d.kind === 'mmove' || d.kind === 'mend') && d.moved)) {
			if (d.kind === 'mmove' || d.kind === 'mend') {
				settle(d.m);
				drawArrays();
			}
			commit();
			if (d.kind === 'rotate') {
				toolHint();
			}
		}
	};
	svg.addEventListener('pointerup', endDrag);
	svg.addEventListener('pointercancel', () => {
		drag = null;
	});
	svg.addEventListener('wheel', (e) => {
		e.preventDefault();
		const p = toPoint(e);
		const delta = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
		zoomAt(Math.exp(clamp(delta, -300, 300) * 0.0015), p.x, p.y);
	}, { passive: false });
	svg.addEventListener('contextmenu', (e) => e.preventDefault());

	document.addEventListener('keydown', (e) => {
		const t = e.target;
		if (t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA')) {
			return;
		}
		const k = e.key;
		const mod = e.ctrlKey || e.metaKey;
		if (mod && (k === 'z' || k === 'Z')) {
			e.preventDefault();
			if (e.shiftKey) {
				redo();
			} else {
				undo();
			}
			return;
		}
		if (mod && (k === 'y' || k === 'Y')) {
			e.preventDefault();
			redo();
			return;
		}
		if (mod && (k === 'd' || k === 'D')) {
			e.preventDefault();
			duplicate();
			return;
		}
		if (mod || e.altKey) {
			return;
		}
		if (k === ' ') {
			e.preventDefault();
			spaceDown = true;
			svg.classList.add('is-panning');
			return;
		}
		const tools = { v: 'select', a: 'draw', p: 'panels', m: 'measure' };
		if (tools[k.toLowerCase()]) {
			setTool(tools[k.toLowerCase()]);
			return;
		}
		if (k === 'Escape') {
			if (S.draft) {
				S.draft = null;
				drawDraft();
				toolHint();
			} else if (S.sel || S.selM !== null) {
				S.sel = null;
				S.selM = null;
				drawArrays();
			}
			return;
		}
		if ((k === 'Delete' || k === 'Backspace') && S.selM !== null) {
			e.preventDefault();
			removeMeasure(S.selM);
			return;
		}
		const a = selected();
		if (!a) {
			return;
		}
		if (k === 'Delete' || k === 'Backspace') {
			e.preventDefault();
			removeSelected();
			return;
		}
		const step = e.shiftKey ? 0.3048 : IN;
		const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
		if (moves[k]) {
			e.preventDefault();
			a.x += moves[k][0];
			a.y += moves[k][1];
			drawArrays();
			commit();
			return;
		}
		if (k === '[' || k === ']' || k === '{' || k === '}') {
			turnTo(a, a.az + (k === '[' || k === '{' ? -1 : 1) * (e.shiftKey ? 5 : 0.5));
			drawArrays();
			commit();
		}
	});
	document.addEventListener('keyup', (e) => {
		if (e.key === ' ') {
			spaceDown = false;
			svg.classList.remove('is-panning');
		}
	});

	// ---------- Toolbar buttons ----------
	$('btn-undo').addEventListener('click', undo);
	$('btn-redo').addEventListener('click', redo);
	$('btn-fit').addEventListener('click', fit);
	$('btn-zoom-in').addEventListener('click', () => zoomAt(1 / 1.6, S.view.cx, S.view.cy));
	$('btn-zoom-out').addEventListener('click', () => zoomAt(1.6, S.view.cx, S.view.cy));
	$('new-mount').addEventListener('change', () => {
		$('new-pitch').closest('label').hidden = $('new-mount').value === 'tracker';
	});
	$('btn-roof-toggle').addEventListener('click', () => {
		S.showRoof = !S.showRoof;
		if (!S.showRoof) {
			S.face = null;
		}
		drawRoof();
		drawSide();
		markDirty();
		hint(S.showRoof ? 'Google roof faces shown. New boxes started on a face line up with it.' : 'Google roof faces hidden. New boxes don\'t line up with them while hidden.');
	});
	// Roof faces can come from several buildings; each lookup adds the building in the middle of the
	// map, or refreshes it when it is already there
	const buildings = () => {
		if (S.roof && !S.roof.buildings) {   // saved before buildings were kept apart
			S.roof.buildings = [{ lat: S.roof.lat, lon: S.roof.lon, quality: S.roof.quality, date: S.roof.date }];
		}
		return S.roof ? S.roof.buildings : [];
	};
	const buildingNear = (x, y, within) => buildings().findIndex((b) => {
		const [bx, by] = toLocal(b.lat, b.lon);
		return Math.hypot(bx - x, by - y) < within;
	});
	const fetchRoof = async (ask) => {
		if (ask && buildingNear(S.view.cx, S.view.cy, 25) >= 0 && !window.confirm(
			'Google roof faces for this building are already loaded. Look them up again? Each lookup is a call to Google\'s Solar API.')) {
			return;
		}
		const btn = $('btn-roof');
		const [lat, lon] = toLatLon(S.view.cx, S.view.cy);
		btn.disabled = true;
		$('roof-info').textContent = 'Asking Google about the building in the middle of the map...';
		try {
			const r = await fetch(cfg.roof + '?' + new URLSearchParams({ lat: lat.toFixed(7), lon: lon.toFixed(7) }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			const j = await r.json();
			if (j.ok) {
				const nb = j.roof;
				const [nx, ny] = toLocal(nb.lat, nb.lon);
				const tag = nb.lat.toFixed(6) + ',' + nb.lon.toFixed(6);
				const segs = nb.segments.map((x) => ({ ...x, bld: tag }));
				const entry = { lat: nb.lat, lon: nb.lon, quality: nb.quality, date: nb.date };
				if (!S.roof) {
					S.roof = { ...nb, segments: segs, buildings: [entry] };
				} else {
					const list = buildings();
					const fallback = list[0].lat.toFixed(6) + ',' + list[0].lon.toFixed(6);
					S.roof.segments.forEach((x) => { x.bld = x.bld || fallback; });
					const same = buildingNear(nx, ny, 5);
					if (same >= 0) {   // the same building again: its faces are replaced
						const old = list[same].lat.toFixed(6) + ',' + list[same].lon.toFixed(6);
						S.roof.segments = S.roof.segments.filter((x) => x.bld !== old);
						list.splice(same, 1);
					}
					S.roof.segments.push(...segs);
					list.push(entry);
					Object.assign(S.roof, { quality: nb.quality, date: nb.date, lat: nb.lat, lon: nb.lon });
				}
				S.face = null;
				S.measures.forEach((m) => {   // measurements that were flat may sit on a face now
					if (m.src === 'flat' || !m.src) {
						m.src = slopeSourceFor(m.x1, m.y1, m.x2, m.y2);
						settle(m);
					}
				});
				S.showRoof = true;
				drawRoof();
				drawArrays();
				markDirty();
				const [x, y] = toLocal(j.roof.lat, j.roof.lon);
				const off = Math.hypot(x - S.view.cx, y - S.view.cy);
				hint('Google found ' + segs.length + ' roof faces on this building' + (buildings().length > 1 ? ' (' + buildings().length + ' buildings loaded)' : '') + (off > 40 ? ' on a building ' + Math.round(off * 3.28084) + ' ft from the map center; check it is the right one' : '') + '.');
			} else {
				$('roof-info').textContent = j.error || 'Google had no roof data here.';
			}
		} catch (err) {
			$('roof-info').textContent = 'Could not reach the server. Try again.';
		}
		btn.disabled = false;
	};
	$('btn-roof').addEventListener('click', () => fetchRoof(true));

	// ---------- Start ----------
	if (typeof ResizeObserver !== 'undefined') {
		new ResizeObserver(() => updateView()).observe(mapEl);
	}
	svg.dataset.tool = 'select';
	syncModuleFields();
	drawModules();
	// Open where it was left, unless no array is in that view
	const r0 = svg.getBoundingClientRect();
	const inView = S.arrays.some((a) => {
		const c = centerOf(a).map;
		return Math.abs(c[0] - S.view.cx) < r0.width * S.view.mpp / 2 && Math.abs(c[1] - S.view.cy) < r0.height * S.view.mpp / 2;
	});
	if (!saved || !saved.view || (S.arrays.length && !inView)) {
		fit();
	} else {
		updateView();
	}
	drawArrays();
	updateUndo();
	toolHint();
	markDirty();
	// A new layout looks up Google's roof faces at the map point once (saved with the layout after that)
	if (cfg.autoRoof) {
		fetchRoof(false);
	}
})();
