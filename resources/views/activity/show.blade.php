@extends('layouts.app')
@section('title','Employee activity')
@section('content')
<header class="page-heading"><div><h1>Activity ACT-{{ str_pad($detail['id'],6,'0',STR_PAD_LEFT) }}</h1><p>{{ $detail['action'] }}</p></div><a class="button button-secondary" href="{{ route('activity.index') }}">Back to activity</a></header>
<section class="panel identity-panel"><x-section-heading tag="h2" icon="activity" tone="info">Event details</x-section-heading><dl class="identity-details">@foreach(['when'=>'Date and time','employee'=>'Employee','action'=>'Action','record'=>'Record','module'=>'System area','outcome'=>'Outcome'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>@if($key==='outcome')<x-status-badge :status="$detail[$key]" />@else{{ $detail[$key] }}@endif</dd></div>@endforeach</dl><x-section-heading tag="h3" icon="activity" tone="info">Additional activity details</x-section-heading>@if($detail['context'])<pre class="audit-context">{{ json_encode($detail['context'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@else<p>No additional details were recorded.</p>@endif<p>This record is read-only. Credential and private system values are excluded.</p></section>
@endsection
