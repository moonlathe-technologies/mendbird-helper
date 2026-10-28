<?php

namespace Mendbird\Helper;

/** The list: names, versions and two settings. Nothing else is ever read. */
final class Lister
{
    public const VERSION = '1.2.0';

    // Folders that hold no code of their own, or whose packages are read from the record beside them.
    private const SKIP = ['.git', '.svn', 'node_modules', 'vendor', 'bower_components', 'cache', 'caches', 'tmp', 'temp',
        'logs', 'log', 'sessions', 'uploads', 'images', 'media', 'storage', 'backups'];

    public static function list(string $base): array
    {
        $software = [...self::system(), ['kind' => 'php', 'name' => 'php', 'version' => PHP_VERSION]];
        foreach (get_loaded_extensions() as $ext) {
            $software[] = ['kind' => 'ext', 'name' => strtolower($ext), 'version' => (string) phpversion($ext)];
        }
        $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (preg_match('/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m)) {
            $software[] = ['kind' => 'server', 'name' => strtolower($m[1]), 'version' => $m[2] ?? ''];
        }

        return [
            'helper' => ['kind' => 'laravel', 'version' => self::VERSION],
            'software' => [...$software, ...self::packages($base)],
            'settings' => [
                // Laravel's debug mode shows full error pages, with code and settings, to every visitor.
                'debug_on' => (bool) config('app.debug'),
                'php_version_announced' => (bool) ini_get('expose_php'),
            ],
        ];
    }

    /**
     * The app's packages, Laravel's own among them, and any second set inside it, such as a file browser bundled in
     * public, which is never updated with the rest. The app's own set is sent as it is; any other says which folder it
     * is in. Links are not followed, and the search stops after 20,000 folders or 10 levels.
     */
    private static function packages(string $base): array
    {
        $found = [];
        $scripts = [];
        $queue = [[$base, 0]];
        for ($seen = 0; $queue && $seen < 20000; $seen++) {
            [$dir, $depth] = array_shift($queue);
            $where = ltrim(substr($dir, strlen($base)), '/');
            foreach ([...self::composer($dir), ...self::npm($dir)] as $p) {
                $p += $where === '' ? [] : ['where' => $where];
                $found[implode(' ', $p)] = $p;
            }
            foreach ($depth < 10 ? (@scandir($dir) ?: []) : [] as $name) {
                $path = $dir . '/' . $name;
                if ($name === '.' || $name === '..' || is_link($path)) {
                    continue;
                }
                if (is_dir($path) && !in_array(strtolower($name), self::SKIP, true)) {
                    $queue[] = [$path, $depth + 1];
                } elseif (count($scripts) < 1000 && str_ends_with($name, '.js') && is_file($path) && ($script = self::script($path, $where))) {
                    // Script libraries copied into the app by hand, which no package record lists.
                    $scripts[] = $script;
                }
            }
        }
        return [...array_values($found), ...$scripts];
    }

    /**
     * A script file's own description of itself: its opening comment, where libraries put their name and version, and
     * the first version number its code sets. Mendbird works out which library it is. Only files that give a version
     * are sent.
     */
    private static function script(string $path, string $where): ?array
    {
        $size = (int) @filesize($path);
        $text = $size > 0 && $size < 3000000 ? (string) @file_get_contents($path) : '';
        $about = '';
        if (preg_match('~^\s*(/\*.*?\*/|(?://[^\n]*\n\s*)+)~s', substr($text, 0, 1024), $m)) {
            $lines = array_filter(array_map(fn ($line) => trim(preg_replace('~^\s*(?:/\*+!?|\*+/?|//+!?)\s*|\s*\*+/\s*$~', '', $line)), explode("\n", $m[1])), 'strlen');
            $about = substr(trim(preg_replace('/[^\w .+:~@\/\-|(),!#=<>&;\']+/', ' ', implode(' | ', $lines))), 0, 160);
        }
        $version = preg_match('/\b(?:VERSION|version)\s*[:=]\s*["\']v?(\d+\.\d+\.\d+)["\']/', $text, $v) ? $v[1] : '';
        if ($version === '' && !preg_match('/\d+\.\d+/', $about)) {
            return null;
        }
        return ['kind' => 'script', 'name' => basename($path), 'version' => $version, 'about' => $about] + ($where === '' ? [] : ['where' => $where]);
    }

    /** A JSON file as an array, or an empty one if it is missing or not JSON. */
    private static function json(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Composer's record of what is installed in one folder, or else its lock file. Only names and versions are
     * taken, and packages for development only are left out.
     */
    private static function composer(string $dir): array
    {
        $data = self::json($dir . '/vendor/composer/installed.json');
        $lock = $data ? [] : self::json($dir . '/composer.lock');
        $packages = $data ? ($data['packages'] ?? (array_is_list($data) ? $data : [])) : ($lock['packages'] ?? []);
        $dev = array_flip(is_array($data['dev-package-names'] ?? null) ? $data['dev-package-names'] : []);
        $found = [];
        foreach (is_array($packages) ? $packages : [] as $p) {
            if (isset($p['name'], $p['version']) && !isset($dev[$p['name']])) {
                $found[] = ['kind' => 'composer', 'name' => (string) $p['name'], 'version' => ltrim((string) $p['version'], 'v')];
            }
        }
        return $found;
    }

    /** The JavaScript packages in one folder, from npm's record or its lock file, leaving out those for development. */
    private static function npm(string $dir): array
    {
        $lock = self::json($dir . '/node_modules/.package-lock.json') ?: self::json($dir . '/package-lock.json');
        $found = [];
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $path => $p) {
            $at = strrpos((string) $path, 'node_modules/');
            if ($at !== false && is_array($p) && empty($p['dev']) && isset($p['version'])) {
                $found[] = ['kind' => 'npm', 'name' => (string) ($p['name'] ?? substr((string) $path, $at + 13)), 'version' => (string) $p['version']];
            }
        }
        return $found;
    }

    /**
     * The server's system and its packages, from the files its package manager keeps (dpkg on Debian and Ubuntu, apk
     * on Alpine). Systems patch the web server, PHP and the rest without changing their headline version, so this is
     * what tells a patched server from an old one. Nothing is run; where the files are out of reach, nothing is sent.
     */
    private static function system(): array
    {
        $os = @is_readable('/etc/os-release') ? (string) @file_get_contents('/etc/os-release') : '';
        if (!preg_match('/^ID="?([\w.-]+)/m', $os, $id)) {
            return [];
        }
        $packages = [];
        if (@is_readable('/var/lib/dpkg/status')) {
            foreach (preg_split('/\n\n+/', (string) @file_get_contents('/var/lib/dpkg/status')) as $entry) {
                if (preg_match('/^Status: .* installed$/m', $entry) && preg_match('/^Package: (\S+)/m', $entry, $name) && preg_match('/^Version: (\S+)/m', $entry, $version)) {
                    // "Source: glibc (2.41-12)" names the source package, as security notices do, and its version.
                    if (preg_match('/^Source: (\S+)(?: \((\S+)\))?/m', $entry, $source)) {
                        [$name[1], $version[1]] = [$source[1], $source[2] ?? $version[1]];
                    }
                    $packages["deb {$name[1]} {$version[1]}"] = ['kind' => 'deb', 'name' => $name[1], 'version' => $version[1]];
                }
            }
        } elseif (@is_readable('/lib/apk/db/installed')) {
            foreach (preg_split('/\n\n+/', (string) @file_get_contents('/lib/apk/db/installed')) as $entry) {
                if (preg_match('/^P:(\S+)/m', $entry, $name) && preg_match('/^V:(\S+)/m', $entry, $version)) {
                    $origin = preg_match('/^o:(\S+)/m', $entry, $o) ? $o[1] : $name[1];
                    $packages["apk {$origin} {$version[1]}"] = ['kind' => 'apk', 'name' => $origin, 'version' => $version[1]];
                }
            }
        }
        ksort($packages);
        $version = preg_match('/^VERSION_ID="?([\w.]+)/m', $os, $v) ? $v[1] : '';
        return [['kind' => 'os', 'name' => strtolower($id[1]), 'version' => $version], ...array_values($packages)];
    }
}
