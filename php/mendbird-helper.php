<?php
/**
 * Mendbird helper for PHP sites: Joomla, Drupal, Laravel and hand built. Version 1.2.0.
 *
 * Put this one file in your site's public folder. It lets Mendbird read the exact version of PHP and its add-ons,
 * Joomla or Drupal if it finds them, every Composer package the site has installed, the JavaScript packages in its
 * package-lock.json, and the server's system packages, so it can check each one against the lists of known security
 * flaws every morning. It looks
 * in this folder and up to three above it, and in the usual app folders beside each (app, etc/app, src and so on).
 *
 * How it works, in full, because you should be able to check:
 *
 * 1. Once a day Mendbird asks for the list at https://your-site/mendbird-helper.php, with the time and a signature
 *    made from the secret key below. The request carries nothing else: no commands, no settings, no code.
 * 2. If the signature is right and the time is within five minutes, this file replies with the list, signed with the
 *    same key so Mendbird knows it was not changed on the way. To anyone else it says "Not found".
 * 3. The list is names and version numbers, and two settings: whether PHP shows errors to visitors, and whether it
 *    announces its version. The system's packages come from the files its package manager keeps; nothing is run.
 *
 * It never reads your database, your .env or settings files, your content or anyone's details, never contacts
 * anyone, and never changes anything. Delete it and it stops. To see exactly what it sends, run this on the server:
 *
 *     php mendbird-helper.php --show
 *
 * Licence: MIT. The code is public at https://github.com/moonlathe-technologies/mendbird-helper
 */

// Written in by Mendbird when you downloaded this copy. "Make a new key" in your Mendbird account retires it.
const MENDBIRD_HELPER_KEY = '__MENDBIRD_KEY__';
const MENDBIRD_HELPER_HOST = '__MENDBIRD_HOST__';
const MENDBIRD_HELPER_VERSION = '1.2.0';

/**
 * The first of these folders that holds the named file: this one, then up to three above it, and beside each of those
 * the folders apps are usually kept in, as a site's public folder is often next to its code rather than inside it.
 */
function mendbird_helper_find($name)
{
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        foreach (array('', '/app', '/etc/app', '/src', '/application', '/backend', '/laravel', '/site', '/private') as $sub) {
            if (is_file($dir . $sub . '/' . $name)) {
                return $dir . $sub . '/' . $name;
            }
        }
        $dir = dirname($dir);
    }
    return null;
}

/** A JSON file as an array, or an empty one if it is missing or not JSON. */
function mendbird_helper_json($file)
{
    $data = $file ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : array();
}

/**
 * Every Composer package installed, from what Composer itself records in vendor/composer/installed.json, or else the
 * lock file. Packages for development only are left out, as they are not usually on a live site.
 */
function mendbird_helper_composer()
{
    $found = array();
    $installed = mendbird_helper_json(mendbird_helper_find('vendor/composer/installed.json'));
    if ($installed) {
        // Composer 2 wraps the list and names the development packages; Composer 1 is the bare list.
        $packages = isset($installed['packages']) ? $installed['packages'] : $installed;
        $dev = isset($installed['dev-package-names']) && is_array($installed['dev-package-names']) ? $installed['dev-package-names'] : array();
    } else {
        $lock = mendbird_helper_json(mendbird_helper_find('composer.lock'));
        $packages = isset($lock['packages']) ? $lock['packages'] : array();
        $dev = array();
    }
    foreach (is_array($packages) ? $packages : array() as $p) {
        if (isset($p['name'], $p['version']) && !in_array($p['name'], $dev, true)) {
            $found[] = array('kind' => 'composer', 'name' => (string) $p['name'], 'version' => ltrim((string) $p['version'], 'v'));
        }
    }
    return $found;
}

/**
 * The JavaScript packages the site is built with, from npm's record of what it installed (node_modules/.package-lock.json)
 * or else package-lock.json. Packages for development only are left out.
 */
function mendbird_helper_npm()
{
    $lock = mendbird_helper_json(mendbird_helper_find('node_modules/.package-lock.json'));
    if (!$lock) {
        $lock = mendbird_helper_json(mendbird_helper_find('package-lock.json'));
    }
    $found = array();
    $seen = array();
    // Lock files from npm 7 on key each package by its folder; older ones nest them under "dependencies".
    $packages = isset($lock['packages']) && is_array($lock['packages']) ? $lock['packages'] : array();
    if (!$packages && isset($lock['dependencies']) && is_array($lock['dependencies'])) {
        foreach ($lock['dependencies'] as $name => $p) {
            $packages['node_modules/' . $name] = $p;
        }
    }
    foreach ($packages as $path => $p) {
        $at = strrpos((string) $path, 'node_modules/');
        if ($at === false || !is_array($p) || !empty($p['dev']) || !isset($p['version'])) {
            continue;
        }
        $name = isset($p['name']) ? (string) $p['name'] : substr((string) $path, $at + 13);
        $key = $name . '@' . $p['version'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $found[] = array('kind' => 'npm', 'name' => $name, 'version' => (string) $p['version']);
        }
    }
    return $found;
}

/**
 * The server's system and its packages, from the files its package manager keeps (Debian and Ubuntu's dpkg status,
 * Alpine's apk database). Systems patch the web server, PHP and the rest without changing their headline version,
 * so this is what tells a patched nginx 1.26.3 from an old one. Named by source package, as security notices are.
 * Nothing is run; on a host that keeps these files out of reach, nothing is sent.
 */
function mendbird_helper_system()
{
    $os = @is_readable('/etc/os-release') ? (string) @file_get_contents('/etc/os-release') : '';
    if (!preg_match('/^ID="?([\w.-]+)/m', $os, $id)) {
        return array();
    }
    $found = array(array('kind' => 'os', 'name' => strtolower($id[1]), 'version' => preg_match('/^VERSION_ID="?([\w.]+)/m', $os, $v) ? $v[1] : ''));
    $packages = array();
    if (@is_readable('/var/lib/dpkg/status')) {
        foreach (preg_split('/\n\n+/', (string) @file_get_contents('/var/lib/dpkg/status')) as $entry) {
            if (!preg_match('/^Status: .* installed$/m', $entry) || !preg_match('/^Package: (\S+)/m', $entry, $name) || !preg_match('/^Version: (\S+)/m', $entry, $version)) {
                continue;
            }
            // "Source: glibc (2.41-12)" names the source package, and its version when that differs.
            if (preg_match('/^Source: (\S+)(?: \((\S+)\))?/m', $entry, $source)) {
                $name[1] = $source[1];
                $version[1] = isset($source[2]) ? $source[2] : $version[1];
            }
            $packages['deb ' . $name[1] . ' ' . $version[1]] = array('kind' => 'deb', 'name' => $name[1], 'version' => $version[1]);
        }
    } elseif (@is_readable('/lib/apk/db/installed')) {
        foreach (preg_split('/\n\n+/', (string) @file_get_contents('/lib/apk/db/installed')) as $entry) {
            if (preg_match('/^P:(\S+)/m', $entry, $name) && preg_match('/^V:(\S+)/m', $entry, $version)) {
                $origin = preg_match('/^o:(\S+)/m', $entry, $o) ? $o[1] : $name[1];
                $packages['apk ' . $origin . ' ' . $version[1]] = array('kind' => 'apk', 'name' => $origin, 'version' => $version[1]);
            }
        }
    }
    ksort($packages);
    return array_merge($found, array_values($packages));
}

/** The list: names, versions and two settings. Nothing else is ever read. */
function mendbird_helper_list()
{
    $software = array_merge(mendbird_helper_system(), array(array('kind' => 'php', 'name' => 'php', 'version' => PHP_VERSION)));
    foreach (get_loaded_extensions() as $ext) {
        $software[] = array('kind' => 'ext', 'name' => strtolower($ext), 'version' => (string) phpversion($ext));
    }
    $server = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    if (preg_match('/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m)) {
        $software[] = array('kind' => 'server', 'name' => strtolower($m[1]), 'version' => isset($m[2]) ? $m[2] : '');
    }

    $software = array_merge($software, mendbird_helper_composer(), mendbird_helper_npm());

    // Joomla keeps its version in a small manifest; Drupal in one line of its core. Only that line is read.
    $joomla = mendbird_helper_find('administrator/manifests/files/joomla.xml');
    if ($joomla && preg_match('/<version>([\d.]+)<\/version>/', (string) file_get_contents($joomla, false, null, 0, 4096), $m)) {
        $software[] = array('kind' => 'cms', 'name' => 'joomla', 'version' => $m[1]);
    }
    $drupal = mendbird_helper_find('core/lib/Drupal.php');
    if ($drupal && preg_match("/const VERSION = '([\d.]+)'/", (string) file_get_contents($drupal), $m)) {
        $software[] = array('kind' => 'cms', 'name' => 'drupal', 'version' => $m[1]);
    }

    return array(
        'helper' => array('kind' => 'php', 'version' => MENDBIRD_HELPER_VERSION),
        'software' => $software,
        'settings' => array(
            'debug_on' => in_array(strtolower((string) ini_get('display_errors')), array('1', 'on', 'stdout'), true),
            'php_version_announced' => (bool) ini_get('expose_php'),
        ),
    );
}

if (PHP_SAPI === 'cli') {
    if (in_array('--show', isset($argv) ? $argv : array(), true)) {
        echo json_encode(mendbird_helper_list(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    } else {
        echo "This is the Mendbird helper. Run it with --show to see exactly what it sends.\n";
    }
    exit;
}

// Answers Mendbird's signed request, and nobody else's.
$time = isset($_SERVER['HTTP_MENDBIRD_TIME']) ? (string) $_SERVER['HTTP_MENDBIRD_TIME'] : '';
$sign = isset($_SERVER['HTTP_MENDBIRD_SIGN']) ? strtolower((string) $_SERVER['HTTP_MENDBIRD_SIGN']) : '';
if (!ctype_digit($time) || abs(time() - (int) $time) > 300 || strlen($sign) !== 64
    || !hash_equals(hash_hmac('sha256', 'pull:' . MENDBIRD_HELPER_HOST . ':' . $time, MENDBIRD_HELPER_KEY), $sign)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$body = json_encode(mendbird_helper_list(), JSON_UNESCAPED_SLASHES);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Mendbird-Sign: ' . hash_hmac('sha256', 'reply:' . $time . ':' . $body, MENDBIRD_HELPER_KEY));
echo $body;
