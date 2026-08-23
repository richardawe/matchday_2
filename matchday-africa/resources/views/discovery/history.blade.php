@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap"><p class="md-eyebrow">AFRICAN FOOTBALL ARCHIVE</p><h1>AFCON player history.</h1><p class="md-lead">Match-by-match performances from the 2023 African Cup of Nations.</p>
<form class="mt-6 flex gap-2" method="GET"><input class="rounded-lg border-gray-300" name="search" value="{{ request('search') }}" placeholder="Search a player"><button class="rounded-lg bg-gray-900 text-white px-5">Search</button></form>
<div class="overflow-x-auto bg-white rounded-xl border border-gray-200 mt-8"><table class="min-w-full text-sm"><thead><tr>@foreach(['Player','Team','Apps','Min','G','A','Shots','Passes','Key passes','Tackles','Interceptions'] as $heading)<th class="p-3 text-left">{{ $heading }}</th>@endforeach</tr></thead><tbody>
@forelse($players as $player)<tr class="border-t"><td class="p-3"><strong>{{ $player->player_name }}</strong><small class="block text-gray-500">{{ $player->country }}</small></td><td class="p-3">{{ $player->team }}</td><td class="p-3">{{ $player->appearances }}</td><td class="p-3">{{ $player->minutes ?: '—' }}</td><td class="p-3">{{ $player->goals }}</td><td class="p-3">{{ $player->assists }}</td><td class="p-3">{{ $player->shots }}</td><td class="p-3">{{ $player->completed_passes }}/{{ $player->passes }}</td><td class="p-3">{{ $player->key_passes }}</td><td class="p-3">{{ $player->tackles }}</td><td class="p-3">{{ $player->interceptions }}</td></tr>@empty<tr><td class="p-6" colspan="11">Historical data will appear after the StatsBomb import runs.</td></tr>@endforelse
</tbody></table></div><div class="mt-5">{{ $players->links() }}</div>
<footer class="mt-8 text-sm text-gray-600"><p>Data source: <a class="underline" href="https://github.com/statsbomb/open-data" target="_blank" rel="noopener noreferrer">StatsBomb Open Data</a>. Used under its open-data terms.</p></footer>
</div></section>
@endsection
