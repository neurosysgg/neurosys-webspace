# Deployment — Strato + PHPStorm

## Strato folder layout

Strato gives you an FTP root with (at least) one web-exposed folder. The mapping is:

```
/                    ← FTP root
├── htdocs/          ← web-exposed → upload public/* here
└── data/            ← NOT web-exposed → upload data/* here
    └── logs/        ← must be writable by PHP
```

The `public/` and `data/` directories are siblings on the server, which matches how `ReleaseRepository`, `Auth`, and `DownloadLogger` resolve `dirname(__DIR__, 3) . '/data/...'` from inside `src/NeuroSYS/Service/`.

If your account only has one folder and there's no "outside webroot" option, `data/.htaccess` (`Require all denied`) is there as a fallback — just ensure it actually uploads (some FTP clients hide dotfiles).

## First-time setup

### 1. Open the project in PHPStorm

Open the `neurosys/` root as the PHPStorm project. The `.idea/` folder is gitignored — it can contain deployment credentials, keep it local.

### 2. Git

```bash
git init
git remote add origin git@github.com:<you>/neurosys.git
git branch -M main
git add .
git commit -m "initial"
git push -u origin main
```

Or: `gh repo create neurosys --private --source=. --push`

### 3. Configure FTP deployment

**Settings → Build, Execution, Deployment → Deployment → +**

1. Type: **FTP** or **SFTP** (prefer SFTP if Strato offers it — check your hosting panel).
2. Name: `Strato (neurosys.gg)`
3. Fill in host, port, username, password (save to system keychain, not the project).
4. **Mappings tab**:
   - Local path: `public`
   - Deployment path: `/htdocs` (or whatever Strato calls the webroot)
   - Web path: `/`

### 4. Upload `data/` manually (one-time)

`data/` is intentionally outside the deployment mapping. Do this once:

1. Open the **Remote Host** tool window in PHPStorm (or any FTP client).
2. Create a `data/` folder next to `htdocs/` on the server.
3. Upload `data/.htaccess`, `data/admin.php`, and `data/releases.php` into it.
4. Create `data/logs/` inside it, ensure it's writable (`chmod 755` if needed).

### 5. Set stats password

On your local machine, generate a bcrypt hash:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the output into `data/admin.php` as `pass_hash`, then upload that file.

## Regular deploy

`./deploy.sh` is the current path — it rsyncs `public/`, `src/`, `autoload.php` and `data/` over the
mounted SFTP in one go, so `data/releases.php` no longer needs a separate manual upload. The script is
gitignored (it holds the host and account name), so it exists only on the local machine.

**It deliberately excludes `data/admin.php` and `data/site_auth.php`.** The copies in the repo are
placeholders — `admin.php` ships an empty `pass_hash` — so syncing them would overwrite the live hashes
and lock `/admin/stats` out. Upload those two by hand when they actually change.

The PHPStorm route still works if the mount isn't up: right-click `public/` → **Deployment → Upload to
Strato (neurosys.gg)**, then upload `data/releases.php` manually via the Remote Host panel.

**Run `npm run build` first if you touched anything under `assets/ts/`.** `public/assets/js/` is
compiled output that is committed and rsynced as-is, so an unbuilt source edit deploys the previous
JS without a word. `composer verify` catches it — it fails when the committed output has drifted.

`vendor/` and `node_modules/` are dev-only tooling and are not in the list above — nothing Composer or
npm installs ever reaches the server.
