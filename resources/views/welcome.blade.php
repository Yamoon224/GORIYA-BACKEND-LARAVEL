<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Goriya') }} — API Backend</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" href="/images/favicon.png">
    <link rel="apple-touch-icon" href="/images/apple-icon.png">
    <style>
        :root {
            --brand: #0f6e5c;
            --brand-dark: #0b5346;
            --bg: #f4f7f6;
            --surface: #ffffff;
            --text: #1b1f24;
            --muted: #5b6670;
            --border: #e2e8e5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1412;
                --surface: #161d1a;
                --text: #edf2f0;
                --muted: #9aa8a2;
                --border: #263229;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 620px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 48px 40px;
            text-align: center;
            box-shadow: 0 20px 60px -30px rgba(15, 110, 92, 0.35);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.04em;
            color: var(--brand);
            background: rgba(15, 110, 92, 0.1);
            border-radius: 999px;
            padding: 5px 14px;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }
        .logo-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            background: #ffffff;
            border-radius: 16px;
            padding: 14px 24px;
            margin: 0 auto 22px auto;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
        }
        .logo-wrap img {
            height: 34px;
            width: auto;
            display: block;
        }
        .kicker {
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0 0 8px 0;
        }
        p.tagline {
            margin: 0 0 28px 0;
            font-size: 15.5px;
            color: var(--muted);
            line-height: 1.55;
        }
        .cta {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: #fff;
            text-decoration: none;
            font-size: 17px;
            font-weight: 600;
            padding: 16px 34px;
            border-radius: 14px;
            box-shadow: 0 12px 28px -10px rgba(15, 110, 92, 0.55);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 32px -10px rgba(15, 110, 92, 0.65);
        }
        .cta svg { flex-shrink: 0; }
        .meta {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: center;
            gap: 22px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--muted);
        }
        .meta strong { color: var(--text); font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge"><span class="dot"></span>API en ligne</span>

        <div class="logo-wrap">
            <img src="/images/goriya-logo.png" alt="Goriya">
        </div>

        <p class="kicker">Backend API</p>
        <p class="tagline">
            Le moteur API qui alimente la plateforme digitale de recrutement Goriya —
            application candidats, backoffice administrateur et espace entreprise.
        </p>

        <a class="cta" href="{{ route('l5-swagger.default.api') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Consulter la documentation API
        </a>

        <div class="meta">
            <span>Framework&nbsp;<strong>Laravel {{ app()->version() }}</strong></span>
            <span>Environnement&nbsp;<strong>{{ app()->environment() }}</strong></span>
            <span>Spéc.&nbsp;<strong>OpenAPI / Swagger</strong></span>
        </div>
    </div>
</body>
</html>
