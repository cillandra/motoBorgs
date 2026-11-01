@extends('layouts.app')
@section('title','Create backup')
@section('content')
<header class="page-heading"><div><h1>Create verified backup</h1><p>Save a checked backup of the database and product images on this computer.</p></div></header><form method="post" action="{{ route('backups.store') }}" class="panel identity-panel stack">@csrf<p>Changes already in progress finish first. New changes pause while the backup is created and checked. The seven most recent regular backups are kept. Keeping only seven backup files never deletes sales or activity records.</p><p>MOTO checks the backup contents and every file before marking it complete.</p><div class="action-row"><x-button type="submit">Create backup</x-button><a class="button button-secondary" href="{{ route('settings.index',['section'=>'backup']) }}">Cancel</a></div></form>
@endsection
