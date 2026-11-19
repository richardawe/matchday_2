@extends('layouts.admin')
@section('title','Content studio')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">CONTENT STUDIO</p><h1>Match stories.</h1><p>61-75 second vertical stories told from our own data after full time, and radio videos from each Matchday Radio edition. Ready videos go into the <a href="{{ route('admin.social-queue.index') }}">social queue</a> as <code>story:&lt;id&gt;</code>. Finished matches from the last {{ $days }} days, covered competitions first (<a href="?days=7">7 days</a> · <a href="?days=14">14 days</a>).</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif

 <table class="md-signup-table"><tbody>
  <tr><th>Story videos</th><td>{{ $enabled ? 'On: finished '.implode(', ', (array) config('story.competitions')).' matches render automatically '.config('story.delay_minutes').' min after full time.' : ($ready ? 'Off (STORY_VIDEOS=false): nothing renders.' : 'Not migrated: run php artisan migrate --force.') }}</td></tr>
  <tr><th>Music</th><td>{{ $music ? 'Our own track: '.basename($music) : 'No music file yet: stories render silent. Add matchday_original_beat_v1.mp3 to resources/audio/ (or set STORY_MUSIC_PATH).' }}</td></tr>
  @if($missing)<tr><th>ffmpeg</th><td class="md-flash-error">Missing: {{ implode(', ', $missing) }}</td></tr>@endif
 </tbody></table>

 <h2>Finished matches</h2>
 @if($matches->isEmpty())<p>No finished matches in this window.</p>@endif
 <table class="md-signup-table"><thead><tr><th>Match</th><th>Story</th><th>Hook, banter and captions</th></tr></thead><tbody>
 @foreach($matches as $match)
  @php($rows = $stories->get($match->id, collect())->keyBy('lang'))
  <tr id="match-{{ $match->id }}">
   <td><b>{{ \App\Support\ClubName::full($match->homeTeam) }} {{ $match->home_score }}-{{ $match->away_score }} {{ \App\Support\ClubName::full($match->awayTeam) }}</b>
    <br><small>{{ $match->league?->name }} · {{ $match->kickoffLabel('D j M H:i') }} · <a href="{{ route('matches.show', $match) }}" target="_blank" rel="noopener">match page</a></small>
    <form method="POST" action="{{ route('admin.studio.render') }}" style="margin-top:.5rem">@csrf
     <input type="hidden" name="kind" value="match_story"><input type="hidden" name="subject_id" value="{{ $match->id }}">
     <select name="lang" aria-label="Language">@foreach($languages as $lang)<option value="{{ $lang }}">{{ strtoupper($lang) }}</option>@endforeach</select>
     <button type="submit">{{ $rows->isEmpty() ? 'Render story' : 'Render again' }}</button>
    </form>
   </td>
   <td>
    @forelse($rows as $story)
     @include('admin.studio.status', ['story' => $story])
    @empty
     <small>No story yet.</small>
    @endforelse
   </td>
   <td>
    @foreach($rows as $story)
     @include('admin.studio.edit', ['story' => $story])
    @endforeach
   </td>
  </tr>
 @endforeach
 </tbody></table>

 <h2>Matchday Radio editions</h2>
 @if($editions->isEmpty())<p>No published editions in this window.</p>@endif
 <table class="md-signup-table"><tbody>
 @foreach($editions as $edition)
  @php($rows = $radioStories->get($edition->id, collect())->keyBy('lang'))
  <tr>
   <td><b>{{ $edition->editionLabel() }}</b>: {{ $edition->title }} <small>({{ $edition->duration_label }})</small>
    <form method="POST" action="{{ route('admin.studio.render') }}" style="margin-top:.5rem">@csrf
     <input type="hidden" name="kind" value="radio_video"><input type="hidden" name="subject_id" value="{{ $edition->id }}">
     <button type="submit">{{ $rows->isEmpty() ? 'Render radio video' : 'Render again' }}</button>
    </form></td>
   <td>@forelse($rows as $story)@include('admin.studio.status', ['story' => $story])@empty<small>No video yet.</small>@endforelse</td>
   <td>@foreach($rows as $story)@include('admin.studio.edit', ['story' => $story, 'radio' => true])@endforeach</td>
  </tr>
 @endforeach
 </tbody></table>
</div>
@endsection
