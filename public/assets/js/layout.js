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
		module: { name: cfg.module.name, watts: cfg.module.watts, length: cfg.module.length, width: cfg.module.width },
		gap: saved ? saved.gap : cfg.gap,
		arrays: saved ? saved.arrays : [],
		measures: saved ? saved.measures : [],
		roof: saved ? saved.roof : null,
		view: saved && saved.view ? saved.view : { cx: 0, cy: 0, mpp: 0.06 },
		maxz: saved ? (saved.maxz || 21) : 21,
		tool: 'select',
		sel: null,
		draft: null,
	};
	const notes = [];
	if (saved && saved.module) {
		if (!S.module.length && saved.module.length) {
			S.module.length = saved.module.length;
			S.module.width = saved.module.width;
		}
		if (saved.module.name && saved.module.name !== S.module.name) {
			notes.push('The build sheet\'s module is now ' + S.module.name + ' (the layout was drawn with ' + saved.module.name + '). Panels are drawn at the new size.');
		}
	}

	// ---------- Small helpers ----------
	const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
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
	const ftIn = (m) => {
		const total = Math.floor(m / IN + 1e-6);   // round down: never more room than there is
		const ft = Math.floor(total / 12);
		return ft ? ft + '\' ' + (total % 12) + '"' : (total % 12) + '"';
	};
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
		return (Math.round(deg * 10) / 10) + '°';
	};
	const parsePitch = (raw) => {
		const t = String(raw).trim().toLowerCase().replace(/°|deg/g, '');
		if (t === '' ) {
			return null;
		}
		if (t === 'flat') {
			return 0;
		}
		const m = t.match(/^(\d+(?:\.\d+)?)\s*[/:]\s*12$/);
		const deg = m ? Math.atan(parseFloat(m[1]) / 12) / RAD : (/^\d+(\.\d+)?$/.test(t) ? parseFloat(t) : NaN);
		return isFinite(deg) && deg >= 0 && deg <= 75 ? Math.round(deg * 100) / 100 : null;
	};
	const COMPASS = ['North', 'Northeast', 'East', 'Southeast', 'South', 'Southwest', 'West', 'Northwest'];
	const SHORT = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
	const compass = (az) => COMPASS[Math.round((((az % 360) + 360) % 360) / 45) % 8];
	const short = (az) => SHORT[Math.round((((az % 360) + 360) % 360) / 45) % 8];
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
	const sizeOk = () => S.module.length >= 0.3 && S.module.width >= 0.3;
	const grid = (a) => {
		const m = S.module;
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
	// Keep the panels where they are on the map while the array turns about its center
	const turnTo = (a, az) => {
		const ctr = centerOf(a);
		a.az = ((az % 360) + 360) % 360;
		const t = a.az * RAD;
		a.x = ctr.map[0] - (ctr.lx * Math.cos(t) - ctr.ly * Math.sin(t));
		a.y = ctr.map[1] - (ctr.lx * Math.sin(t) + ctr.ly * Math.cos(t));
	};
	// The same with a change that resizes the grid (pitch, orientation, module, gaps)
	const keepCenter = (a, change) => {
		const before = centerOf(a).map;
		change();
		const after = centerOf(a).map;
		a.x += before[0] - after[0];
		a.y += before[1] - after[1];
	};

	// Google roof face under a point (smallest box that holds it)
	const roofFaceAt = (x, y) => {
		if (!S.roof) {
			return null;
		}
		let best = null;
		for (const s of S.roof.segments) {
			const [x1, y2] = toLocal(s.box[0], s.box[1]);
			const [x2, y1] = toLocal(s.box[2], s.box[3]);
			if (x >= x1 && x <= x2 && y >= y1 && y <= y2) {
				const area = (x2 - x1) * (y2 - y1);
				if (!best || area < best.area) {
					best = { s, area };
				}
			}
		}
		return best ? best.s : null;
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
		markDirty();
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
					$('layout-attrib').textContent = cfg.placeholder ? j.copyright : 'Imagery: Google' + (j.copyright ? ' · ' + j.copyright : '');
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
	const drawArrays = () => {
		clear(L.arrays);
		for (const a of S.arrays) {
			const g = el('g', { class: 'arr' + (a.key === S.sel ? ' is-sel' : '') + (a.mount === 'tracker' ? ' is-tracker' : ''),
				transform: 'translate(' + a.x + ' ' + a.y + ') rotate(' + a.az + ')' }, L.arrays);
			g.dataset.key = a.key;
			const gr = grid(a);
			for (const [r, c] of a.cells) {
				el('rect', { x: c * gr.su, y: r * gr.sv, width: gr.u, height: gr.v, class: 'panel' }, g);
			}
		}
		for (const a of S.arrays) {
			if (a.mount === 'tracker' && a.cells.length) {
				const sw = sweep(a);
				el('circle', { cx: sw.cx, cy: sw.cy, r: sw.r, class: 'sweep' }, L.arrays);
			}
		}
		drawLabels();
		drawHandles();
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
			t.textContent = a.name + ' · ' + a.cells.length;
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
		if (!S.roof) {
			return;
		}
		const fs = 11 * S.view.mpp;
		for (const s of S.roof.segments) {
			const [x1, y2] = toLocal(s.box[0], s.box[1]);
			const [x2, y1] = toLocal(s.box[2], s.box[3]);
			el('rect', { x: x1, y: y1, width: x2 - x1, height: y2 - y1, class: 'roof-box' }, L.roof);
			const t = el('text', { x: (x1 + x2) / 2, y: (y1 + y2) / 2, 'font-size': fs, class: 'roof-label' }, L.roof);
			t.textContent = fmtPitch(s.pitch, 'roof') + ' ' + short(s.azimuth);
		}
	};
	const measureSlope = (x1, y1, x2, y2) => {
		// The array under either end (the selected one first), else the selected array, else flat
		const order = S.arrays.slice().sort((a, b) => (b.key === S.sel) - (a.key === S.sel));
		for (const a of order) {
			if (a.mount === 'tracker') {
				continue;
			}
			const b = localBox(a);
			for (const [x, y] of [[x1, y1], [x2, y2]]) {
				const [lx, ly] = toArr(a, x, y);
				if (lx >= b.x0 && lx <= b.x1 && ly >= b.y0 && ly <= b.y1) {
					return { pitch: a.pitch, az: a.az, name: a.name };
				}
			}
		}
		const sel = S.arrays.find((x) => x.key === S.sel && x.mount !== 'tracker');
		return sel ? { pitch: sel.pitch, az: sel.az, name: sel.name } : { pitch: 0, az: 0, name: '' };
	};
	const trueLength = (m) => {
		const dx = m.x2 - m.x1;
		const dy = m.y2 - m.y1;
		if (!m.pitch) {
			return Math.hypot(dx, dy);
		}
		const t = m.az * RAD;
		const across = dx * Math.cos(t) + dy * Math.sin(t);
		const up = -dx * Math.sin(t) + dy * Math.cos(t);
		return Math.hypot(across, up / Math.cos(m.pitch * RAD));
	};
	const drawMeasure = (m, g, cls) => {
		const px = S.view.mpp;
		el('line', { x1: m.x1, y1: m.y1, x2: m.x2, y2: m.y2, class: 'measure ' + (cls || '') }, g);
		for (const [x, y] of [[m.x1, m.y1], [m.x2, m.y2]]) {
			el('circle', { cx: x, cy: y, r: 3 * px, class: 'measure-end' }, g);
		}
		const t = el('text', { x: (m.x1 + m.x2) / 2, y: (m.y1 + m.y2) / 2 - 6 * px, 'font-size': 13 * px, class: 'measure-label' }, g);
		t.textContent = ftIn(trueLength(m));
	};
	const drawMeasures = () => {
		clear(L.measures);
		for (const m of S.measures) {
			drawMeasure(m, L.measures);
		}
	};
	const draftArray = (p1, p2, p3) => {
		let ux = p2.x - p1.x;
		let uy = p2.y - p1.y;
		const len = Math.hypot(ux, uy);
		if (len < 0.05) {
			return null;
		}
		ux /= len;
		uy /= len;
		let vx = -uy;
		let vy = ux;
		let o = p1;
		let depth = (p3.x - p1.x) * vx + (p3.y - p1.y) * vy;
		if (depth < 0) {   // the third point sets which side is up the roof
			o = p2;
			ux = -ux; uy = -uy; vx = -vx; vy = -vy;
			depth = -depth;
		}
		const mount = $('new-mount').value;
		const mid = { x: (p1.x + p2.x) / 2 + vx * depth / 2, y: (p1.y + p2.y) / 2 + vy * depth / 2 };
		const face = mount === 'roof' ? roofFaceAt(mid.x, mid.y) : null;
		const typed = parsePitch($('new-pitch').value);
		const a = {
			key: '', name: '', mount: mount, orient: $('new-orient').value,
			pitch: mount === 'tracker' ? 0 : (face ? Math.round(face.pitch * 100) / 100 : (typed === null ? 0 : typed)),
			az: ((Math.atan2(uy, ux) / RAD) % 360 + 360) % 360, x: o.x, y: o.y, cells: [],
		};
		const g = grid(a);
		const cols = Math.max(1, Math.floor((len + S.gap.panel + 1e-9) / g.su));
		const rows = Math.max(1, Math.floor((depth + g.sv - g.v + 1e-9) / g.sv));
		for (let r = 0; r < rows && r < 200; r++) {
			for (let c = 0; c < cols && c < 200; c++) {
				a.cells.push([r, c]);
			}
		}
		return { a, len, depth, face };
	};
	const drawDraft = () => {
		clear(L.draft);
		const d = S.draft;
		if (!d || !d.cursor) {
			return;
		}
		if (d.tool === 'measure') {
			const sl = measureSlope(d.p1.x, d.p1.y, d.cursor.x, d.cursor.y);
			const m = { x1: d.p1.x, y1: d.p1.y, x2: d.cursor.x, y2: d.cursor.y, pitch: sl.pitch, az: sl.az };
			drawMeasure(m, L.draft, 'is-draft');
			hint('Click the end point. ' + ftIn(trueLength(m)) + (sl.pitch ? ' along ' + sl.name + ' (' + fmtPitch(sl.pitch, 'roof') + ')' : ' flat') + '.');
			return;
		}
		if (!d.p2) {
			el('line', { x1: d.p1.x, y1: d.p1.y, x2: d.cursor.x, y2: d.cursor.y, class: 'draft-line' }, L.draft);
			hint('Click the other end of the eave (low edge). ' + ftIn(Math.hypot(d.cursor.x - d.p1.x, d.cursor.y - d.p1.y)) + '.');
			return;
		}
		const r = draftArray(d.p1, d.p2, d.cursor);
		if (!r) {
			return;
		}
		el('line', { x1: d.p1.x, y1: d.p1.y, x2: d.p2.x, y2: d.p2.y, class: 'draft-line' }, L.draft);
		const g = el('g', { class: 'arr is-draft', transform: 'translate(' + r.a.x + ' ' + r.a.y + ') rotate(' + r.a.az + ')' }, L.draft);
		const gr = grid(r.a);
		for (const [row, c] of r.a.cells) {
			el('rect', { x: c * gr.su, y: row * gr.sv, width: gr.u, height: gr.v, class: 'panel' }, g);
		}
		el('rect', { x: 0, y: 0, width: r.len, height: r.depth, class: 'draft-box' }, g);
		if (r.a.mount === 'tracker') {
			hint('Click to place a tracker of ' + r.a.cells.length + ' panels: ' + ftIn(r.len) + ' by ' + ftIn(r.depth) + ', sweeping ' + ftIn(sweep(r.a).r * 2) + ' across.');
			return;
		}
		hint('Click to place ' + r.a.cells.length + ' panels: ' + ftIn(r.len) + ' along the eave, ' + ftIn(r.depth / Math.cos(r.a.pitch * RAD)) + ' up the slope at '
			+ fmtPitch(r.a.pitch, r.a.mount) + (r.face ? ' (Google roof face)' : '') + ', facing ' + compass(r.a.az) + ' ' + Math.round(r.a.az) + '°.');
	};

	// ---------- Side panel ----------
	const hint = (text) => {
		$('layout-hint').textContent = text;
	};
	const toolHint = () => {
		const h = {
			select: 'Click an array to select it. Drag it to move, drag the round handle to turn. Drag empty map to pan, wheel to zoom.',
			draw: sizeOk() ? 'Click one end of the eave (the low edge of the roof).' : 'Type the module length and width (right) before drawing.',
			panels: 'Click or drag over panels to remove them, or over empty spots beside an array to add them.',
			measure: 'Click the start point.',
		};
		hint(h[S.tool]);
	};
	const drawSide = () => {
		const n = panelCount();
		const kw = n * (S.module.watts || 0) / 1000;
		$('layout-totals').textContent = n + ' panels' + (S.module.watts ? ' · ' + kw.toFixed(2) + ' kW DC' : '') + ' · ' + S.arrays.length + (S.arrays.length === 1 ? ' array' : ' arrays');
		const note = [...notes];
		if (!sizeOk()) {
			note.push('This module has no size in the Sales catalog. Type its length and width below to draw.');
		}
		$('layout-module-note').textContent = note.join(' ');
		$('layout-module-note').hidden = !note.length;

		const list = $('array-list');
		list.replaceChildren();
		for (const a of S.arrays) {
			const li = html('li', a.key === S.sel ? 'is-sel' : '');
			const b = html('button', 'linklike', a.name);
			b.type = 'button';
			b.addEventListener('click', () => select(a.key, true));
			li.append(b, html('span', 'small muted', ' ' + a.cells.length + ' panels, '
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
			set('sel-pitch', a.mount === 'tracker' ? '' : fmtPitch(a.pitch, a.mount) === 'flat' ? '0' : fmtPitch(a.pitch, a.mount));
			set('sel-az', a.mount === 'tracker' ? '' : String(Math.round(a.az * 10) / 10));
			$('sel-pitch').disabled = $('sel-az').disabled = a.mount === 'tracker';
			$('sel-pitch-label').textContent = a.mount === 'roof' ? 'Pitch' : 'Tilt';
			let stats = a.cells.length + ' panels' + (S.module.watts ? ', ' + (a.cells.length * S.module.watts / 1000).toFixed(2) + ' kW' : '');
			if (a.mount === 'tracker') {
				stats += '. Sweep: ' + ftIn(sweep(a).r * 2) + ' across.';
			} else {
				stats += ', facing ' + compass(a.az) + '.';
			}
			$('sel-stats').textContent = stats;
		}

		const ml = $('measure-list');
		ml.replaceChildren();
		S.measures.forEach((m, i) => {
			const li = html('li');
			li.append(html('span', '', ftIn(trueLength(m))), html('span', 'small muted', m.pitch ? ' along a ' + fmtPitch(m.pitch, 'roof') + ' slope ' : ' flat '));
			const x = html('button', 'btn btn-ghost btn-small', '×');
			x.type = 'button';
			x.title = 'Remove this measurement';
			x.setAttribute('aria-label', 'Remove this measurement');
			x.addEventListener('click', () => {
				S.measures.splice(i, 1);
				commit();
				drawMeasures();
			});
			li.append(x);
			ml.append(li);
		});
		if (!S.measures.length) {
			ml.append(html('li', 'small muted', 'None. Use Measure.'));
		}

		const rl = $('roof-list');
		rl.replaceChildren();
		if (S.roof) {
			$('roof-info').textContent = 'Google: ' + S.roof.quality.toLowerCase() + ' quality, imagery from ' + S.roof.date + ', ' + S.roof.segments.length + ' roof faces (dashed boxes are their outer bounds).';
			const sel = S.arrays.find((x) => x.key === S.sel && x.mount !== 'tracker');
			S.roof.segments.forEach((s) => {
				const li = html('li');
				li.append(html('span', 'small', fmtPitch(s.pitch, 'roof') + ' (' + (Math.round(s.pitch * 10) / 10) + '°), facing ' + short(s.azimuth) + ' ' + Math.round(s.azimuth) + '°, '
					+ Math.round(s.area_m2 * 10.7639).toLocaleString() + ' sq ft '));
				const b = html('button', 'btn btn-ghost btn-small', 'Use pitch');
				b.type = 'button';
				b.disabled = !sel;
				b.title = sel ? 'Set ' + sel.name + ' to this pitch' : 'Select an array first';
				b.addEventListener('click', () => {
					const a = S.arrays.find((x) => x.key === S.sel);
					if (a) {
						keepCenter(a, () => { a.pitch = Math.round(s.pitch * 100) / 100; });
						commit();
						drawArrays();
					}
				});
				li.append(b);
				rl.append(li);
			});
		}
	};

	// ---------- History and saving ----------
	const snapshot = () => JSON.stringify({ arrays: S.arrays, measures: S.measures, module: { length: S.module.length, width: S.module.width }, gap: S.gap });
	const past = [snapshot()];
	const future = [];
	function serialize() {
		return { origin: S.origin, module: S.module, gap: S.gap, view: S.view, maxz: S.maxz, arrays: S.arrays, measures: S.measures, roof: S.roof };
	}
	// What needs saving: the drawing, not where the map was moved to
	const drawingState = () => JSON.stringify({ module: S.module, gap: S.gap, arrays: S.arrays, measures: S.measures, roof: S.roof });
	const savedState = drawingState();
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
		S.module.length = s.module.length;
		S.module.width = s.module.width;
		S.gap = s.gap;
		if (!S.arrays.some((a) => a.key === S.sel)) {
			S.sel = null;
		}
		syncModuleFields();
		drawArrays();
		drawMeasures();
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
	$('layout-form').addEventListener('submit', () => {
		$('layout-json').value = JSON.stringify(serialize());
		dirty = false;
	});

	// ---------- Selection and editing ----------
	const select = (key, center) => {
		S.sel = key;
		drawArrays();
		if (center && key) {
			const a = S.arrays.find((x) => x.key === key);
			const c = centerOf(a).map;
			S.view.cx = c[0];
			S.view.cy = c[1];
			updateView();
		}
	};
	const selected = () => S.arrays.find((x) => x.key === S.sel);
	const uniqueName = (base) => {
		let name = base;
		let i = 2;
		while (S.arrays.some((a) => a.name === name)) {
			name = base + ' ' + i++;
		}
		return name;
	};
	const removeSelected = () => {
		const a = selected();
		if (a) {
			S.arrays = S.arrays.filter((x) => x !== a);
			S.sel = null;
			commit();
			drawArrays();
		}
	};
	const duplicate = () => {
		const a = selected();
		if (!a) {
			return;
		}
		const b = JSON.parse(JSON.stringify(a));
		b.key = newKey();
		b.name = uniqueName(a.name.replace(/ \d+$/, ''));
		// Beside it along the eave, a foot past its far edge (trackers: clear of its sweep)
		const box = localBox(a);
		const step = a.mount === 'tracker' ? 2 * sweep(a).r + 1 : (box.x1 - box.x0) + 0.3048;
		const t = a.az * RAD;
		b.x += step * Math.cos(t);
		b.y += step * Math.sin(t);
		S.arrays.push(b);
		S.sel = b.key;
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
	onField('sel-name', (a, v) => { a.name = v.trim().slice(0, 60) || a.name; }, true);
	$('sel-name').addEventListener('change', commit);
	onField('sel-mount', (a, v) => keepCenter(a, () => {
		a.mount = v;
		if (v === 'tracker') {
			a.pitch = 0;
		}
	}));
	onField('sel-orient', (a, v) => keepCenter(a, () => { a.orient = v; }));
	onField('sel-pitch', (a, v) => {
		const p = parsePitch(v);
		if (p === null) {
			hint('Type a pitch like 6/12, or degrees from 0 to 75.');
			return;
		}
		keepCenter(a, () => { a.pitch = p; });
	});
	onField('sel-az', (a, v) => {
		const az = parseFloat(v);
		if (isFinite(az)) {
			turnTo(a, az);
		}
	});
	$('sel-delete').addEventListener('click', removeSelected);
	$('sel-copy').addEventListener('click', duplicate);

	const syncModuleFields = () => {
		$('mod-length').value = S.module.length ? Math.round(S.module.length * 1000) : '';
		$('mod-width').value = S.module.width ? Math.round(S.module.width * 1000) : '';
		$('gap-panel').value = Math.round(S.gap.panel / IN * 100) / 100;
		$('gap-row').value = Math.round(S.gap.row / IN * 100) / 100;
	};
	const onSize = () => {
		const l = parseFloat($('mod-length').value);
		const w = parseFloat($('mod-width').value);
		const gp = parseFloat($('gap-panel').value);
		const gr = parseFloat($('gap-row').value);
		if (!(l >= 300 && l <= 4000 && w >= 300 && w <= 4000)) {
			hint('Module length and width are in millimeters, e.g. 1722 by 1134.');
			return;
		}
		if (!(gp >= 0 && gp <= 12 && gr >= 0 && gr <= 120)) {
			hint('Gaps are in inches: up to 12 between panels, 120 between rows.');
			return;
		}
		// Each array keeps its center while the panels resize
		const before = S.arrays.map((a) => centerOf(a).map);
		S.module.length = Math.max(l, w) / 1000;
		S.module.width = Math.min(l, w) / 1000;
		S.gap = { panel: gp * IN, row: gr * IN };
		S.arrays.forEach((a, i) => {
			const after = centerOf(a).map;
			a.x += before[i][0] - after[0];
			a.y += before[i][1] - after[1];
		});
		commit();
		drawArrays();
		toolHint();
	};
	['mod-length', 'mod-width', 'gap-panel', 'gap-row'].forEach((id) => $(id).addEventListener('change', onSize));

	// ---------- Tools ----------
	const setTool = (t) => {
		if (t === 'draw' && !sizeOk()) {
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

	let drag = null;
	let spaceDown = false;
	svg.addEventListener('pointerdown', (e) => {
		if (e.button === 2) {
			return;
		}
		svg.focus({ preventScroll: true });
		const p = toPoint(e);
		const target = e.target;
		const pan = () => {
			drag = { kind: 'pan', sx: e.clientX, sy: e.clientY, cx: S.view.cx, cy: S.view.cy, moved: false };
		};
		if (e.button === 1 || spaceDown) {
			pan();
		} else if (S.tool === 'select') {
			const handle = target.closest('[data-handle]');
			const g = target.closest('.arr');
			if (handle && selected()) {
				const a = selected();
				drag = { kind: 'rotate', a, center: centerOf(a).map };
			} else if (g && g.dataset.key) {
				const a = S.arrays.find((x) => x.key === g.dataset.key);
				if (S.sel !== a.key) {
					select(a.key);
				}
				drag = { kind: 'move', a, sx: p.x, sy: p.y, ax: a.x, ay: a.y, moved: false };
			} else {
				pan();
				drag.deselect = true;
			}
		} else if (S.tool === 'draw') {
			if (!S.draft) {
				S.draft = { tool: 'draw', p1: p, cursor: p };
			} else if (!S.draft.p2) {
				if (Math.hypot(p.x - S.draft.p1.x, p.y - S.draft.p1.y) > 0.1) {
					S.draft.p2 = p;
				}
			} else {
				const r = draftArray(S.draft.p1, S.draft.p2, p);
				if (r) {
					r.a.key = newKey();
					r.a.name = uniqueName(r.a.mount === 'tracker' ? 'Tracker' : compass(r.a.az) + ' ' + r.a.mount);
					S.arrays.push(r.a);
					S.sel = r.a.key;
					if (r.a.mount !== 'tracker') {
						$('new-pitch').value = fmtPitch(r.a.pitch, r.a.mount) === 'flat' ? '0' : fmtPitch(r.a.pitch, r.a.mount);
					}
					commit();
					drawArrays();
				}
				S.draft = null;
				toolHint();
			}
			drawDraft();
		} else if (S.tool === 'panels') {
			const s = spotAt(p);
			if (s) {
				drag = { kind: 'paint', a: s.a, mode: s.has ? 'remove' : 'add', changed: false };
				paint(s);
			}
		} else if (S.tool === 'measure') {
			if (!S.draft) {
				S.draft = { tool: 'measure', p1: p, cursor: p };
			} else {
				const sl = measureSlope(S.draft.p1.x, S.draft.p1.y, p.x, p.y);
				const m = { x1: S.draft.p1.x, y1: S.draft.p1.y, x2: p.x, y2: p.y, pitch: sl.pitch, az: sl.az };
				if (Math.hypot(m.x2 - m.x1, m.y2 - m.y1) > 0.02) {
					S.measures.push(m);
					commit();
					drawMeasures();
					drawSide();
					hint(ftIn(trueLength(m)) + (m.pitch ? ' along ' + sl.name + ' (' + fmtPitch(m.pitch, 'roof') + ').' : ' flat.') + ' Click to start another.');
				}
				S.draft = null;
			}
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
	const endDrag = () => {
		if (!drag) {
			return;
		}
		if (drag.kind === 'pan') {
			if (drag.deselect && !drag.moved && S.sel) {
				S.sel = null;
				drawArrays();
			}
			markDirty();
		} else if ((drag.kind === 'move' && drag.moved) || drag.kind === 'rotate' || (drag.kind === 'paint' && drag.changed)) {
			commit();
			if (drag.kind === 'rotate') {
				toolHint();
			}
		}
		drag = null;
	};
	svg.addEventListener('pointerup', endDrag);
	svg.addEventListener('pointercancel', endDrag);
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
			} else if (S.sel) {
				S.sel = null;
				drawArrays();
			}
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
	$('btn-fit').addEventListener('click', () => {
		fit();
		markDirty();
	});
	$('btn-zoom-in').addEventListener('click', () => zoomAt(1 / 1.6, S.view.cx, S.view.cy));
	$('btn-zoom-out').addEventListener('click', () => zoomAt(1.6, S.view.cx, S.view.cy));
	$('new-mount').addEventListener('change', () => {
		$('new-pitch').closest('label').hidden = $('new-mount').value === 'tracker';
	});
	$('btn-roof').addEventListener('click', async () => {
		const btn = $('btn-roof');
		const [lat, lon] = toLatLon(S.view.cx, S.view.cy);
		btn.disabled = true;
		$('roof-info').textContent = 'Asking Google about the building in the middle of the map...';
		try {
			const r = await fetch(cfg.roof + '?' + new URLSearchParams({ lat: lat.toFixed(7), lon: lon.toFixed(7) }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
			const j = await r.json();
			if (j.ok) {
				S.roof = j.roof;
				drawRoof();
				drawSide();
				markDirty();
				const [x, y] = toLocal(j.roof.lat, j.roof.lon);
				if (Math.hypot(x - S.view.cx, y - S.view.cy) > 40) {
					$('roof-info').textContent += ' Google picked a building ' + Math.round(Math.hypot(x - S.view.cx, y - S.view.cy) * 3.28084) + ' ft from the map center; check it is the right one.';
				}
			} else {
				$('roof-info').textContent = j.error || 'Google had no roof data here.';
			}
		} catch (err) {
			$('roof-info').textContent = 'Could not reach the server. Try again.';
		}
		btn.disabled = false;
	});

	// ---------- Start ----------
	if (typeof ResizeObserver !== 'undefined') {
		new ResizeObserver(() => updateView()).observe(mapEl);
	}
	svg.dataset.tool = 'select';
	syncModuleFields();
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
	drawMeasures();
	updateUndo();
	toolHint();
})();
