<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BreakingNews;
use App\Services\BreakingNewsDesk;
use Illuminate\Http\Request;

/** /admin/breaking: every breaking news item with who posted it; admins post, edit and end them. */
class BreakingNewsController extends Controller
{
    public function __construct(private BreakingNewsDesk $desk) {}

    public function index()
    {
        abort_unless(BreakingNews::ready(), 404, 'Run the migrations first.');

        return view('admin.breaking.index', [
            'current' => BreakingNews::current(),
            'items' => BreakingNews::with(['author', 'editor', 'ender'])->latest('created_at')->latest('id')->paginate(30),
            'maxHours' => BreakingNewsDesk::MAX_HOURS, 'defaultHours' => BreakingNewsDesk::DEFAULT_HOURS,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(BreakingNews::ready(), 404);
        $this->desk->create($this->input($request), $request->user(), 'admin');

        return back()->with('success', 'Breaking news is live.');
    }

    public function update(Request $request, BreakingNews $breaking)
    {
        $this->desk->update($breaking, $this->input($request), $request->user());

        return back()->with('success', 'Updated.');
    }

    public function end(Request $request, BreakingNews $breaking)
    {
        $this->desk->end($breaking, $request->user());

        return back()->with('success', 'Ended: it is off the site.');
    }

    /** Form fields as the desk expects them (blank optional fields clear the value). */
    private function input(Request $request): array
    {
        $input = $request->only(['headline', 'body', 'link_url', 'image_url', 'image_credit', 'expires_in_hours']);

        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $input);
    }
}
