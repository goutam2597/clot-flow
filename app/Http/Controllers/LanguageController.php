<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * UI language management.
 *
 * Languages drive the topbar switcher and the direction of the document, so exactly
 * one row is default, the default can never be deleted, and the locale in use can
 * never be removed either.
 */
class LanguageController extends Controller
{
    protected int $perPage = 15;

    public function index(): View
    {
        $languages = Language::query()
            ->withCount('translations')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage);

        return view('admin.languages.index', [
            'languages' => $languages,
            'directions' => ['ltr' => __('Left to right'), 'rtl' => __('Right to left')],
            'stats' => [
                'total' => Language::count(),
                'active' => Language::where('is_active', true)->count(),
                // The size of the translatable surface: every string the UI
                // can render in any language (lang/en.json is the source).
                'strings' => count(json_decode((string) file_get_contents(lang_path('en.json')), true) ?: []),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateLanguage($request);

        $language = new Language();
        $language->fill($validated)->save();

        if ($language->is_default) {
            $this->clearOtherDefaults($language);
        }

        AuditLogger::log(
            'created',
            Language::class,
            $language->id,
            null,
            $language->only(['name', 'native_name', 'code', 'locale', 'direction', 'is_active', 'is_default', 'sort_order']),
            __('Language created')
        );

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __('Language added successfully.'));
    }

    public function update(Request $request, Language $language): RedirectResponse
    {
        $validated = $this->validateLanguage($request, $language);

        $old = $language->only(['name', 'native_name', 'code', 'locale', 'direction', 'is_active', 'is_default', 'sort_order']);

        $language->fill($validated)->save();

        if ($language->is_default) {
            $this->clearOtherDefaults($language);
        }

        AuditLogger::log(
            'updated',
            Language::class,
            $language->id,
            $old,
            $language->only(['name', 'native_name', 'code', 'locale', 'direction', 'is_active', 'is_default', 'sort_order']),
            __('Language updated')
        );

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __('Language updated successfully.'));
    }

    /**
     * Promote a language to default. The default is always active so the topbar
     * switcher and the document direction stay valid.
     */
    public function makeDefault(Language $language): RedirectResponse
    {
        $previous = Language::where('is_default', true)->pluck('code')->first();

        Language::where('is_default', true)->where('id', '!=', $language->id)->update(['is_default' => false]);

        $language->fill(['is_default' => true, 'is_active' => true])->save();

        AuditLogger::log(
            'updated',
            Language::class,
            $language->id,
            ['is_default' => $previous],
            ['is_default' => $language->code],
            __('Default language changed')
        );

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __(':name is now the default language.', ['name' => $language->name]));
    }

    public function destroy(Language $language): RedirectResponse
    {
        if ($language->is_default) {
            return back()->with('error', __('The default language cannot be deleted.'));
        }

        if ($language->code === app()->getLocale()) {
            return back()->with('error', __('You cannot delete the language currently in use.'));
        }

        $snapshot = $language->only(['name', 'code', 'locale', 'direction']);
        $languageId = $language->id;
        $language->delete();

        // The delete confirmation promises this — the string file goes with
        // the row. The default (English) can never be reached from here.
        @unlink(lang_path($language->code . '.json'));

        AuditLogger::log('deleted', Language::class, $languageId, $snapshot, null, __('Language deleted'));

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __('Language deleted successfully.'));
    }

    /**
     * Ensure only the given row is flagged as default.
     */
    protected function clearOtherDefaults(Language $language): void
    {
        Language::where('is_default', true)
            ->where('id', '!=', $language->id)
            ->update(['is_default' => false]);
    }

    protected function validateLanguage(Request $request, ?Language $language = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'native_name' => ['nullable', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:10',
                'regex:/^[a-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/',
                Rule::unique('languages', 'code')->ignore($language?->id),
            ],
            'locale' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'string', Rule::in(['ltr', 'rtl'])],
            'flag' => ['nullable', 'string', 'max:8'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ], [], [
            'native_name' => __('native name'),
        ]);
    }

    /**
     * The key/value translation editor for one language.
     *
     * Translations live in lang/{code}.json as flat "English string" -> value
     * pairs — the exact mapping every __('...') call resolves against. English
     * (the default) is the source of truth for which keys exist, so a freshly
     * added language always appears here with every key it can fill in.
     */
    public function translations(Language $language): View
    {
        $entries = collect($this->translationEntries($language))->values();

        return view('admin.languages.translations', [
            'language' => $language,
            'entries' => $entries,
            'stats' => [
                'total' => $entries->count(),
                'translated' => $entries->where('translated', true)->count(),
            ],
        ]);
    }

    /**
     * Save edited values back into lang/{code}.json.
     *
     * Source-file keys are the known set; values for keys a view uses that
     * en.json does not list are accepted too (the editor's "Add a string" row)
     * and appended after the known block. An emptied value means
     * "untranslated": the key is dropped so English shows through again.
     */
    public function saveTranslations(Request $request, Language $language): RedirectResponse
    {
        $source = $this->readJson('en');
        $values = (array) $request->input('t', []);

        $target = $this->readJson($language->code);
        $written = 0;

        foreach ($values as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || mb_strlen($key) > 300) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                if (array_key_exists($key, $target)) {
                    unset($target[$key]);
                    $written++;
                }
                continue;
            }

            if (($target[$key] ?? null) !== $value) {
                $target[$key] = $value;
                $written++;
            }
        }

        // Known keys keep the source order so diffs between locales read line
        // by line; custom keys (submitted or saved earlier) follow, sorted.
        $known = [];
        foreach (array_keys($source) as $key) {
            if (array_key_exists($key, $target)) {
                $known[$key] = $target[$key];
            }
        }

        $custom = collect($target)
            ->reject(fn ($value, $key) => array_key_exists($key, $known))
            ->sortKeys()
            ->all();

        file_put_contents(
            lang_path($language->code . '.json'),
            json_encode($known + $custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        AuditLogger::log('updated', Language::class, $language->id, null, [
            'language' => $language->code,
            'entries_changed' => $written,
        ], __('Translations updated'));

        return redirect()
            ->route('admin.languages.translations', $language)
            ->with('success', __(':count translations saved for :name.', ['count' => $written, 'name' => $language->name]));
    }

    /**
     * Every translatable key, merged with the language's current value.
     */
    protected function translationEntries(Language $language): array
    {
        $source = $this->readJson('en');
        $target = $this->readJson($language->code);

        $entries = [];

        foreach ($source as $key => $english) {
            $has = array_key_exists($key, $target) && trim((string) $target[$key]) !== '';
            $entries[] = [
                'key' => $key,
                'source' => $english,
                'value' => $has ? $target[$key] : '',
                'translated' => $has,
            ];
        }

        // Custom keys — strings a view uses that en.json never listed. Their
        // key IS the English source, so they render as any other row.
        foreach ($target as $key => $value) {
            if (array_key_exists($key, $source)) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'source' => $key,
                'value' => $value,
                'translated' => trim((string) $value) !== '',
            ];
        }

        // Untranslated first: that is the to-do list, and it only grows at the
        // top when new English strings ship.
        usort($entries, function ($a, $b) {
            return [$a['translated'], $a['key']] <=> [$b['translated'], $b['key']];
        });

        return $entries;
    }

    /**
     * The JSON string file for a language code.
     */
    protected function readJson(string $code): array
    {
        $path = lang_path($code . '.json');

        if (! is_file($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }
}
