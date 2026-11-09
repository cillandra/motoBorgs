<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Explore motorcycle parts, accessories and service information from {{ $shop['display_name'] }}. Find the right product option and contact the shop.">
    <title>{{ $shop['display_name'] }} · Motorcycle parts & accessories</title>
    <link rel="preload" href="{{ asset('assets/fonts/BarlowCondensed-Bold.ttf') }}" as="font" type="font/ttf" crossorigin>
    @vite(['resources/css/app.css','resources/js/storefront.js'])
</head>
<body class="storefront-page">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="shop-utility">
    <div class="shop-container">
        <span>Motorcycle parts. Accessories. Shop inquiries.</span>
        @if($shop['demo_content'])<span class="shop-demo-label">School demonstration · Sample business details</span>@endif
    </div>
</div>
<header class="shop-header">
    <div class="shop-nav shop-container">
        <a class="shop-brand" href="{{ route('storefront.index') }}" aria-label="{{ $shop['display_name'] }} home">
            <img src="{{ asset('assets/brand/borgs-logo.png') }}" width="52" height="52" alt="">
            <span><strong>{{ \Illuminate\Support\Str::before($shop['display_name'],' Motorcycle') }}</strong><small>Motorcycle Parts & Accessories</small></span>
        </a>
        <button class="shop-menu-toggle" type="button" data-shop-menu aria-controls="shop-navigation" aria-expanded="false" hidden>Menu <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h16M4 16h16"/></svg></button>
        <nav id="shop-navigation" aria-label="Shop navigation">
            <a href="#products">Products</a>
            <a href="#services">Services</a>
            <a href="#about">About the shop</a>
            <a href="#questions">FAQs</a>
            <a class="button button-primary" href="#contact">Contact & visit</a>
        </nav>
    </div>
</header>
<main id="main-content">
    <section class="shop-hero" aria-labelledby="shop-hero-title">
        <img class="shop-hero-photo" src="{{ asset('assets/brand/storefront-ride-v2.png') }}" alt="Illustrative black and red underbone motorcycle parked beside a concrete building" width="1942" height="809" fetchpriority="high">
        <div class="shop-container shop-hero-content">
            <h1 id="shop-hero-title">Keep your<br>ride ready.</h1>
            <p>{{ $shop['about'] }}</p>
            <div class="shop-actions">
                <a class="button button-primary" href="#products">Browse products @include('storefront.arrow')</a>
                <button class="shop-hero-link" type="button" data-inquire>Find the right part @include('storefront.arrow')</button>
            </div>
        </div>
        <p class="shop-image-caption">Illustrative motorcycle image</p>
    </section>

<section id="products" class="shop-section shop-container" aria-labelledby="shop-products-title">
        <header class="shop-section-heading">
            <div><h2 id="shop-products-title">Parts & accessories.</h2><p>Browse by category, search your motorcycle model, or look up a part number.</p></div>
            <button class="shop-text-link" type="button" data-inquire>Need help choosing? @include('storefront.arrow')</button>
        </header>
        <div class="shop-catalog-layout">
            <aside class="shop-category-sidebar">
                <h3>Categories</h3>
                <nav class="shop-categories" aria-label="Product categories">
                    <a href="{{ route('storefront.index',array_filter(array_replace($filters,['category'=>null,'page'=>null]))) }}#products" @class(['active'=>empty($filters['category'])]) @if(empty($filters['category'])) aria-current="true" @endif>All products @include('storefront.arrow')</a>
                    @foreach($categories as $category)
                        <a href="{{ route('storefront.index',array_filter(array_replace($filters,['category'=>$category->id,'page'=>null]))) }}#products" @class(['active'=>($filters['category']??null)==$category->id]) @if(($filters['category']??null)==$category->id) aria-current="true" @endif>{{ $category->name }} @include('storefront.arrow')</a>
                    @endforeach
                </nav>
</aside>
            <div class="shop-catalog-results">
                <form method="get" action="{{ route('storefront.index') }}#products" class="shop-filters" role="search" aria-label="Search shop products">
                    @if(!empty($filters['category']))<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif
                    <div class="field shop-search-field"><label for="shop-search">Search products</label><input class="form-input" id="shop-search" type="search" name="search" maxlength="100" value="{{ $filters['search']??'' }}" placeholder="Product, model or part number"></div>
                    <div class="field"><label for="shop-availability">Availability</label><select id="shop-availability" name="availability"><option value="all">All products</option><option value="available" @selected(($filters['availability']??'all')==='available')>Available only</option></select></div>
                    <div class="field"><label for="shop-sort">Sort by</label><select id="shop-sort" name="sort">@foreach(['available'=>'Available first','name'=>'Name: A–Z','price_asc'=>'Price: low to high','price_desc'=>'Price: high to low'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['sort']??'available')===$value)>{{ $label }}</option>@endforeach</select></div>
                    <button type="submit" class="button button-dark">Search</button>
                </form>
                @if($errors->getBag('catalog')->any())<p class="shop-notice shop-error" role="alert">{{ $errors->getBag('catalog')->first() }} <a href="{{ route('storefront.index') }}#products">Reset filters</a></p>@endif
                <div class="shop-result-line">
                    <p>@if($products->total())Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} products @if(!empty($filters['search'])) for <strong>“{{ $filters['search'] }}”</strong>@endif @else No matching products @endif</p>
                    @if(!empty($filters['search'])||!empty($filters['category'])||($filters['availability']??'all')!=='all')<a class="shop-text-link" href="{{ route('storefront.index') }}#products">Clear filters</a>@endif
                </div>
                <div class="shop-product-grid">
                    @forelse($products as $product)
                        @include('storefront.product-card')
                    @empty
                        <div class="shop-empty"><h3>No products found</h3><p>Try another product name or motorcycle model. You can also ask the shop to help find a part.</p><div class="shop-actions"><a class="button button-secondary" href="{{ route('storefront.index') }}#products">View all products</a><button class="button button-primary" type="button" data-inquire>Ask about a part</button></div></div>
                    @endforelse
                </div>
                @if($products->hasPages())
                    <nav class="shop-pagination" aria-label="Product pages"><p>Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</p><div>@if($products->previousPageUrl())<a class="button button-secondary" href="{{ $products->previousPageUrl() }}#products">Previous</a>@endif @if($products->nextPageUrl())<a class="button button-dark" href="{{ $products->nextPageUrl() }}#products">Next page @include('storefront.arrow')</a>@endif</div></nav>
                @endif
                <p class="shop-catalog-note">Catalog prices and availability may change. Confirm the fit and final details with the shop before purchasing.</p>
            </div>
        </div>
    </section>

    <section id="services" class="shop-service-section">
        <div class="shop-container shop-service-layout">
            <header><h2>Services & support.</h2><p>Ask about compatibility, service availability and what your motorcycle needs.</p>@if($shop['demo_content'])<p class="shop-demo-note">Demonstration content: these sample services are awaiting owner confirmation.</p>@endif</header>
            <div class="shop-services">
                @forelse($services as $service)
                    <article><div><h3>{{ $service['name'] }}</h3><p>{{ $service['description'] }}</p></div><button class="shop-service-button" type="button" data-inquire data-topic="service" data-subject="{{ $service['name'] }}" aria-label="Ask about {{ $service['name'] }}"><span>Ask</span>@include('storefront.arrow')</button></article>
                @empty
                    <p>Service details have not been listed yet. Send an inquiry to ask the shop.</p>
                @endforelse
            </div>
        </div>
    </section>

    @include('storefront.about')

    <section id="questions" class="shop-section shop-container shop-faq">
        <div><h2>Frequently asked questions.</h2><p>A few things to know about browsing and contacting the shop.</p></div>
        <div>
            <details><summary>Do I need an account?</summary><p>No. Browse products and services and send an inquiry without signing up.</p></details>
            <details><summary>How do I know if a part fits?</summary><p>Check the compatibility notes. Include your motorcycle’s brand, model and year in your inquiry, then confirm the fit with the shop before buying.</p></details>
            <details><summary>Can I buy or pay through this page?</summary><p>This page is for browsing and inquiries. Purchase arrangements and payments are handled directly with the shop.</p></details>
            <details><summary>How will the shop respond?</summary><p>Leave a phone number or email address so an employee can follow up. Call and Messenger options appear when the official contacts are configured.</p></details>
        </div>
    </section>

    <section id="contact" class="shop-visit-section" aria-labelledby="shop-contact-title">
        <div id="visit" class="shop-container shop-visit-grid">
            <div class="shop-visit-intro">
                <h2 id="shop-contact-title">Visit or contact us.</h2>
                <p>Ask about a product, a service or your motorcycle’s needs. Confirm the details with the shop before visiting or purchasing.</p>
                <div class="shop-contact-actions">
                    <button type="button" class="button button-primary" data-inquire>Send an inquiry @include('storefront.arrow')</button>
                    @if($shop['phone'])<a class="shop-text-link" href="tel:{{ $shop['phone'] }}">Call the shop</a>@endif
                    @if($shop['messenger_page'])<a class="shop-text-link" href="https://m.me/{{ $shop['messenger_page'] }}" target="_blank" rel="noopener noreferrer">Open Messenger</a>@endif
                </div>
                <p class="shop-contact-expectation">Leave your phone number or email so the shop can follow up. No customer account is needed.</p>
            </div>
            <div class="shop-visit-details">
                <h3>{{ $shop['display_name'] }}</h3>
                <dl>
                    <div><dt>Shop location</dt><dd>{{ $shop['address'] }}</dd></div>
                    <div><dt>Opening hours</dt><dd>{{ $shop['hours'] }}</dd></div>
                    <div><dt>Phone</dt><dd>@if($shop['phone'])<a href="tel:{{ $shop['phone'] }}">{{ $shop['phone'] }}</a>@else Official number awaiting confirmation @endif</dd></div>
                </dl>
                @if($shop['demo_content'])<p class="shop-small-note">Demonstration details · Confirm the official address and hours before planning a visit.</p>@endif
            </div>
        </div>
        @if(session('inquiry_status'))<div class="shop-container shop-notice" role="status">{{ session('inquiry_status') }}</div>@endif
    </section>
</main>
<footer class="shop-footer">
    <div class="shop-container shop-footer-grid">
        <div class="shop-footer-brand"><strong>{{ \Illuminate\Support\Str::before($shop['display_name'],' Motorcycle') }}</strong><p>Motorcycle Parts & Accessories</p><p>Parts information. Product options.<br>A direct line to the shop.</p></div>
        <div><h3>Explore</h3><nav aria-label="Footer navigation"><a href="#products">Products</a><a href="#services">Services</a><a href="#about">About the shop</a></nav></div>
        <div><h3>Customer support</h3><nav aria-label="Customer support"><a href="#questions">Frequently asked questions</a><a href="#contact">Contact the shop</a></nav><p>@if($shop['phone']){{ $shop['phone'] }}@else Official phone & Messenger details pending.@endif</p></div>
        <div><h3>For employees</h3><a href="{{ auth()->check()?route('dashboard'):route('login') }}">{{ auth()->check()?'Employee workspace':'Employee login' }} @include('storefront.arrow')</a><p>Authorized shop personnel only.</p></div>
    </div>
    <div class="shop-container shop-footer-bottom"><span>{{ $shop['display_name'] }}</span><span>MOTO · School demonstration</span></div>
</footer>
@include('storefront.inquiry')
<dialog id="shop-product-detail" class="shop-dialog shop-detail-dialog" aria-labelledby="shop-product-detail-title"><header class="shop-dialog-header"><h2 id="shop-product-detail-title">Product details</h2><button class="shop-close" type="button" data-dialog-close aria-label="Close product details"><img src="{{ asset('assets/icons/close.svg') }}" width="22" height="22" alt=""></button></header><div class="shop-dialog-body" data-product-detail-body></div></dialog>
</body>
</html>
