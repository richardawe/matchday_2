@extends('layouts.public')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
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

        <article class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            @if($blog->featured_image)
            <img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}" class="w-full h-96 object-cover">
            @else
            <div class="w-full h-96 bg-gray-200 flex items-center justify-center">
                <span class="text-gray-500 text-2xl">No Featured Image</span>
            </div>
            @endif
            
            <div class="p-8">
                <header class="mb-8">
                    <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $blog->title }}</h1>
                    <div class="flex items-center text-gray-600 text-sm">
                        <span>By {{ $blog->author_name ?? 'Admin' }}</span>
                        <span class="mx-2">•</span>
                        <span>{{ $blog->formatted_published_date }}</span>
                        <span class="mx-2">•</span>
                        <span>{{ $blog->reading_time }}</span>
                        @if($blog->view_count > 0)
                        <span class="mx-2">•</span>
                        <span>{{ $blog->view_count }} {{ Str::plural('view', $blog->view_count) }}</span>
                        @endif
                    </div>
                </header>

                <!-- Social Sharing Section -->
                <div class="mb-8 pb-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Share this article
                        </div>
                        <x-social-share-buttons :content="$blog" :show-counts="true" />
                    </div>
                </div>
                
                @if($blog->excerpt)
                <div class="mb-8 p-4 bg-blue-50 border-l-4 border-blue-400">
                    <p class="text-lg text-gray-700 italic">{{ $blog->excerpt }}</p>
                </div>
                @endif
                
                <div class="prose prose-lg max-w-none text-gray-800 blog-content">
                    <div class="whitespace-pre-wrap break-words overflow-wrap-anywhere">
                        @php($hasHtml = $blog->content !== strip_tags($blog->content))
                        @if($hasHtml)
                            {!! $blog->content !!}
                        @else
                            {!! \Illuminate\Support\Str::markdown($blog->content) !!}
                        @endif
                    </div>
                </div>
                
                @if($blog->metadata && isset($blog->metadata['tags']))
                <div class="mt-8 pt-6 border-t border-gray-200">
                    <h3 class="text-lg font-semibold mb-3">Tags:</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach($blog->metadata['tags'] as $tag)
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">{{ $tag }}</span>
                        @endforeach
                    </div>
                </div>
                @endif
                
                <!-- Chat & Banter Section -->
                <div class="mt-8 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-semibold mb-4 flex items-center">
                            💬 Comments
                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-2 font-normal">
                                ..lets have it, lets have it...
                            </span>
                        </h3>
                        
                        @auth
                            <!-- Chat Messages Container -->
                            <div id="chat-messages" class="mb-4 h-64 sm:h-80 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg p-2 sm:p-3 bg-gray-50 dark:bg-gray-900">
                                <div class="space-y-2 sm:space-y-3" id="chat-messages-container">
                                    <!-- Messages will be added here -->
                                </div>
                            </div>

                            <!-- Chat Input Form -->
                            <form id="chat-form" class="space-y-3" action="#" method="post">
                                @csrf
                                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                    <div class="flex space-x-2 flex-1">
                                        <input type="text" 
                                               id="chat-input" 
                                               placeholder="Type your message... (@username to mention)" 
                                               class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm sm:text-base px-3 py-2">
                                        <button type="button" 
                                                id="gif-button"
                                                class="px-3 py-2 sm:px-4 bg-purple-500 hover:bg-purple-700 text-white rounded-lg text-xs sm:text-sm font-medium flex-shrink-0">
                                            📸
                                        </button>
                                    </div>
                                    <button type="submit" 
                                            class="px-4 py-2 bg-blue-500 hover:bg-blue-700 text-white rounded-lg text-sm font-medium w-full sm:w-auto">
                                        Send
                                    </button>
                                </div>
                                
                                <!-- User mention suggestions -->
                                <div id="mention-suggestions" class="hidden relative bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-40 overflow-y-auto z-10 w-full">
                                    <!-- Suggestions will be populated via JavaScript -->
                                </div>
                            </form>

                            <!-- GIF Picker Modal -->
                            <div id="gif-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 sm:p-6 w-full max-w-4xl max-h-[90vh] sm:max-h-[80vh] overflow-hidden">
                                    <div class="flex justify-between items-center mb-4">
                                        <h4 class="text-lg font-semibold">Choose a GIF</h4>
                                        <button id="close-gif-modal" class="text-gray-500 hover:text-gray-700">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>
                                    
                                    <!-- GIF Search -->
                                    <div class="mb-4">
                                        <input type="text" 
                                               id="gif-search-input" 
                                               placeholder="Search for GIFs..." 
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                    </div>
                                    
                                    <!-- GIF Tabs -->
                                    <div class="flex space-x-4 mb-4">
                                        <button class="gif-tab active px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600" data-tab="trending">
                                            Trending
                                        </button>
                                        <button class="gif-tab px-4 py-2 text-sm font-medium text-gray-500 hover:text-blue-600" data-tab="football">
                                            Football
                                        </button>
                                    </div>
                                    
                                    <!-- GIF Grid -->
                                    <div id="gif-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 sm:gap-3 max-h-64 sm:max-h-96 overflow-y-auto">
                                        <!-- GIFs will be loaded here -->
                                    </div>
                                    
                                    <div id="gif-loading" class="text-center py-4 hidden">
                                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
                                        <span class="ml-2">Loading GIFs...</span>
                                    </div>
                                </div>
                            </div>
                            
                        @else
                            <div class="text-center py-8">
                                <p class="text-gray-600 dark:text-gray-400 mb-4">Join the conversation! Sign in to chat with other fans.</p>
                                <div class="space-x-4">
                                    <a href="{{ route('login') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                        Login
                                    </a>
                                    <a href="{{ route('register') }}" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                        Register
                                    </a>
                                </div>
                            </div>
                        @endauth
                    </div>
                </div>

                <footer class="mt-8 pt-6 border-t border-gray-200">
                    <a href="{{ route('blogs.index') }}" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Articles
                    </a>
                </footer>
            </div>
        </article>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const chatMessages = document.getElementById('chat-messages');
    const gifButton = document.getElementById('gif-button');
    const gifModal = document.getElementById('gif-modal');
    const closeGifModal = document.getElementById('close-gif-modal');
    const gifSearchInput = document.getElementById('gif-search-input');
    const gifGrid = document.getElementById('gif-grid');
    const gifLoading = document.getElementById('gif-loading');
    const gifTabs = document.querySelectorAll('.gif-tab');
    const mentionSuggestions = document.getElementById('mention-suggestions');
    
    let currentGifTab = 'trending';
    let searchTimeout;
    let mentionTimeout;
    let selectedMentionIndex = -1;
    const matchId = '001';

    // Chat form submission
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const message = chatInput.value.trim();
            if (!message) return;
            
            sendMessage({ message: message });
            chatInput.value = '';
            hideMentionSuggestions();
        });
    }

    // Mention functionality
    if (chatInput) {
        chatInput.addEventListener('input', function(e) {
            const message = e.target.value;
            const cursorPosition = e.target.selectionStart;
            
            // Find the last @ symbol before cursor
            const beforeCursor = message.substring(0, cursorPosition);
            const atIndex = beforeCursor.lastIndexOf('@');
            
            if (atIndex !== -1) {
                const afterAt = beforeCursor.substring(atIndex + 1);
                // Only search if there's no space after @ and we're still typing the username
                if (!afterAt.includes(' ') && afterAt.length >= 0) {
                    clearTimeout(mentionTimeout);
                    mentionTimeout = setTimeout(() => {
                        searchUsers(afterAt);
                    }, 300);
                } else {
                    hideMentionSuggestions();
                }
            } else {
                hideMentionSuggestions();
            }
        });

        chatInput.addEventListener('keydown', function(e) {
            if (mentionSuggestions && !mentionSuggestions.classList.contains('hidden')) {
                const suggestions = mentionSuggestions.querySelectorAll('.mention-suggestion');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedMentionIndex = Math.min(selectedMentionIndex + 1, suggestions.length - 1);
                    updateMentionSelection(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedMentionIndex = Math.max(selectedMentionIndex - 1, 0);
                    updateMentionSelection(suggestions);
                } else if (e.key === 'Enter' && selectedMentionIndex >= 0) {
                    e.preventDefault();
                    selectMention(suggestions[selectedMentionIndex]);
                } else if (e.key === 'Escape') {
                    hideMentionSuggestions();
                }
            }
        });
    }

    // GIF button click
    if (gifButton) {
        gifButton.addEventListener('click', function() {
            gifModal.classList.remove('hidden');
            loadGifs(currentGifTab);
        });
    }

    // Close GIF modal
    if (closeGifModal) {
        closeGifModal.addEventListener('click', function() {
            gifModal.classList.add('hidden');
        });
    }

    // GIF modal backdrop click
    if (gifModal) {
        gifModal.addEventListener('click', function(e) {
            if (e.target === gifModal) {
                gifModal.classList.add('hidden');
            }
        });
    }

    // GIF search
    if (gifSearchInput) {
        gifSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    searchGifs(query);
                }, 500);
            } else if (query.length === 0) {
                loadGifs(currentGifTab);
            }
        });
    }

    // GIF tabs
    gifTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabType = this.dataset.tab;
            
            // Update active tab
            gifTabs.forEach(t => {
                t.classList.remove('active', 'text-blue-600', 'border-b-2', 'border-blue-600');
                t.classList.add('text-gray-500');
            });
            
            this.classList.add('active', 'text-blue-600', 'border-b-2', 'border-blue-600');
            this.classList.remove('text-gray-500');
            
            currentGifTab = tabType;
            gifSearchInput.value = '';
            loadGifs(tabType);
        });
    });

    // Send message function
    function sendMessage(data) {
        fetch(`/matches/${matchId}/chats`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                addMessageToChat(data.chat);
                scrollToBottom();
            } else {
                console.error('Error sending message:', data.errors);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    // Add message to chat - FIXED VERSION
    function addMessageToChat(chat) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex space-x-2 sm:space-x-3';
        
        const avatarInitial = chat.user.name.charAt(0).toUpperCase();
        
        let messageContent;
        if (chat.is_gif && chat.gif_url) {
            messageContent = `
                <div class="mt-1">
                    <img src="${chat.gif_url}" alt="${chat.gif_title || 'GIF'}" class="max-w-full sm:max-w-xs rounded-lg">
                    ${chat.gif_title ? `<p class="text-xs text-gray-600 dark:text-gray-400 mt-1 truncate">${chat.gif_title}</p>` : ''}
                </div>
            `;
        } else {
            // Use processed_message to show highlighted mentions
            messageContent = `<p class="text-xs sm:text-sm mt-1 break-words">${chat.processed_message || escapeHtml(chat.message)}</p>`;
        }
        
        messageDiv.innerHTML = `
            <div class="flex-shrink-0">
                <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs sm:text-sm font-semibold">
                    ${avatarInitial}
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-1 sm:space-x-2">
                    <span class="text-xs sm:text-sm font-medium truncate">${escapeHtml(chat.user.name)}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">${chat.created_at}</span>
                </div>
                ${messageContent}
            </div>
        `;
        
        // Use the correct container ID
        document.getElementById('chat-messages-container').appendChild(messageDiv);
    }

    // Load GIFs
    function loadGifs(type) {
        showGifLoading();
        
        let url;
        if (type === 'trending') {
            url = '/gifs/trending';
        } else if (type === 'football') {
            url = '/gifs/football';
        }
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                hideGifLoading();
                if (data.success) {
                    displayGifs(data.gifs);
                }
            })
            .catch(error => {
                console.error('Error loading GIFs:', error);
                hideGifLoading();
            });
    }

    // Search GIFs
    function searchGifs(query) {
        showGifLoading();
        
        fetch(`/gifs/search?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                hideGifLoading();
                if (data.success) {
                    displayGifs(data.gifs);
                }
            })
            .catch(error => {
                console.error('Error searching GIFs:', error);
                hideGifLoading();
            });
    }

    // Display GIFs
    function displayGifs(gifs) {
        gifGrid.innerHTML = '';
        
        gifs.forEach(gif => {
            const gifElement = document.createElement('div');
            gifElement.className = 'cursor-pointer hover:opacity-75 transition-opacity';
            gifElement.innerHTML = `
                <img src="${gif.preview_url || gif.url}" alt="${gif.title}" 
                     class="w-full h-24 object-cover rounded-lg"
                     data-gif-url="${gif.url}" 
                     data-gif-title="${gif.title}">
            `;
            
            gifElement.addEventListener('click', function() {
                const gifUrl = this.querySelector('img').dataset.gifUrl;
                const gifTitle = this.querySelector('img').dataset.gifTitle;
                
                sendMessage({
                    gif_url: gifUrl,
                    gif_title: gifTitle
                });
                
                gifModal.classList.add('hidden');
            });
            
            gifGrid.appendChild(gifElement);
        });
    }

    // Show/hide loading
    function showGifLoading() {
        gifLoading.classList.remove('hidden');
        gifGrid.classList.add('hidden');
    }

    function hideGifLoading() {
        gifLoading.classList.add('hidden');
        gifGrid.classList.remove('hidden');
    }

    // Scroll to bottom of chat
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Auto-refresh chat every 10 seconds
    setInterval(function() {
        refreshChat();
    }, 10000);

    // Refresh chat messages
    function refreshChat() {
        fetch(`/matches/${matchId}/chats`)
            .then(response => response.json())
            .then(data => {
                if (data.chats && data.chats.length > 0) {
                    const chatContainer = document.getElementById('chat-messages-container');
                    const currentCount = chatContainer.children.length;
                    
                    if (data.chats.length > currentCount) {
                        // Clear existing messages and re-add all
                        chatContainer.innerHTML = '';
                        
                        // Add all messages
                        data.chats.forEach(chat => {
                            addMessageToChat(chat);
                        });
                        scrollToBottom();
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing chat:', error);
            });
    }

    // Mention helper functions
    function searchUsers(query) {
        if (query.length < 1) {
            hideMentionSuggestions();
            return;
        }

        fetch(`/users/search?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.users.length > 0) {
                    showMentionSuggestions(data.users);
                } else {
                    hideMentionSuggestions();
                }
            })
            .catch(error => {
                console.error('Error searching users:', error);
                hideMentionSuggestions();
            });
    }

    function showMentionSuggestions(users) {
        if (!mentionSuggestions) return;

        mentionSuggestions.innerHTML = '';
        selectedMentionIndex = -1;

        users.forEach((user, index) => {
            const suggestion = document.createElement('div');
            suggestion.className = 'mention-suggestion px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer flex items-center space-x-2';
            suggestion.innerHTML = `
                <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                    ${user.name.charAt(0).toUpperCase()}
                </div>
                <span class="text-sm">${user.name}</span>
            `;

            suggestion.addEventListener('click', () => selectMention(suggestion));
            suggestion.setAttribute('data-username', user.name);
            mentionSuggestions.appendChild(suggestion);
        });

        mentionSuggestions.classList.remove('hidden');
    }

    function hideMentionSuggestions() {
        if (!mentionSuggestions) return;
        mentionSuggestions.classList.add('hidden');
        selectedMentionIndex = -1;
    }

    function updateMentionSelection(suggestions) {
        suggestions.forEach((suggestion, index) => {
            if (index === selectedMentionIndex) {
                suggestion.classList.add('bg-gray-100', 'dark:bg-gray-700');
            } else {
                suggestion.classList.remove('bg-gray-100', 'dark:bg-gray-700');
            }
        });
    }

    function selectMention(suggestionElement) {
        const username = suggestionElement.getAttribute('data-username');
        const message = chatInput.value;
        const cursorPosition = chatInput.selectionStart;
        
        // Find the @ symbol position
        const beforeCursor = message.substring(0, cursorPosition);
        const atIndex = beforeCursor.lastIndexOf('@');
        
        if (atIndex !== -1) {
            // Replace the partial mention with the complete username
            const beforeAt = message.substring(0, atIndex);
            const afterCursor = message.substring(cursorPosition);
            const newMessage = beforeAt + '@' + username + ' ' + afterCursor;
            
            chatInput.value = newMessage;
            
            // Position cursor after the mention
            const newCursorPosition = atIndex + username.length + 2;
            chatInput.setSelectionRange(newCursorPosition, newCursorPosition);
        }
        
        hideMentionSuggestions();
        chatInput.focus();
    }

    // Initial scroll to bottom
    scrollToBottom();
});
</script>
@endpush