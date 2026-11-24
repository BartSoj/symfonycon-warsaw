# SymfonyCon Live

The SymfonyCon Warsaw 2026 companion app that attendees build at the JetBrains booth: they ask an AI
agent in PhpStorm for a feature, and it goes live a few minutes later. Agents read `AGENTS.md`.

- `bin/setup`: first run (composer install, database, talks).
- `bin/ship "Title" "Name" [idea-id]`: check every page, record, commit, merge to main, deploy.
- `bin/serve`: serve the main checkout on the internet without Upsun (prod mode, Cloudflare quick tunnel).
- `/tv`: the booth screen with the QR code. `/ideas`: what to build next, from `data/ideas.yaml`.

Upsun: `upsun project:create`, then `upsun project:set-remote <id>`, rename the remote to `upsun`
(`git remote rename upsun upsun` if needed), and `bin/ship` deploys on every ship.
