<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArticlePublishLog;
use App\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** /admin/settings (admins only): site switches. First one: whether the agent may publish its own articles. */
class SettingsController extends Controller
{
    public function index()
    {
        return view('admin.settings.index', [
            'ready' => SiteSettings::ready(),
            'agentCanPublish' => SiteSettings::agentCanPublish(),
            'log' => Schema::hasTable('article_publish_logs')
                ? ArticlePublishLog::with('blog:id,title', 'user:id,name')->latest('id')->limit(20)->get() : collect(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (!SiteSettings::ready()) {
            return back()->with('error', 'Run the migrations first (php artisan migrate --force).');
        }
        $on = $request->boolean('agent_can_publish');
        SiteSettings::set(SiteSettings::AGENT_CAN_PUBLISH, $on, $request->user()->id);

        return back()->with('success', $on ? 'The agent can publish its own articles.' : 'Agent publishing is off: the API answers 403.');
    }
}
