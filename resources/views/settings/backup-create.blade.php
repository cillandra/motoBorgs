@extends('layouts.app')
@section('title','Create backup')
@section('content')
<header class="page-heading"><div><h1>Create verified backup</h1><p>Save a consistent local snapshot of the database and product images.</p></div></header><form method="post" action="{{ route('backups.store') }}" class="panel identity-panel stack">@csrf<p>Active writes finish first. New writes pause while the snapshot is created and checked. Seven completed regular files are retained. Live sales and activity are never removed by retention.</p><p>No backup is successful until its manifest and every file checksum have been verified.</p><div class="action-row"><x-button type="submit">Create backup</x-button><a class="button button-secondary" href="{{ route('settings.index',['section'=>'backup']) }}">Cancel</a></div></form>
@endsection
