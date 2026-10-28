@props(['label','value','help'=>null,'icon'=>'info','tone'=>'info'])
<article {{ $attributes->class(['panel','metric-card']) }} data-tone="{{ $tone }}">
    <div class="metric-heading"><span class="metric-label">{{ $label }}</span><span class="kpi-icon" aria-hidden="true"><span class="ui-icon" style="--ui-icon:url('{{ asset('assets/icons/'.$icon.'.svg') }}')"></span></span></div>
    <strong class="metric-value">{{ $value }}</strong>
    @if($help)<p class="metric-context">{{ $help }}</p>@endif
</article>
