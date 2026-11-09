@php
    $shopGoals=collect(explode("\n",$shop['goals']))->map(fn($goal)=>trim($goal))->filter()->values();
    $hasProfile=trim($shop['history'])!==''||trim($shop['mission'])!==''||trim($shop['vision'])!==''||$shopGoals->isNotEmpty();
@endphp
<section id="about" class="shop-section shop-container shop-about" aria-labelledby="shop-about-title">
    <header class="shop-section-heading">
        <div><h2 id="shop-about-title">About the shop.</h2><p>{{ $shop['display_name'] }}</p></div>
    </header>
    <div class="shop-about-content">
    @if($shop['profile_pending']&&$hasProfile)
        <p class="shop-profile-note">The shop background, mission, vision and goals below are based on our MOTO project documentation and await owner confirmation.</p>
    @endif
    @if(trim($shop['history'])!=='')
        <div class="shop-history"><h3>Our history</h3><p>{{ $shop['history'] }}</p></div>
    @endif
    @if(trim($shop['mission'])!==''||trim($shop['vision'])!=='')
        <div class="shop-purpose">
            @if(trim($shop['mission'])!=='')<article><h3>Our mission</h3><p>{{ $shop['mission'] }}</p></article>@endif
            @if(trim($shop['vision'])!=='')<article><h3>Our vision</h3><p>{{ $shop['vision'] }}</p></article>@endif
        </div>
    @endif
    @if($shopGoals->isNotEmpty())
        <div class="shop-goals"><h3>Our goals</h3><ul>@foreach($shopGoals as $goal)<li>{{ $goal }}</li>@endforeach</ul></div>
    @endif
    </div>
</section>
