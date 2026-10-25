@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<header class="page-heading"><div><h1>Welcome back, {{ explode(' ',auth()->user()->name)[0] }}</h1><p>{{ auth()->user()->isManager() ? 'Shop overview' : 'Your workspace' }} · {{ now()->timezone('Asia/Manila')->format('l, j F Y') }}</p></div></header>
<section class="panel"><h2>{{ ucfirst(auth()->user()->role) }} workspace</h2><p>You are signed in as {{ auth()->user()->name }}.</p></section>
@endsection
