<!doctype html>
<html lang="en" data-theme="{{ auth()->user()->preference?->value['theme'] ?? 'dark' }}" data-preferences="{{ json_encode(app(\App\Services\Settings\PreferenceService::class)->read(auth()->user())) }}" data-sidebar="collapsed" data-density="{{ auth()->user()->preference?->value['density'] ?? 'comfortable' }}" data-reduced-motion="{{ (auth()->user()->preference?->value['reduced_motion'] ?? false)?'true':'false' }}" data-ui-sound="{{ (auth()->user()->preference?->value['sound'] ?? false)?'true':'false' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Dashboard') · MOTO</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="app-page">
<a class="skip-link" href="#main-content">Skip to content</a>
<x-sidebar />
<div class="main-workspace"><x-topbar :title="html_entity_decode(trim($__env->yieldContent('title','Dashboard')), ENT_QUOTES, 'UTF-8')" />
<main id="main-content" class="page-content">@if(session('status'))<x-toast>{{ session('status') }}</x-toast>@endif @yield('content')</main></div>
<x-workspace-modal />
</body></html>
