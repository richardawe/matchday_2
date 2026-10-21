{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
<channel>
 <title>Matchday Africa Radio</title><link>{{ route('radio.index') }}</link><language>en</language>
 <description>Football bulletins at 6am, noon and 6pm WAT. {{ config('radio.disclosure') }}</description>
 <atom:link href="{{ route('radio.feed') }}" rel="self" type="application/rss+xml" />
 <itunes:author>Matchday Africa</itunes:author><itunes:explicit>false</itunes:explicit>
 <itunes:category text="Sports"><itunes:category text="Soccer" /></itunes:category>
 @foreach($editions as $edition)
 <item><title>{{ $edition->title }} — {{ $edition->recorded_at->copy()->timezone('Africa/Lagos')->format('j M Y H:i') }} WAT</title>
 <link>{{ route('radio.show', $edition) }}</link><guid isPermaLink="true">{{ route('radio.show', $edition) }}</guid>
 <pubDate>{{ $edition->published_at->copy()->utc()->toRfc2822String() }}</pubDate>
 <description>{{ \Illuminate\Support\Str::limit($edition->script, 600) }} Transcript and sources: {{ route('radio.show', $edition) }}</description>
 <enclosure url="{{ route('radio.audio', $edition) }}" length="{{ $edition->audio_bytes }}" type="audio/mpeg" />
 <itunes:duration>{{ $edition->duration_seconds }}</itunes:duration></item>
 @endforeach
</channel></rss>
