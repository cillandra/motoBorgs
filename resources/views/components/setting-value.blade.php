@props(['label','value','help'=>null])
<div class="setting-value"><div><span>{{ $label }}</span><strong>{{ $value }}</strong></div>@if($help)<p>{{ $help }}</p>@endif</div>
