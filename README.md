# Mendbird helper

The helper tells [Mendbird](https://mendbird.com) the exact version of each piece of software a website runs, so
Mendbird can check every one against the public lists of known security flaws each morning.

It reads names and version numbers, nothing else. It answers only Mendbird, takes no instructions, and never changes
anything. This is all of its code, so you can check that for yourself before you install it. The page written for
site owners and IT teams is at [mendbird.com/helper](https://mendbird.com/helper).

## The five helpers

| Folder | For | How it talks to Mendbird |
| --- | --- | --- |
| [`wordpress/`](wordpress/mendbird-helper.php) | WordPress sites. A plugin, uploaded from the dashboard. | Answers a signed request at `/?mendbird-helper`. |
| [`laravel/`](laravel/) | Laravel apps. A Composer package, `mendbird/helper`, with two lines for `.env`. | Answers a signed request at `/mendbird-helper`. |
| [`php/`](php/mendbird-helper.php) | Joomla, Drupal and hand built PHP sites. One file. | Answers a signed request at `/mendbird-helper.php`. |
| [`linux/`](linux/mendbird-helper) | Linux servers you run yourself, and the Python, Node.js, PHP, Ruby, Rust and Go apps on them. A POSIX shell script, run daily from cron. | Posts a signed list to `https://mendbird.com/api/helper`. |
| [`github/`](github/mendbird.yml) | Sites deployed from a GitHub repository: Cloudflare Workers and Pages, Vercel, Netlify, AWS Lambda. A workflow file. | Posts a signed list to `https://mendbird.com/api/helper`. |

The copies here hold placeholders such as `__MENDBIRD_KEY__`. The copy you download from your Mendbird account has
your site's own key, address and number written in. The GitHub workflow holds no key: it reads it from a repository
secret called `MENDBIRD_KEY`. The program in [`github/mendbird-helper.mjs`](github/mendbird-helper.mjs) is the one
written into the workflow file.

## What each one reads

- **WordPress:** WordPress, every plugin and theme with its version and whether it is switched on, PHP and its
  add-ons, the database and the web server, and on a Debian, Ubuntu or Alpine server the system and its packages,
  read from `/etc/os-release` and the package manager's own files (it runs nothing). Libraries a plugin bundles are
  left to the plugin's own security notices, as updating the plugin is what fixes them. Five settings: whether debug mode shows errors to visitors, whether admins
  can edit code from the dashboard, whether plugins update themselves, whether PHP announces its version, and how many
  admin accounts there are (a number, never names).
- **Laravel:** PHP and its add-ons, the web server, the packages in `vendor/composer/installed.json` (Laravel itself
  among them, leaving out packages for development only) and in `package-lock.json`, any second set a bundled tool
  carries in a folder of its own inside the app (named by its folder), and the system and its packages, as for PHP.
  Two settings: whether Laravel's debug mode is on,
  and whether PHP announces its version.
- **PHP:** PHP and its add-ons, the web server, Joomla or Drupal's version, every Composer package installed (from
  `vendor/composer/installed.json`, or else `composer.lock`) and the JavaScript packages in `package-lock.json`,
  leaving out packages for development only. It searches the whole project its folder belongs to (found by looking up
  to three folders above, and in the usual app folders beside each: `app`, `etc/app`, `src` and so on), so a second
  set inside a bundled tool, such as a file browser with its own `vendor` folder, is sent too, named by its folder.
  It skips uploads, caches, logs and version control, follows no links, and stops after 20,000 folders. On a Debian,
  Ubuntu or Alpine server it also sends the system and its packages, read from `/etc/os-release` and the package
  manager's own files (it runs nothing), as the system patches the web server without changing its version. Two settings:
  whether PHP shows errors to visitors, and whether it announces its version.
- **Linux:** the operating system and its installed packages (via `dpkg-query`, `rpm` or `apk`), and the versions of
  the web server, PHP, Node.js and the database. For each app folder you list on its `APPS` line, it reads the
  packages the app is built from, only ever reading files, never running anything in the app:
  - Python: the package folders in `venv`, `.venv` or `env` (`name-version.dist-info`), or else pinned
    `requirements*.txt`;
  - Node.js: `node_modules/*/package.json`, in the app's own `node_modules` and any deeper one;
  - PHP: `vendor/composer/installed.json` in the app's own `vendor` and any deeper one a bundled tool brings, without
    packages for development only;
  - Ruby and Rust: `Gemfile.lock` and `Cargo.lock`;
  - Go: `go.mod`, and the module list every Go program carries inside it as plain text, plus its Go version.
- **GitHub:** the packages in `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `composer.lock`, `requirements*.txt`,
  `poetry.lock`, `uv.lock`, `Pipfile.lock`, `Gemfile.lock`, `go.mod`, `Cargo.lock`, `gradle.lockfile`,
  `packages.lock.json`, `mix.lock` and `pubspec.lock`, Composer's record in any `vendor` folder committed to the
  repository, and the Node.js version in `.nvmrc` or `.node-version`. It
  leaves out packages for development only where the file says which they are, and folders of tests, examples and
  docs.

A package found in a second set, away from the site's main one, carries the folder it is in (`"where"`), so the
report can say which copy needs updating.

The PHP, Laravel, Linux (for each app) and GitHub helpers also look at script files copied into the site by hand, such
as a copy of jQuery in a `javascripts` folder, which no package record lists. For each `.js` file that gives a version
they send its name, its opening comment (where libraries put their name and version) and the first version its code
sets (`"kind": "script"`, with `"about"` and `"where"`), at most 1,000 files. Mendbird works out which library each
one is and checks it like any other script. A file only matters if a page loads it, which the file cannot show, so
the report keeps these at minor unless the page visitors see loads that same version. The WordPress plugin leaves
scripts to each plugin's and theme's own security notices.

None of them read content, customer or user details, passwords, keys, `wp-config.php`, `.env`, logs or your code.

## How messages are signed

Each site has its own key. Every message is signed with HMAC-SHA256, using the key as text, over a line that says what
kind of message it is:

- `pull:<host>:<time>` for Mendbird's request to the WordPress plugin or the PHP file;
- `reply:<time>:<body>` for their answer;
- `push:<time>:<body>` for the Linux and GitHub helpers' lists.

`<time>` is Unix seconds. Anything more than five minutes out, or seen before, is refused. Without a correct
signature, the WordPress plugin does nothing (the site shows its normal page) and the PHP file says `Not found`.

## See what it sends

- WordPress: Tools, Mendbird helper.
- Laravel: `php artisan mendbird:show`
- PHP: `php mendbird-helper.php --show`
- Linux: `mendbird-helper --show`
- GitHub: every run prints the list in the workflow's log. Locally, `node github/mendbird-helper.mjs --show` in your
  repository.

## Tests

`node test/run.mjs` runs every helper for real in Docker and checks it from the outside, the way Mendbird talks to it:
WordPress with MariaDB, a new Laravel app with the package installed through Composer, a plain PHP site, Debian 12, Ubuntu 24.04, Alpine 3.20 and Rocky Linux 9, a Debian server
running a real Django, Node.js, PHP, Ruby, Rust and Go app, and the GitHub program in a clean Node.js container. It checks the signatures, that strangers get nothing, that only the agreed
fields are sent, and that nothing personal ever appears. Run one part with `node test/run.mjs wordpress` (or `laravel`, `php`,
`linux`, `apps`, `github`). It needs Node.js 22 and Docker.

## Security

If you find a problem, email [hello@mendbird.com](mailto:hello@mendbird.com). We are happy to answer questions or help
with a security review.

## Licence

MIT, except the WordPress plugin, which is GPL-2.0-or-later. See [LICENSE](LICENSE).
