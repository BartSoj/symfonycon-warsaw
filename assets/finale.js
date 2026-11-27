// The 5pm finale player (/finale). Plays a replay from Battle: every bot on one arena.
// Start → 3-2-1 countdown → the battle, with live standings and a crash feed → podium.

const sleep = ms => new Promise(r => setTimeout(r, ms));
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
const STEP = {U: [0, -1], D: [0, 1], L: [-1, 0], R: [1, 0]};

const el = document.querySelector('[data-finale]');
if (el) {
    const {title, replay} = JSON.parse(el.dataset.finale);
    const {w, h, walls, players, ticks, vanish} = replay;
    el.innerHTML = `
        <div class="finale-main">
            <div class="finale-head"><h1>🏁 ${esc(title)}</h1><span class="finale-count"></span>
                <span class="finale-controls">
                    <button class="btn" data-start>▶ Start</button>
                    <button class="btn btn-outline" data-speed="0.5">½×</button><button class="btn btn-outline on" data-speed="1">1×</button><button class="btn btn-outline" data-speed="2">2×</button><button class="btn btn-outline" data-speed="4">4×</button>
                </span>
            </div>
            <div class="finale-stage"><canvas></canvas><div class="finale-overlay"></div></div>
        </div>
        <aside class="finale-side"><ol class="finale-standings"></ol><div class="finale-feed"></div></aside>`;
    const canvas = el.querySelector('canvas'), ctx = canvas.getContext('2d');
    const overlay = el.querySelector('.finale-overlay'), standings = el.querySelector('.finale-standings');
    const feed = el.querySelector('.finale-feed'), count = el.querySelector('.finale-count');

    const paths = players.map(p => {
        const pts = [[p.x, p.y]];
        for (const m of p.moves) { const [x, y] = pts.at(-1); pts.push([x + STEP[m][0], y + STEP[m][1]]); }
        return pts;
    });
    const crashes = players.map((p, i) => ({...p, i})).filter(p => p.crash).sort((a, b) => a.crash.tick - b.crash.tick);
    let cell = 10;

    const size = () => {
        const stage = el.querySelector('.finale-stage');
        cell = Math.max(4, Math.floor(Math.min(stage.clientWidth / w, (innerHeight - stage.getBoundingClientRect().top - 16) / h)));
        const dpr = devicePixelRatio || 1;
        canvas.width = w * cell * dpr; canvas.height = h * cell * dpr;
        canvas.style.width = w * cell + 'px'; canvas.style.height = h * cell + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    const draw = t => {
        ctx.fillStyle = '#0d0e12';
        ctx.fillRect(0, 0, w * cell, h * cell);
        if (cell >= 8) {
            ctx.strokeStyle = '#17191f'; ctx.lineWidth = 1; ctx.beginPath();
            for (let x = 0; x <= w; x++) { ctx.moveTo(x * cell + .5, 0); ctx.lineTo(x * cell + .5, h * cell); }
            for (let y = 0; y <= h; y++) { ctx.moveTo(0, y * cell + .5); ctx.lineTo(w * cell, y * cell + .5); }
            ctx.stroke();
        }
        ctx.fillStyle = '#5a5f6e';
        for (const [x, y] of walls) ctx.fillRect(x * cell + 1, y * cell + 1, cell - 2, cell - 2);
        const pad = cell >= 8 ? 2 : 1;
        players.forEach((p, i) => {
            const dead = p.crash && t >= p.crash.tick;
            if (dead && vanish && t > p.crash.tick + 6) return;
            const pts = paths[i].slice(0, t + 1);
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
        const alive = players.filter(p => !p.crash || p.crash.tick > t);
        const out = crashes.filter(p => p.crash.tick <= t).reverse();
        count.textContent = `${alive.length} of ${players.length} still riding`;
        standings.innerHTML = alive.map(p => `<li class="alive"><span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></li>`).join('')
            + out.map(p => `<li class="out"><span class="place">${p.place}.</span><span class="swatch" style="background:${esc(p.color)}"></span>${esc(p.name)}</li>`).join('');
        feed.innerHTML = out.slice(0, 6).map(p => `<div>💥 <b style="color:${esc(p.color)}">${esc(p.name)}</b> ${esc(p.crash.reason)}</div>`).join('');
    };

    let speed = 1, run = 0;
    const play = async () => {
        const me = ++run;
        overlay.innerHTML = ''; overlay.className = 'finale-overlay';
        draw(0); side(0);
        for (const n of ['3', '2', '1', 'GO!']) {
            overlay.innerHTML = `<div class="countdown">${n}</div>`;
            await sleep(900);
            if (me !== run) return;
        }
        overlay.innerHTML = '';
        const base = Math.min(150, Math.max(35, 45000 / ticks)); // about 45 s at 1×
        for (let t = 0; t <= ticks; t++) {
            if (me !== run) return;
            draw(t);
            if (t % 3 === 0 || crashes.some(p => p.crash.tick === t)) side(t);
            await sleep(base / speed);
        }
        side(ticks);
        const podium = [...players].sort((a, b) => a.place - b.place).slice(0, 3);
        overlay.className = 'finale-overlay show';
        overlay.innerHTML = `<div class="podium"><div class="winner">🏆 ${esc(podium[0].name)}</div><div class="muted">by ${esc(podium[0].author)}</div>
            <ol>${podium.map(p => `<li><span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></li>`).join('')}</ol></div>`;
    };

    size(); draw(0); side(0);
    addEventListener('resize', () => { size(); draw(0); });
    el.querySelector('[data-start]').addEventListener('click', play);
    for (const b of el.querySelectorAll('[data-speed]')) {
        b.addEventListener('click', () => {
            speed = +b.dataset.speed;
            el.querySelectorAll('[data-speed]').forEach(x => x.classList.toggle('on', x === b));
        });
    }
    if (el.dataset.autostart === '1') play();
}

// After "Compute a new finale": wait for it, then open it.
const wait = document.querySelector('[data-finale-wait]');
if (wait) {
    (async () => {
        for (;;) {
            await sleep(1500);
            const s = await (await fetch('/finale/latest.json')).json();
            if (s.id && s.id > +wait.dataset.finaleWait) { location.href = '/finale/' + s.id; return; }
            if (!s.running) { wait.textContent = '⚠️ Computing stopped without a result: see var/log/finale.log'; return; }
        }
    })();
}
