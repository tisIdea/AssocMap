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
    <main id="main-content" tabindex="-1" class="staff-login-main" aria-labelledby="staff-login-title">
        <div class="staff-login-wrap">

            <div class="staff-login-brand">
                <a
                    href="{{ route('home') }}"
                    class="public-brand"
                    aria-label="AssocMap home"
                >
                    <span class="public-brand-mark">
                        <x-assocmap-mark />
                    </span>

                    <span>
                        <strong>
                            Assoc<span>Map</span>
                        </strong>
                        <small>Association and project monitoring</small>
                    </span>
                </a>
            </div>

            <section
                class="staff-login-card"
                aria-labelledby="staff-login-title"
            >
                <h1 id="staff-login-title">Staff Sign In</h1>

                <p class="staff-login-intro">
                    Sign in to access your authorized AssocMap workspace.
                </p>

                <form
                    method="POST"
                    action="{{ route('login.store') }}"
                    aria-describedby="staff-login-note"
                >
                    @csrf

                    <div class="staff-login-field">
                        <label for="staff-email">Email</label>

                        <input
                            id="staff-email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="Enter your email"
                            @if($errors->has('email')) aria-invalid="true" aria-describedby="staff-email-error" @endif
                            required
                        >
                        @error('email')<div id="staff-email-error" class="notice notice-error" role="alert">{{ $message }}</div>@enderror
                    </div>

                    <div class="staff-login-field">
                        <label for="staff-password">Password</label>

                        <div class="staff-password-control">
                            <input
                                id="staff-password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                @if($errors->has('password')) aria-invalid="true" aria-describedby="staff-password-error" @endif
                                required
                            >

                            <button
                                id="staff-password-toggle"
                                type="button"
                                class="staff-password-toggle"
                                aria-controls="staff-password"
                                aria-label="Show password"
                                hidden
                            >
                                <x-icon name="eye" />
                                <span>Show</span>
                            </button>
                        </div>
                        @error('password')<div id="staff-password-error" class="notice notice-error" role="alert">{{ $message }}</div>@enderror
                    </div>

                    <div class="staff-login-options">
                        <label
                            class="staff-remember"
                            for="staff-remember"
                        >
                            <input
                                id="staff-remember"
                                name="remember"
                                type="checkbox"
                            >
                            Remember me
                        </label>

                        <a href="{{ route('password.forgot') }}">Forgot password?</a>
                    </div>

                    <button
                        type="submit"
                        class="public-button public-button-primary staff-sign-in"
                        aria-describedby="staff-login-note"
                    >
                        Sign In
                        <x-icon name="arrow" />
                    </button>

                    <p
                        id="staff-login-note"
                        class="staff-login-note"
                    >
                        Authorized AssocMap accounts only.
                    </p>
                </form>

                <p class="staff-login-note"><a href="{{ route('invitation.restart') }}">Resume account activation</a></p>

                <a
                    href="{{ route('home') }}"
                    class="staff-login-back"
                >
                    <x-icon name="back" />
                    Back to AssocMap
                </a>
            </section>

            <p class="staff-login-access">
                <x-icon name="shield" />
                Authorized staff access only.
            </p>

        </div>
    </main>
</body>
</html>
