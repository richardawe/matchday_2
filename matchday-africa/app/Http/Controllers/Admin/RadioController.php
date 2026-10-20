<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RadioBulletin;
use App\Services\RadioScript;
use App\Services\RadioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class RadioController extends Controller
{
    public function index()
    {
        return view('admin.radio.index', [
            'editions' => Schema::hasTable('radio_bulletins') ? RadioBulletin::orderByDesc('slot')->paginate(12) : collect(),
        ]);
    }

    public function queue(RadioService $radio)
    {
        return $this->change(fn () => $radio->queue());
    }

    public function update(Request $request, RadioBulletin $bulletin, RadioScript $writer, RadioService $radio)
    {
        $data = $request->validate(['paragraphs' => 'required|array|list|max:16', 'paragraphs.*' => 'required|string|min:15|max:6000']);

        return $this->change(function () use ($data, $bulletin, $writer, $radio) {
            $bulletin->refresh();
            abort_unless($bulletin->status === 'draft', 409);
            $radio->assertFresh($bulletin);
            $segments = $bulletin->segments;
            abort_unless(count($data['paragraphs']) === count($segments), 422);
            foreach ($segments as $i => &$segment) {
                $segment['text'] = $data['paragraphs'][$i];
            }
            unset($segment);
            $writer->validate($segments, $bulletin->facts);
            $writer->verify($segments, $bulletin->facts);
            $bulletin->update(['segments' => $segments, 'script' => $radio->compose($bulletin, $segments)]);
        });
    }

    public function action(Request $request, RadioBulletin $bulletin, RadioService $radio)
    {
        $action = $request->validate(['action' => 'required|in:voice,publish,hold,retry'])['action'];

        return $this->change(function () use ($action, $bulletin, $radio) {
            $bulletin->refresh();
            if ($action === 'publish') {
                $radio->publish($bulletin);
            } elseif ($action === 'voice') {
                abort_unless($bulletin->status === 'draft', 409);
                $radio->assertFresh($bulletin);
                $bulletin->update(['status' => 'queued', 'stage' => 'audio', 'attempts' => 0]);
            } elseif ($action === 'hold') {
                abort_if($bulletin->status === 'processing', 409);
                $bulletin->update(['status' => 'held']);
            } else {
                abort_unless($bulletin->status === 'failed', 409);
                $bulletin->update(['status' => 'queued', 'attempts' => 0, 'failure_reason' => null]);
            }
        });
    }

    public function audio(RadioBulletin $bulletin)
    {
        abort_unless($file = $bulletin->audioFile(), 404);

        return response()->file($file, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, no-store']);
    }

    private function change(callable $operation)
    {
        abort_unless(RadioBulletin::available(), 409, 'Enable radio and run the migration first.');
        $lock = Cache::lock('radio-pipeline', 1800);
        abort_unless($lock->get(), 409, 'A radio worker is running. Try again after it finishes.');
        try {
            $operation();

            return back()->with('success', 'Radio edition updated. Queued work runs in the background.');
        } catch (\RuntimeException $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }

            return back()->with('error', $e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
