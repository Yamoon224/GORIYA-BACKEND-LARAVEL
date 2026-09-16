<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
{{--
    Gabarit "pleine couleur" : le fond reprend le dégradé bleu du Hero du site
    (--brand-gradient : #2b7fff -> #1e7df2 -> #0b2ca8) et la carte blanche
    porte le message. Tout est en tables + styles inline, seule mise en forme
    fiable dans Outlook/Gmail (pas de flex, pas de <style> externe, pas de
    classes). `background-color`/`bgcolor` restent en solide (#1e7df2, le
    ton médian du dégradé) pour les clients qui ignorent `background` en CSS
    (Outlook desktop) — le dégradé n'est qu'un rehaussement.
--}}
<body style="margin:0;padding:0;background-color:#1e7df2;background:linear-gradient(135deg,#2b7fff 0%,#1e7df2 45%,#0b2ca8 100%);font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1e7df2" style="background-color:#1e7df2;background:linear-gradient(135deg,#2b7fff 0%,#1e7df2 45%,#0b2ca8 100%);">
        <tr>
            <td align="center" style="padding:32px 16px 40px;">

                {{-- Logo --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
                    <tr>
                        <td align="center" style="padding:0 0 28px;">
                            <img src="{{ $logoUrl }}" alt="Goriya" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;">
                        </td>
                    </tr>
                </table>

                {{-- Carte --}}
                <table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:580px;background-color:#ffffff;border-radius:6px;">
                    <tr>
                        <td style="padding:0;font-size:0;line-height:0;">
                            <img src="{{ $headerImageUrl }}" alt="" width="580" style="display:block;width:100%;max-width:580px;height:auto;border:0;border-radius:6px 6px 0 0;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 32px 36px;">

                            <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:bold;color:#1b2331;">
                                {{ $title }}
                            </h1>

                            {{-- Pastille de statut (équivalent du badge "Généré à partir du CV") --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="background-color:#eef2ff;border-radius:12px;padding:5px 12px;font-size:12px;color:#0029a9;">
                                        &#10003; {{ $badge }}
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#1b2331;">
                                Bonne nouvelle, {{ $name }} !
                            </p>

                            @foreach ($paragraphs as $paragraph)
                                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563;">
                                    {{ $paragraph }}
                                </p>
                            @endforeach

                            <p style="margin:0 0 28px;font-size:13px;line-height:1.6;color:#7a8495;font-style:italic;">
                                {{ $tip }}
                            </p>

                            {{-- CTA : le fond est porté par la <td>, l'<a> ne fait que remplir
                                 la cellule — un <a> à fond coloré seul est ignoré par Outlook. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#0029a9" style="background-color:#0029a9;border-radius:6px;">
                                        <a href="{{ $ctaUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">
                                            {{ $ctaLabel }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                </table>

                {{-- Pied de page — même largeur que la carte pour rester aligné dessous --}}
                <table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:580px;">
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
                    <tr>
                        <td align="center" style="padding:16px 16px 0;font-size:12px;line-height:1.6;color:#ffffff;">
                            Pour en savoir plus sur la gestion de vos données personnelles,<br>
                            veuillez consulter notre
                            <a href="{{ $privacyUrl }}" target="_blank" rel="noopener" style="color:#ffffff;text-decoration:underline;">politique de confidentialité</a>.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
