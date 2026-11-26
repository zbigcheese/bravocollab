/**
 * VideoRenderer — draws an approved storyboard on a 1080×1920 <canvas>, frame
 * by frame, and encodes it to MP4 in the browser (WebCodecs VideoEncoder +
 * vendored mp4-muxer). No server rendering, no build step.
 *
 * Every number shown comes from the server-resolved `scene._r` (PHP formats
 * it in VideoStoryboard::format). Count-up animations format intermediate
 * values here with the same rules, and the settled frame uses the server's
 * string verbatim.
 */
const VideoRenderer = {
    W: 1080,
    H: 1920,
    FPS: 30,
    ACCENT: '#F16820',
    ACCENT_LIGHT: '#F7A26A',
    PAD: 72,
    FONT: 'BravoCond, "Roboto Condensed", "Arial Narrow", sans-serif',
    _fontPromise: null,

    // ─────────────────────────────────────────────────────────── assets ──

    ensureFont() {
        if (!this._fontPromise) {
            this._fontPromise = (async () => {
                try {
                    const face = new FontFace('BravoCond', 'url(public/vendor/fonts/RobotoCondensed-wght.ttf)', { weight: '100 900' });
                    await face.load();
                    document.fonts.add(face);
                } catch (e) {
                    console.warn('[VideoRenderer] font failed to load, using fallback', e);
                }
            })();
        }
        return this._fontPromise;
    },

    /** assets = {logo: url, backgrounds: {att_ID: url}} → loaded + pre-scaled images. */
    async loadAssets(assets) {
        await this.ensureFont();
        const load = (src) => new Promise((resolve) => {
            if (!src) return resolve(null);
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = src;
        });
        const out = { logo: await load(assets.logo), bg: {} };
        await Promise.all(Object.entries(assets.backgrounds || {}).map(async ([id, url]) => {
            const img = await load(url);
            if (img) out.bg[id] = this._prescale(img);
        }));
        return out;
    },

    /** Cover-fit once to a canvas 20% larger than the frame (room for Ken Burns). */
    _prescale(img) {
        const cw = Math.round(this.W * 1.2), ch = Math.round(this.H * 1.2);
        const c = document.createElement('canvas');
        c.width = cw; c.height = ch;
        const s = Math.max(cw / img.naturalWidth, ch / img.naturalHeight);
        const w = img.naturalWidth * s, h = img.naturalHeight * s;
        c.getContext('2d').drawImage(img, (cw - w) / 2, (ch - h) / 2, w, h);
        return c;
    },

    // ───────────────────────────────────────────────────────── timeline ──

    timeline(sb) {
        let t = 0;
        const parts = (sb.scenes || []).map((s, i) => {
            const p = { scene: s, index: i, start: t, end: t + s.duration_s };
            t += s.duration_s;
            return p;
        });
        return { parts, duration: t };
    },

    sceneAt(tl, t) {
        for (const p of tl.parts) if (t < p.end) return p;
        return tl.parts[tl.parts.length - 1];
    },

    // ─────────────────────────────────────────────────────────── frames ──

    drawFrame(ctx, sb, assets, t) {
        const tl = sb._tl || (sb._tl = this.timeline(sb));
        const part = this.sceneAt(tl, t);
        if (!part) return;
        const lt = t - part.start;
        const d = part.scene.duration_s;

        // Background: Ken Burns on the current scene's image, crossfade from the previous one.
        ctx.globalAlpha = 1;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, this.W, this.H);
        const prev = tl.parts[part.index - 1];
        if (prev && lt < 0.6 && prev.scene.background !== part.scene.background) {
            this._drawBg(ctx, assets, prev.scene.background, prev.index, (t - prev.start) / prev.scene.duration_s, 1);
            this._drawBg(ctx, assets, part.scene.background, part.index, lt / d, lt / 0.6);
        } else {
            this._drawBg(ctx, assets, part.scene.background, part.index, lt / d, 1);
        }
        ctx.globalAlpha = 1;
        ctx.fillStyle = 'rgba(0,0,0,0.66)';
        ctx.fillRect(0, 0, this.W, this.H);

        this._baseAlpha = 1;
        this._drawChrome(ctx, sb, assets, t, tl, part);

        // Scene content: fades out over the last 0.35 s (not the final scene).
        const isLast = part.index === tl.parts.length - 1;
        const exit = isLast ? 1 : Math.min(1, Math.max(0, (d - lt) / 0.35));
        const fn = this['_scene_' + part.scene.type];
        if (fn) {
            ctx.save();
            ctx.globalAlpha = exit;
            this._baseAlpha = exit;
            fn.call(this, ctx, part.scene._r || {}, lt, d, assets, sb.meta || {});
            ctx.restore();
        }
        this._baseAlpha = 1;
        ctx.globalAlpha = 1;
    },

    _drawBg(ctx, assets, bgId, index, progress, alpha) {
        ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
        const img = assets.bg && assets.bg[bgId];
        if (img) {
            const k = 1 / 1.2 * (1.0 + 0.12 * progress);       // zoom in slowly
            const w = img.width * k, h = img.height * k;
            const dir = index % 2 ? -1 : 1;
            const x = (this.W - w) / 2 + dir * 40 * (progress - 0.5);
            const y = (this.H - h) / 2;
            ctx.drawImage(img, x, y, w, h);
        } else {
            const g = ctx.createLinearGradient(0, 0, this.W, this.H);
            g.addColorStop(0, '#171717');
            g.addColorStop(1, '#3a1d0a');
            ctx.fillStyle = g;
            ctx.fillRect(0, 0, this.W, this.H);
            const r = ctx.createRadialGradient(this.W * (0.25 + 0.5 * progress), this.H * 0.35, 0, this.W * 0.5, this.H * 0.4, this.W);
            r.addColorStop(0, 'rgba(241,104,32,0.35)');
            r.addColorStop(1, 'rgba(241,104,32,0)');
            ctx.fillStyle = r;
            ctx.fillRect(0, 0, this.W, this.H);
        }
        ctx.globalAlpha = 1;
    },

    _drawChrome(ctx, sb, assets, t, tl, part) {
        const m = sb.meta || {};
        const P = this.PAD;
        if (assets.logo) {
            const h = 64, w = assets.logo.naturalWidth * (h / assets.logo.naturalHeight);
            ctx.drawImage(assets.logo, P, 88, w, h);
        } else {
            this._text(ctx, 'BRAVO', P, 140, { size: 56, weight: 800 });
        }
        if (m.section_tag) {
            this._text(ctx, m.section_tag, this.W - P, 136, { size: 38, weight: 800, color: this.ACCENT, align: 'right', spacing: 3 });
        }
        let x = P;
        const chipY = 196;
        if (m.date_chip) x += this._chip(ctx, m.date_chip, x, chipY, true) + 16;
        if (m.context_chip) this._chip(ctx, m.context_chip, x, chipY, false);

        // Footer: progress dots (one lit per third of the video) + domain.
        const lit = Math.min(2, Math.floor((t / Math.max(1, tl.duration)) * 3));
        for (let i = 0; i < 3; i++) {
            ctx.beginPath();
            ctx.arc(P + 9 + i * 30, 1622, 9, 0, Math.PI * 2);
            ctx.fillStyle = i <= lit ? this.ACCENT : 'rgba(241,104,32,0.35)';
            ctx.fill();
        }
        if (m.domain) this._text(ctx, m.domain, this.W - P, 1634, { size: 32, weight: 600, align: 'right', alpha: 0.85 });

        // Ticker.
        const ty = 1672, th = 78;
        ctx.fillStyle = this.ACCENT;
        ctx.fillRect(0, ty, this.W, th);
        if (m.ticker) {
            ctx.save();
            ctx.beginPath();
            ctx.rect(0, ty, this.W, th);
            ctx.clip();
            this._font(ctx, 36, 700);
            const unit = m.ticker + '     •     ';
            const uw = ctx.measureText(unit).width;
            let off = -((t * 150) % uw);
            ctx.fillStyle = '#fff';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            for (let xx = off; xx < this.W; xx += uw) ctx.fillText(unit, xx, ty + th / 2 + 2);
            ctx.restore();
            ctx.textBaseline = 'alphabetic';
        }
    },

    _chip(ctx, text, x, y, filled) {
        this._font(ctx, 30, 700);
        const w = ctx.measureText(text).width + 32, h = 50;
        this._roundRect(ctx, x, y, w, h, 8);
        if (filled) { ctx.fillStyle = this.ACCENT; ctx.fill(); }
        else { ctx.strokeStyle = 'rgba(255,255,255,0.9)'; ctx.lineWidth = 2.5; ctx.stroke(); }
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, x + 16, y + h / 2 + 1);
        ctx.textBaseline = 'alphabetic';
        return w;
    },

    // ────────────────────────────────────────────────────────── scenes ──
    // Each gets (ctx, r = resolved scene, lt = local time, d = duration).

    _scene_title(ctx, r, lt) {
        const lines = [...(r.headline || [])];
        if (r.headline_accent) lines.push(r.headline_accent);
        const accentIdx = r.headline_accent ? lines.length - 1 : -1;
        const size = this._fitSize(ctx, lines, this.W - 2 * this.PAD, 140, 70, 800);
        const lh = size * 1.02;
        const sub = r.subtitle ? this._wrap(ctx, r.subtitle, this.W - 2 * this.PAD, 46, 500) : [];
        const blockH = lines.length * lh + (sub.length ? 40 + sub.length * 58 : 0);
        let y = 960 - blockH / 2 + size * 0.8;
        lines.forEach((line, i) => {
            const a = this._ease(lt, 0.15 + i * 0.18, 0.5);
            this._text(ctx, line, this.PAD, y + (1 - a) * 50, { size, weight: 800, color: i === accentIdx ? this.ACCENT : '#fff', alpha: a });
            y += lh;
        });
        y += 40;
        const sa = this._ease(lt, 0.25 + lines.length * 0.18, 0.5);
        sub.forEach((l) => { this._text(ctx, l, this.PAD, y + (1 - sa) * 30, { size: 46, weight: 500, alpha: 0.85 * sa }); y += 58; });
    },

    _scene_area_compare(ctx, r, lt) {
        let y = this._heading(ctx, r.headline, r.subtitle, lt, 420);
        const items = r.items || [];
        const n = Math.max(1, items.length);
        const maxV = Math.max(...items.map((i) => i.value), 1e-9);
        const Rmax = n === 2 ? 250 : 168;
        const slot = (this.W - 2 * this.PAD) / n;
        const bottom = Math.max(y + 2 * Rmax + 40, 1120);
        items.forEach((it, i) => {
            const r0 = Rmax * Math.sqrt(Math.max(0, it.value) / maxV);
            const g = this._easeBack(lt, 0.45 + i * 0.22, 0.85);
            const cx = this.PAD + slot * (i + 0.5);
            ctx.beginPath();
            ctx.arc(cx, bottom - r0, Math.max(0.1, r0 * g), 0, Math.PI * 2);
            ctx.fillStyle = it.accent ? this.ACCENT : 'rgba(255,255,255,0.92)';
            ctx.fill();
            const a = this._ease(lt, 0.8 + i * 0.22, 0.4);
            this._text(ctx, it.label, cx, bottom + 72, { size: 46, weight: 800, align: 'center', alpha: a, maxWidth: slot - 16, color: it.accent ? this.ACCENT : '#fff' });
            this._text(ctx, this._countUp(it, lt, 0.8 + i * 0.22), cx, bottom + 128, { size: 40, weight: 600, align: 'center', alpha: a, maxWidth: slot - 16 });
        });
        if (r.legend) {
            const a = this._ease(lt, 1.6, 0.5);
            this._text(ctx, r.legend, this.W / 2, 1530, { size: 32, weight: 500, align: 'center', alpha: 0.75 * a });
        }
    },

    _scene_big_numbers(ctx, r, lt) {
        const top = r.headline ? this._heading(ctx, r.headline, '', lt, 420) + 40 : 0;
        const rows = r.rows || [];
        const rowH = 240;
        const blockH = rows.length * rowH + (r.callout ? 140 : 0);
        let y = Math.max(top, 960 - blockH / 2);
        rows.forEach((row, i) => {
            const delay = 0.25 + i * 0.35;
            const a = this._ease(lt, delay, 0.45);
            this._text(ctx, (row.label || '').toUpperCase(), this.PAD + (1 - a) * -40, y + 50, { size: 42, weight: 700, alpha: 0.85 * a, spacing: 2, maxWidth: this.W - 2 * this.PAD });
            this._text(ctx, this._countUp(row, lt, delay, 1.1), this.PAD, y + 180, { size: 128, weight: 800, alpha: a, color: row.accent ? this.ACCENT : '#fff', maxWidth: this.W - 2 * this.PAD });
            y += rowH;
        });
        if (r.callout && r.callout.text) {
            const delay = 0.25 + rows.length * 0.35 + 1.1;
            const a = this._ease(lt, delay, 0.45);
            if (a > 0) {
                this._font(ctx, 56, 800);
                const w = Math.min(ctx.measureText(r.callout.text).width + 60, this.W - 2 * this.PAD);
                const x = this.PAD - (1 - a) * (w + this.PAD);
                this._roundRect(ctx, x, y + 10, w, 96, 14);
                ctx.fillStyle = this.ACCENT;
                ctx.fill();
                this._text(ctx, r.callout.text, x + 30, y + 78, { size: 56, weight: 800, maxWidth: w - 60 });
            }
        }
    },

    _scene_spotlight(ctx, r, lt) {
        let y = this._heading(ctx, r.headline, r.subtitle, lt, 560, 100);
        y = Math.max(y + 70, 880);
        const a = this._ease(lt, 0.6, 0.45);
        if (r.value_label) this._text(ctx, r.value_label.toUpperCase(), this.PAD, y, { size: 40, weight: 800, color: this.ACCENT, alpha: a, spacing: 3 });
        if (r.value) this._text(ctx, this._countUp(r.value, lt, 0.6, 1.2), this.PAD, y + 140, { size: 132, weight: 800, alpha: a, maxWidth: this.W - 2 * this.PAD });
        const segs = r.segments || [];
        if (segs.length) {
            const bx = this.PAD, by = y + 210, bw = this.W - 2 * this.PAD, bh = 96, gap = segs.length > 30 ? 2 : 5;
            const total = segs.reduce((s, v) => s + Math.max(0, v), 0) || 1;
            const reveal = bw * this._ease(lt, 1.3, 1.5);
            ctx.fillStyle = 'rgba(255,255,255,0.15)';
            ctx.fillRect(bx, by, bw, bh);
            let x = bx;
            segs.forEach((v, i) => {
                const w = bw * Math.max(0, v) / total;
                const visible = Math.min(w, Math.max(0, bx + reveal - x));
                if (visible > 0) {
                    ctx.fillStyle = i % 2 ? this.ACCENT_LIGHT : this.ACCENT;
                    ctx.fillRect(x, by, Math.max(0, visible - (visible >= w ? gap : 0)), bh);
                }
                x += w;
            });
        }
    },

    _scene_bar_ranking(ctx, r, lt) {
        const top = this._heading(ctx, r.headline, r.subtitle, lt, 420) + 50;
        const items = r.items || [];
        const n = Math.max(1, items.length);
        const rowH = Math.min(175, (1540 - top) / n);
        const maxV = Math.max(...items.map((i) => i.value), 1e-9);
        const fullW = this.W - 2 * this.PAD - 250;
        items.forEach((it, i) => {
            const y = top + i * rowH;
            const delay = 0.45 + i * 0.15;
            const g = this._ease(lt, delay, 0.8);
            this._text(ctx, it.label, this.PAD, y + 40, { size: 40, weight: 700, alpha: Math.min(1, g * 2), maxWidth: this.W - 2 * this.PAD, color: it.accent ? this.ACCENT : '#fff' });
            const w = Math.max(6, fullW * (it.value / maxV)) * g;
            ctx.fillStyle = it.accent ? this.ACCENT : 'rgba(255,255,255,0.9)';
            ctx.fillRect(this.PAD, y + 60, w, 56);
            this._text(ctx, this._countUp(it, lt, delay, 0.8), this.PAD + w + 18, y + 102, { size: 40, weight: 700, alpha: Math.min(1, g * 2) });
        });
    },

    _scene_quote(ctx, r, lt) {
        const a = this._ease(lt, 0.1, 0.5);
        this._text(ctx, '“', this.PAD - 10, 760, { size: 300, weight: 800, color: this.ACCENT, alpha: a });
        const size = r.quote && r.quote.length > 110 ? 58 : 68;
        const lines = this._wrap(ctx, r.quote || '', this.W - 2 * this.PAD, size, 600);
        let y = 840;
        lines.forEach((l, i) => {
            const la = this._ease(lt, 0.3 + i * 0.12, 0.45);
            this._text(ctx, l, this.PAD, y + (1 - la) * 30, { size, weight: 600, alpha: la });
            y += size * 1.22;
        });
        if (r.attribution) {
            const aa = this._ease(lt, 0.4 + lines.length * 0.12, 0.45);
            this._text(ctx, '— ' + r.attribution, this.PAD, y + 50, { size: 42, weight: 700, color: this.ACCENT, alpha: aa });
        }
    },

    _scene_outro(ctx, r, lt, d, assets, meta) {
        const a = this._easeBack(lt, 0.1, 0.6);
        if (assets.logo) {
            const h = 150 * a, w = assets.logo.naturalWidth * (h / assets.logo.naturalHeight);
            if (h > 0) ctx.drawImage(assets.logo, (this.W - w) / 2, 700 - h / 2, w, h);
        }
        const ha = this._ease(lt, 0.4, 0.5);
        const lines = this._wrap(ctx, r.headline || '', this.W - 2 * this.PAD, 84, 800);
        let y = 920;
        lines.forEach((l) => { this._text(ctx, l, this.W / 2, y + (1 - ha) * 30, { size: 84, weight: 800, align: 'center', alpha: ha }); y += 90; });
        if (r.cta) {
            const ca = this._ease(lt, 0.7, 0.5);
            this._font(ctx, 50, 800);
            const w = ctx.measureText(r.cta).width + 70;
            ctx.globalAlpha = this._baseAlpha * ca;
            this._roundRect(ctx, (this.W - w) / 2, y + 20, w, 92, 46);
            ctx.fillStyle = this.ACCENT;
            ctx.fill();
            ctx.globalAlpha = this._baseAlpha;
            this._text(ctx, r.cta, this.W / 2, y + 84, { size: 50, weight: 800, align: 'center', alpha: ca });
        }
    },

    /** Headline + subtitle block; returns the y below it. */
    _heading(ctx, headline, subtitle, lt, y, size = 84) {
        const W = this.W - 2 * this.PAD;
        if (headline) {
            const a = this._ease(lt, 0.1, 0.45);
            const fs = this._fitSize(ctx, this._wrap(ctx, headline, W, size, 800).slice(0, 2), W, size, 56, 800);
            this._wrap(ctx, headline, W, fs, 800).slice(0, 3).forEach((l) => {
                this._text(ctx, l, this.PAD, y + (1 - a) * 30, { size: fs, weight: 800, alpha: a });
                y += fs * 1.05;
            });
        }
        if (subtitle) {
            const a = this._ease(lt, 0.25, 0.45);
            y += 10;
            this._wrap(ctx, subtitle, W, 42, 500).slice(0, 3).forEach((l) => {
                this._text(ctx, l, this.PAD, y + (1 - a) * 20, { size: 42, weight: 500, alpha: 0.8 * a });
                y += 52;
            });
        }
        return y;
    },

    // ───────────────────────────────────────────────────────── helpers ──

    _font(ctx, size, weight) {
        ctx.font = `${weight} ${size}px ${this.FONT}`;
    },

    _text(ctx, text, x, y, o = {}) {
        if (!text) return;
        const alpha = o.alpha === undefined ? 1 : Math.max(0, Math.min(1, o.alpha));
        if (alpha <= 0) return;
        let size = o.size || 40;
        this._font(ctx, size, o.weight || 700);
        if ('letterSpacing' in ctx) ctx.letterSpacing = (o.spacing || 0) + 'px';
        if (o.maxWidth) {
            while (size > 18 && ctx.measureText(text).width > o.maxWidth) { size -= 2; this._font(ctx, size, o.weight || 700); }
        }
        ctx.globalAlpha = (this._baseAlpha === undefined ? 1 : this._baseAlpha) * alpha;
        ctx.fillStyle = o.color || '#fff';
        ctx.textAlign = o.align || 'left';
        ctx.fillText(text, x, y);
        ctx.globalAlpha = this._baseAlpha === undefined ? 1 : this._baseAlpha;
        if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
    },

    _wrap(ctx, text, maxW, size, weight) {
        this._font(ctx, size, weight);
        const words = String(text || '').split(/\s+/).filter(Boolean);
        const lines = [];
        let cur = '';
        words.forEach((w) => {
            const test = cur ? cur + ' ' + w : w;
            if (ctx.measureText(test).width > maxW && cur) { lines.push(cur); cur = w; }
            else cur = test;
        });
        if (cur) lines.push(cur);
        return lines;
    },

    /** Largest size ≤ max at which every line fits maxW. */
    _fitSize(ctx, lines, maxW, max, min, weight) {
        let size = max;
        while (size > min) {
            this._font(ctx, size, weight);
            if (lines.every((l) => ctx.measureText(l).width <= maxW)) break;
            size -= 4;
        }
        return size;
    },

    _roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    },

    _ease(t, delay, dur) {
        const x = Math.max(0, Math.min(1, (t - delay) / dur));
        return 1 - Math.pow(1 - x, 3);
    },

    _easeBack(t, delay, dur) {
        const x = Math.max(0, Math.min(1, (t - delay) / dur));
        const c1 = 1.4, c3 = c1 + 1;
        return x === 0 ? 0 : 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2);
    },

    /** Count-up text: animated value, then the server's exact display string. */
    _countUp(item, lt, delay, dur = 1.0) {
        if (!item || item.value === undefined) return '';
        const p = this._ease(lt, delay, dur);
        if (p >= 1) return item.display;
        return this.formatValue(item.value * p, item.unit, this._lang, item.value);
    },

    /** Mirror of VideoStoryboard::format() (PHP). */
    formatValue(value, unit, lang, finalValue) {
        const cyr = lang === 'sr-Cyrl';
        if (unit === 'seconds') {
            const t = Math.round(value);
            const h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
            const parts = [];
            if (h) parts.push(h + (cyr ? ' ч' : ' h'));
            if (m) parts.push(m + (cyr ? ' мин' : ' min'));
            if (s || !parts.length) parts.push(s + (cyr ? ' с' : ' s'));
            return parts.join(' ');
        }
        const ref = finalValue === undefined ? value : finalValue;
        let dec;
        if (unit === 'percent') dec = Math.abs(ref - Math.round(ref)) < 0.05 ? 0 : 1;
        else dec = Math.abs(ref - Math.round(ref)) < 0.005 ? 0 : (Math.abs(ref) >= 10 ? 1 : 2);
        const en = lang === 'en';
        let str = Math.abs(value).toFixed(dec);
        let [int, frac] = str.split('.');
        int = int.replace(/\B(?=(\d{3})+(?!\d))/g, en ? ',' : '.');
        if (frac) frac = frac.replace(/0+$/, '');
        str = (value < 0 ? '-' : '') + int + (frac ? (en ? '.' : ',') + frac : '');
        return unit === 'percent' ? str + '%' : str;
    },

    // ─────────────────────────────────────────────────────────── player ──

    /** Live preview on a visible canvas. Returns {play, pause, seek, toggle, destroy, duration}. */
    createPlayer(canvas, sb, assets, onTime) {
        const ctx = canvas.getContext('2d');
        this._lang = (sb.meta || {}).language;
        delete sb._tl;
        const tl = this.timeline(sb);
        let t = 0, playing = false, raf = null, last = 0;
        const draw = () => { this._lang = (sb.meta || {}).language; this.drawFrame(ctx, sb, assets, Math.min(t, Math.max(0, tl.duration - 0.001))); onTime && onTime(t, tl.duration, playing); };
        const loop = (now) => {
            if (!playing) return;
            t += (now - last) / 1000;
            last = now;
            if (t >= tl.duration) { t = tl.duration; playing = false; }
            draw();
            if (playing) raf = requestAnimationFrame(loop);
        };
        const api = {
            duration: tl.duration,
            play() { if (t >= tl.duration) t = 0; playing = true; last = performance.now(); raf = requestAnimationFrame(loop); },
            pause() { playing = false; cancelAnimationFrame(raf); draw(); },
            toggle() { playing ? api.pause() : api.play(); },
            seek(x) { t = Math.max(0, Math.min(tl.duration, x)); draw(); },
            destroy() { playing = false; cancelAnimationFrame(raf); },
            get time() { return t; },
            get playing() { return playing; },
        };
        draw();
        return api;
    },

    // ─────────────────────────────────────────────────────────── encode ──

    isSupported() {
        return typeof window.VideoEncoder === 'function' && typeof window.VideoFrame === 'function';
    },

    async _pickCodec() {
        const base = { width: this.W, height: this.H, bitrate: 5_000_000, framerate: this.FPS };
        for (const codec of ['avc1.640028', 'avc1.4D0028', 'avc1.42E028', 'avc1.640032', 'avc1.42E032']) {
            try {
                const res = await VideoEncoder.isConfigSupported({ ...base, codec });
                if (res.supported) return codec;
            } catch (e) { /* try next */ }
        }
        throw new Error('This browser cannot encode H.264 video. Use a recent desktop Chrome or Edge.');
    },

    /** Render the storyboard to an MP4 Blob. onProgress(0..1); signal = AbortSignal. */
    async renderMp4(sb, assets, onProgress, signal) {
        if (!this.isSupported()) throw new Error('Video rendering needs WebCodecs (recent desktop Chrome or Edge).');
        if (typeof Mp4Muxer === 'undefined') throw new Error('MP4 muxer failed to load.');
        const codec = await this._pickCodec();
        const canvas = document.createElement('canvas');
        canvas.width = this.W;
        canvas.height = this.H;
        const ctx = canvas.getContext('2d', { alpha: false });
        this._lang = (sb.meta || {}).language;
        delete sb._tl;
        const tl = this.timeline(sb);
        const total = Math.round(tl.duration * this.FPS);

        const muxer = new Mp4Muxer.Muxer({
            target: new Mp4Muxer.ArrayBufferTarget(),
            video: { codec: 'avc', width: this.W, height: this.H, frameRate: this.FPS },
            fastStart: 'in-memory',
        });
        let encError = null;
        const encoder = new VideoEncoder({
            output: (chunk, meta) => muxer.addVideoChunk(chunk, meta),
            error: (e) => { encError = e; },
        });
        encoder.configure({ codec, width: this.W, height: this.H, bitrate: 5_000_000, framerate: this.FPS, avc: { format: 'avc' } });

        try {
            for (let i = 0; i < total; i++) {
                if (encError) throw encError;
                if (signal && signal.aborted) throw new Error('Rendering cancelled.');
                this.drawFrame(ctx, sb, assets, i / this.FPS);
                const frame = new VideoFrame(canvas, { timestamp: Math.round(i * 1e6 / this.FPS), duration: Math.round(1e6 / this.FPS) });
                encoder.encode(frame, { keyFrame: i % (this.FPS * 2) === 0 });
                frame.close();
                while (encoder.encodeQueueSize > 6) await new Promise((r) => setTimeout(r, 4));
                if (i % 15 === 0) {
                    onProgress && onProgress(i / total);
                    await new Promise((r) => setTimeout(r, 0));
                }
            }
            await encoder.flush();
            if (encError) throw encError;
        } finally {
            if (encoder.state !== 'closed') encoder.close();
        }
        muxer.finalize();
        onProgress && onProgress(1);
        return new Blob([muxer.target.buffer], { type: 'video/mp4' });
    },
};
