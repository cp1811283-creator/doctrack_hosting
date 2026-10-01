@component('mail::message')
# Welcome to {{ config('app.name') }}

An account was created for you as **{{ $user->full_name }}** ({{ $user->displayRole() }}).

Before you can log in, please confirm this is really your email address:

@component('mail::button', ['url' => $verificationUrl])
Verify My Account
@endcomponent

This link expires in 60 minutes. If it expires before you use it, ask your administrator to resend it.

If you weren't expecting this, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
