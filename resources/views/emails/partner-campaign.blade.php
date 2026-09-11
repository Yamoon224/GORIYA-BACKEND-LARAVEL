<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Goriya</title>
</head>
{{--
    Même gabarit "pleine couleur" que emails/welcome.blade.php (tables +
    styles inline pour Outlook/Gmail). Le corps ({{ $bodyHtml }}) est rédigé
    par l'admin dans le module Potentiels Partenaires et injecté tel quel ;
    seuls le header et le pied de page (désabonnement) sont fixes.
--}}
<body style="margin:0;padding:0;background-color:#1e7df2;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#1e7df2;">
        <tr>
            <td align="center" style="padding:32px 16px 40px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
                    <tr>
                        <td align="center" style="padding:0 0 28px;">
                            <img src="{{ $logoUrl }}" alt="Goriya" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;">
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:520px;background-color:#ffffff;border-radius:6px;">
                    <tr>
                        <td style="padding:36px 32px 28px;font-size:15px;line-height:1.6;color:#1b2331;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#8a94a6;">
                                Vous recevez cet email car votre entreprise a été identifiée comme partenaire potentiel de Goriya.
                                <a href="{{ $unsubscribeUrl }}" style="color:#8a94a6;text-decoration:underline;">Se désinscrire de ces communications</a>.
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
