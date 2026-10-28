# Mendbird helper for Laravel

Lets [Mendbird](https://mendbird.com) read the exact version of PHP, Laravel, every package your app runs and the
server's system packages, so it can check each one against the public lists of known security flaws every morning.

It reads names and version numbers, nothing else. It answers only Mendbird, takes no instructions, and never changes
anything. This is all of its code, so you can check that for yourself before you install it.

## Install

```sh
composer require mendbird/helper
```

Then add the two lines your Mendbird account shows you for this site to your `.env` file:

```sh
MENDBIRD_KEY=...
MENDBIRD_HOST=your-site.co.uk
```

If you cache your settings, run `php artisan config:cache` again. That is all: there is nothing to schedule.

To see exactly what it sends, run:

```sh
php artisan mendbird:show
```

## How it works

1. Once a day Mendbird asks for the list at `https://your-site/mendbird-helper`, with the time and a signature made
   from your key. The request carries nothing else: no commands, no settings, no code.
2. If the signature is right and the time is within five minutes, the helper replies with the list, signed with the
   same key so Mendbird knows it was not changed on the way. To anyone else it says `Not found`.
3. The list is PHP and its add-ons, the web server, the packages in `vendor/composer/installed.json` and
   `package-lock.json` (leaving out packages for development only), any second set a bundled tool carries in a folder
   of its own inside the app (named by its folder), and the server's system and its packages, read from
   `/etc/os-release` and the package manager's own files with nothing run. Plus two settings: whether Laravel's debug
   mode is on, and whether PHP announces its version.

The address is added with no middleware, so it sets no cookies and starts no session. Of your settings it reads its
own two lines and whether debug mode is on, nothing more. It never reads your database, your content or anyone's
details, never contacts anyone, and never changes anything. Remove the package and it stops.

Signing follows the same rules as every Mendbird helper: HMAC-SHA256 with the key as text, over
`pull:<host>:<time>` for the request and `reply:<time>:<body>` for the answer. The other helpers are at
[github.com/moonlathe-technologies/mendbird-helper](https://github.com/moonlathe-technologies/mendbird-helper).

## Licence

MIT.
