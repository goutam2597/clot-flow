<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keep lang/en.json in step with the interface.
 *
 * Views and controllers write their strings as __('...') literals. Any that
 * en.json does not know about are untranslated forever and invisible to the
 * translation editors, so this command scans the codebase for every literal
 * and adds the missing ones (an English key is its own English value).
 *
 * Run after adding UI, or to audit: php artisan translations:sync
 */
class SyncTranslations extends Command
{
    protected $signature = 'translations:sync {--dry-run : Report what would be added without writing}';

    protected $description = 'Scan the codebase for translated strings and add missing keys to lang/en.json';

    public function handle(): int
    {
        $used = [];

        foreach (['resources/views', 'app', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! preg_match('/\.(blade\.php|php)$/', $file->getPathname())) {
                    continue;
                }

                $src = file_get_contents($file->getPathname());

                // __(), trans() and @lang with a simple literal first argument.
                preg_match_all('/(?:__|trans|@lang)\(\s*([\'"])(.+?)\1\s*[\),]/', $src, $matches);

                foreach ($matches[2] as $key) {
                    // Single-quoted PHP escapes \' to ' — the literal key seen
                    // at runtime has no backslash.
                    $key = str_replace("\\'", "'", trim($key));

                    if ($key !== '' && $key !== 'native name') {
                        $used[$key] = true;
                    }
                }
            }
        }

        $path = lang_path('en.json');
        $existing = json_decode((string) file_get_contents($path), true) ?: [];
        $added = array_diff_key($used, $existing);

        $this->info('Strings used in the interface : ' . count($used));
        $this->info('Already in en.json            : ' . count($existing));

        if (empty($added)) {
            $this->components->success('en.json is already in sync.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach (array_keys($added) as $key) {
                $this->line('  + ' . $key);
            }
            $this->comment(count($added) . ' strings would be added.');

            return self::SUCCESS;
        }

        // Known keys keep the source file's order; new keys append sorted,
        // so a diff against a language file stays reviewable.
        $merged = $existing;
        foreach (array_keys($added) as $key) {
            $merged[$key] = $key;
        }

        $sorted = [];
        $keys = array_keys($merged);
        sort($keys, SORT_FLAG_CASE | SORT_STRING);
        foreach ($keys as $key) {
            $sorted[$key] = $merged[$key];
        }

        file_put_contents(
            $path,
            json_encode($sorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->components->success(count($added) . ' strings added to en.json.');

        return self::SUCCESS;
    }
}
