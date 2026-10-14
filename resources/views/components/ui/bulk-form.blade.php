@props([
    /** Form action the selection is submitted to. */
    'action',
    /** Ids submitted under this name. */
    'name' => 'ids[]',
    /** What is being selected, for the confirmation text. */
    'subject' => 'records',
    /** Button label. */
    'label' => null,
    /** Put the action above the table instead of in the toolbar. */
    'align' => 'end',
])

@php
    $label = $label ?? __('Delete');
    $listId = 'bulk-'.sha1($action.$name);
@endphp

{{--
    Bulk delete.

    Built as a real form so it works without JavaScript, and progressively
    enhanced by bulk-actions.js for the parts that need it: showing how many
    rows are ticked, disabling itself at zero, and confirming with the count
    in the message rather than a vague "are you sure".

    The bar is hidden until something is ticked - a permanently visible "Delete
    0 selected" button is noise on every list page in the app.
--}}
<form method="POST" action="{{ $action }}" class="bulk-form" data-bulk-form
      data-bulk-endpoint="{{ $action }}" hidden>
    @csrf
    @method('DELETE')

    <div class="bulk-bar" data-bulk-bar role="region" aria-live="polite"
         aria-label="{{ __('Bulk actions') }}" style="justify-content: {{ $align === 'start' ? 'flex-start' : 'flex-end' }};">

        <span class="bulk-count" data-bulk-count>{{ __('Nothing selected') }}</span>

        <x-ui.button type="submit" variant="danger" size="sm" icon="trash"
                     data-bulk-submit
                     :disabled="true">
            {{ $label }}
        </x-ui.button>

        <x-ui.button type="button" variant="ghost" size="sm" data-bulk-clear>
            {{ __('Clear') }}
        </x-ui.button>

        <noscript>
            <span class="bulk-count">{{ __('Tick the boxes, then press Delete.') }}</span>
        </noscript>
    </div>

    {{-- One hidden input per ticked row, written by the enhancer. Posting a
         real array keeps the server side a plain array validation. --}}
    <div data-bulk-inputs hidden></div>
</form>
