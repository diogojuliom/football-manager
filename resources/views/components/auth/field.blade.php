@props([
    'name', 'label', 'type' => 'text', 'icon' => 'mail', 'autocomplete' => '',
    'placeholder' => '', 'value' => null, 'required' => false,
    'minlength' => null, 'maxlength' => null, 'forgotUrl' => null, 'describedby' => '',
    'toggle' => false,
])
@php($describedByIds = trim($describedby.' '.($errors->has($name) ? $name.'-error' : '')))
<div class="auth-field">
    <div class="auth-label-row">
        <label for="{{ $name }}">{{ $label }}</label>
        @if($forgotUrl)<a href="{{ $forgotUrl }}">Esqueceu sua senha?</a>@endif
    </div>
    <div class="auth-input-wrap">
        <span class="auth-input-icon" aria-hidden="true">
            @if($icon === 'user')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            @elseif($icon === 'lock')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 8 9 6 9-6"/></svg>
            @endif
        </span>
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}"
            placeholder="{{ $placeholder }}" class="auth-input {{ $errors->has($name) ? 'auth-input-invalid' : '' }} {{ $toggle ? 'auth-input-toggle' : '' }}"
            @if($value !== null) value="{{ $value }}" @endif
            @if($minlength !== null) minlength="{{ $minlength }}" @endif
            @if($maxlength !== null) maxlength="{{ $maxlength }}" @endif
            @if($required) required @endif
            @if($describedByIds !== '') aria-describedby="{{ $describedByIds }}" @endif
            aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}">
        @if($toggle)
            <button class="auth-visibility" type="button" data-password-toggle aria-controls="{{ $name }}" aria-label="Mostrar senha" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
        @endif
    </div>
    @error($name)<p class="auth-field-error" id="{{ $name }}-error">{{ $message }}</p>@enderror
</div>
