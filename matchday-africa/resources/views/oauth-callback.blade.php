<!DOCTYPE html>
<html>
<head>
    <title>OAuth Callback</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-favicon />
</head>
<body>
    <script>
        // Get CSRF token from Laravel
        async function getCsrfToken() {
            try {
                const response = await fetch('/api/csrf-token');
                const data = await response.json();
                return data.csrf_token;
            } catch (error) {
                console.error('Failed to get CSRF token:', error);
                return '';
            }
        }
        
        // Extract the authorization code from the URL
        const urlParams = new URLSearchParams(window.location.search);
        const code = urlParams.get('code');
        const state = urlParams.get('state');
        const error = urlParams.get('error');
        
        // Debug logging
        console.log('OAuth Callback Debug:');
        console.log('Full URL:', window.location.href);
        console.log('Search params:', window.location.search);
        console.log('Code:', code);
        console.log('State:', state);
        console.log('Error:', error);
        
        if (error) {
            // Redirect back to login with error
            console.log('OAuth error:', error);
            window.location.href = '/login?error=' + encodeURIComponent(error);
        } else if (code) {
            // Get CSRF token and send the code to the Laravel backend
            getCsrfToken().then(csrfToken => {
                fetch('/auth/google/callback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        code: code,
                        state: state
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        window.location.href = '/login?error=' + encodeURIComponent(data.error || 'Authentication failed');
                    }
                })
                .catch(error => {
                    console.error('OAuth callback error:', error);
                    window.location.href = '/login?error=' + encodeURIComponent('Authentication failed');
                });
            });
        } else {
            // No code received - log all available information
            console.log('No authorization code received');
            console.log('All URL parameters:', Object.fromEntries(urlParams));
            console.log('Hash fragment:', window.location.hash);
            console.log('Full URL:', window.location.href);
            
            // Check if there's an error in the hash fragment
            const hashParams = new URLSearchParams(window.location.hash.substring(1));
            const hashError = hashParams.get('error');
            const hashCode = hashParams.get('code');
            
            if (hashError) {
                console.log('Error in hash:', hashError);
                window.location.href = '/login?error=' + encodeURIComponent('OAuth error: ' + hashError);
            } else if (hashCode) {
                console.log('Code found in hash:', hashCode);
                // Process the code from hash
                getCsrfToken().then(csrfToken => {
                    fetch('/auth/google/callback', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify({
                            code: hashCode,
                            state: state
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = data.redirect;
                        } else {
                            window.location.href = '/login?error=' + encodeURIComponent(data.error || 'Authentication failed');
                        }
                    })
                    .catch(error => {
                        console.error('OAuth callback error:', error);
                        window.location.href = '/login?error=' + encodeURIComponent('Authentication failed');
                    });
                });
            } else {
                window.location.href = '/login?error=' + encodeURIComponent('No authorization code received');
            }
        }
    </script>
</body>
</html>
