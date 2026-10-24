<?php

namespace Mendbird\Helper;

/** The list: names, versions and two settings. Nothing else is ever read. */
final class Lister
{
    public const VERSION = '1.0.0';

    public static function list(string $base): array
    {
        $software = [['kind' => 'php', 'name' => 'php', 'version' => PHP_VERSION]];
        foreach (get_loaded_extensions() as $ext) {
            $software[] = ['kind' => 'ext', 'name' => strtolower($ext), 'version' => (string) phpversion($ext)];
        }
        $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (preg_match('/^(apache|nginx|litespeed|openresty)(?:\/([\d.]+))?/i', $server, $m)) {
            $software[] = ['kind' => 'server', 'name' => strtolower($m[1]), 'version' => $m[2] ?? ''];
        }

        // Composer's record of what is installed lists every package with its exact version, Laravel's own included.
        // Only names and versions are taken, and packages for development only are left out.
        $installed = $base . '/vendor/composer/installed.json';
        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data['packages'] ?? null) ? $data['packages'] : (array_is_list((array) $data) ? (array) $data : []);
            $dev = array_flip(is_array($data['dev-package-names'] ?? null) ? $data['dev-package-names'] : []);
            foreach ($packages as $p) {
                if (isset($p['name'], $p['version']) && !isset($dev[$p['name']])) {
                    $software[] = ['kind' => 'composer', 'name' => (string) $p['name'], 'version' => ltrim((string) $p['version'], 'v')];
                }
            }
        }

        return [
            'helper' => ['kind' => 'laravel', 'version' => self::VERSION],
            'software' => $software,
            'settings' => [
                // Laravel's debug mode shows full error pages, with code and settings, to every visitor.
                'debug_on' => (bool) config('app.debug'),
                'php_version_announced' => (bool) ini_get('expose_php'),
            ],
        ];
    }
}
