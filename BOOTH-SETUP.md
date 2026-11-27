# Booth laptop setup

For an agent preparing this project on the booth laptop. This is not for visitor features; those
follow `AGENTS.md`. Work in this folder only. Don't change any app code, `AGENTS.md` or `bin/`
scripts. Be quick, and don't spawn subagents.

1. **Sync.** Run `git checkout main && git pull`.
2. **Prerequisites.** You need PHP 8.4 or newer with `pdo_sqlite`, Composer and `cloudflared`.
   Install anything missing with Homebrew (`brew install php composer cloudflared`). Report `php -v`.
3. **Git setup.**
   - Run `git config receive.denyCurrentBranch updateInstead`. `bin/ship` needs this to update
     `main` from a worktree.
   - If a remote named `upsun` exists, run `git remote rename upsun upsun-suspended`. The Upsun
     project is suspended, so ships update the local server instead.
   - Make sure `git config user.name` and `user.email` are set, so commits work.
4. **Install and check.** Run `bin/setup`, then `php bin/console app:check`. Every line must say
   `ok`. If not, report the error and stop.
5. **Serve it publicly.** Start `bin/serve` in the background, logging to `var/log/serve.log`. It
   switches this folder to prod mode, starts PHP on 127.0.0.1:8000, and opens a Cloudflare quick
   tunnel. Wait until the log shows a `https://….trycloudflare.com` URL. Then curl `/`, `/tv` and
   `/merch` on that URL; each must return 200.
6. **Dry-run the ship setup.** Nothing gets shipped:
   1. Run `git worktree add ../scl-try -b try`.
   2. Run `bin/setup` in that worktree, then `php bin/console app:check` there.
   3. Remove it with `git worktree remove --force ../scl-try && git branch -D try`.
7. **Report:**
   - the `/tv` URL to open on the booth TV;
   - the PHP version;
   - whether `git push origin main --dry-run` works. A failure blocks nothing, because `bin/ship`
     carries on without GitHub;
   - one line on anything that failed.

End by reminding the person of the booth rules:
- Each visitor gets a new Air session in its own worktree. Never use this folder while `bin/serve`
  runs here.
- The agent finishes each feature with `bin/ship "Title" "Visitor name" [idea-id]`.
- If `bin/serve` restarts, the URL changes, but the QR code on `/tv` follows it automatically.
