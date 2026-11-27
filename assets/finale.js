// The 5pm finale player (/finale, /finale/show). Plays one or more precomputed battles (Battle.php) back to back:
// Start → 3-2-1 countdown → the battle with live standings and a crash feed → podium → next battle → all winners.

const sleep = ms => new Promise(r => setTimeout(r, ms));
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
const STEP = {U: [0, -1], D: [0, 1], L: [-1, 0], R: [1, 0]};
const BATTLE_MS = 45000; // a battle lasts about this long at 1×
const PODIUM_MS = 7000;  // between battles

const el = document.querySelector('[data-finale]');
if (el) {
    const games = JSON.parse(el.dataset.finale);
    el.innerHTML = `
        <div class="finale-main">
            <div class="finale-head"><h1>🏁 <span class="finale-title"></span></h1><span class="finale-map muted"></span><span class="finale-count"></span>
                <span class="finale-controls">
                    <button class="btn" data-start>▶ Start</button>
                    <button class="btn btn-outline" data-speed="0.5">½×</button><button class="btn btn-outline on" data-speed="1">1×</button><button class="btn btn-outline" data-speed="2">2×</button><button class="btn btn-outline" data-speed="4">4×</button>
                </span>
            </div>
            <div class="finale-stage"><canvas></canvas><div class="finale-overlay"></div></div>
        </div>
        <aside class="finale-side"><ol class="finale-standings"></ol><div class="finale-feed"></div></aside>
        <div class="finale-scores" hidden></div>`;
    const $ = s => el.querySelector(s);
    const liveAt = el.dataset.liveAt;
    if (liveAt) $('.finale-controls').remove();
    const canvas = $('canvas'), ctx = canvas.getContext('2d'), overlay = $('.finale-overlay');
    let g, cell = 10, speed = 1, run = 0, shown = 0;

    const load = i => {
        const {title, replay} = games[i];
        const paths = replay.players.map(p => {
            const pts = [[p.x, p.y]];
            for (const m of p.moves) { const [x, y] = pts.at(-1); pts.push([x + STEP[m][0], y + STEP[m][1]]); }
            return pts;
        });
        const crashes = replay.players.filter(p => p.crash).sort((a, b) => a.crash.tick - b.crash.tick);
        g = {...replay, title, paths, crashes, index: i};
        $('.finale-title').textContent = title;
        $('.finale-map').textContent = (games.length > 1 ? `Battle ${i + 1} of ${games.length} · ` : '') + (replay.options?.label ?? '') + ` · ${replay.w}×${replay.h}`;
        size(); draw(0); side(0);
    };

    const size = () => {
        const stage = $('.finale-stage');
        cell = Math.max(3, Math.floor(Math.min(stage.parentElement.clientWidth / g.w, (innerHeight - stage.getBoundingClientRect().top - 16) / g.h)));
        const dpr = devicePixelRatio || 1;
        canvas.width = g.w * cell * dpr; canvas.height = g.h * cell * dpr;
        canvas.style.width = g.w * cell + 'px'; canvas.style.height = g.h * cell + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    const alive = t => g.players.filter(p => !p.crash || p.crash.tick > t);

    const draw = t => {
        shown = t;
        const {w, h} = g;
        ctx.fillStyle = '#0d0e12';
        ctx.fillRect(0, 0, w * cell, h * cell);
        if (cell >= 8) {
            ctx.strokeStyle = '#17191f'; ctx.lineWidth = 1; ctx.beginPath();
            for (let x = 0; x <= w; x++) { ctx.moveTo(x * cell + .5, 0); ctx.lineTo(x * cell + .5, h * cell); }
            for (let y = 0; y <= h; y++) { ctx.moveTo(0, y * cell + .5); ctx.lineTo(w * cell, y * cell + .5); }
            ctx.stroke();
        }
        ctx.fillStyle = '#5a5f6e';
        for (const [x, y] of g.walls) ctx.fillRect(x * cell + 1, y * cell + 1, cell - 2, cell - 2);
        const pad = cell >= 8 ? 2 : 1;
        g.players.forEach((p, i) => {
            const dead = p.crash && t >= p.crash.tick;
            if (dead && g.vanish && t > p.crash.tick + 6) return;
            const pts = g.paths[i].slice(0, t + 1);
            ctx.globalAlpha = dead ? .3 : .85; ctx.fillStyle = p.color;
            for (const [x, y] of pts) ctx.fillRect(x * cell + pad, y * cell + pad, cell - 2 * pad, cell - 2 * pad);
            ctx.globalAlpha = 1;
            const [hx, hy] = pts.at(-1);
            if (dead) {
                if (t < p.crash.tick + 20) {
                    ctx.font = `${cell * 2}px system-ui`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText('💥', hx * cell + cell / 2, hy * cell + cell / 2);
                }
                return;
            }
            ctx.shadowColor = p.color; ctx.shadowBlur = cell * 1.5;
            ctx.fillRect(hx * cell - 1, hy * cell - 1, cell + 2, cell + 2);
            ctx.shadowBlur = 0;
            ctx.font = `600 ${Math.max(11, cell * 1.1)}px system-ui`; ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
            ctx.lineWidth = 3; ctx.strokeStyle = '#0d0e12';
            ctx.strokeText(p.name, hx * cell + cell / 2, hy * cell - 2);
            ctx.fillStyle = p.color; ctx.fillText(p.name, hx * cell + cell / 2, hy * cell - 2);
        });
    };

    const side = t => {
        const live = alive(t), out = g.crashes.filter(p => p.crash.tick <= t).reverse();
        $('.finale-count').textContent = `${live.length} of ${g.players.length} still riding`;
        $('.finale-standings').innerHTML = live.map(p => `<li class="alive"><span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></li>`).join('')
            + out.map(p => `<li class="out"><span class="place">${p.place}.</span><span class="swatch" style="background:${esc(p.color)}"></span>${esc(p.name)}</li>`).join('');
        $('.finale-feed').innerHTML = out.slice(0, 6).map(p => `<div>💥 <b style="color:${esc(p.color)}">${esc(p.name)}</b> ${esc(p.crash.reason)}</div>`).join('');
    };

    const podium = () => [...g.players].sort((a, b) => a.place - b.place).slice(0, 3);
    const show = html => { overlay.className = 'finale-overlay show'; overlay.innerHTML = html; };

    const battle = async me => {
        overlay.className = 'finale-overlay'; overlay.innerHTML = '';
        for (const n of ['3', '2', '1', 'GO!']) {
            overlay.innerHTML = `<div class="countdown">${n}</div>`;
            await sleep(900);
            if (me !== run) return false;
        }
        overlay.innerHTML = '';
        const base = Math.min(150, Math.max(25, BATTLE_MS / g.ticks));
        for (let t = 0; t <= g.ticks; t++) {
            if (me !== run) return false;
            draw(t);
            if (t % 3 === 0 || g.crashes.some(p => p.crash.tick === t)) side(t);
            await sleep(base / speed / (alive(t).length <= 2 ? 2.5 : 1)); // the final duel goes faster
        }
        side(g.ticks);
        return true;
    };

    const start = async () => {
        const me = ++run, winners = [];
        for (let i = 0; i < games.length; i++) {
            load(i);
            if (!await battle(me)) return;
            const top = podium();
            winners.push({...top[0], map: g.options?.label});
            const next = i < games.length - 1 ? `<p class="muted">Next battle in a few seconds…</p>` : '';
            show(`<div class="podium"><div class="winner">🏆 ${esc(top[0].name)}</div><div class="muted">by ${esc(top[0].author)}</div>
                <ol>${top.map(p => `<li><span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></li>`).join('')}</ol>${next}</div>`);
            if (next) { await sleep(PODIUM_MS); if (me !== run) return; }
        }
        if (games.length > 1) {
            await sleep(PODIUM_MS);
            if (me !== run) return;
            show(`<div class="podium"><div class="winner">🏁 The winners</div><ol class="winners">${winners.map(p => `<li><span class="muted">${esc(p.map ?? '')}</span> <span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></li>`).join('')}</ol></div>`);
        }
    };

    // /finale/live: count down to the start time, play in sync with the server clock, then everyone's place.
    const clockText = s => { const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, ss = String(s % 60).padStart(2, '0'); return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`; };
    const scoreboard = () => {
        const rows = [...g.players].sort((a, b) => a.place - b.place || a.name.localeCompare(b.name));
        const medal = p => ['🥇', '🥈', '🥉'][p.place - 1] ?? `${p.place}.`;
        const board = $('.finale-scores');
        board.innerHTML = `<h1>🏁 ${esc(g.title)}: final standings</h1>
            <ol class="finale-scores-list">${rows.map(p => `<li class="${p.place <= 3 ? 'top' : ''}"><span class="place">${medal(p)}</span><span class="swatch big" style="background:${esc(p.color)}"></span>
                <span class="who"><b>${esc(p.name)}</b> <span class="muted">by ${esc(p.author)}</span></span>
                <span class="how muted">${p.crash ? esc(p.crash.reason) : '🏆 last one riding'}</span></li>`).join('')}</ol>`;
        board.style.setProperty('--rows', Math.ceil(rows.length / 2));
        board.insertAdjacentHTML('beforeend', `<p class="finale-replay"><a class="btn btn-outline" href="${esc(el.dataset.replay)}">↻ Replay</a></p>`);
        board.hidden = false;
    };
    const live = async () => {
        const at = Date.parse(liveAt), skew = +el.dataset.now * 1000 - Date.now(), now = () => Date.now() + skew;
        const base = Math.min(150, Math.max(25, BATTLE_MS / g.ticks)), times = [0];
        for (let t = 0; t < g.ticks; t++) times.push(times[t] + base / (alive(t).length <= 2 ? 2.5 : 1)); // same pace as battle()
        const end = times[g.ticks], clock = $('.finale-count');
        $('.finale-map').textContent = `${g.players.length} bots · starts at ${el.dataset.liveLabel}`;
        while (now() < at - 3000) {
            clock.textContent = 'starts in ' + clockText(Math.ceil((at - now()) / 1000));
            await sleep(1005 - now() % 1000);
        }
        while (now() < at) {
            show(`<div class="countdown">${Math.ceil((at - now()) / 1000)}</div>`);
            await sleep(100);
        }
        $('.finale-map').textContent = `${g.players.length} bots`;
        if (now() - at < 1500) {
            show('<div class="countdown">GO!</div>');
            setTimeout(() => { overlay.className = 'finale-overlay'; overlay.innerHTML = ''; }, 900);
        } else {
            overlay.className = 'finale-overlay'; overlay.innerHTML = '';
        }
        let t = 0;
        while (now() - at < end) {
            const e = now() - at;
            while (t < g.ticks && times[t + 1] <= e) t++;
            draw(t); side(t);
            await sleep(Math.max(5, times[t + 1] - e));
        }
        draw(g.ticks); side(g.ticks);
        const left = end + PODIUM_MS - (now() - at);
        if (left > 0) {
            const top = podium();
            show(`<div class="podium"><div class="winner">🏆 ${esc(top[0].name)}</div><div class="muted">by ${esc(top[0].author)}</div></div>`);
            await sleep(left);
        }
        scoreboard();
    };

    load(0);
    addEventListener('resize', () => { size(); draw(shown); });
    if (liveAt) live();
    else $('[data-start]').addEventListener('click', start);
    for (const b of el.querySelectorAll('[data-speed]')) {
        b.addEventListener('click', () => {
            speed = +b.dataset.speed;
            el.querySelectorAll('[data-speed]').forEach(x => x.classList.toggle('on', x === b));
        });
    }
    if (el.dataset.autostart === '1') start();
}

// After "Compute": wait until the newest finale is done, then open it (or the staff list, for a batch).
const wait = document.querySelector('[data-finale-wait]');
if (wait) {
    (async () => {
        for (;;) {
            await sleep(1500);
            const s = await (await fetch('/finale/latest.json')).json();
            if (!s.running) {
                if (s.id && s.id > +wait.dataset.finaleWait) { location.href = '/finale/' + s.id; return; }
                wait.textContent = '⚠️ Computing stopped without a result: see var/log/finale.log';
                return;
            }
            if (s.id > +wait.dataset.finaleWait) wait.textContent = `⏳ Computing… #${s.id} is done, more to come.`;
        }
    })();
}
