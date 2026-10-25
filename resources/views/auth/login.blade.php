<!doctype html>
<html lang="en" data-theme="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · MOTO</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="login-page">
<main class="authentication-workspace" aria-label="MOTO sign in">
<section class="brand-panel" aria-label="MOTO identity">
<div class="brand-lockup"><img class="login-logo" src="{{ asset('assets/brand/borgs-logo.png') }}" width="224" height="224" alt="BORG’S Motorcycle Parts and Accessories">
<div class="system-identity"><h1 class="wordmark">MOTO</h1><span class="racing-signature"></span><p>Management of Transactions and Order</p><p class="shop-caption">For BORG’S Motorcycle Parts<br>and Accessories</p></div></div>
</section>
<section class="sign-in-panel">
<div class="welcome"><h2>Welcome back</h2><p>Sign in with your assigned account.</p></div>
<form action="{{ route('login.store') }}" method="post" class="login-form" data-submit-form>
@csrf
<div class="credentials">
<x-input id="username" label="Username" :value="old('username')" placeholder="Enter your username" autocomplete="username" required autofocus />
<x-input id="password" label="Password" type="password" placeholder="Enter your password" autocomplete="current-password" required />
</div>
@if($errors->any())<div class="inline-alert error" role="alert">{{ $errors->first() }}</div>@endif
<x-button type="submit" class="login-submit">Sign in</x-button>
</form>
<div class="account-help"><p>Need access or a password reset?</p><strong>Contact your Manager.</strong></div>
</section>
</main>
</body></html>
