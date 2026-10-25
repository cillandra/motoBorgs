@props(['title'])
<header class="topbar">
<button class="icon-button" type="button" data-sidebar-toggle aria-label="Expand sidebar" aria-expanded="false"><img src="{{ asset('assets/icons/sidebar-toggle.svg') }}" width="20" height="20" alt=""></button><h2>{{ $title }}</h2>
<details class="profile-menu"><summary><span class="avatar">{{ collect(explode(' ',auth()->user()->name))->filter()->take(2)->map(fn($part)=>mb_substr($part,0,1))->join('') }}</span><span class="profile-identity"><strong>{{ auth()->user()->name }}</strong><span>{{ ucfirst(auth()->user()->role) }}</span></span><img src="{{ asset('assets/icons/chevron-down.svg') }}" width="16" height="16" alt=""></summary>
<div class="profile-popover"><p>{{ auth()->user()->name }}<br><small>{{ ucfirst(auth()->user()->role) }}</small></p>@if(Route::has('settings.index'))<a href="{{ route('settings.index',['section'=>'account']) }}">My account</a>@endif<button type="button" data-theme-toggle>Switch appearance</button><form action="{{ route('logout') }}" method="post">@csrf<button type="submit">Log out</button></form></div>
</details>
</header>
