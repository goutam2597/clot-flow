{{--
    The drawn box, shared by every checkbox variant.

    The tick is a sibling SVG rather than a background image on the input, and
    that is the reason this partial exists. A background image cannot be scaled
    in, so a tick that appears between two frames reads as a glitch rather than
    a confirmation; a pseudo-element is not available either, because they do not
    render on a replaced element like <input>.

    Both glyphs are in the markup and CSS picks between them. Which one is
    correct depends on state, and state lives in the DOM property, not in CSS.
--}}
<span class="checkbox-box">
    <input type="checkbox"
           class="checkbox-input"
           id="{{ $id }}"
           @if ($name) name="{{ $name }}" @endif
           value="{{ $value }}"
           @checked($checked)
            @disabled($disabled)
            @required($required)
           @if ($indeterminate) data-indeterminate="true" @endif
           {{ $attributes ?? '' }}
           @if ($label) aria-label="{{ $label }}" @endif>

    <svg class="checkbox-tick" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
        <path d="M2.5 6.2 4.8 8.5 9.5 3.8" fill="none" stroke="currentColor"
              stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>

    <svg class="checkbox-dash" viewBox="0 0 12 12" aria-hidden="true" focusable="false">
        <path d="M2.8 6h6.4" fill="none" stroke="currentColor"
              stroke-width="1.9" stroke-linecap="round"/>
    </svg>
</span>
