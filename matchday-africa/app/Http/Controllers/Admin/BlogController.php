<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $blogs = Blog::orderBy('created_at', 'desc')
                    ->paginate(15);

        return view('admin.blogs.index', compact('blogs'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.blogs.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string|min:10',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'status' => 'required|in:draft,published',
            'author_name' => 'nullable|string|max:255',
            'metadata.tags' => 'nullable|string',
            'metadata.category' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $request->only([
                'title', 'excerpt', 'content', 'status', 'author_name'
            ]);

            // Handle featured image upload
            if ($request->hasFile('featured_image')) {
                try {
                    $imagePath = $request->file('featured_image')->store('blog-images', 'public');
                    $data['featured_image'] = $imagePath;
                } catch (\Exception $e) {
                    Log::error('Image upload failed: ' . $e->getMessage());
                    return redirect()->back()
                        ->with('error', 'Failed to upload image. Please try again.')
                        ->withInput();
                }
            }

            // Generate unique slug from title
            $baseSlug = Str::slug($request->title);
            $slug = $baseSlug;
            $counter = 1;
            
            // Check for slug uniqueness
            while (Blog::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
            
            // Set published_at based on status
            if ($request->status === 'published') {
                $data['published_at'] = now();
            } else {
                $data['published_at'] = null; // Explicitly set to null for drafts
            }

            // Handle metadata
            $metadata = [];
            if ($request->filled('metadata.tags')) {
                $tags = array_filter(array_map('trim', explode(',', $request->input('metadata.tags'))));
                if (!empty($tags)) {
                    $metadata['tags'] = $tags;
                }
            }
            if ($request->filled('metadata.category')) {
                $category = trim($request->input('metadata.category'));
                if (!empty($category)) {
                    $metadata['category'] = $category;
                }
            }
            $data['metadata'] = $metadata;

            // Log the creation attempt
            Log::info('Creating new blog post', [
                'title' => $data['title'],
                'slug' => $data['slug'],
                'status' => $data['status']
            ]);

            // Create the blog post
            $blog = Blog::create($data);

            Log::info('Blog post created successfully', [
                'blog_id' => $blog->id,
                'slug' => $blog->slug
            ]);

            return redirect()->route('admin.blogs.show', $blog)
                ->with('success', 'Blog post created successfully!');

        } catch (\Exception $e) {
            Log::error('Blog creation failed: ' . $e->getMessage(), [
                'request_data' => $request->all(),
                'exception' => $e
            ]);
            
            return redirect()->back()
                ->with('error', 'Failed to create blog post: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Blog $blog)
    {
        return view('admin.blogs.show', compact('blog'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Blog $blog)
    {
        return view('admin.blogs.edit', compact('blog'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Blog $blog)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string|min:10',
            'featured_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'status' => 'required|in:draft,published,archived',
            'author_name' => 'nullable|string|max:255',
            'metadata.tags' => 'nullable|string',
            'metadata.category' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $request->only([
                'title', 'excerpt', 'content', 'status', 'author_name'
            ]);

            // Handle featured image upload
            if ($request->hasFile('featured_image')) {
                // Delete old image if exists
                if ($blog->featured_image) {
                    Storage::disk('public')->delete($blog->featured_image);
                }
                
                $imagePath = $request->file('featured_image')->store('blog-images', 'public');
                $data['featured_image'] = $imagePath;
            }

            // Generate new slug if title changed
            if ($request->title !== $blog->title) {
                $data['slug'] = Str::slug($request->title);
            }

            // Handle published_at based on status
            if ($request->status === 'published' && $blog->status !== 'published') {
                $data['published_at'] = now();
            } elseif ($request->status !== 'published') {
                $data['published_at'] = null;
            }

            // Handle metadata
            $metadata = $blog->metadata ?? [];
            if ($request->filled('metadata.tags')) {
                $metadata['tags'] = array_filter(array_map('trim', explode(',', $request->input('metadata.tags'))));
            }
            if ($request->filled('metadata.category')) {
                $metadata['category'] = $request->input('metadata.category');
            }
            $data['metadata'] = $metadata;

            // Log the update attempt
            Log::info('Updating blog post', [
                'blog_id' => $blog->id,
                'old_title' => $blog->title,
                'new_title' => $data['title'],
                'data' => $data
            ]);

            // Update the blog
            $blog->update($data);

            // Refresh the model to get updated data
            $blog->refresh();

            // Log successful update
            Log::info('Blog post updated successfully', [
                'blog_id' => $blog->id,
                'new_slug' => $blog->slug
            ]);

            // Redirect to the updated blog's show page instead of index
            return redirect()->route('admin.blogs.show', $blog)
                ->with('success', 'Blog post updated successfully!');

        } catch (\Exception $e) {
            Log::error('Blog update failed: ' . $e->getMessage(), [
                'blog_id' => $blog->id,
                'request_data' => $request->all(),
                'exception' => $e
            ]);
            
            return redirect()->back()
                ->with('error', 'Failed to update blog post: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Blog $blog)
    {
        try {
            // Delete featured image if exists
            if ($blog->featured_image) {
                Storage::disk('public')->delete($blog->featured_image);
            }

            $blog->delete();

            return redirect()->route('admin.blogs.index')
                ->with('success', 'Blog post deleted successfully!');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to delete blog post: ' . $e->getMessage());
        }
    }

    /**
     * Toggle blog status
     */
    public function toggleStatus(Blog $blog)
    {
        try {
            if ($blog->status === 'published') {
                $blog->unpublish();
                $message = 'Blog post unpublished successfully!';
            } else {
                $blog->publish();
                $message = 'Blog post published successfully!';
            }

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to toggle blog status: ' . $e->getMessage());
        }
    }

    /**
     * Preview blog post
     */
    public function preview(Blog $blog)
    {
        return view('admin.blogs.preview', compact('blog'));
    }
}
