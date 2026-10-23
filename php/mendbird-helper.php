<?php
/**
 * Mendbird helper for PHP sites: Joomla, Drupal, Laravel and hand built. Version 1.0.0.
 *
 * Put this one file in your site's public folder. It lets Mendbird read the exact version of PHP and its add-ons,
 * Joomla or Drupal if it finds them, and every package listed in your composer.lock, so it can check each one
 * against the lists of known security flaws every morning.
 *
 * How it works, in full, because you should be able to check:
 *
 * 1. Once a day Mendbird asks for the list at https://your-site/mendbird-helper.php, with the time and a signature
 *    made from the secret key below. The request carries nothing else: no commands, no settings, no code.
 * 2. If the signature is right and the time is within five minutes, this file replies with the list, signed with the
 *    same key so Mendbird knows it was not changed on the way. To anyone else it says "Not found".
 * 3. The list is names and version numbers, and two settings: whether PHP shows errors to visitors, and whether it
 *    announces its version.
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
const MENDBIRD_HELPER_VERSION = '1.0.0';

/** The first of these folders that holds the named file: this one, then up to three above it. */
function mendbird_helper_find($name)
{
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        if (is_file($dir . '/' . $name)) {
            return $dir . '/' . $name;
        }
        $dir = dirname($dir);
    }
    return null;
}

/** The list: names, versions and two settings. Nothing else is ever read. */
function mendbird_helper_list()
{
    $software = array(array('kind' => 'php', 'name' => 'php', 'version' => PHP_VERSION));
    foreach (get_loaded_extensions() as $ext) {
        $software[] = array('kind' => 'ext', 'name' => strtolower($ext), 'version' => (string) phpversion($ext));
    }
    $server = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    if (preg_match('/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m)) {
        $software[] = array('kind' => 'server', 'name' => strtolower($m[1]), 'version' => isset($m[2]) ? $m[2] : '');
    }

    // Composer's lock file lists every package the site is built from, with its exact version. Only the names and
    // versions are taken; packages for development only are left out, as they are not usually on a live site.
    $lock = mendbird_helper_find('composer.lock');
    if ($lock) {
        $data = json_decode((string) file_get_contents($lock), true);
        foreach (isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : array() as $p) {
            if (isset($p['name'], $p['version'])) {
                $software[] = array('kind' => 'composer', 'name' => (string) $p['name'], 'version' => ltrim((string) $p['version'], 'v'));
            }
        }
    }

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
