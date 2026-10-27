@extends('layouts.app')
@section('title','Report unavailable')
@section('content')
<section class="panel stack" role="alert"><h1>Report unavailable</h1><p>The export could not be prepared. No successful export has been recorded.</p><a class="button button-secondary" href="{{ route('reports.export',array_merge($filters,['type'=>$type])) }}">Retry CSV</a><a class="button button-secondary" href="{{ route('reports.index',$filters) }}">Back to filtered reports</a></section>
@endsection
