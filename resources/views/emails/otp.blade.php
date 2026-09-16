<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
{{--
    Même gabarit "pleine couleur" que emails/welcome.blade.php (tables +
    styles inline pour Outlook/Gmail, dégradé --brand-gradient du site avec
    repli en aplat #1e7df2 pour Outlook desktop qui ignore `background`,
    logo centré en en-tête).
--}}
<body style="margin:0;padding:0;background-color:#1e7df2;background:linear-gradient(135deg,#2b7fff 0%,#1e7df2 45%,#0b2ca8 100%);font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1e7df2" style="background-color:#1e7df2;background:linear-gradient(135deg,#2b7fff 0%,#1e7df2 45%,#0b2ca8 100%);">
        <tr>
            <td align="center" style="padding:32px 16px 40px;">

                {{-- Logo --}}
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:480px;">
                    <tr>
                        <td align="center" style="padding:0 0 28px;">
                            <img src="{{ $logoUrl }}" alt="Goriya" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;">
                        </td>
                    </tr>
                </table>

                {{-- Carte --}}
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:480px;background-color:#ffffff;border-radius:6px;">
                    <tr>
                        <td style="padding:36px 32px 32px;">
                            <p style="font-size:16px;color:#1b2331;margin:0 0 16px;">Bonjour {{ $name }},</p>
                            <p style="font-size:14px;color:#4b5563;margin:0 0 24px;">
                                {{ $intro }}
                            </p>
                            <div style="text-align:center;margin:0 0 24px;">
                                <span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:8px;color:#1e7df2;background-color:#eef2ff;padding:16px 24px;border-radius:8px;">
                                    {{ $code }}
                                </span>
                            </div>
                            <p style="font-size:13px;color:#7a8495;margin:0;">
                                {{ $footer }}
                            </p>
                        </td>
                    </tr>
                </table>

                {{-- Pied de page — même largeur que la carte pour rester aligné dessous --}}
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:480px;">
                    <tr>
                        <td align="center" style="padding:24px 16px 0;font-size:12px;line-height:1.8;color:#ffffff;">
                            <a href="mailto:{{ $contactEmail }}" style="color:#ffffff;text-decoration:none;">{{ $contactEmail }}</a>
                            &nbsp;&middot;&nbsp;
                            <a href="tel:{{ str_replace(' ', '', $contactPhone) }}" style="color:#ffffff;text-decoration:none;">{{ $contactPhone }}</a>
                            <br>
                            Candidat ou Chercheur d'emploi : <a href="{{ $candidateUrl }}" style="color:#ffffff;text-decoration:underline;">goriya.net</a>
                            &nbsp;&middot;&nbsp;
                            Entreprise ou Business : <a href="{{ $enterpriseUrl }}" style="color:#ffffff;text-decoration:underline;">entreprise.goriya.net</a>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
