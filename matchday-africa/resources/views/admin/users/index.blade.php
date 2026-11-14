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

 <nav class="md-signup-window" aria-label="Window for the source tables">
  <span>Source tables cover</span>
  @foreach($windows as $w)<a href="{{ route('admin.users.index', ['window' => $w]) }}" @if($w === $window) aria-current="page" @endif>{{ $w }} days</a>@endforeach
  @if($tracked)<a class="md-signup-csv" href="{{ route('admin.users.export', ['window' => $window]) }}">Download CSV</a>@endif
 </nav>
 <p class="md-xguide">These are first-touch signups, guest picks and email subscribes recorded by the site (Attribution: the visitor's first UTM tags, else the referring site, else direct). Visits, sessions and clicks are not counted here: @if($clarity)read them in Microsoft Clarity, filtered by the same UTM source and campaign.@else no session tool is configured (services.clarity.project_id is empty).@endif</p>

 @if(!$tracked)
  <p class="md-xguide md-xerror">Run the migrations to start recording sources (users.utm_source).</p>
 @else
 <header class="md-xsection"><div><p class="md-eyebrow">BY DAY AND SOURCE · {{ $window }} DAYS</p><h2>Signups per day, by source.</h2></div></header>
 <table class="md-signup-table md-signup-daily"><thead><tr><th>Date</th><th>Total</th>@foreach($daily['sources'] as $source)<th>{{ $source }}</th>@endforeach<th>Premium starts</th></tr></thead><tbody>
  @php($premiumByDate = $days->pluck('premium', 'date'))
  @foreach($daily['rows'] as $row)<tr><td>{{ \Carbon\Carbon::parse($row['date'])->format('D j M') }}</td><td><b>{{ $row['total'] }}</b></td>@foreach($daily['sources'] as $source)<td>{{ $row['by'][$source] ?? 0 }}</td>@endforeach<td>{{ ($premiumByDate[$row['date']] ?? 0) ?: '—' }}</td></tr>@endforeach
 </tbody><tfoot><tr><td>Total</td><td><b>{{ collect($daily['rows'])->sum('total') }}</b></td>@foreach($daily['sources'] as $source)<td>{{ collect($daily['rows'])->sum(fn ($r) => $r['by'][$source] ?? 0) }}</td>@endforeach<td></td></tr></tfoot></table>

 <div class="md-signup-grid">
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">CAMPAIGNS · {{ $window }} DAYS</p><h2>Source, medium and campaign.</h2></div></header>
   <p class="md-xguide">Tag links like ?utm_source=tiktok&amp;utm_medium=social&amp;utm_campaign=bio. Open a row for its days{{ $content ? ' and utm_content' : '' }}.</p>
   <table class="md-signup-table"><thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Signups</th><th>Premium</th></tr></thead><tbody>
    @forelse($sources as $row)<tr><td>{{ $row['source'] }}</td><td>{{ $row['medium'] }}</td><td><details><summary>{{ $row['campaign'] }}</summary><small>@foreach($row['days'] as $date => $n){{ \Carbon\Carbon::parse($date)->format('j M') }}: {{ $n }}@if(!$loop->last), @endif @endforeach</small>@if($row['content'])<small>Content: @foreach($row['content'] as $c => $n){{ $c }} ({{ $n }})@if(!$loop->last), @endif @endforeach</small>@endif</details></td><td>{{ $row['signups'] }}</td><td>{{ $row['premium'] ?: '—' }}</td></tr>
    @empty<tr><td colspan="5">No signups in the last {{ $window }} days.</td></tr>@endforelse
   </tbody></table>
  </section>
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">LANDING PAGES · {{ $window }} DAYS</p><h2>Where signups first landed.</h2></div></header>
   <table class="md-signup-table"><thead><tr><th>First page</th><th>Signups</th></tr></thead><tbody>
    @forelse($landings as $path => $count)<tr><td>{{ $path }}</td><td>{{ $count }}</td></tr>
    @empty<tr><td colspan="2">No signups in the last {{ $window }} days.</td></tr>@endforelse
   </tbody></table>
  </section>
 </div>
 @endif

 <div class="md-signup-grid">
  @if($guests !== null)
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">GUEST PICKS · {{ $window }} DAYS</p><h2>Calls made without an account.</h2></div></header>
   <p class="md-xguide">Guest picks keep the UTM source only, so untagged picks show as direct. Claimed: the guest later signed up or logged in.</p>
   <table class="md-signup-table"><thead><tr><th>Source</th><th>Picks</th><th>Guests</th><th>Claimed</th></tr></thead><tbody>
    @forelse($guests as $row)<tr><td>{{ $row['source'] }}</td><td>{{ $row['picks'] }}</td><td>{{ $row['guests'] }}</td><td>{{ $row['claimed'] }}</td></tr>
    @empty<tr><td colspan="4">No guest picks in the last {{ $window }} days.</td></tr>@endforelse
   </tbody></table>
  </section>
  @endif
  @if($emails)
  <section>
   <header class="md-xsection"><div><p class="md-eyebrow">EMAIL SIGNUPS · {{ $window }} DAYS</p><h2>Weekend fixtures list.</h2></div></header>
   <table class="md-signup-table"><thead><tr><th>Source · campaign · page</th><th>Signups</th></tr></thead><tbody>
    @forelse($emails['sources'] as $label => $count)<tr><td>{{ $label }}</td><td>{{ $count }}</td></tr>
    @empty<tr><td colspan="2">No email signups in the last {{ $window }} days.</td></tr>@endforelse
   </tbody></table>
  </section>
  @endif
 </div>

 <header class="md-xsection"><div><p class="md-eyebrow">LATEST</p><h2>Newest accounts.</h2></div></header>
 <table class="md-signup-table"><thead><tr><th>Joined</th><th>Name</th>@if($tracked)<th>Source</th><th>Landing page</th>@endif<th>Premium</th></tr></thead><tbody>
  @foreach($latest as $user)<tr><td>{{ $user->created_at?->format('j M H:i') }}</td><td>{{ $user->name }}</td>@if($tracked)<td>{{ \App\Support\Attribution::label($user->utm_source, $user->signup_referrer) }}{{ $user->utm_medium ? ' · '.$user->utm_medium : '' }}{{ $user->utm_campaign ? ' · '.$user->utm_campaign : '' }}</td><td>{{ $user->signup_landing ?: '—' }}</td>@endif<td>{{ $user->isPremium() ? 'Yes' : '—' }}</td></tr>@endforeach
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
 .md-signup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2rem}
 @media(max-width:900px){.md-signup-grid{grid-template-columns:1fr}}
 .md-signup-table{display:block;max-width:100%;overflow-x:auto}
 .md-signups>*,.md-signup-grid>*{min-width:0}
 .md-signup-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:2rem}
 .md-signup-table th{text-align:left;font-size:.65rem;letter-spacing:.14em;text-transform:uppercase;color:var(--md-muted);padding:.5rem;border-bottom:1px solid var(--md-line)}
 .md-signup-table td{padding:.5rem;border-bottom:1px solid var(--md-line)}
 .md-signup-table tfoot td{font-weight:700}
 .md-signup-table details summary{cursor:pointer}.md-signup-table details small{display:block;color:var(--md-muted);margin-top:.3rem}
 .md-signup-window{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:0 0 1rem;font-size:.8rem;color:var(--md-muted)}
 .md-signup-window a{padding:.4rem .7rem;border:1px solid var(--md-line);color:var(--md-paper)}
 .md-signup-window a[aria-current]{background:var(--md-gold);color:#130e08;border-color:var(--md-gold)}
 .md-signup-window .md-signup-csv{margin-left:auto}
</style>
@endsection
