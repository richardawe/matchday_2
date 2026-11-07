<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * /admin/social-media: upload social creatives (videos and images) to the public disk and copy
 * their public URLs, so a new file doesn't need a deploy. Files go to
 * storage/app/public/social/<yyyy-mm>/<slug>-<random>.<ext>, served at /storage/social/….
 */
class SocialMediaController extends Controller
{
    public const MAX_KB = 200 * 1024; // 200 MB

    public function index()
    {
        $disk = Storage::disk('public');
        $files = collect($disk->allFiles('social'))
            ->filter(fn ($path) => preg_match('~^social/\d{4}-\d{2}/[^/]+\.(mp4|mov|jpe?g|png)$~i', $path))
            ->map(fn ($path) => [
                'name' => basename($path),
                'url' => $disk->url($path),
                'size' => $disk->size($path),
                'modified' => $disk->lastModified($path),
                'video' => (bool) preg_match('/\.(mp4|mov)$/i', $path),
            ])
            ->sortByDesc('modified')->values();

        return view('admin.social-media.index', [
            'files' => $files,
            'uploadLimit' => ini_get('upload_max_filesize'),
            'postLimit' => ini_get('post_max_size'),
            'serverBytes' => min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size'))),
            'maxBytes' => self::MAX_KB * 1024,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,mov,jpg,jpeg,png', 'max:'.self::MAX_KB],
        ], [
            'file.mimes' => 'Upload an MP4, MOV, JPG or PNG file.',
            'file.max' => 'The file is larger than 200 MB.',
            'file.uploaded' => 'The upload did not arrive. It may be larger than the server allows (see the limits below).',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!in_array($ext, ['mp4', 'mov', 'jpg', 'png'], true)) {
            $ext = $file->guessExtension() === 'jpeg' ? 'jpg' : (string) $file->guessExtension();
        }
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $name = Str::limit($slug, 80, '').'-'.Str::lower(Str::random(8)).'.'.$ext;
        $path = $file->storeAs('social/'.now()->format('Y-m'), $name, 'public');

        return redirect()->route('admin.social-media.index')
            ->with('success', 'Uploaded.')
            ->with('uploaded', Storage::disk('public')->url($path));
    }

    /** "128M" → bytes. */
    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (float) $value;

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
