<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Support\AuditLogger;
use App\Support\BrandLogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Business settings.
 *
 * Every field is declared once, together with the settings group it is persisted
 * under, so the form, the validation rules and the audit trail cannot drift apart.
 * Values are read and written through Setting::get()/set().
 */
class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $options = $this->optionSets();
        $tabs = $this->settingsTabs();

        return view('admin.settings.index', [
            'groups' => $this->groupedFields($options),
            'tabs' => $tabs,
            'active' => $request->query('tab', 'general'),
            'taxDefault' => optional(TaxRate::find(Setting::get('default_tax_rate_id')))->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $options = $this->optionSets();
        $fields = $this->fields($options);

        $rules = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = $field['rules'];
        }

        $request->validate($rules, [], $this->attributeNames($fields));

        $written = [];

        DB::transaction(function () use ($request, $fields, &$written) {
            foreach ($fields as $key => $field) {
                $value = $field['type'] === 'boolean'
                    ? $request->boolean($key)
                    : ($request->input($key) ?? null);

                Setting::set($key, $value, $field['group'], $field['store_type']);

                $written[$field['group'] . '.' . $key] = is_scalar($value)
                    ? ($field['type'] === 'boolean' ? ($value ? '1' : '0') : (string) $value)
                    : null;
            }
        });

        AuditLogger::log('updated', Setting::class, null, null, $written, __('Settings updated'));

        // Land back on the tab the form was submitted from, so a validation
        // error puts the field that failed on screen.
        return redirect()
            ->route('admin.settings.index', ['tab' => $request->input('_tab', 'general')])
            ->with('success', __('Settings saved successfully.'));
    }

    /**
     * Replace the brand logo (SVG or PNG), which the sign-in page and the
     * sidebar show in place of the letter mark and the name.
     *
     * This lives outside the settings form's field table on purpose: a logo
     * is a file rather than a value, its validation is its own, and the main
     * form would need multipart for a field most tabs never touch.
     */
    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['nullable', 'file', 'max:1024'],
        ]);

        $file = $request->file('logo');

        if (! $file) {
            return back()->withErrors(['logo' => __('Choose a logo file to upload.')]);
        }

        $kind = strtolower($file->getClientOriginalExtension());

        if (! in_array($kind, ['svg', 'png'], true)) {
            return back()->withErrors(['logo' => __('The logo must be an SVG or PNG file.')]);
        }

        if ($kind === 'png') {
            // A PNG is shown as-is everywhere, so it must genuinely decode.
            if (! @getimagesize($file->getRealPath())) {
                return back()->withErrors(['logo' => __('The file must be a valid PNG image.')]);
            }
        }

        try {
            BrandLogo::storeFrom($file->getRealPath(), $kind);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['logo' => __('The file must be a valid SVG.')]);
        }

        AuditLogger::log('updated', Setting::class, null, null, [
            BrandLogo::SETTING_GROUP . '.' . BrandLogo::SETTING_KEY => BrandLogo::type(),
        ], __('Brand logo updated'));

        return back()->with('success', __('Brand logo updated. It replaces the name on the sign-in page and the sidebar.'));
    }

    public function removeLogo(): RedirectResponse
    {
        BrandLogo::delete();

        AuditLogger::log('updated', Setting::class, null, null, [
            BrandLogo::SETTING_GROUP . '.' . BrandLogo::SETTING_KEY => '',
        ], __('Brand logo removed'));

        return back()->with('success', __('Brand logo removed. The name is shown again.'));
    }

    /**
     * The settings groups that get their own tab, in display order.
     *
     * A group with no editable fields (languages, taxes) is a door to its
     * dedicated screen, not an empty tab -- see the view.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function settingsTabs(): array
    {
        return [
            ['value' => 'general', 'label' => __('General')],
            ['value' => 'business', 'label' => __('Business')],
            ['value' => 'tax', 'label' => __('Tax')],
            ['value' => 'invoice', 'label' => __('Invoicing')],
            ['value' => 'pos', 'label' => __('Point of Sale')],
            ['value' => 'languages', 'label' => __('Languages')],
            ['value' => 'taxes', 'label' => __('Tax rates')],
        ];
    }

    /**
     * Lookup lists backing the select fields.
     */
    protected function optionSets(): array
    {
        return [
            'timezones' => $this->timezones(),
            'date_formats' => $this->dateFormats(),
            'languages' => Language::where('is_active', true)->orderBy('name')->pluck('name', 'code')->all(),
            'tax_rates' => TaxRate::orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /**
     * The single source of truth for every editable setting.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function fields(array $options): array
    {
        $fields = [
            // --- General ---
            'app_name' => $this->field('general', __('General'), 'app_name', __('Application name'), 'text', 'string', true, __('Shown in the browser title and the sidebar brand.')),
            'timezone' => $this->field('general', __('General'), 'timezone', __('Timezone'), 'select', 'string', true, null, 'timezones'),
            'date_format' => $this->field('general', __('General'), 'date_format', __('Date format'), 'select', 'string', true, null, 'date_formats'),
            'default_language' => $this->field('general', __('General'), 'default_language', __('Default language'), 'select', 'string', false, __('Used for new sessions and printed documents.'), 'languages'),

            // --- Business ---
            'legal_name' => $this->field('business', __('Business'), 'legal_name', __('Legal entity name'), 'text', 'string', false),
            'registration_number' => $this->field('business', __('Business'), 'registration_number', __('Registration / tax number'), 'text', 'string', false),
            'email' => $this->field('business', __('Business'), 'email', __('Contact email'), 'email', 'string', false),
            'phone' => $this->field('business', __('Business'), 'phone', __('Contact phone'), 'text', 'string', false),
            'website' => $this->field('business', __('Business'), 'website', __('Website'), 'text', 'string', false),
            'address_line' => $this->field('business', __('Business'), 'address_line', __('Address'), 'text', 'string', false),
            'city' => $this->field('business', __('Business'), 'city', __('City'), 'text', 'string', false),
            'state' => $this->field('business', __('Business'), 'state', __('State / region'), 'text', 'string', false),
            'country' => $this->field('business', __('Business'), 'country', __('Country'), 'text', 'string', false),
            'postal_code' => $this->field('business', __('Business'), 'postal_code', __('Postal code'), 'text', 'string', false),

            // --- Tax ---
            'default_tax_rate_id' => $this->field('tax', __('Tax'), 'default_tax_rate_id', __('Default tax rate'), 'select', 'integer', false, __('Applied to new products when no rate is chosen.'), 'tax_rates'),
            'prices_include_tax' => $this->field('tax', __('Tax'), 'prices_include_tax', __('Prices include tax'), 'boolean', 'boolean', false, __('When enabled tax is extracted from the entered price instead of added.')),
            'tax_registration_number' => $this->field('tax', __('Tax'), 'tax_registration_number', __('Tax registration number'), 'text', 'string', false, __('Printed on invoices and receipts.')),
            'tax_rounding_decimals' => $this->field('tax', __('Tax'), 'tax_rounding_decimals', __('Tax rounding decimals'), 'integer', 'integer', false, null, null, '2', 0, 4),
            'show_tax_breakdown' => $this->field('tax', __('Tax'), 'show_tax_breakdown', __('Show tax breakdown on receipts'), 'boolean', 'boolean', false),

            // --- Invoicing ---
            'invoice_prefix' => $this->field('invoice', __('Invoicing'), 'invoice_prefix', __('Invoice number prefix'), 'text', 'string', false, __('Sequential numbers are generated below this prefix.')),
            'invoice_due_days' => $this->field('invoice', __('Invoicing'), 'invoice_due_days', __('Default payment terms (days)'), 'integer', 'integer', false, null, null, '14', 0, 365),
            'invoice_terms' => $this->field('invoice', __('Invoicing'), 'invoice_terms', __('Terms & conditions'), 'textarea', 'string', false, __('Printed at the bottom of every invoice.')),
            'invoice_footer' => $this->field('invoice', __('Invoicing'), 'invoice_footer', __('Invoice footer note'), 'textarea', 'string', false),

            // White-label tax/regulatory lines. Countries name and structure
            // these differently, so every field is free text and blank means
            // "not printed on the invoice".
            'invoice_header_line_1' => $this->field('invoice', __('Invoicing'), 'invoice_header_line_1', __('Header line 1 (e.g. government authority)'), 'text', 'string', false, __('Printed as the first line of the invoice, above the business name.')),
            'invoice_header_line_2' => $this->field('invoice', __('Invoicing'), 'invoice_header_line_2', __('Header line 2'), 'text', 'string', false, __('Printed under header line 1.')),
            'invoice_vat_number' => $this->field('invoice', __('Invoicing'), 'invoice_vat_number', __('VAT / BIN / GST / TIN number'), 'text', 'string', false, __('The tax registration of this business, printed near the address.')),
            'invoice_vat_label' => $this->field('invoice', __('Invoicing'), 'invoice_vat_label', __('Tax ID label'), 'text', 'string', false, __('How the number is labelled on the invoice, e.g. VAT No., BIN, GSTIN. Blank uses the default translation.')),
            'invoice_chalan_number' => $this->field('invoice', __('Invoicing'), 'invoice_chalan_number', __('Chalan / delivery note number'), 'text', 'string', false, __('Printed in the invoice meta rows when set.')),
            'invoice_chalan_type' => $this->field('invoice', __('Invoicing'), 'invoice_chalan_type', __('Chalan type (e.g. MUSHAK 6.3)'), 'text', 'string', false, __('Printed in brackets next to the chalan number.')),
            'invoice_terminal_id' => $this->field('invoice', __('Invoicing'), 'invoice_terminal_id', __('Terminal ID'), 'text', 'string', false, __('Identifier of the selling terminal, printed in the meta rows.')),

            // --- Point of sale ---
            'pos_receipt_footer' => $this->field('pos', __('Point of Sale'), 'pos_receipt_footer', __('Receipt footer'), 'textarea', 'string', false, __('Thank-you note printed on POS receipts.')),
            'pos_require_customer' => $this->field('pos', __('Point of Sale'), 'pos_require_customer', __('Require a customer'), 'boolean', 'boolean', false, __('Cashiers must attach a customer before completing a sale.')),
            'pos_allow_negative_stock' => $this->field('pos', __('Point of Sale'), 'pos_allow_negative_stock', __('Allow selling out of stock'), 'boolean', 'boolean', false, __('Not recommended — stock movements will go negative.')),
            'pos_auto_print_receipt' => $this->field('pos', __('Point of Sale'), 'pos_auto_print_receipt', __('Print receipts automatically'), 'boolean', 'boolean', false),
            'pos_enable_offline_mode' => $this->field('pos', __('Point of Sale'), 'pos_enable_offline_mode', __('Enable offline mode'), 'boolean', 'boolean', false, __('Queue sales in the browser when the connection drops.')),
            'pos_max_discount_percent' => $this->field('pos', __('Point of Sale'), 'pos_max_discount_percent', __('Maximum line discount (%)'), 'integer', 'integer', false, __('Cashiers cannot exceed this discount without a manager.'), null, '0', 0, 100),
        ];

        foreach ($fields as $key => $field) {
            $field['rules'] = $this->rulesFor($field, $options);
            $fields[$key] = $field;
        }

        return $fields;
    }

    /**
     * Fields grouped for display, with their stored value resolved.
     */
    protected function groupedFields(array $options): array
    {
        $grouped = [];

        foreach ($this->fields($options) as $field) {
            $field['value'] = Setting::get($field['key'], $field['default'], $field['group']);
            $field['options'] = $options[$field['option_set']] ?? [];
            $grouped[$field['group_label']][] = $field;
        }

        return $grouped;
    }

    /**
     * Describe a single settings field.
     */
    protected function field(
        string $group,
        string $groupLabel,
        string $key,
        string $label,
        string $type,
        string $storeType,
        bool $required = false,
        ?string $hint = null,
        ?string $optionSet = null,
        string $default = '',
        ?int $min = null,
        ?int $max = null
    ): array {
        return [
            'group' => $group,
            'group_label' => $groupLabel,
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'store_type' => $storeType,
            'required' => $required,
            'hint' => $hint,
            'option_set' => $optionSet,
            'default' => $default,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Validation rules derived from the field definition.
     */
    protected function rulesFor(array $field, array $options): array
    {
        return match ($field['type']) {
            'boolean' => ['nullable', 'boolean'],
            'integer' => array_values(array_filter([
                $field['required'] ? 'required' : 'nullable',
                'integer',
                ! is_null($field['min']) ? 'min:' . $field['min'] : null,
                ! is_null($field['max']) ? 'max:' . $field['max'] : null,
            ])),
            'email' => [$field['required'] ? 'required' : 'nullable', 'email', 'max:191'],
            'textarea' => [$field['required'] ? 'required' : 'nullable', 'string', 'max:2000'],
            'select' => [
                $field['required'] ? 'required' : 'nullable',
                'string',
                Rule::in(array_keys($options[$field['option_set']] ?? [''])),
            ],
            default => [$field['required'] ? 'required' : 'nullable', 'string', 'max:191'],
        };
    }

    /**
     * Friendly attribute names for validation messages.
     */
    protected function attributeNames(array $fields): array
    {
        $names = [];

        foreach ($fields as $key => $field) {
            $names[$key] = $field['label'];
        }

        return $names;
    }

    /**
     * Timezones offered in the general settings section.
     */
    protected function timezones(): array
    {
        $zones = [
            'UTC',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
            'Europe/London', 'Europe/Dublin', 'Europe/Lisbon', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid',
            'Europe/Rome', 'Europe/Athens', 'Europe/Moscow',
            'Africa/Cairo', 'Africa/Lagos', 'Africa/Johannesburg', 'Africa/Nairobi',
            'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Jakarta',
            'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Seoul',
            'Australia/Perth', 'Australia/Sydney', 'Pacific/Auckland',
        ];

        $labels = [];

        foreach ($zones as $zone) {
            $labels[$zone] = str_replace(['/', '_'], [' — ', ' '], $zone);
        }

        return $labels;
    }

    /**
     * Date formats offered in the general settings section.
     */
    protected function dateFormats(): array
    {
        return [
            'Y-m-d' => 'YYYY-MM-DD (' . now()->format('Y-m-d') . ')',
            'd/m/Y' => 'DD/MM/YYYY (' . now()->format('d/m/Y') . ')',
            'm/d/Y' => 'MM/DD/YYYY (' . now()->format('m/d/Y') . ')',
            'd M, Y' => 'DD Mon, YYYY (' . now()->format('d M, Y') . ')',
            'M j, Y' => 'Mon J, YYYY (' . now()->format('M j, Y') . ')',
        ];
    }
}
