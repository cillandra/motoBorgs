@props(['id','name'=>null,'label','type'=>'text','value'=>'','required'=>false])
@php
    $inputName = $name ?? $id;
    $errorKey = str_replace(['[', ']'], ['.', ''], $inputName);
    $hasError = $errors->has($errorKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').($hasError ? ' '.$id.'-error' : ''));
@endphp
<div class="field">
<label for="{{ $id }}">{{ $label }} @if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
<div class="field-perimeter"><div class="input-wrap">
<input id="{{ $id }}" name="{{ $inputName }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : $value }}" @required($required) {{ $attributes->except(['aria-describedby','aria-invalid'])->class(['form-input']) }} @if($hasError) aria-invalid="true" @elseif($attributes->has('aria-invalid')) aria-invalid="{{ $attributes->get('aria-invalid') }}" @endif @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
@if($type === 'password')<button type="button" class="password-toggle" data-password-toggle="{{ $id }}" aria-label="Show password" aria-pressed="false"><img src="{{ asset('assets/icons/eye.svg') }}" width="20" height="20" alt=""></button>@endif
</div></div>
@error($errorKey)<p class="field-error" id="{{ $id }}-error">{{ $message }}</p>@enderror
</div>
