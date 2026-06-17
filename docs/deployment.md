# Deployment — Strato + PHPStorm

## Strato folder layout

Strato gives you an FTP root with (at least) one web-exposed folder. The mapping is:

```
/                    ← FTP root
├── htdocs/          ← web-exposed → upload public/* here
└── data/            ← NOT web-exposed → upload data/* here
    └── logs/        ← must be writable by PHP
```

The `public/` and `data/` directories are siblings on the server, which matches how the router resolves `__DIR__ . '/../data/...'` from `public/index.php`.

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
3. Upload `data/.htaccess` and `data/admin.php` into it.
4. Create `data/logs/` inside it, ensure it's writable (`chmod 755` if needed).

`data/releases.php` also goes here — upload it along with `admin.php`.

### 5. Set stats password

On your local machine, generate a bcrypt hash:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the output into `data/admin.php` as `pass_hash`, then upload that file.

## Regular deploy

Right-click `public/` → **Deployment → Upload to Strato (neurosys.gg)**.

When `data/releases.php` changes (new release, new HiDrive URL): upload it manually to `data/` on the server via the Remote Host panel.

## Cover art

Drop `{slug}-cover.jpg` (1400×1400 min, square) into `public/assets/img/` and deploy. The release template falls back to `cover-placeholder.svg` automatically until the file exists.
