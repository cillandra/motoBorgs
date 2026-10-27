@props(['name','label','options','value'])
<div class="field"><label for="setting-{{ $name }}">{{ $label }}</label><div class="field-perimeter"><select id="setting-{{ $name }}" name="values[{{ $name }}]">@foreach($options as $option=>$text)<option value="{{ $option }}" @selected((string)$value===(string)$option)>{{ $text }}</option>@endforeach</select></div></div>
