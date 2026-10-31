@extends('layouts.admin')
@section('title','Signups')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">SIGNUP DESK</p><h1>Who joined, and from where.</h1><p>New accounts per day for the last 30 days (Lagos time), the first-touch source of each signup and Premium conversions.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 <div class="md-signup-stats">
  <div><small>TOTAL USERS</small><b>{{ number_format($total) }}</b></div>
  <div><small>TODAY</small><b>{{ $today }}</b></div>
  <div><small>LAST 7 DAYS</small><b>{{ $week }}</b></div>
  <div><small>LAST 30 DAYS</small><b>{{ $month }}</b></div>
  <div><small>PREMIUM NOW</small><b>{{ $premium }}</b><span>{{ $conversion }}% of users</span></div>
  <div><small>PREMIUM STARTS · 30 DAYS</small><b>{{ $premiumMonth }}</b></div>
  @if($emails)<div><small>EMAIL SUBSCRIBERS</small><b>{{ $emails['active'] }}</b><span>+{{ $emails['month'] }} in 30 days</span></div>@endif
 </div>

 <header class="md-xsection"><div><p class="md-eyebrow">LAST 30 DAYS</p><h2>New signups per day.</h2></div><span>PEAK {{ $peak }}</span></header>
 @php($chart = $days->reverse()->values())
 <figure class="md-signup-chart">
  <svg viewBox="0 0 {{ $chart->count() * 24 }} 160" role="img" aria-label="Signups per day, last 30 days" preserveAspectRatio="none">
   @foreach($chart as $i => $day)
    @php($h = $day['signups'] ? max(3, round(140 * $day['signups'] / $peak)) : 0)
    <rect x="{{ $i * 24 + 3 }}" y="{{ 150 - $h }}" width="18" height="{{ $h }}" class="bar"><title>{{ \Carbon\Carbon::parse($day['date'])->format('D j M') }}: {{ $day['signups'] }} signups</title></rect>
    @if($day['premium'])<circle cx="{{ $i * 24 + 12 }}" cy="{{ 150 - $h - 7 }}" r="3.5" class="dot"><title>{{ $day['premium'] }} Premium</title></circle>@endif
   @endforeach
   <line x1="0" y1="150.5" x2="{{ $chart->count() * 24 }}" y2="150.5" class="axis" />
  </svg>
  <figcaption><span>{{ \Carbon\Carbon::parse($chart->first()['date'])->format('j M') }}</span><span><i class="key-bar"></i>Signups <i class="key-dot"></i>Premium start</span><span>{{ \Carbon\Carbon::parse($chart->last()['date'])->format('j M') }}</span></figcaption>
 </figure>

 <div class="md-signup-grid">
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">BY DAY</p><h2>Daily table.</h2></div></header>
   <table class="md-signup-table"><thead><tr><th>Date</th><th>Signups</th><th>Premium starts</th></tr></thead><tbody>
    @foreach($days as $day)<tr><td>{{ \Carbon\Carbon::parse($day['date'])->format('D j M') }}</td><td>{{ $day['signups'] }}</td><td>{{ $day['premium'] ?: '—' }}</td></tr>@endforeach
   </tbody></table>
  </section>
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">SIGNUP SOURCE · 30 DAYS</p><h2>Where they came from.</h2></div></header>
   @if(!$tracked)
    <p class="md-xguide md-xerror">Run the migrations to start recording sources (users.utm_source).</p>
   @else
    <p class="md-xguide">First touch: the visitor's first UTM tags, else the referring site, else direct. Tag links like ?utm_source=x&amp;utm_medium=social&amp;utm_campaign=reel.</p>
    <table class="md-signup-table"><thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Signups</th><th>Premium</th></tr></thead><tbody>
     @forelse($sources as $row)<tr><td>{{ $row['source'] }}</td><td>{{ $row['medium'] }}</td><td>{{ $row['campaign'] }}</td><td>{{ $row['signups'] }}</td><td>{{ $row['premium'] ?: '—' }}</td></tr>
     @empty<tr><td colspan="5">No signups in the last 30 days.</td></tr>@endforelse
    </tbody></table>
   @endif
  </section>
 </div>

 @if($emails && $emails['sources']->isNotEmpty())
 <header class="md-xsection"><div><p class="md-eyebrow">EMAIL SIGNUPS · 30 DAYS</p><h2>Weekend fixtures list.</h2></div></header>
 <table class="md-signup-table"><thead><tr><th>Source · page</th><th>Signups</th></tr></thead><tbody>
  @foreach($emails['sources'] as $label => $count)<tr><td>{{ $label }}</td><td>{{ $count }}</td></tr>@endforeach
 </tbody></table>
 @endif

 <header class="md-xsection"><div><p class="md-eyebrow">LATEST</p><h2>Newest accounts.</h2></div></header>
 <table class="md-signup-table"><thead><tr><th>Joined</th><th>Name</th>@if($tracked)<th>Source</th><th>Landing page</th>@endif<th>Premium</th></tr></thead><tbody>
  @foreach($latest as $user)<tr><td>{{ $user->created_at?->format('j M H:i') }}</td><td>{{ $user->name }}</td>@if($tracked)<td>{{ \App\Support\Attribution::label($user->utm_source, $user->signup_referrer) }}{{ $user->utm_campaign ? ' · '.$user->utm_campaign : '' }}</td><td>{{ $user->signup_landing ?? '—' }}</td>@endif<td>{{ $user->isPremium() ? 'Yes' : '—' }}</td></tr>@endforeach
 </tbody></table>
</div>
<style>
 .md-signup-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.75rem;margin-bottom:2rem}
 .md-signup-stats>div{padding:1rem;border:1px solid var(--md-line);background:var(--md-panel);display:grid;gap:.3rem}
 .md-signup-stats small{font-size:.65rem;font-weight:900;letter-spacing:.16em;color:var(--md-muted)}
 .md-signup-stats b{font:700 2rem/1 Georgia,serif;color:var(--md-gold-bright)}
 .md-signup-stats span{font-size:.75rem;color:var(--md-muted)}
 .md-signup-chart{margin:0 0 2rem;padding:1rem;border:1px solid var(--md-line);background:var(--md-panel)}
 .md-signup-chart svg{width:100%;height:12rem;display:block}
 .md-signup-chart .bar{fill:var(--md-gold)}.md-signup-chart .bar:hover{fill:var(--md-gold-bright)}
 .md-signup-chart .dot{fill:#3fae6a}.md-signup-chart .axis{stroke:var(--md-line)}
 .md-signup-chart figcaption{display:flex;justify-content:space-between;gap:1rem;font-size:.72rem;color:var(--md-muted);margin-top:.5rem}
 .md-signup-chart i{display:inline-block;width:.6rem;height:.6rem;margin:0 .3rem 0 .8rem;vertical-align:middle}
 .md-signup-chart .key-bar{background:var(--md-gold)}.md-signup-chart .key-dot{background:#3fae6a;border-radius:50%}
 .md-signup-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:2rem}
 @media(max-width:900px){.md-signup-grid{grid-template-columns:1fr}}
 .md-signup-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:2rem}
 .md-signup-table th{text-align:left;font-size:.65rem;letter-spacing:.14em;text-transform:uppercase;color:var(--md-muted);padding:.5rem;border-bottom:1px solid var(--md-line)}
 .md-signup-table td{padding:.5rem;border-bottom:1px solid var(--md-line)}
</style>
@endsection
