<!doctype html>
<html lang="en" data-theme="{{ auth()->user()->preference?->value['theme'] ?? 'dark' }}" data-sidebar="collapsed">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Dashboard') · MOTO</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="app-page">
<a class="skip-link" href="#main-content">Skip to content</a>
<x-sidebar />
<div class="main-workspace"><x-topbar :title="trim($__env->yieldContent('title','Dashboard'))" />
<main id="main-content" class="page-content">@if(session('status'))<x-toast>{{ session('status') }}</x-toast>@endif @yield('content')</main></div>
</body></html>
