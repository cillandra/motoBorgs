@php
$items = [['dashboard','Dashboard','dashboard'],['pos.index','Sales / POS','cart'],['inventory.index','Product inventory','inventory'],['sales.index','Sales history','receipt']];
if(auth()->user()->isManager()) { $items = array_merge($items,[['reports.index','Reports & analytics','reports'],['employees.index','Management hub','users'],['activity.index','Employee activity hub','activity']]); }
@endphp
<aside class="sidebar" aria-label="Main navigation">
<a href="{{ route('dashboard') }}" class="sidebar-brand"><img src="{{ asset('assets/brand/borgs-logo.png') }}" alt="" width="44" height="44"><div><span class="wordmark">MOTO</span><span class="racing-signature"></span><span class="brand-description">Borg’s Motorcycle<br>Parts & Accessories</span></div></a>
<nav class="main-navigation">
@foreach($items as [$route,$label,$icon])
@if(Route::has($route))<a href="{{ route($route) }}" @class(['sidebar-link','active'=>request()->routeIs($route) || ($route !== 'dashboard' && request()->routeIs(strtok($route,'.').'.*'))]) aria-label="{{ $label }}" title="{{ $label }}" @if(request()->routeIs($route)) aria-current="page" @endif><img src="{{ asset('assets/icons/'.$icon.'.svg') }}" width="20" height="20" alt=""><span>{{ $label }}</span></a>@endif
@endforeach
</nav>
<div class="sidebar-bottom">
@if(Route::has('settings.index'))<a class="sidebar-link" href="{{ route('settings.index') }}" aria-label="Settings" title="Settings"><img src="{{ asset('assets/icons/settings.svg') }}" width="20" height="20" alt=""><span>Settings</span></a>@endif
<button type="button" class="sidebar-link" data-sidebar-toggle aria-label="Expand sidebar" aria-expanded="false"><img src="{{ asset('assets/icons/chevron-right.svg') }}" width="20" height="20" alt=""><span>Collapse sidebar</span></button>
<div class="sidebar-account"><span class="avatar">{{ collect(explode(' ',auth()->user()->name))->filter()->take(2)->map(fn($part)=>mb_substr($part,0,1))->join('') }}</span><span class="account-name">{{ auth()->user()->name }}</span></div>
<form method="post" action="{{ route('logout') }}">@csrf<button class="sidebar-link" type="submit" aria-label="Log out" title="Log out"><img src="{{ asset('assets/icons/logout.svg') }}" width="20" height="20" alt=""><span>Log out</span></button></form>
</div>
</aside>
