<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OtpCode;
use App\Models\AppareilConfiance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'identifiant' => 'required|string',
            'mot_de_passe' => 'required|string',
        ]);

        $user = User::where('email', $request->identifiant)
            ->orWhere('telephone', $request->identifiant)
            ->first();

        if (!$user || !Hash::check($request->mot_de_passe, $user->password)) {
            throw ValidationException::withMessages([
                'identifiant' => ['Identifiants incorrects.'],
            ]);
        }

        if ($user->statut !== 'actif') {
            throw ValidationException::withMessages([
                'identifiant' => ['Ce compte est suspendu.'],
            ]);
        }

        $tokenAppareil = $request->cookie('device_token') ?? $request->header('X-Device-Token');

        if ($tokenAppareil) {
            $appareil = AppareilConfiance::where('user_id', $user->id)
                ->where('token_appareil', $tokenAppareil)
                ->where('date_expiration', '>', now())
                ->first();

            if ($appareil) {
                return response()->json([
                    'message' => 'Connexion reussie',
                    'user' => $user,
                    'otp_requis' => false,
                ]);
            }
        }

        $code = $this->genererOtp($user, 'connexion');

        return response()->json([
            'message' => 'Code de verification envoye',
            'otp_requis' => true,
            'identifiant' => $request->identifiant,
            'code_dev_uniquement' => $code,
        ]);
    }

    public function verifierOtp(Request $request)
    {
        $request->validate([
            'identifiant' => 'required|string',
            'code' => 'required|string|size:6',
            'faire_confiance_appareil' => 'boolean',
        ]);

        $user = User::where('email', $request->identifiant)
            ->orWhere('telephone', $request->identifiant)
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'identifiant' => ['Utilisateur introuvable.'],
            ]);
        }

        $otp = OtpCode::where('user_id', $user->id)
            ->where('code', $request->code)
            ->where('type', 'connexion')
            ->where('utilise', false)
            ->where('expire_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expire.'],
            ]);
        }

        $otp->update(['utilise' => true]);

        $reponse = [
            'message' => 'Connexion reussie',
            'user' => $user,
        ];

        if ($request->boolean('faire_confiance_appareil')) {
            $tokenAppareil = Str::random(64);

            AppareilConfiance::create([
                'user_id' => $user->id,
                'token_appareil' => $tokenAppareil,
                'nom_appareil' => $request->header('User-Agent'),
                'date_expiration' => now()->addDays(30),
            ]);

            $reponse['device_token'] = $tokenAppareil;
        }

        return response()->json($reponse);
    }

    public function renvoyerOtp(Request $request)
    {
        $request->validate(['identifiant' => 'required|string']);

        $user = User::where('email', $request->identifiant)
            ->orWhere('telephone', $request->identifiant)
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'identifiant' => ['Utilisateur introuvable.'],
            ]);
        }

        $code = $this->genererOtp($user, 'connexion');

        return response()->json([
            'message' => 'Nouveau code envoye',
            'code_dev_uniquement' => $code,
        ]);
    }

    public function motDePasseOublie(Request $request)
    {
        $request->validate(['identifiant' => 'required|string']);

        $user = User::where('email', $request->identifiant)
            ->orWhere('telephone', $request->identifiant)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Si ce compte existe, un code a ete envoye.',
            ]);
        }

        $code = $this->genererOtp($user, 'mot_de_passe_oublie');

        return response()->json([
            'message' => 'Code de reinitialisation envoye',
            'code_dev_uniquement' => $code,
        ]);
    }

    public function reinitialiserMotDePasse(Request $request)
    {
        $request->validate([
            'identifiant' => 'required|string',
            'code' => 'required|string|size:6',
            'nouveau_mot_de_passe' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->identifiant)
            ->orWhere('telephone', $request->identifiant)
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'identifiant' => ['Utilisateur introuvable.'],
            ]);
        }

        $otp = OtpCode::where('user_id', $user->id)
            ->where('code', $request->code)
            ->where('type', 'mot_de_passe_oublie')
            ->where('utilise', false)
            ->where('expire_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expire.'],
            ]);
        }

        $otp->update(['utilise' => true]);

        $user->update([
            'password' => Hash::make($request->nouveau_mot_de_passe),
        ]);

        return response()->json([
            'message' => 'Mot de passe reinitialise avec succes',
        ]);
    }

    private function genererOtp(User $user, string $type): string
    {
        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'type' => $type,
            'expire_at' => now()->addMinutes(5),
            'utilise' => false,
        ]);

        return $code;
    }
}
