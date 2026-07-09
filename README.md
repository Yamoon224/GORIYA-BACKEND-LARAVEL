# Goriya — Backend Laravel

API REST de **Goriya**, plateforme de recrutement pour le marché ivoirien : recherche d'emploi, gestion des candidatures, portfolios, et analyse de CV par IA.

Ce dépôt est un **portage Laravel** du backend historique NestJS de Goriya, avec parité fonctionnelle sur les modèles métier et les routes API (mêmes chemins, mêmes comportements — y compris certaines limitations connues, documentées dans le code).

## Stack technique

- **Laravel 12** / PHP 8.2+
- **MySQL** ou **PostgreSQL** (schéma agnostique, testé sur les deux)
- **JWT** (`tymon/jwt-auth`) pour l'authentification stateless
- **Swagger / OpenAPI** (`darkaonline/l5-swagger`) pour la documentation API interactive
- **Claude (Anthropic)** pour l'analyse de CV, le scoring, le matching et la simulation d'entretien
- `phpoffice/phpword` / `smalot/pdfparser` pour la génération et l'extraction de documents

## Fonctionnalités

- **Authentification** : email/mot de passe, Google OAuth, JWT (access + refresh)
- **Utilisateurs & entreprises** : candidats, comptes entreprise, gestion de profil
- **Offres d'emploi & candidatures** : publication, recherche filtrée/paginée, suivi de statut
- **Portfolios** candidats
- **IA** : analyse de CV, scoring, matching, simulation d'entretien
- **Abonnements** : plans candidats/entreprises, paiement Kkiapay
- **Back-office admin** : dashboard, analytics, planning, messagerie, recherche, paramètres
- **Documentation API interactive** (Swagger UI)

## Architecture

```text
Route  →  Controller  →  FormRequest (validation)  →  Service (logique métier)  →  Repository  →  Eloquent Model
```

- **Rôles** (`ADMIN` / `USER` / `ENTREPRISE`) appliqués par middleware (`role:ADMIN`) sur les routes qui l'exigent.
- **Propriété des ressources** : les entités rattachées à un utilisateur ou une entreprise (companies, job-offers, portfolios, candidatures) vérifient que l'appelant est bien le propriétaire (ou un admin) avant toute modification/suppression — voir `App\Http\Concerns\AuthorizesOwnership`.
- **Champs privilégiés** (`role`, `status`, `companyId` d'un utilisateur) non modifiables en self-service : seul un admin peut les changer — voir `UserService::update()`.

## Démarrage

```bash
composer install

cp .env.example .env
php artisan key:generate

# configurer DB_CONNECTION / DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
# ainsi que JWT_SECRET dans .env

php artisan migrate
php artisan db:seed        # jeu de données de démo (~500 enregistrements par entité, reliés entre eux)

php artisan serve
```

## Documentation API

Une fois le serveur lancé : [http://localhost:8000/api/documentation](http://localhost:8000/api/documentation)

## Tests

```bash
composer test
```

## Sécurité

- Authentification JWT stateless, tokens invalidés à la déconnexion.
- Autorisation par rôle (middleware `role:ADMIN`) sur les routes d'administration.
- Autorisation par propriété (`AuthorizesOwnership`) sur les ressources métier, pour empêcher qu'un utilisateur authentifié modifie ou supprime la ressource d'un tiers.
- Pas de mass-assignment de champs sensibles (`role`, `status`, `companyId`) hors contexte admin.

Toute vulnérabilité découverte peut être signalée directement via une issue privée ou par contact direct avec le mainteneur du dépôt.
