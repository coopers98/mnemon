@php
    $subject = 'Mnemon · Correspondence from '.$submission['name'];
@endphp

<x-mail-layout :subject="$subject" pretitle="Frontispiece · correspondence" colophonLabel="dispatch · vol. i">

    {{-- Title --}}
    <h1 style="margin: 0 0 8px; font-family: Georgia, 'EB Garamond', serif; font-weight: 500; font-size: 32px; line-height: 1.05; letter-spacing: -0.02em; color:#2d271e;">
        New <em style="color:#b34f30; font-style: italic; font-weight: 400;">dispatch</em> arrived.
    </h1>
    <p style="margin: 0 0 24px; font-family: Georgia, serif; font-style: italic; font-size: 16px; line-height: 1.45; color:#6b6452;">
        Someone wrote in through the Mnemon frontispiece. The full message is below.
    </p>

    {{-- Byline --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top: 1px solid #b8ad94; border-bottom: 1px solid #b8ad94; margin-bottom: 24px;">
        <tr>
            <td style="padding: 12px 0; font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color:#6b6452;">
                <span style="color:#2d271e; font-weight: 600;">From</span> &nbsp; {{ $submission['name'] }}
                &nbsp;&nbsp;<span style="color:#a09679;">·</span>&nbsp;&nbsp;
                <span style="color:#2d271e; font-weight: 600;">Reply</span> &nbsp; {{ $submission['email'] }}
                &nbsp;&nbsp;<span style="color:#a09679;">·</span>&nbsp;&nbsp;
                <span style="color:#2d271e; font-weight: 600;">Sealed</span> &nbsp; {{ now()->format('Y-m-d H:i') }} UTC
            </td>
        </tr>
    </table>

    {{-- Caption --}}
    <p style="margin: 0 0 8px; font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase; color:#6b6452;">
        Message · verbatim
    </p>

    {{-- Pull-quote-style message body --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
        <tr>
            <td style="padding: 18px 22px; background-color:#efe9da; border-left: 3px solid #b34f30;">
                <div style="font-family: Georgia, 'EB Garamond', serif; font-size: 17px; line-height: 1.55; color:#2d271e; white-space: pre-wrap;">{{ $submission['message'] }}</div>
            </td>
        </tr>
    </table>

    {{-- Reply CTA --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px;">
        <tr>
            <td style="background-color:#2d271e; padding: 0;">
                <a href="mailto:{{ $submission['email'] }}?subject=Re%3A%20Mnemon%20correspondence"
                   style="display:inline-block; padding: 12px 22px; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 13px; font-weight: 500; letter-spacing: 0.02em; color:#f5f1e8; text-decoration: none;">
                    Reply to {{ $submission['name'] }} &nbsp;→
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 16px 0 0; font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color:#a09679;">
        Submitted via the contact form on the Mnemon frontispiece.
    </p>

</x-mail-layout>
