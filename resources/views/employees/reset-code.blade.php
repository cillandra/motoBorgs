@extends('layouts.app')
@section('title','Recovery code')
@section('content')
<header class="page-heading"><div><h1>Recovery code issued</h1><p>{{ $employee->name }} · {{ $employee->username }}</p></div></header><section class="panel identity-panel"><h2>Show this only to {{ $employee->name }}</h2><p>This code appears once, expires in 15 minutes and can be used once. The old passphrase and existing sessions have been revoked.</p><label for="recovery-code">Private recovery code</label><textarea id="recovery-code" class="recovery-code" readonly autocomplete="off" spellcheck="false">{{ $token }}</textarea><x-button variant="secondary" data-copy-recovery>Copy code</x-button><p data-copy-status role="status"></p><p>The employee opens <strong>{{ route('recovery.form') }}</strong>, enters this code and chooses a new passphrase. No email or internet connection is required.</p><a class="button button-secondary" href="{{ route('employees.index') }}">Done</a></section>
@endsection
