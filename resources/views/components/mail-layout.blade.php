@props(['subject' => 'Mnemon', 'pretitle' => null, 'colophonLabel' => 'dispatch · vol. i'])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>{{ $subject }}</title>
    <!--[if mso]>
    <style type="text/css">body, table, td { font-family: Georgia, serif !important; }</style>
    <![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#f5f1e8; font-family: 'EB Garamond', Georgia, serif; color:#2d271e; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f1e8;">
        <tr>
            <td align="center" style="padding: 32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#f7f3ea; border:1px solid #d8d0bc;">

                    {{-- Colophon --}}
                    <tr>
                        <td style="padding: 22px 32px 18px; border-bottom: 1px solid #d8d0bc;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-family: Georgia, 'EB Garamond', serif; font-size: 22px; font-weight: 600; color:#2d271e; letter-spacing: 0.005em;">
                                        <span style="display:inline-block; width:14px; height:14px; transform: rotate(45deg); border:2px solid #2d271e; vertical-align: middle; position: relative; margin-right: 12px;">
                                            <span style="display:block; position:absolute; top:50%; left:50%; width:6px; height:6px; background:#b34f30; transform: translate(-50%, -50%) rotate(45deg);"></span>
                                        </span>
                                        Mnemon
                                    </td>
                                    <td align="right" style="font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase; color:#6b6452;">
                                        {{ $colophonLabel }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($pretitle)
                        <tr>
                            <td style="padding: 28px 32px 0; font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color:#6b6452;">
                                — {{ $pretitle }} —
                            </td>
                        </tr>
                    @endif

                    {{-- Body slot --}}
                    <tr>
                        <td style="padding: 24px 32px 8px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Ornament --}}
                    <tr>
                        <td align="center" style="padding: 16px 32px 28px; font-family: Georgia, serif; font-size: 18px; letter-spacing: 0.5em; color:#b34f30;">
                            ❦ · ❦ · ❦
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding: 18px 32px; border-top: 1px solid #d8d0bc; background-color:#efe9da;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color:#6b6452; line-height: 1.6;">
                                        MIT licensed<br/>
                                        Self-hosted · No telemetry · No vendor
                                    </td>
                                    <td align="right" style="font-family: Georgia, serif; font-style: italic; font-size: 12px; color:#6b6452; line-height: 1.5;">
                                        "Memoria est thesaurus<br/>omnium rerum et custos."
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; margin-top: 16px;">
                    <tr>
                        <td align="center" style="font-family: 'Courier New', monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color:#a09679;">
                            Treatise No. 001 · A.M. MMXXVI · plate dispatched {{ now()->format('Y-m-d') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
