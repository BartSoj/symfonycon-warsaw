import './styles/app.css';

// "Starts in 12:34" on links to the finale (home page). The server's clock is the reference.
for (const a of document.querySelectorAll('[data-countdown]')) {
    const at = Date.parse(a.dataset.countdown), skew = +a.dataset.now * 1000 - Date.now(), text = a.querySelector('[data-countdown-text]');
    const tick = () => {
        const s = Math.ceil((at - Date.now() - skew) / 1000);
        if (s <= 0) { text.textContent = '🔴 Live now'; return; }
        const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, sec = String(s % 60).padStart(2, '0');
        text.textContent = 'starts in ' + (h ? `${h}:${String(m).padStart(2, '0')}` : m) + ':' + sec;
        setTimeout(tick, 1000 - (Date.now() + skew) % 1000);
    };
    tick();
}
