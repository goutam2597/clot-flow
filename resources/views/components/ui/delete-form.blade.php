@props([
    'action',
    /** Accessible name for the icon-only button. */
    'label',
    /** Shown in the confirmation. Null skips the confirmation entirely. */
    'confirm' => null,
    'variant' => 'danger-ghost',
    'size' => 'sm',
    'icon' => 'trash',
    'class' => null,
    'block' => false,
])

{{--
    A destructive row action: CSRF, the DELETE verb, the confirmation and the
    button, in one component.

    It exists because each of those was previously assembled by hand at every
    call site, and a hand-assembled form has a way of being subtly wrong that
    no amount of looking at it catches. The one that actually shipped was a
    missing type="submit": `x-ui.button` defaults to type="button" so that a
    button placed next to a form cannot accidentally submit it, which is the
    right default and also means a submit button has to ask for it. Six delete
    buttons across the app did not ask, and every one of them silently did
    nothing at all.

    The confirmation text goes through @js() for the same class of reason: a
    product called Bob's Widget, interpolated into a single-quoted inline
    handler, produces invalid JavaScript — and an inline handler that fails to
    parse silently blocks the submit rather than falling back.
--}}
<form method="POST" action="{{ $action }}" data-prevent-double
    @if ($confirm)
        onsubmit="return confirm(@js($confirm));"
    @endif
>
    @csrf
    @method('DELETE')

    <x-ui.button :variant="$variant" :size="$size" :icon="$icon" type="submit"
                 :block="$block" :class="$class" :aria-label="$label" />
</form>
