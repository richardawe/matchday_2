@extends('layouts.public')

@section('content')
<section class="md-phase md-commerce"><div class="md-wrap">
    <p class="md-eyebrow">THE QUARTERMASTER</p>
    <h1>Original goods. Built for the faithful.</h1>
    <p class="md-lead">Every item uses Matchday Africa’s original, rights-safe warrior artwork—no club badges or protected marks.</p>
    <div class="md-product-grid">
        @foreach($products as $product)
        <article class="md-product"><img src="{{ asset(ltrim($product->image,'/')) }}" alt="{{ $product->name }}"><div><span>{{ strtoupper($product->type) }}</span><h2>{{ $product->name }}</h2><p>{{ $product->description }}</p><div class="md-buy"><strong>£{{ number_format($product->price/100,2) }}</strong>@auth<form method="POST" action="{{ route('commerce.checkout',$product) }}">@csrf @if(request('creator'))<input type="hidden" name="creator" value="{{ request('creator') }}">@endif<button class="md-primary">{{ $product->type==='digital'?'Unlock':'Order now' }}</button></form>@else<a class="md-primary" href="{{ route('login') }}">Sign in to buy</a>@endauth</div></div></article>
        @endforeach
    </div>

    @php($warriors = [
        ['Roman Legion','roman-legion.png'], ['Anglo-Saxon Housecarl','anglo-saxon-housecarl.png'],
        ['Barbary Corsair','barbary-corsair.png'], ['Prussian Infantry','prussian-infantry.png'],
        ['Byzantine Cataphract','byzantine-cataphract.png'], ['Ottoman Janissary','ottoman-janissary.png'],
        ['Zulu Impi','zulu-impi.png'], ['Saxon Fyrd','saxon-fyrd.png'],
        ['Norman Knight','norman-knight.png'], ['Venetian Marine','venetian-marine.png'],
        ['Numidian Cavalry','numidian-cavalry.png'], ['Rus Varangian Guard','rus-varangian-guard.png'],
        ['White Rose Yorkist','white-rose-yorkist.png'], ['Norse Viking','norse-viking.png'],
        ['Mongol Horde','mongol-horde.png'], ['Napoleonic Grenadier','napoleonic-grenadier.png'],
        ['Northumbrian Raider','northumbrian-raider.png'], ['Robin Hood Outlaw','robin-hood-outlaw.png'],
        ['Northern Rebel','northern-rebel.png'], ['Classical Hoplite','spartan-hoplite.png'],
    ])
    <div class="md-shop-warriors">
        <p class="md-eyebrow">FREE DIGITAL COLLECTIBLES</p>
        <h2>Claim your warrior.</h2>
        <p>Choose from 20 original, unbranded portraits and download a rights-safe wallpaper for your phone.</p>
        <div class="md-product-grid">
            @foreach($warriors as [$name, $file])
            <article class="md-product"><img src="{{ asset('war/downloads/rights-safe/'.$file) }}" alt="{{ $name }} wallpaper"><div><span>DIGITAL WALLPAPER</span><h2>{{ $name }}</h2><div class="md-buy"><strong>FREE</strong><a class="md-primary" href="{{ asset('war/downloads/rights-safe/'.$file) }}" download>Download PNG</a></div></div></article>
            @endforeach
        </div>
    </div>
</div></section>
@endsection
