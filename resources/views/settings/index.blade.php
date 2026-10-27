@extends('layouts.app')
@section('title','Settings')
@section('content')
<div class="settings-page" data-settings-root data-section="{{ $section }}"><header class="page-heading"><div><h1>Settings</h1><p>{{ auth()->user()->isManager()?'Shop configuration and your personal workspace.':'Your preferences and account · Staff access.' }}</p></div><span class="status-badge">{{ auth()->user()->isManager()?'Manager access':'Staff access' }}</span></header>
<div class="settings-workspace"><nav class="panel settings-categories" aria-label="Settings categories"><p>SETTINGS</p>@foreach($categories as $key=>$label)<a href="{{ route('settings.index',['section'=>$key]) }}" @class(['settings-category','active'=>$key===$section]) @if($key===$section) aria-current="page" @endif><img src="{{ asset('assets/icons/'.(['general'=>'settings','sales'=>'cart','receipts'=>'receipt','inventory'=>'inventory','notifications'=>'activity','security'=>'users','backup'=>'inventory','system'=>'settings','preferences'=>'dashboard','account'=>'users','about'=>'info'][$key]).'.svg') }}" width="20" height="20" alt=""><span>{{ $label }}</span></a>@endforeach</nav>
@if($unavailable??false)<section class="panel identity-panel" role="alert"><h2>Data unavailable</h2><p>This Settings section could not load.</p><a class="button button-secondary" href="{{ route('settings.index',['section'=>$section]) }}">Retry</a></section>
@else
@php
    $editable=!in_array($section,['system','account','about'],true);
    $titles=\App\Http\Controllers\SettingsController::LABELS;
    $descriptions=['general'=>'Shop details used in the app and receipt header.','sales'=>'Accepted payment records and sales safeguards.','receipts'=>'Saved receipt layout and browser printing.','inventory'=>'New-variant defaults and stock protection.','notifications'=>'Choose which in-app events are published.','security'=>'Session and passphrase policies require Manager verification.','backup'=>'Local backup policy and recovery status.','system'=>'Measured status for this installation.','preferences'=>'Personal display and feedback preferences.','account'=>'Your identity, passphrase and active sessions.','about'=>'School project information and operating scope.'];
@endphp
@if($editable)<form method="post" action="{{ $section==='preferences'?route('preferences.update'):route('settings.update',$section) }}" class="panel settings-editor" data-settings-form data-sensitive="{{ $section==='security'?'1':'0' }}">@csrf @method('put')<input type="hidden" name="version" value="{{ $version }}">@else<section class="panel settings-editor">@endif
<header class="settings-section-heading"><h2>{{ $titles[$section] }}</h2><p>{{ $descriptions[$section] }}</p></header>
<div class="settings-scroll">@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif @include('settings.'.$section)</div>
@if($editable)<footer class="settings-action-bar"><p data-settings-status>No unsaved changes.</p><button type="reset" class="button button-secondary" data-settings-discard hidden>Discard</button><button type="submit" class="button button-primary" data-settings-save>Save changes</button></footer></form>@else</section>@endif
@endif</div>
<x-dialog id="settings-discard" title="Discard unsaved changes?"><p>Your changes have not been saved.</p><form method="dialog" class="action-row"><x-button variant="secondary" data-dialog-close>Keep editing</x-button><x-button type="submit" variant="danger" value="discard">Discard changes</x-button></form></x-dialog>
<x-dialog id="settings-reauth" title="Verify security changes"><form class="stack" data-settings-reauth><p>Enter your current Manager passphrase to apply this security policy.</p><x-input id="settings-manager-password" name="manager_password" label="Your Manager passphrase" type="password" autocomplete="current-password" required /><div class="action-row"><x-button variant="secondary" data-dialog-close>Cancel</x-button><x-button type="submit">Verify and save</x-button></div></form></x-dialog>
</div>
@endsection
