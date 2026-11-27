// Replays arena matches on a <canvas>. Any element with data-arena gets a player:
//   data-game='{"id":1,"replay":{...}}'  plays that match (and replays it on tap)
//   data-loop="/arena/next"              fetches and plays new matches forever (the TV)
//   data-board="/tv/board"               with data-loop: refreshes #tv-board after each match
// A link with data-arena-next plays a fresh match in the first player on the page.

const sleep = ms => new Promise(r => setTimeout(r, ms));
const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c]);
const STEP = {U: [0, -1], D: [0, 1], L: [-1, 0], R: [1, 0]};

function setup(el) {
    el.innerHTML = '<div class="arena-head"></div><canvas></canvas><div class="arena-result"></div>';
    return {el, head: el.querySelector('.arena-head'), canvas: el.querySelector('canvas'), result: el.querySelector('.arena-result'), run: 0};
}

async function play(view, game) {
    const run = ++view.run;
    const {w, h, walls, players, winner, ticks} = game.replay;
    const cell = Math.max(8, Math.floor(Math.min(view.el.clientWidth, 1600) / w));
    const c = view.canvas, ctx = c.getContext('2d'), dpr = devicePixelRatio || 1;
    c.width = w * cell * dpr; c.height = h * cell * dpr;
    c.style.width = '100%'; c.style.aspectRatio = `${w} / ${h}`;
    ctx.scale(dpr, dpr);

    view.head.innerHTML = (game.trial ? '<span class="tag hoodie">🧥 hoodie trial</span> ' : '')
        + players.map(p => `<span class="pl"><span class="swatch" style="background:${esc(p.color)}"></span><b>${esc(p.name)}</b> <span class="muted">${esc(p.author)}</span></span>`).join('<span class="vs">vs</span>');
    view.result.innerHTML = '&nbsp;';

    const paths = players.map(p => {
        const pts = [[p.x, p.y]];
        for (const m of p.moves) { const [x, y] = pts.at(-1); pts.push([x + STEP[m][0], y + STEP[m][1]]); }
        return pts;
    });
    const dark = matchMedia('(prefers-color-scheme: dark)').matches || document.body.classList.contains('tv-page');

    const draw = t => {
        ctx.fillStyle = dark ? '#0d0e12' : '#f2f3f7';
        ctx.fillRect(0, 0, w * cell, h * cell);
        ctx.strokeStyle = dark ? '#1b1d24' : '#e1e3ea';
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (let x = 0; x <= w; x++) { ctx.moveTo(x * cell + .5, 0); ctx.lineTo(x * cell + .5, h * cell); }
        for (let y = 0; y <= h; y++) { ctx.moveTo(0, y * cell + .5); ctx.lineTo(w * cell, y * cell + .5); }
        ctx.stroke();
        ctx.fillStyle = dark ? '#5a5f6e' : '#9aa0ad';
        for (const [x, y] of walls) ctx.fillRect(x * cell + 1, y * cell + 1, cell - 2, cell - 2);
        players.forEach((p, i) => {
            const pts = paths[i].slice(0, t + 1);
            ctx.globalAlpha = .8; ctx.fillStyle = p.color;
            for (const [x, y] of pts) ctx.fillRect(x * cell + 2, y * cell + 2, cell - 4, cell - 4);
            ctx.globalAlpha = 1;
            const [hx, hy] = pts.at(-1);
            ctx.shadowColor = p.color; ctx.shadowBlur = cell;
            ctx.fillRect(hx * cell, hy * cell, cell, cell);
            ctx.shadowBlur = 0;
            if (p.crash && t >= p.crash.tick) {
                ctx.font = `${cell * 1.6}px system-ui`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillText('💥', hx * cell + cell / 2, hy * cell + cell / 2);
            }
        });
    };

    const tickMs = Math.min(60, Math.max(20, 12000 / ticks)); // about 12 s per match, whatever the size
    for (let t = 0; t <= ticks; t++) {
        if (run !== view.run) return false;
        draw(t);
        await sleep(t === 0 ? 600 : tickMs);
    }
    const crashes = players.filter(p => p.crash).map(p => `${esc(p.name)} ${esc(p.crash.reason)}`).join(' · ');
    view.result.innerHTML = winner === null
        ? `🤝 <b>Draw</b> <span class="muted">${crashes || 'nobody crashed'}</span>`
        : `🏆 <b style="color:${esc(players[winner].color)}">${esc(players[winner].name)}</b> wins! <span class="muted">${crashes}</span>`;
    return true;
}

for (const el of document.querySelectorAll('[data-arena]')) {
    const view = setup(el);
    el._arena = view;
    if (el.dataset.game) {
        const game = JSON.parse(el.dataset.game);
        play(view, game);
        view.canvas.addEventListener('click', () => play(view, game));
    }
    if (el.dataset.loop) {
        (async () => {
            for (let n = 1; ; n++) {
                try {
                    const game = await (await fetch(el.dataset.loop)).json();
                    await play(view, game);
                    if (el.dataset.board) {
                        const board = document.getElementById('tv-board');
                        board.innerHTML = await (await fetch(el.dataset.board)).text();
                    }
                    await sleep(3500);
                    if (n % 25 === 0) location.reload(); // pick up new deploys
                } catch (e) {
                    console.error(e);
                    await sleep(5000);
                }
            }
        })();
    }
}

for (const a of document.querySelectorAll('[data-arena-next]')) {
    a.addEventListener('click', async e => {
        e.preventDefault();
        const view = document.querySelector('[data-arena]')._arena;
        a.textContent = '⏳ Playing…';
        const game = await (await fetch('/arena/next')).json();
        a.textContent = '▶ Play a new match';
        play(view, game);
        view.canvas.onclick = () => play(view, game);
    });
}
