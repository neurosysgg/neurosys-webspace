# The runtimes

Three PHP runtimes run this code, and they are not the same PHP:

- **Strato**, the live host: PHP under `cgi-fcgi` behind Apache, which is the only one that serves
  visitors.
- **The local Apache**: the systemd `httpd` + `php-fpm` serving the working tree at
  `http://neurosys.localhost`. This is where a change is tried before it is pushed.
- **The local CLI**: the `php` on `$PATH`. It runs PHPUnit, the verify script's `php -S`, and every
  command under `tools/`.

**These tables are a reading, not a contract.** They were taken on **2026-09-10** through
`capability v1`, and they go stale the day a host changes. The local columns' `intl` row, extension
counts and `health` tally were re-read on **2026-09-11**, when `intl` was declared and switched on
locally. What the site actually *needs* is
declared in `Support/RequirementInitialization.php` and checked by `health v1`; see
[health.md](../phpanta/docs/health.md). When a table here disagrees with the API, the API is right, and this page
is the one to update. [Re-reading](#re-reading-them) is at the end.

## At a glance

| | Strato | local Apache | local CLI |
|---|---|---|---|
| PHP | `8.5.9` | `8.5.10` | `8.5.10` |
| SAPI | `cgi-fcgi` | `fpm-fcgi` | `cli` |
| Zend | `4.5.9` | `4.5.10` | `4.5.10` |
| server | `Apache/2.4.68 (Unix)` | `Apache/2.4.68 (Unix)` | — |
| protocol | `HTTP/1.1` | `HTTP/1.1` | — |
| kernel | `5.14.0-611.24.1.el9_7.x86_64` | `7.2.3-arch1-2` | `7.2.3-arch1-2` |
| `date.timezone` | `UTC` | `UTC` | `UTC` |
| extensions | 55 + OPcache | 36 + OPcache, Xdebug | 35 + OPcache, Xdebug |
| php.ini directives | 290 | 327 | 319 |
| `health v1 report` | 200 — 16 pass, 1 warn (OPcache off) | 200 — 20 pass | — (no `DOCUMENT_ROOT` on a CLI run) |

**Local runs one patch release ahead.** A fix that lands in 8.5.10 is in force in the test suite
and on the local Apache, but not on Strato.

## Extensions

The five the site cannot run without are in bold. Only those are declared, in `composer.json` and
as required requirements, and `health v1 extensions` proves them by using them.

| extension | Strato | local Apache | local CLI |
|---|---|---|---|
| bcmath | `8.5.9` | — | — |
| bz2 | `8.5.9` | — | — |
| calendar | `8.5.9` | — | — |
| cgi-fcgi | `8.5.9` | `8.5.10` | — |
| Core | `8.5.9` | `8.5.10` | `8.5.10` |
| ctype | `8.5.9` | `8.5.10` | `8.5.10` |
| curl | `8.5.9` | `8.5.10` | `8.5.10` |
| date | `8.5.9` | `8.5.10` | `8.5.10` |
| dba | `8.5.9` | — | — |
| **dom** | `20031129` | `20031129` | `20031129` |
| exif | `8.5.9` | — | — |
| fileinfo | `8.5.9` | `8.5.10` | `8.5.10` |
| filter | `8.5.9` | `8.5.10` | `8.5.10` |
| ftp | `8.5.9` | — | — |
| gd | `8.5.9` | — | — |
| gettext | `8.5.9` | — | — |
| gmp | `8.5.9` | — | — |
| hash | `8.5.9` | `8.5.10` | `8.5.10` |
| iconv | `8.5.9` | `8.5.10` | `8.5.10` |
| imagick | `3.8.0` | — | — |
| imap | `1.0.3` | — | — |
| **intl** | `8.5.9` | `8.5.10` | `8.5.10` |
| json | `8.5.9` | `8.5.10` | `8.5.10` |
| lexbor | `8.5.9` | `8.5.10` | `8.5.10` |
| libxml | `8.5.9` | `8.5.10` | `8.5.10` |
| mailparse | `3.1.8` | — | — |
| mbstring | `8.5.9` | `8.5.10` | `8.5.10` |
| mysqli | `8.5.9` | — | — |
| mysqlnd | `8.5.9` | `8.5.10` | `8.5.10` |
| **openssl** | `8.5.9` | `8.5.10` | `8.5.10` |
| pcntl | `8.5.9` | `8.5.10` | `8.5.10` |
| pcre | `8.5.9` | `8.5.10` | `8.5.10` |
| PDO | `8.5.9` | `8.5.10` | `8.5.10` |
| pdo_mysql | `8.5.9` | — | — |
| pdo_sqlite | `8.5.9` | — | — |
| Phar | `8.5.9` | `8.5.10` | `8.5.10` |
| posix | `8.5.9` | `8.5.10` | `8.5.10` |
| random | `8.5.9` | `8.5.10` | `8.5.10` |
| readline | — | `8.5.10` | `8.5.10` |
| Reflection | `8.5.9` | `8.5.10` | `8.5.10` |
| session | `8.5.9` | `8.5.10` | `8.5.10` |
| SimpleXML | `8.5.9` | `8.5.10` | `8.5.10` |
| soap | `8.5.9` | — | — |
| sodium | `8.5.9` | — | — |
| SPL | `8.5.9` | `8.5.10` | `8.5.10` |
| sqlite3 | `8.5.9` | — | — |
| standard | `8.5.9` | `8.5.10` | `8.5.10` |
| tidy | `8.5.9` | — | — |
| tokenizer | `8.5.9` | `8.5.10` | `8.5.10` |
| **uri** | `8.5.9` | `8.5.10` | `8.5.10` |
| xml | `8.5.9` | `8.5.10` | `8.5.10` |
| xmlreader | `8.5.9` | `8.5.10` | `8.5.10` |
| xmlwriter | `8.5.9` | `8.5.10` | `8.5.10` |
| xsl | `8.5.9` | — | — |
| zip | `1.22.8` | `1.22.8` | `1.22.8` |
| **zlib** | `8.5.9` | `8.5.10` | `8.5.10` |
| *Zend:* Zend OPcache | `8.5.9` (loaded, off) | `8.5.10` | `8.5.10` |
| *Zend:* Xdebug | — | `3.5.3` | `3.5.3` |

- **Strato has 20 extensions no local runtime has.** They are bcmath, bz2, calendar, dba, exif,
  ftp, gd, gettext, gmp, imagick, imap, mailparse, mysqli, pdo_mysql, pdo_sqlite, soap, sodium,
  sqlite3, tidy and xsl. **That is the dangerous direction**: code that reached for `sodium` would
  work in production and fail every test. `intl` was the twenty-first, and left the list the way
  any of these would: switched on locally (`extension=intl` in `/etc/php/php.ini`), then declared. An extension becomes something the site
  uses only by being declared: in `composer.json`, as a `PhpExtension` case, and so as a required
  requirement. That order also makes it fail locally first.
- **Only the local runtimes have `readline` and Xdebug.** Nothing in `src/` uses either, and Xdebug
  is what `composer coverage` measures with, which is why coverage can only be taken locally.
- **`ext/curl` is on all three**, and the site still makes no outbound request. It is `require-dev`,
  for `tools/` only; see CLAUDE.md's Stack.

## Settings

The directives that bound a request, decide where a diagnostic goes, or have ever mattered to this
site. An empty cell is a directive with no value, which for a switch means off; `—` means that
runtime has no such directive. The full list — 290 directives on Strato, 327 under the local Apache,
319 on the CLI — is `capability v1 settings`.

| directive | Strato | local Apache | local CLI |
|---|---|---|---|
| `memory_limit` | `512M` | `128M` | `128M` |
| `post_max_size` | `128M` | `8M` | `8M` |
| `upload_max_filesize` | `128M` | `2M` | `2M` |
| `max_execution_time` | `240` | `30` | `0` |
| `max_input_time` | `60` | `60` | `-1` |
| `max_input_vars` | `4000` | `1000` | `1000` |
| `display_errors` | | | |
| `display_startup_errors` | | | |
| `log_errors` | `1` | `1` | `1` |
| `error_log` | | `/var/log/php_errors.log` | `/var/log/php_errors.log` |
| `error_reporting` | `22519` | `22527` | `22527` |
| `html_errors` | | `1` | `0` |
| `zend.assertions` | `-1` | `-1` | `-1` |
| `zend.exception_ignore_args` | `1` | `1` | `1` |
| `output_buffering` | | `4096` | `0` |
| `implicit_flush` | | | `1` |
| `register_argc_argv` | *(off by `.user.ini`)* | *(off by `.user.ini`)* | `0` |
| `variables_order` | `EGPCS` | `GPCS` | `GPCS` |
| `request_order` | | `GP` | `GP` |
| `short_open_tag` | `1` | | |
| `expose_php` | `1` | `1` | `1` |
| `allow_url_fopen` | `1` | `1` | `1` |
| `allow_url_include` | | | |
| `open_basedir` | | | |
| `disable_functions` | | | |
| `enable_dl` | `1` | | |
| `default_charset` | `UTF-8` | `UTF-8` | `UTF-8` |
| `default_socket_timeout` | `60` | `60` | `60` |
| `realpath_cache_size` | `4096K` | `4096K` | `4096K` |
| `realpath_cache_ttl` | `120` | `120` | `120` |
| `pcre.jit` | `1` | `1` | `1` |
| `opcache.enable` | `0` | `1` | `1` |
| `opcache.enable_cli` | `0` | `0` | `0` |
| `opcache.memory_consumption` | `256` | `128` | `128` |
| `opcache.max_accelerated_files` | `4000` | `10000` | `10000` |
| `opcache.validate_timestamps` | `1` | `1` | `1` |
| `opcache.revalidate_freq` | `0` | `2` | `2` |
| `opcache.jit` | `disable` | `disable` | `disable` |
| `user_ini.filename` | `.user.ini` | `.user.ini` | `.user.ini` |
| `user_ini.cache_ttl` | `300` | `300` | `300` |
| `cgi.fix_pathinfo` | `1` | `1` | — |
| `cgi.force_redirect` | `0` | — | — |
| `include_path` | `.:/opt/RZphp84/includes` | `.:` | `.:` |
| `extension_dir` | `/opt/RZphp85/lib/php/extensions/no-debug-non-zts-20250925` | `/usr/lib/php/modules/` | `/usr/lib/php/modules/` |

What the differences mean, the dangerous ones first:

- **Strato does not buffer output; the local Apache buffers 4096 bytes.** A stray byte before a
  `header()` call is swallowed by the local buffer and works. On Strato the same byte sends the
  headers, and that request loses its security headers and its status. The verify script's `php -S`
  runs unbuffered like Strato, which makes it the more honest of the two local servers for this.
- **No runtime's php.ini sends a diagnostic anywhere this repository can read, so the site sets
  its own.** Strato's `error_reporting` `22519` is `E_ALL` minus `E_DEPRECATED`, `E_STRICT` and
  `E_NOTICE`, and its empty `error_log` sends what is left to the SAPI's own log. Locally, `22527`
  keeps notices, but `/var/log/php_errors.log` is `root:root 0644` and php-fpm runs as `http`, so
  the Apache could never open it either. The columns above are the php.ini values; at runtime
  `public/index.php` overrides both through `ErrorLog`: `E_ALL`, into
  `data/logs/php-YYYY-MM.log`, on every runtime that serves a request. `capability v1 errors` quotes
  the file. What PHP raises before the script starts still goes to the host's log, and a missing or
  unwritable `data/logs/` silently sends everything there — `health v1` warns on that.
- **The limits are Strato's to be generous with.** Every local limit is lower. Locally,
  `post_max_size` is exactly `ApiGate::MAX_BODY`, so a push to the local Apache at the cap would
  only just fit. On the CLI, `max_execution_time` is `0` (unlimited), so a test can never hit the
  limit a request would.
- **OPcache is loaded on Strato and switched off.** Only the host can enable it, because PHP turns
  OPcache on only at startup. It is `health`'s one `warn`. Locally, `revalidate_freq` is `2`, so an
  edited file can take up to two seconds to show under the local Apache. On Strato it would be `0`,
  checked on every request, so a push takes effect at once.
- **Strato fills `$_ENV` (`EGPCS`) and builds `$_REQUEST` from `variables_order`.** Neither matters:
  nothing under `src/` or `public/` reads `$_ENV`, `$_REQUEST`, any other superglobal or
  `getenv()`. `Request` and `ServerVariable` read the request's own globals.
- **`register_argc_argv` is off in both web runtimes because `public/.user.ini` says so.** Strato
  had it on, and PHP 8.5 raised a deprecation for it on every request. The CLI sets `argv`
  regardless of this directive. See [deployment.md](deployment.md#what-htaccess-does-to-a-response).
- **Harmless:** Strato's `short_open_tag`, `enable_dl` and `cgi.force_redirect`. No file here uses a
  short open tag, and nothing loads an extension at runtime. The `include_path` naming
  `/opt/RZphp84` is a leftover of the host's previous PHP. Nothing here uses `include_path`: every
  `require` is absolute from `__DIR__`.

## Re-reading them

For Strato, the API answers directly:

```bash
php tools/api.php capability v1 runtime
php tools/api.php capability v1 extensions
php tools/api.php capability v1 settings
```

**The local Apache is plain HTTP, and `tools/api.php` only speaks `https`** (`Url` refuses
anything else, by design, on the one request that carries a signature). Build the same signed
request and carry it with `curl`. The signature binds the method and the path, not the host or the
scheme:

```php
$request = SignedRequest::build(new Url('https://neurosys.localhost'), ApiService::Capability,
    ApiVersion::V1, CapabilityAction::Settings, '', [], PrivateKey::fromFile(new File($keyPath)));
echo $request->header(OutboundHeader::Authorization)?->line();
```

```bash
curl -H 'X-Forwarded-Proto: https' -H "$AUTHORIZATION_LINE" http://neurosys.localhost/api/capability/v1/settings
```

The header is what lets the request past `.htaccess`'s HTTPS redirect. The CLI's own values are
`php -i`, or the same three handlers constructed in-process.
