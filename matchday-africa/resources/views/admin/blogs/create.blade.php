@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">✍️ Create New Blog Post</h1>
                            <p class="text-gray-600 mt-2">Write and publish your latest article</p>
                        </div>
                        <a href="{{ route('admin.blogs.index') }}" 
                           class="text-gray-600 hover:text-gray-900">
                            ← Back to Blog List
                        </a>
                    </div>
                </div>

                @if($errors->any())
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        <ul class="list-disc list-inside">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('admin.blogs.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Title -->
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                                Blog Title *
                            </label>
                            <input type="text" 
                                   name="title" 
                                   id="title" 
                                   value="{{ old('title') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Enter your blog post title"
                                   required>
                        </div>

                        <!-- Excerpt -->
                        <div>
                            <label for="excerpt" class="block text-sm font-medium text-gray-700 mb-2">
                                Excerpt (Optional)
                            </label>
                            <textarea name="excerpt" 
                                      id="excerpt" 
                                      rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="A brief summary of your blog post (will be auto-generated if left empty)">{{ old('excerpt') }}</textarea>
                            <p class="text-sm text-gray-500 mt-1">Leave empty to auto-generate from content</p>
                        </div>

                        <!-- Featured Image -->
                        <div>
                            <label for="featured_image" class="block text-sm font-medium text-gray-700 mb-2">
                                Featured Image
                            </label>
                            <div class="flex items-center space-x-4">
                                <input type="file" 
                                       name="featured_image" 
                                       id="featured_image" 
                                       accept="image/*"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            </div>
                            <p class="text-sm text-gray-500 mt-1">Recommended size: 1200x630px. Max size: 2MB</p>
                        </div>

                        <!-- Content -->
                        <div>
                            <label for="content" class="block text-sm font-medium text-gray-700 mb-2">
                                Blog Content *
                            </label>
                            <textarea name="content" 
                                      id="content" 
                                      rows="15"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                                      placeholder="Write your blog post content here..."
                                      required>{{ old('content') }}</textarea>
                            <p class="text-sm text-gray-500 mt-1">
                                Formatting: Use plain text, <strong>Markdown</strong> (e.g., <code>**bold**</code>, <code>*italic*</code>, lists, quotes),
                                or basic HTML tags. Plain text will be auto-formatted.
                            </p>
                        </div>

                        <!-- Metadata Row -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Author Name -->
                            <div>
                                <label for="author_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Author Name
                                </label>
                                <input type="text" 
                                       name="author_name" 
                                       id="author_name" 
                                       value="{{ old('author_name', 'Admin') }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Author name">
                            </div>

                            <!-- Status -->
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                    Status *
                                </label>
                                <select name="status" 
                                        id="status" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="draft" {{ old('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                                    <option value="published" {{ old('status') === 'published' ? 'selected' : '' }}>Published</option>
                                </select>
                            </div>
                        </div>

                        <!-- Tags and Category -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Tags -->
                            <div>
                                <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">
                                    Tags
                                </label>
                                <input type="text" 
                                       name="metadata[tags]" 
                                       id="tags" 
                                       value="{{ old('metadata.tags') }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="football, premier league, transfer news">
                                <p class="text-sm text-gray-500 mt-1">Separate tags with commas</p>
                            </div>

                            <!-- Category -->
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-2">
                                    Category
                                </label>
                                <input type="text" 
                                       name="metadata[category]" 
                                       id="category" 
                                       value="{{ old('metadata.category') }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="e.g., Transfer News, Match Reports">
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center justify-end space-x-4 pt-6 border-t border-gray-200">
                            <a href="{{ route('admin.blogs.index') }}" 
                               class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Create Blog Post
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate excerpt from content if empty
    const contentField = document.getElementById('content');
    const excerptField = document.getElementById('excerpt');
    
    if (contentField && excerptField) {
        contentField.addEventListener('input', function() {
            if (!excerptField.value.trim()) {
                const content = this.value;
                const excerpt = content.substring(0, 150).trim();
                if (excerpt.length === 150) {
                    excerptField.value = excerpt + '...';
                } else {
                    excerptField.value = excerpt;
                }
            }
        });
    }

    // Preview image before upload
    const imageField = document.getElementById('featured_image');
    if (imageField) {
        imageField.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                console.log('Image selected:', file.name);
                // You can add image preview functionality here
            }
        });
    }
});
</script>
@endsection 