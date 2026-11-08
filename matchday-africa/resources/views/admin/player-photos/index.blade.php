@extends('layouts.admin')
@section('title','Player photos')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">PLAYER PHOTOS</p><h1>Who has a photo.</h1><p>Photos come from API-Football's squad lists (<code>players:photos</code>, every day at 04:45 Lagos, 8 clubs a run, each club refreshed weekly). They show on the stream, the goal and red-card cards on X and Instagram, the match page and the African players pages.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @unless($configured)<section class="md-xguide"><span>NOT SET UP</span><p><code>API_FOOTBALL_KEY</code> is not set, so no new photos come in.</p></section>@endunless
 <header class="md-xsection"><div><p class="md-eyebrow">ALL CLUBS</p><h2>{{ $totals['clubs'] }} of {{ $rows->count() }} clubs have photos · {{ number_format($totals['photos']) }} of {{ number_format($totals['players']) }} players</h2></div></header>
 <table class="md-signup-table"><thead><tr><th>Club</th><th>Photos</th><th>API-Football</th><th>Last filled</th></tr></thead><tbody>
  @foreach($rows as $r)
   <tr @if($selected && $selected->id === $r['team']->id) style="font-weight:700" @endif>
    <td><a href="{{ route('admin.player-photos.index', ['team' => $r['team']->id]) }}#gallery">{{ $r['team']->name }}</a></td>
    <td>{{ $r['photos'] }} of {{ $r['players'] }}@if($r['players'] && $r['photos'] === $r['players']) ✓@endif</td>
    <td>{{ $r['linked'] ? 'Linked' : 'Not linked yet' }}</td>
    <td>{{ $r['filled_at'] ? \Illuminate\Support\Carbon::parse($r['filled_at'])->timezone('Africa/Lagos')->format('D j M H:i') : 'Not yet' }}</td>
   </tr>
  @endforeach
 </tbody></table>

 @if($selected)
  <header class="md-xsection" id="gallery"><div><p class="md-eyebrow">SQUAD</p><h2>{{ $selected->name }}: {{ $players->whereNotNull('photo_url')->count() }} of {{ $players->count() }} with a photo</h2></div></header>
  <p>Flags: <b>No photo</b> = nothing from API-Football yet. <b>Not matched</b> = API-Football's squad has no player with this name (a spelling difference, or the player left), so the next fill won't find one either.</p>
  <div class="md-photo-grid">
   @foreach($players as $p)
    @php($matched = !empty(data_get($p->metadata, 'api_football_player_id')))
    <figure class="md-photo-card{{ $p->photo_url ? '' : ' is-missing' }}{{ $p->is_active ? '' : ' is-gone' }}">
     @if($p->photo_url)<img src="{{ $p->photo_url }}" alt="{{ $p->name }}" width="96" height="96" loading="lazy">@else<span class="md-photo-blank" aria-hidden="true">{{ mb_strtoupper(mb_substr($p->name, 0, 1)) }}</span>@endif
     <figcaption><b>{{ $p->name }}</b>
      <small>{{ $p->position ?: '—' }}@if($p->shirt_number) · #{{ $p->shirt_number }}@endif @if($p->nationality) · {{ $p->nationality }}@endif</small>
      @unless($p->photo_url)<small class="md-photo-flag">No photo{{ $matched ? '' : ' · Not matched' }}</small>@endunless
      @unless($p->is_active)<small>Left the squad</small>@endunless
     </figcaption>
    </figure>
   @endforeach
  </div>
 @endif
</div>
@endsection
