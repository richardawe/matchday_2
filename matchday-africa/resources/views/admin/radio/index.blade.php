@extends('layouts.admin')
@section('title', 'Radio desk')
@section('header')<div class="md-admin-heading"><div><p class="md-eyebrow">RADIO DESK</p><h1>Morning. Midday. Evening.</h1><p>6am, noon and 6pm WAT · Kokoro · {{ config('radio.auto_publish') ? 'Automatic publishing' : 'Draft review before publishing' }}</p></div></div>@endsection
@section('content')
<div class="md-radio-desk">
@if(!\App\Models\RadioBulletin::available())<p class="md-xguide">Radio is off or awaiting its migration. See docs/RADIO.md for setup.</p>@endif
@if(\App\Services\RadioVideo::columnsReady())<p class="md-xguide">Vertical videos: {{ config('radio.video.enabled') ? 'on. Each published edition over '.config('radio.video.min_seconds').' s gets a 1080x1920 video within a few minutes (radio:video), then appears on the social queue as radio:{id}.' : 'off (RADIO_VIDEO_ENABLED).' }}</p>@endif
@if(!config('radio.news_sources'))<p class="md-xguide">No news sources are approved for radio yet. Editions use recent results and fixtures until RADIO_NEWS_SOURCES is set.</p>@endif
@if($errors->any())<p class="md-xerror">{{ $errors->first() }}</p>@endif
<form method="POST" action="{{ route('admin.radio.queue') }}">@csrf<button class="md-primary" @disabled(!\App\Models\RadioBulletin::available())>Queue current edition</button></form>
@forelse($editions as $edition)
<article class="md-radio-draft"><header class="md-radio-head"><div><p class="md-eyebrow">{{ $edition->slot }} WAT · {{ $edition->status }} · {{ $edition->stage }} · {{ $edition->attempts }} attempts</p><h2>{{ $edition->title }}</h2></div></header>
@if($edition->failure_reason)<p class="md-xerror">{{ $edition->failure_reason }}</p>@endif
@if(\App\Services\RadioVideo::columnsReady() && $edition->status === 'published')
<p class="md-radio-video">Video: @switch($edition->video_status)
 @case('ready') ready ({{ rtrim(rtrim(number_format((float) $edition->video_seconds, 1), '0'), '.') }} s) · <a href="{{ $edition->videoUrl() }}" target="_blank" rel="noopener">Open mp4</a> · <a href="{{ route('admin.social-queue.index') }}#radio-{{ $edition->id }}">Social queue</a> @break
 @case('rendering') rendering now (attempt {{ $edition->video_attempts }}) @break
 @case('failed') failed after {{ $edition->video_attempts }} {{ \Illuminate\Support\Str::plural('attempt', $edition->video_attempts) }} @break
 @case('too_short') not made: the audio is {{ $edition->duration_seconds }} s @break
 @default {{ config('radio.video.enabled') ? 'waiting for the video worker' : 'off' }}
@endswitch</p>
@if($edition->video_status === 'failed' && $edition->video_error)<p class="md-xerror">Video: {{ $edition->video_error }}</p>@endif
@endif
@if($edition->audioFile())<audio controls preload="none" src="{{ route('admin.radio.audio', $edition) }}" aria-label="Preview {{ $edition->title }}"></audio>@endif
@if($edition->status === 'draft')<form method="POST" action="{{ route('admin.radio.update', $edition) }}">@csrf @method('PUT')
@foreach($edition->segments as $segment)<label class="md-radio-edit">Paragraph {{ $loop->iteration }}<textarea name="paragraphs[]" required maxlength="6000">{{ $segment['text'] }}</textarea></label>@endforeach
<button class="md-secondary">Save and check facts</button></form>
@elseif($edition->script)<details class="md-radio-transcript"><summary>Script</summary><p class="md-radio-script">{{ $edition->script }}</p></details>@endif
@if($edition->facts)<details class="md-radio-transcript"><summary>Source facts</summary>@foreach($edition->facts as $fact)<p><a href="{{ $fact['url'] }}" target="_blank" rel="nofollow noopener">{{ $fact['source'] }} — {{ $fact['headline'] }}</a></p><p>{{ $fact['text'] }}</p>@endforeach</details>@endif
<form method="POST" action="{{ route('admin.radio.action', $edition) }}" class="md-radio-actions">@csrf
@if($edition->status === 'draft')<button name="action" value="voice" class="md-primary">Approve script &amp; generate audio</button>@endif
@if($edition->status === 'ready')<button name="action" value="publish" class="md-primary">Publish checked audio</button>@endif
@if($edition->status === 'failed')<button name="action" value="retry" class="md-secondary">Retry</button>@endif
@if($edition->status === 'published' && \App\Services\RadioVideo::ready() && $edition->video_status === 'failed')<button name="action" value="video" class="md-secondary">Retry video</button>@endif
@if(!in_array($edition->status, ['processing', 'held']))<button name="action" value="hold" class="md-secondary">{{ $edition->status === 'published' ? 'Withdraw edition' : 'Hold edition' }}</button>@endif
</form></article>
@empty<p class="md-ops-empty">No radio editions yet.</p>@endforelse
@if($editions instanceof \Illuminate\Contracts\Pagination\Paginator){{ $editions->links() }}@endif
</div>
@endsection
