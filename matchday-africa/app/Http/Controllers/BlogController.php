<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogController extends Controller
{
    public function image(string $filename)
    {
        abort_unless($filename === basename($filename), 404);
        $path = 'blog-images/'.$filename;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    /**
     * Display a listing of published blogs
     */
    public function index()
    {
        $blogs = Blog::where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        return view('blogs.index', compact('blogs'));
    }

    /**
     * Display the specified blog post
     */
    public function show(Blog $blog)
    {
        // Check if blog is published and accessible
        if ($blog->status !== 'published' || !$blog->published_at || $blog->published_at > now()) {
            abort(404);
        }

        // Increment view count
        $blog->incrementViewCount();

        return view('blogs.show', compact('blog'))->with('content', $blog);
    }
}
