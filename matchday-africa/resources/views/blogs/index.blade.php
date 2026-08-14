@extends('layouts.public')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Sign-in notification for non-authenticated users -->
        @guest
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            <strong>Sign in to access exclusive content!</strong> 
                            Create an account to comment, save articles, and get personalized updates.
                        </p>
                        <div class="mt-2 flex space-x-3">
                            <a href="{{ route('login') }}" class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                Sign In
                            </a>
                            <a href="{{ route('register') }}" class="text-sm font-medium text-blue-600 hover:text-blue-500">
                                Register
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endguest

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <h1 class="text-3xl font-bold mb-8">Latest Articles</h1>
                
                @if($blogs->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($blogs as $blog)
                        <article class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200 hover:shadow-lg transition-shadow">
                            @if($blog->featured_image)
                            <img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}" class="w-full h-48 object-cover">
                            @else
                            <div class="w-full h-48 bg-gray-200 flex items-center justify-center">
                                <span class="text-gray-500 text-lg">No Image</span>
                            </div>
                            @endif
                            <div class="p-6">
                                <h2 class="text-xl font-semibold mb-2">
                                    <a href="{{ route('blogs.show', $blog) }}" class="text-blue-600 hover:text-blue-800 transition-colors">
                                        {{ $blog->title }}
                                    </a>
                                </h2>
                                @php($excerptHasHtml = $blog->excerpt !== strip_tags($blog->excerpt))
                                <p class="text-gray-600 mb-4">
                                    @if($excerptHasHtml)
                                        {!! $blog->excerpt !!}
                                    @else
                                        {!! \Illuminate\Support\Str::markdown($blog->excerpt) !!}
                                    @endif
                                </p>
                                <div class="flex items-center justify-between text-sm text-gray-500">
                                    <span>{{ $blog->formatted_published_date }}</span>
                                    <span>{{ $blog->reading_time }}</span>
                                </div>
                            </div>
                        </article>
                        @endforeach
                    </div>
                    
                    <div class="mt-8">
                        {{ $blogs->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <h3 class="text-lg text-gray-600">No articles published yet.</h3>
                        <p class="text-gray-500 mt-2">Check back soon for new content!</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection 