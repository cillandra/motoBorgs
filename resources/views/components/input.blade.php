@props(['id','label','type'=>'text','value'=>'','required'=>false])
<div class="field">
<label for="{{ $id }}">{{ $label }} @if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
<div class="field-perimeter"><div class="input-wrap">
<input id="{{ $id }}" name="{{ $id }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : $value }}" @required($required) {{ $attributes->class(['form-input']) }} @if($errors->has($id)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
@if($type === 'password')<button type="button" class="password-toggle" data-password-toggle="{{ $id }}" aria-label="Show password" aria-pressed="false"><img src="{{ asset('assets/icons/eye.svg') }}" width="20" height="20" alt=""></button>@endif
</div></div>
@error($id)<p class="field-error" id="{{ $id }}-error">{{ $message }}</p>@enderror
</div>
