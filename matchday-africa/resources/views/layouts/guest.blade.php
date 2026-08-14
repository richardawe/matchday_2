<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Account — Matchday Africa</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <!-- Google Sign-In JavaScript SDK -->
        <script src="https://accounts.google.com/gsi/client" async defer></script>
        
        <!-- JavaScript OAuth Implementation -->
        <script>
            // OAuth Configuration - will be loaded from API
            let GOOGLE_CLIENT_ID = '';
            let TWITTER_CLIENT_ID = '';
            let APP_URL = '';
            
            // Load OAuth configuration from API
            fetch('/api/oauth-config')
                .then(response => response.json())
                .then(data => {
                    GOOGLE_CLIENT_ID = data.google_client_id;
                    TWITTER_CLIENT_ID = data.twitter_client_id;
                    APP_URL = data.app_url;
                    
                    // Initialize Google Sign-In after config is loaded
                    if (GOOGLE_CLIENT_ID) {
                        initializeGoogleSignIn();
                    }
                })
                .catch(error => {
                    console.error('Failed to load OAuth config:', error);
                });
            
            function initializeGoogleSignIn() {
                // Check if Google Client ID is configured
                if (!GOOGLE_CLIENT_ID || GOOGLE_CLIENT_ID === '') {
                    return;
                }
                
                // Wait for Google SDK to load
                function waitForGoogleSDK() {
                    if (typeof google !== 'undefined' && google.accounts && google.accounts.id) {
                        try {
                            // Initialize Google Sign-In with better error handling
                            google.accounts.id.initialize({
                                client_id: GOOGLE_CLIENT_ID,
                                callback: handleGoogleSignIn,
                                auto_select: false,
                                cancel_on_tap_outside: true,
                                use_fedcm_for_prompt: false // Disable FedCM to avoid the error
                            });
                        } catch (error) {
                            console.error('Google Sign-In initialization error:', error);
                            // If initialization fails, set up fallback
                            setupGoogleFallback();
                        }
                    } else {
                        setTimeout(waitForGoogleSDK, 100);
                    }
                }
                
                waitForGoogleSDK();
            }
            
            function setupGoogleFallback() {
                // Set up fallback for Google Sign-In
                const googleBtn = document.getElementById('google-signin-button');
                if (googleBtn) {
                    googleBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        handleGoogleFallback();
                    });
                }
            }
            
            function handleGoogleFallback() {
                // Direct redirect to Google OAuth
                const redirectUri = APP_URL + '/oauth-callback';
                console.log('=== GOOGLE OAUTH DEBUG ===');
                console.log('APP_URL:', APP_URL);
                console.log('GOOGLE_CLIENT_ID:', GOOGLE_CLIENT_ID);
                console.log('Redirect URI:', redirectUri);
                
                const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' + new URLSearchParams({
                    client_id: GOOGLE_CLIENT_ID,
                    redirect_uri: redirectUri,
                    response_type: 'code',
                    scope: 'openid email profile',
                    state: 'google_oauth_state'
                });
                
                console.log('Generated Google OAuth URL:', authUrl);
                console.log('=== END DEBUG ===');
                window.location.href = authUrl;
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                // Google Sign-In Button - Use fallback by default to avoid FedCM issues
                const googleBtn = document.getElementById('google-signin-button');
                if (googleBtn) {
                    googleBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        handleGoogleFallback();
                    });
                }
                
                // Twitter Sign-In Button
                const twitterBtn = document.getElementById('twitter-signin-button');
                if (twitterBtn) {
                    twitterBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        handleTwitterSignIn();
                    });
                }
                
                // Debug form inputs
                const inputs = document.querySelectorAll('input[type="email"], input[type="password"], input[type="text"]');
                inputs.forEach(input => {
                    input.addEventListener('mousedown', function(e) {
                        console.log('Mouse down on input:', this.id, e);
                    });
                    
                    input.addEventListener('click', function(e) {
                        console.log('Click on input:', this.id, e);
                    });
                    
                    input.addEventListener('focus', function(e) {
                        console.log('Focus on input:', this.id, e);
                    });
                });
                
                // Debug submit buttons
                const submitButtons = document.querySelectorAll('button[type="submit"]');
                submitButtons.forEach(button => {
                    button.addEventListener('mousedown', function(e) {
                        console.log('Mouse down on submit button:', this.id, e);
                    });
                    
                    button.addEventListener('click', function(e) {
                        console.log('Click on submit button:', this.id, e);
                    });
                    
                    button.addEventListener('focus', function(e) {
                        console.log('Focus on submit button:', this.id, e);
                    });
                });
                
                // Debug form submission
                const forms = document.querySelectorAll('form');
                forms.forEach(form => {
                    form.addEventListener('submit', function(e) {
                        console.log('Form submit event triggered:', this.action, e);
                    });
                });
                
                // Debug navigation links
                const signupLink = document.getElementById('signup-link');
                const signinLink = document.getElementById('signin-link');
                
                if (signupLink) {
                    signupLink.addEventListener('click', function(e) {
                        console.log('Signup link clicked:', this.href, e);
                    });
                    signupLink.addEventListener('mousedown', function(e) {
                        console.log('Signup link mousedown:', this.href, e);
                    });
                }
                
                if (signinLink) {
                    signinLink.addEventListener('click', function(e) {
                        console.log('Signin link clicked:', this.href, e);
                    });
                    signinLink.addEventListener('mousedown', function(e) {
                        console.log('Signin link mousedown:', this.href, e);
                    });
                }
                
                // Test button clickability
                setTimeout(() => {
                    const loginBtn = document.getElementById('login-submit-button');
                    const registerBtn = document.getElementById('register-submit-button');
                    
                    if (loginBtn) {
                        console.log('Login button found:', loginBtn);
                        console.log('Login button disabled:', loginBtn.disabled);
                        console.log('Login button style:', window.getComputedStyle(loginBtn).pointerEvents);
                    }
                    
                    if (registerBtn) {
                        console.log('Register button found:', registerBtn);
                        console.log('Register button disabled:', registerBtn.disabled);
                        console.log('Register button style:', window.getComputedStyle(registerBtn).pointerEvents);
                    }
                    
                    // Test link clickability
                    if (signupLink) {
                        console.log('Signup link found:', signupLink);
                        console.log('Signup link href:', signupLink.href);
                        console.log('Signup link style:', window.getComputedStyle(signupLink).pointerEvents);
                    }
                    
                    if (signinLink) {
                        console.log('Signin link found:', signinLink);
                        console.log('Signin link href:', signinLink.href);
                        console.log('Signin link style:', window.getComputedStyle(signinLink).pointerEvents);
                    }
                }, 1000);
            });
            
            // Handle Google Sign-In
            function handleGoogleSignIn(response) {
                if (!response || !response.credential) {
                    console.error('Invalid Google Sign-In response');
                    return;
                }

                fetch('{{ route("auth.google.callback") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({
                        id_token: response.credential
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        alert('Google authentication failed: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Google authentication error:', error);
                    alert('Google authentication error. Please try again.');
                });
            }
            
            // Handle Twitter Sign-In
            function handleTwitterSignIn() {
                if (!TWITTER_CLIENT_ID || TWITTER_CLIENT_ID === '' || TWITTER_CLIENT_ID === 'your_actual_twitter_api_key_here') {
                    alert('Twitter OAuth not configured. Please set TWITTER_CLIENT_ID in your .env file.');
                    return;
                }
                
                // Generate PKCE parameters
                const codeVerifier = 'challenge'; // For simplicity, using 'challenge' as both challenge and verifier
                const codeChallenge = 'challenge';
                
                // Redirect to Twitter OAuth (not popup)
                const twitterAuthUrl = 'https://twitter.com/i/oauth2/authorize?' + new URLSearchParams({
                    response_type: 'code',
                    client_id: TWITTER_CLIENT_ID,
                    redirect_uri: APP_URL + '/auth/twitter/callback',
                    scope: 'tweet.read users.read',
                    state: 'twitter_oauth_state',
                    code_challenge: codeChallenge,
                    code_challenge_method: 'plain'
                });
                
                window.location.href = twitterAuthUrl;
            }
        </script>
    </head>
    <body class="font-sans antialiased matchday-shell matchday-guest">
        <div class="matchday-auth-stage min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div class="matchday-auth-brand mb-6">
                <a href="/" class="matchday-wordmark">
                    <span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span>
                </a>
            </div>

            <div class="matchday-auth-card w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
