@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">👁️ Blog Preview</h1>
                            <p class="text-gray-600 mt-2">Preview how your blog post will appear to readers</p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <a href="{{ route('admin.blogs.edit', $blog) }}" 
                               class="bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg">
                                Edit Post
                            </a>
                            <a href="{{ route('admin.blogs.index') }}" 
                               class="text-gray-600 hover:text-gray-900">
                                ← Back to Blog List
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Blog Post Preview -->
                <article class="prose prose-lg max-w-none">
                    <!-- Header -->
                    <header class="mb-8">
                        <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $blog->title }}</h1>
                        
                        <div class="flex items-center space-x-6 text-sm text-gray-600 mb-6">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                {{ $blog->author_name }}
                            </div>
                            
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                {{ $blog->published_at ? $blog->published_at->format('M d, Y') : 'Not published yet' }}
                            </div>
                            
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                {{ $blog->reading_time }}
                            </div>
                        </div>

                        @if($blog->featured_image)
                            <div class="mb-6">
                                <img src="{{ $blog->featured_image_url }}" 
                                     alt="{{ $blog->title }}" 
                                     class="w-full h-64 md:h-96 object-cover rounded-lg shadow-lg">
                            </div>
                        @endif

                        @if($blog->excerpt)
                            <div class="text-xl text-gray-700 leading-relaxed mb-6 p-4 bg-gray-50 rounded-lg border-l-4 border-blue-500">
                                {{ $blog->excerpt }}
                            </div>
                        @endif

                        <!-- Tags and Category -->
                        @if(isset($blog->metadata['tags']) || isset($blog->metadata['category']))
                            <div class="flex flex-wrap items-center space-x-3 mb-6">
                                @if(isset($blog->metadata['category']))
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                        {{ $blog->metadata['category'] }}
                                    </span>
                                @endif
                                
                                @if(isset($blog->metadata['tags']))
                                    @foreach($blog->metadata['tags'] as $tag)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            #{{ $tag }}
                                        </span>
                                    @endforeach
                                @endif
                            </div>
                        @endif
                    </header>

                    <!-- Content -->
                    <div class="blog-content text-gray-800 leading-relaxed">
                        <div class="whitespace-pre-wrap break-words overflow-wrap-anywhere">
                            @php($hasHtml = $blog->content !== strip_tags($blog->content))
                            @if($hasHtml)
                                {!! $blog->content !!}
                            @else
                                {!! \Illuminate\Support\Str::markdown($blog->content) !!}
                            @endif
                        </div>
                    </div>

                    <!-- Footer -->
                    <footer class="mt-12 pt-8 border-t border-gray-200">
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <div class="flex items-center space-x-4">
                                <span>Views: {{ number_format($blog->view_count) }}</span>
                                <span>•</span>
                                <span>Last updated: {{ $blog->updated_at->format('M d, Y H:i') }}</span>
                            </div>
                            
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                    @if($blog->status === 'published') 
                                        bg-green-100 text-green-800
                                    @elseif($blog->status === 'draft')
                                        bg-yellow-100 text-yellow-800
                                    @else
                                        bg-gray-100 text-gray-800
                                    @endif">
                                    {{ ucfirst($blog->status) }}
                                </span>
                            </div>
                        </div>
                    </footer>
                </article>

                <!-- Action Buttons -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            @if($blog->status === 'draft')
                                <span class="text-yellow-600">⚠️ This post is currently a draft and not visible to readers</span>
                            @elseif($blog->status === 'published')
                                <span class="text-green-600">✅ This post is published and visible to readers</span>
                            @else
                                <span class="text-gray-600">📁 This post is archived</span>
                            @endif
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            @if($blog->status === 'draft')
                                <form action="{{ route('admin.blogs.toggle-status', $blog) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" 
                                            class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                                        Publish Now
                                    </button>
                                </form>
                            @elseif($blog->status === 'published')
                                <form action="{{ route('admin.blogs.toggle-status', $blog) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" 
                                            class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded-lg">
                                        Unpublish
                                    </button>
                                </form>
                            @endif
                            
                            <a href="{{ route('admin.blogs.edit', $blog) }}" 
                               class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                                Edit Post
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.blog-content {
    font-size: 1.125rem;
    line-height: 1.75;
}

.blog-content p {
    margin-bottom: 1.5rem;
}

.blog-content h2, .blog-content h3, .blog-content h4 {
    margin-top: 2rem;
    margin-bottom: 1rem;
    font-weight: 600;
    color: #1f2937;
}

.blog-content h2 {
    font-size: 1.875rem;
}

.blog-content h3 {
    font-size: 1.5rem;
}

.blog-content h4 {
    font-size: 1.25rem;
}

.blog-content ul, .blog-content ol {
    margin-bottom: 1.5rem;
    padding-left: 1.5rem;
}

.blog-content li {
    margin-bottom: 0.5rem;
}

.blog-content blockquote {
    border-left: 4px solid #3b82f6;
    padding-left: 1rem;
    margin: 1.5rem 0;
    font-style: italic;
    color: #6b7280;
}

.blog-content code {
    background-color: #f3f4f6;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 0.875rem;
}

.blog-content pre {
    background-color: #1f2937;
    color: #f9fafb;
    padding: 1rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 1.5rem 0;
}

.blog-content pre code {
    background-color: transparent;
    color: inherit;
    padding: 0;
}
</style>
@endsection 