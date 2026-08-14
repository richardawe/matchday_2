@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">📖 Blog Post Details</h1>
                            <p class="text-gray-600 mt-2">View and manage blog post information</p>
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

                <!-- Blog Post Details -->
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

                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Status: <span class="ml-1 px-2 py-1 text-xs rounded-full {{ $blog->status === 'published' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">{{ ucfirst($blog->status) }}</span>
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
                            {!! $blog->content !!}
                        </div>
                    </div>

                    <!-- Footer -->
                    <footer class="mt-12 pt-8 border-t border-gray-200">
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <div class="flex items-center space-x-4">
                                <span>Views: {{ number_format($blog->view_count) }}</span>
                                <span>•</span>
                                <span>Created: {{ $blog->created_at->format('M d, Y H:i') }}</span>
                                @if($blog->updated_at != $blog->created_at)
                                    <span>•</span>
                                    <span>Updated: {{ $blog->updated_at->format('M d, Y H:i') }}</span>
                                @endif
                            </div>
                        </div>
                    </footer>
                </article>

                <!-- Action Buttons -->
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <a href="{{ route('admin.blogs.preview', $blog) }}" 
                               class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                                👁️ Preview
                            </a>
                            @if($blog->status === 'published')
                                <a href="{{ route('blogs.show', $blog) }}" 
                                   target="_blank"
                                   class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                                    🌐 View Public
                                </a>
                            @endif
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <form action="{{ route('admin.blogs.destroy', $blog) }}" method="POST" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" 
                                        class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg"
                                        onclick="return confirm('Are you sure you want to delete this blog post? This action cannot be undone.')">
                                    🗑️ Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection 