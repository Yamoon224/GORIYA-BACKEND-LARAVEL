<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\UserResource;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Mirroir de backend/src/auth/auth.service.ts. Extrait de AuthController
 * pour cohérence avec le reste du port (contrôleurs fins, logique métier
 * dans un Service).
 */
class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AuditLogService $auditLogService,
        private readonly OtpService $otpService,
        private readonly GoogleTokenVerifier $googleTokenVerifier,
        private readonly LinkedInOAuthClient $linkedInOAuthClient,
        private readonly WelcomeEmailService $welcomeEmailService,
    ) {}

    /**
     * @return array{access_token: string, user: UserResource}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmailWithPassword($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLogService->log('login_failed', null, [], ['email' => $email]);
            abort(401, 'Invalid credentials');
        }

        if ($user->status === UserStatus::INACTIVE) {
            $this->auditLogService->log('login_failed', $user, [], ['email' => $email, 'reason' => 'inactive'], actor: $user);
            abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
        }

        // Tant que l'email n'est pas vérifié via OTP, la connexion d'un compte
        // candidat (USER) est refusée. Les comptes antérieurs à l'OTP ont été
        // rétro-marqués vérifiés par la migration. On renvoie un code frais pour
        // que l'utilisateur puisse finaliser sa vérification immédiatement.
        if ($user->role === UserRole::USER && $user->email_verified_at === null) {
            try {
                $this->otpService->send($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Renvoi OTP au login non vérifié échoué", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $this->auditLogService->log('login_failed', $user, [], ['email' => $email, 'reason' => 'email_not_verified'], actor: $user);
            abort(403, 'EMAIL_NOT_VERIFIED');
        }

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('login', $fullUser, actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser)];
    }

    /**
     * @return array{message: string}
     */
    public function logout(?string $authHeader): array
    {
        if (! $authHeader) {
            return ['message' => 'No token provided'];
        }

        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $user = JWTAuth::setToken($token)->authenticate() ?: null;
            JWTAuth::setToken($token)->invalidate();
            $this->auditLogService->log('logout', $user, actor: $user);
        } catch (JWTException) {
            // Token déjà invalide/expiré : on considère la déconnexion réussie quand même.
        }

        return ['message' => 'Logged out successfully'];
    }

    /**
     * @return array{id: mixed, email: mixed, role: mixed}
     */
    public function profile(): array
    {
        $payload = auth('api')->payload();

        return [
            'id' => $payload->get('sub'),
            'email' => $payload->get('email'),
            'role' => $payload->get('role'),
        ];
    }

    public function refresh(): string
    {
        // Pas de middleware auth:api sur la route : on tolère un token expiré
        // tant qu'il reste dans la fenêtre refresh_ttl (rotation de session
        // NextAuth côté front). Au-delà, ou token absent/corrompu → 401.
        try {
            return auth('api')->refresh();
        } catch (JWTException) {
            abort(401, 'Session expirée. Veuillez vous reconnecter.');
        }
    }

    /**
     * @return array{message: string}
     */
    public function requestOtp(string $email, string $purpose = 'EMAIL_VERIFICATION'): array
    {
        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            abort(404, 'Utilisateur introuvable');
        }

        $this->otpService->send($user, $purpose);

        return ['message' => 'Code envoyé par email'];
    }

    /**
     * @return array{access_token: string, user: UserResource}
     */
    public function verifyOtp(string $email, string $code, string $purpose = 'EMAIL_VERIFICATION'): array
    {
        $user = $this->otpService->verify($email, $code, $purpose);

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('login', $fullUser, actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser)];
    }

    /**
     * Démarre une réinitialisation de mot de passe : envoi d'un code OTP
     * dédié (purpose PASSWORD_RESET, distinct de la vérification d'email —
     * un code de vérification ne doit pas permettre de changer un mot de
     * passe, et réciproquement).
     *
     * La réponse est volontairement identique que le compte existe ou non :
     * cet endpoint est public, et un message différencié en ferait un
     * oracle d'énumération d'adresses. Même raison pour le cooldown 429 de
     * OtpService, avalé ici.
     *
     * @return array{message: string}
     */
    public function forgotPassword(string $email): array
    {
        $genericResponse = ['message' => "Si un compte existe pour cette adresse, un code de réinitialisation vient d'être envoyé."];

        $user = $this->userRepository->findByEmail($email);

        // Compte bloqué : la réinitialisation ne doit pas servir à contourner
        // la désactivation décidée par un administrateur.
        if (! $user || $user->status === UserStatus::INACTIVE) {
            return $genericResponse;
        }

        try {
            $this->otpService->send($user, OtpService::PURPOSE_PASSWORD_RESET);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Envoi du code de réinitialisation échoué', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->auditLogService->log('password_reset_requested', $user, [], ['email' => $user->email], actor: $user);

        return $genericResponse;
    }

    /**
     * Finalise la réinitialisation : le code OTP tient lieu de preuve de
     * possession de la boîte mail, donc l'ancien mot de passe n'est pas
     * demandé.
     *
     * @return array{message: string}
     */
    public function resetPassword(string $email, string $code, string $password): array
    {
        $user = $this->otpService->verify($email, $code, OtpService::PURPOSE_PASSWORD_RESET);

        if ($user->status === UserStatus::INACTIVE) {
            abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
        }

        // Le cast 'hashed' du modèle chiffre la valeur ; forceFill car
        // 'password' est masqué des écritures de masse habituelles.
        $user->forceFill(['password' => $password])->save();

        // Franchir l'OTP prouve l'accès à la boîte mail : un compte resté non
        // vérifié le devient ici, sinon il resterait bloqué au login (403
        // EMAIL_NOT_VERIFIED) juste après avoir choisi son mot de passe.
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Tout autre code de réinitialisation encore valide devient caduc.
        $this->otpService->invalidatePending($user, OtpService::PURPOSE_PASSWORD_RESET);

        $this->auditLogService->log('password_reset', $user, [], ['email' => $user->email], actor: $user);

        return ['message' => 'Mot de passe réinitialisé. Tu peux maintenant te connecter.'];
    }

    /**
     * @param  array{credential: string, allowSignup?: bool}  $data
     * @return array{access_token: string, user: UserResource, isNewUser: bool}
     */
    public function googleAuth(array $data): array
    {
        $profile = $this->googleTokenVerifier->verify(
            $data['credential'],
            config('services.google.client_ids', []),
        );

        return $this->authenticateWithProvider($profile, 'google', ($data['allowSignup'] ?? true) !== false);
    }

    /**
     * @param  array{code: string, redirectUri: string, allowSignup?: bool}  $data
     * @return array{access_token: string, user: UserResource, isNewUser: bool}
     */
    public function linkedinAuth(array $data): array
    {
        $profile = $this->linkedInOAuthClient->fetchProfile($data['code'], $data['redirectUri']);

        return $this->authenticateWithProvider($profile, 'linkedin', ($data['allowSignup'] ?? true) !== false);
    }

    /**
     * Ouvre une session à partir d'un profil déjà vérifié auprès du
     * fournisseur d'identité (Google, LinkedIn…). L'appelant est responsable
     * de la vérification : rien de ce qui arrive ici ne doit provenir
     * directement du navigateur.
     *
     * @param  array{sub: string, email: string, name: ?string, picture: ?string, given_name: ?string, family_name: ?string}  $profile
     * @return array{access_token: string, user: UserResource, isNewUser: bool}
     */
    private function authenticateWithProvider(array $profile, string $provider, bool $allowSignup): array
    {
        $existingUser = $this->userRepository->findByEmail($profile['email']);

        if ($existingUser) {
            if ($existingUser->status === UserStatus::INACTIVE) {
                abort(401, "Compte bloqué. Vous n'êtes pas autorisé à vous connecter. Veuillez contacter l'administrateur.");
            }

            $token = auth('api')->login($existingUser);
            $fullUser = $this->userRepository->findOrFail($existingUser->id);
            $fullUser->load('company');

            $this->auditLogService->log('login', $fullUser, [], ['provider' => $provider], actor: $fullUser);

            return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => false];
        }

        // Certains fronts (espace entreprise) n'autorisent la connexion via un
        // fournisseur externe que pour un compte déjà existant — la création
        // d'un compte entreprise passe par un autre parcours (choix d'offre,
        // création de la société).
        if (! $allowSignup) {
            abort(404, "Aucun compte n'est associé à cette adresse.");
        }

        $user = $this->userRepository->create([
            'name' => $profile['name'] ?: ($profile['given_name'] ?? explode('@', $profile['email'])[0]),
            'email' => $profile['email'],
            'password' => Str::uuid()->toString(),
            'role' => UserRole::USER,
            'status' => UserStatus::ACTIVE,
            'avatar' => $profile['picture'] ?? null,
        ]);

        // L'email est déjà vérifié par le fournisseur (claim email_verified
        // contrôlé par GoogleTokenVerifier / LinkedInOAuthClient) — pas d'OTP
        // à repasser.
        $user->forceFill(['email_verified_at' => now()])->save();

        // Inscription aboutie dès la création ici : pas d'étape OTP où
        // accrocher l'email de bienvenue, on l'envoie donc directement.
        $this->welcomeEmailService->send($user);

        $token = auth('api')->login($user);
        $fullUser = $this->userRepository->findOrFail($user->id);
        $fullUser->load('company');

        $this->auditLogService->log('register', $fullUser, [], ['provider' => $provider], actor: $fullUser);

        return ['access_token' => $token, 'user' => new UserResource($fullUser), 'isNewUser' => true];
    }
}
