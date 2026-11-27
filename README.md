# SymfonyCon Live

The SymfonyCon Warsaw 2026 companion app that attendees build at the JetBrains booth: they ask an AI
agent in PhpStorm for a feature, and it goes live a few minutes later. Agents read `AGENTS.md`.

- `BOOTH-SETUP.md`: steps for an agent preparing the booth laptop ("read BOOTH-SETUP.md and do it").
- `bin/setup`: first run (composer install, database).
- `bin/ship "Title" "Name" [idea-id]`: check every page, record, commit, merge to main, deploy.
- `bin/serve`: serve the main checkout on the internet without Upsun (prod mode, Cloudflare quick tunnel).
- `/tv`: the booth screen: live Bot Arena matches, leaderboard, QR code. Bots live in `src/Bot/`, strategy ideas in `data/ideas.yaml`.

Live: https://main-bvxea6i-4fi6yr36b7jd4.eu-5.platformsh.site/ (Upsun project `4fi6yr36b7jd4`, fixed
organisation, config in `.platform.app.yaml`). On a new machine: install the Upsun CLI
(`brew install upsun/tap/upsun-cli`), `upsun login`, then
`git remote add upsun 4fi6yr36b7jd4@git.eu-5.platform.sh:4fi6yr36b7jd4.git`. `bin/ship` deploys through
`bin/deploy-upsun` (about 1 minute) and also pushes to GitHub (`origin`).
