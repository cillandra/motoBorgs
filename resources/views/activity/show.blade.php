@extends('layouts.app')
@section('title','Employee activity hub')
@section('content')
<header class="page-heading"><div><h1>Activity ACT-{{ str_pad($detail['id'],6,'0',STR_PAD_LEFT) }}</h1><p>{{ $detail['action'] }}</p></div><a class="button button-secondary" href="{{ route('activity.index') }}">Back to activity</a></header>
<section class="panel identity-panel"><h2>Event details</h2><dl class="identity-details">@foreach(['when'=>'When · Manila','employee'=>'Employee','action'=>'Action','record'=>'Record','module'=>'Module','outcome'=>'Outcome'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>{{ $detail[$key] }}</dd></div>@endforeach</dl><h3>Recorded context</h3>@if($detail['context'])<pre class="audit-context">{{ json_encode($detail['context'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@else<p>No additional context.</p>@endif<p>This record is read-only. Credential and private system values are excluded.</p></section>
@endsection
