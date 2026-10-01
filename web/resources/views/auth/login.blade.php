<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">

    <title>Staff Sign In · AssocMap</title>

   @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])
</head>
<body class="public-page staff-login-page">
    <main class="staff-login-main" aria-labelledby="staff-login-title">
        <div class="staff-login-wrap">
            <div class="staff-login-brand">
                <a href="{{ route('home') }}" class="public-brand" aria-label="AssocMap home (placeholder)">
                    <span class="public-brand-mark"><x-icon name="map" /></span>
                    <span><strong>Assoc<span>Map</span></strong><small>SAAD Phase II · Region VII</small></span>
                </a>
            </div>
            <section class="staff-login-card" aria-labelledby="staff-login-title">
                <h1 id="staff-login-title">Staff Sign In</h1>
                <p class="staff-login-intro">Staff access for System Administrators and Field Officers.</p>

                <form onsubmit="return false;" aria-describedby="staff-login-note">
                    <div class="staff-login-field">
                        <label for="staff-email">Email</label>
                        <input id="staff-email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Enter your email" required>
                    </div>
                    <div class="staff-login-field">
                        <label for="staff-password">Password</label>
                        <div class="staff-password-control">
                            <input id="staff-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                            <button id="staff-password-toggle" type="button" class="staff-password-toggle" aria-controls="staff-password" aria-label="Show password" hidden>
                                <x-icon name="eye" /><span>Show</span>
                            </button>
                        </div>
                    </div>
                    <div class="staff-login-options">
                        <label class="staff-remember" for="staff-remember"><input id="staff-remember" name="remember" type="checkbox"> Remember me</label>
                        <a href="#">Forgot Password?</a>
                    </div>
                    <button type="button" class="public-button public-button-primary staff-sign-in" aria-describedby="staff-login-note">Sign In <x-icon name="arrow" /></button>
                    <p id="staff-login-note" class="staff-login-note">Sign-in and password recovery are not connected yet.</p>
                </form>

                <a href="{{ route('home') }}" class="staff-login-back"><x-icon name="back" /> Back to AssocMap</a>
            </section>
            <p class="staff-login-access"><x-icon name="shield" /> Authorized staff access only.</p>
        </div>
    </main>
</body>
</html>
