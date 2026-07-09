<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Votre code de vérification Goriya</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#2f6de6;padding:24px 32px;">
                            <span style="color:#ffffff;font-size:20px;font-weight:bold;">Goriya</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="font-size:16px;color:#1b2331;margin:0 0 16px;">Bonjour {{ $name }},</p>
                            <p style="font-size:14px;color:#4b5563;margin:0 0 24px;">
                                Voici ton code de vérification. Il expire dans {{ $validMinutes }} minutes.
                            </p>
                            <div style="text-align:center;margin:0 0 24px;">
                                <span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:8px;color:#2f6de6;background-color:#f0f4ff;padding:16px 24px;border-radius:8px;">
                                    {{ $code }}
                                </span>
                            </div>
                            <p style="font-size:13px;color:#7a8495;margin:0;">
                                Si tu n'es pas à l'origine de cette demande, tu peux ignorer cet email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
