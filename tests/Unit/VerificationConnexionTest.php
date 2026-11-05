<?php

namespace Tests\Unit;

use App\Services\VerificationConnexion;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerificationConnexionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Le poste de developpement (APP_ENV=local) passe sans OTP : on se place comme a l'ecole.
        $this->app['env'] = 'production';
        config(['app.url' => 'http://127.0.0.1:8080', 'lakoli.otp_actif' => false, 'lakoli.tailscale_actif' => false]);
    }

    private function requete(string $adresse, string $hote = '127.0.0.1:8080', array $serveur = []): Request
    {
        return Request::create('http://' . $hote . '/api/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => $adresse] + $serveur);
    }

    public function test_acces_local_sans_otp(): void
    {
        $this->assertFalse(VerificationConnexion::otpRequis($this->requete('127.0.0.1')));
    }

    public function test_tailscale_desactive_par_defaut_otp_exige(): void
    {
        $this->assertTrue(VerificationConnexion::otpRequis($this->requete('100.101.179.91', '100.107.132.74:8080')));
    }

    #[DataProvider('adressesTailscale')]
    public function test_tailscale_actif_sans_otp(string $adresse): void
    {
        config(['lakoli.tailscale_actif' => true]);
        $this->assertFalse(VerificationConnexion::otpRequis($this->requete($adresse, '100.107.132.74:8080')));
    }

    public static function adressesTailscale(): array
    {
        return [['100.64.0.0'], ['100.101.179.91'], ['100.127.255.255']];
    }

    #[DataProvider('adressesHorsTailscale')]
    public function test_tailscale_actif_autres_adresses_otp_exige(string $adresse): void
    {
        config(['lakoli.tailscale_actif' => true]);
        $this->assertTrue(VerificationConnexion::otpRequis($this->requete($adresse, '100.107.132.74:8080')));
    }

    public static function adressesHorsTailscale(): array
    {
        return [['100.63.255.255'], ['100.128.0.0'], ['192.168.1.104'], ['8.8.8.8'], ['fd7a:115c:a1e0::1'], ['::ffff:192.168.1.5']];
    }

    public function test_en_tete_x_forwarded_for_refuse(): void
    {
        config(['lakoli.tailscale_actif' => true]);
        // Passage par un relais : l'adresse vue n'est pas celle de l'appareil.
        $requete = $this->requete('100.101.179.91', '100.107.132.74:8080', ['HTTP_X_FORWARDED_FOR' => '8.8.8.8']);
        $this->assertTrue(VerificationConnexion::otpRequis($requete));
    }

    public function test_en_tete_x_forwarded_for_ne_fait_pas_passer_pour_tailscale(): void
    {
        config(['lakoli.tailscale_actif' => true]);
        $requete = $this->requete('8.8.8.8', '100.107.132.74:8080', ['HTTP_X_FORWARDED_FOR' => '100.101.179.91']);
        $this->assertTrue(VerificationConnexion::otpRequis($requete));
    }

    public function test_tailscale_actif_garde_le_comportement_local(): void
    {
        config(['lakoli.tailscale_actif' => true]);
        $this->assertFalse(VerificationConnexion::otpRequis($this->requete('127.0.0.1')));
        config(['lakoli.otp_actif' => true]);
        $this->assertTrue(VerificationConnexion::otpRequis($this->requete('127.0.0.1')));
    }
}
