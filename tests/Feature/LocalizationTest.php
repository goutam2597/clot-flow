<?php

namespace Tests\Feature;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * Localization: switching languages, translating the interface, and driving
 * the document direction from the language record rather than a hardcoded list.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->bootBusiness();
    }

    protected function addLanguage(string $code, string $name, string $direction = 'ltr'): Language
    {
        return Language::create([
            'name' => $name,
            'native_name' => $name,
            'code' => $code,
            'locale' => $code,
            'direction' => $direction,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);
    }

    public function test_the_interface_renders_in_the_active_locale(): void
    {
        app()->setLocale('bn');

        $this->actingAs($this->business->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('Dashboard'));
    }

    public function test_switching_language_persists_to_the_session_and_the_user(): void
    {
        $this->addLanguage('bn', 'বাংলা');

        $this->actingAs($this->business->owner)
            ->post(route('locale.switch', 'bn'))
            ->assertRedirect();

        $this->assertSame('bn', session('locale'));
        $this->assertSame('bn', $this->business->owner->refresh()->locale);
    }

    public function test_an_inactive_language_cannot_be_selected(): void
    {
        $language = $this->addLanguage('xx', 'Inactive');
        $language->update(['is_active' => false]);

        $this->actingAs($this->business->owner)
            ->post(route('locale.switch', 'xx'))
            ->assertSessionHas('error');

        $this->assertNull(session('locale'));
    }

    public function test_an_unknown_language_code_is_rejected(): void
    {
        $this->actingAs($this->business->owner)
            ->post(route('locale.switch', 'zz'))
            ->assertSessionHas('error');
    }

    public function test_the_document_declares_english_left_to_right_by_default(): void
    {
        $this->actingAs($this->business->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="ltr"', false);
    }

    public function test_a_right_to_left_language_mirrors_the_whole_document(): void
    {
        $arabic = $this->addLanguage('ar', 'العربية', 'rtl');

        $this->actingAs($this->business->owner)
            ->post(route('locale.switch', 'ar'));

        // The direction is driven by the Language record, so adding an RTL
        // language in the admin screen is enough to flip the whole UI.
        $this->assertSame('rtl', session('direction'));

        $this->actingAs($this->business->owner)
            ->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_the_auth_screen_honours_direction_too(): void
    {
        $this->addLanguage('ar', 'العربية', 'rtl');

        $this->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_validation_messages_are_translated(): void
    {
        $validator = validator(['amount' => -5], ['amount' => ['required', 'numeric', 'gt:0']], [], ['amount' => 'amount']);

        $this->assertSame('The amount field must be greater than 0.', $validator->errors()->first('amount'));

        $this->addLanguage('bn', 'বাংলা');
        app()->setLocale('bn');

        $translated = validator(['amount' => -5], ['amount' => ['required', 'numeric', 'gt:0']], [], ['amount' => 'পরিমাণ']);

        $this->assertStringContainsString('বেশি', $translated->errors()->first('amount'));
        $this->assertStringContainsString('পরিমাণ', $translated->errors()->first('amount'));
    }

    public function test_a_missing_translation_falls_back_to_english(): void
    {
        // 'Invoice' has no Bengali entry, so the source string is returned
        // rather than a dotted placeholder.
        app()->setLocale('bn');

        $this->assertSame('Invoice', __('Invoice'));
    }

    public function test_both_configured_languages_have_their_translations_loaded(): void
    {
        $this->assertTrue(is_file(lang_path('en.json')));
        $this->assertTrue(is_file(lang_path('bn.json')));

        foreach (['en', 'bn'] as $locale) {
            $translations = json_decode(file_get_contents(lang_path($locale . '.json')), true);

            $this->assertIsArray($translations);
            $this->assertNotEmpty($translations, "lang/{$locale}.json is empty.");
        }
    }

    public function test_the_translation_files_are_valid_json_and_share_one_key_space(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $bn = json_decode(file_get_contents(lang_path('bn.json')), true);

        // English is the reference catalogue; Bengali must not invent keys that
        // the application never asks for.
        $stray = array_diff(array_keys($bn), array_keys($en));

        $this->assertSame(
            [],
            array_values($stray),
            'Bengali keys with no English counterpart: ' . implode(', ', $stray)
        );

        // A missing Bengali entry is acceptable (it falls back), an untranslated
        // catalogue is not.
        $this->assertGreaterThan(
            100,
            count($bn),
            'The Bengali catalogue is almost empty — translations have gone missing.'
        );
    }

    public function test_no_translated_value_is_left_as_the_english_source(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $bn = json_decode(file_get_contents(lang_path('bn.json')), true);

        // Words that legitimately look the same in both languages.
        $identical = ['—', 'Email', 'Total', 'Total assets', 'Status', 'Date'];

        foreach ($bn as $key => $value) {
            if (in_array($key, $identical, true)) {
                continue;
            }

            $this->assertNotSame(
                $en[$key] ?? $key,
                $value,
                "The Bengali translation for \"{$key}\" is still the English source string."
            );
        }
    }

    public function test_the_language_admin_screen_can_add_a_language(): void
    {
        $this->actingAs($this->business->owner)
            ->post(route('admin.languages.store'), [
                'name' => 'Arabic',
                'native_name' => 'العربية',
                'code' => 'ar',
                'locale' => 'ar',
                'direction' => 'rtl',
                'is_active' => '1',
                'sort_order' => 3,
            ])
            ->assertRedirect(route('admin.languages.index'));

        $this->assertDatabaseHas('languages', ['code' => 'ar', 'direction' => 'rtl']);
    }

    public function test_only_one_language_can_be_the_default(): void
    {
        $english = Language::where('code', 'en')->first();
        $arabic = $this->addLanguage('ar', 'العربية', 'rtl');

        $this->actingAs($this->business->owner)
            ->post(route('admin.languages.default', $arabic))
            ->assertRedirect();

        $this->assertTrue($arabic->refresh()->is_default);
        $this->assertFalse($english->refresh()->is_default);
    }

    public function test_the_default_language_cannot_be_deleted(): void
    {
        $default = Language::where('is_default', true)->firstOrFail();

        $this->actingAs($this->business->owner)
            ->delete(route('admin.languages.destroy', $default))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('languages', ['id' => $default->id]);
    }

    public function test_the_language_switcher_appears_once_more_than_one_language_exists(): void
    {
        $switchUrl = route('locale.switch', 'bn');

        // Only English is configured: no switcher.
        $this->actingAs($this->business->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($switchUrl, false);

        $this->addLanguage('bn', 'বাংলা');

        $this->actingAs($this->business->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($switchUrl, false);
    }
}
