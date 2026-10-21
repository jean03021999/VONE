# Prépare le paquet d'installation LAKOLI pour une école (à lancer sur le poste de développement).
#
# Contenu du paquet (dossier + archive .zip dans C:\projets\paquets) :
#   lakoli\     backend Laravel (dernier commit, dépendances de production)
#               + application web compilée dans lakoli\public (API sur la même adresse : /api)
#   php\        PHP officiel pour Windows (windows.php.net, NTS x64) : licence PHP, gratuite
#   caddy\      serveur web Caddy (licence Apache 2.0, gratuite)
#   outils\     WinSW (services Windows, licence MIT) et Microsoft Visual C++ Redistributable
#               (redistribuable gratuitement, nécessaire à PHP)
#   installer.ps1, sauvegarde.ps1, restaurer.ps1, verifier.ps1, desinstaller-lakoli.ps1,
#   reinitialiser-mot-de-passe-postgres.ps1, GUIDE-INSTALLATION.md
#
# Seuls les fichiers COMMITÉS du backend sont pris (git archive) : jamais le .env ni les données locales.
# Les logiciels sont téléchargés une fois dans $Outils, puis vérifiés à chaque paquet (empreinte
# publiée par l'éditeur, signature Microsoft pour Visual C++). Pour changer de version : modifier
# $logiciels ci-dessous (adresse et empreinte ensemble).
#
# Utilisation (PowerShell) :  powershell -ExecutionPolicy Bypass -File deploiement\preparer-paquet.ps1

param(
    [string]$Backend = "C:\projets\lakoli",
    [string]$Frontend = "C:\projets\lakoli-web",
    [string]$Outils = "C:\projets\paquets\outils",
    [string]$Destination = "C:\projets\paquets"
)

$ErrorActionPreference = "Stop"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }

$logiciels = @(
    # https://windows.php.net/downloads/releases/sha256sum.txt
    @{ Fichier = "php-8.5.11-nts-Win32-vs17-x64.zip"; Url = "https://windows.php.net/downloads/releases/php-8.5.11-nts-Win32-vs17-x64.zip"
       Algorithme = "SHA256"; Empreinte = "0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043" },
    # https://github.com/caddyserver/caddy/releases/download/v2.11.7/caddy_2.11.7_checksums.txt
    @{ Fichier = "caddy_2.11.7_windows_amd64.zip"; Url = "https://github.com/caddyserver/caddy/releases/download/v2.11.7/caddy_2.11.7_windows_amd64.zip"
       Algorithme = "SHA512"; Empreinte = "c308154504e53755958ffc62d8a2657ab17eefd6dd25c45dbb2c3d93d29519971f77ca652f7d0f2bcb7f980b9ba3822478865e29969d0272d7613550a43152b1" },
    # Version 2.12.0 (stable) : https://github.com/winsw/winsw/releases/tag/v2.12.0 (empreinte relevée au téléchargement)
    @{ Fichier = "WinSW.NET461.exe"; Url = "https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW.NET461.exe"
       Algorithme = "SHA256"; Empreinte = "b5066b7bbdfba1293e5d15cda3caaea88fbeab35bd5b38c41c913d492aadfc4f" },
    # Mis à jour par Microsoft à la même adresse : vérifié par sa signature, pas par une empreinte.
    @{ Fichier = "vc_redist.x64.exe"; Url = "https://aka.ms/vs/17/release/vc_redist.x64.exe"; Signataire = "CN=Microsoft Corporation*" }
)

Etape "Logiciels (PHP, Caddy, WinSW, Visual C++) : téléchargement et vérification"
New-Item -ItemType Directory -Force $Outils | Out-Null
foreach ($l in $logiciels) {
    $chemin = Join-Path $Outils $l.Fichier
    if (-not (Test-Path $chemin)) {
        Write-Host "  téléchargement : $($l.Url)"
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest $l.Url -OutFile $chemin -UseBasicParsing
    }
    if ($l.Empreinte) {
        $calculee = (Get-FileHash $chemin -Algorithm $l.Algorithme).Hash
        if ($calculee -ne $l.Empreinte) { Remove-Item $chemin; throw "$($l.Fichier) : empreinte incorrecte (fichier supprimé, relancez pour le retélécharger)." }
    } else {
        $signature = Get-AuthenticodeSignature $chemin
        if ($signature.Status -ne "Valid" -or $signature.SignerCertificate.Subject -notlike $l.Signataire) { throw "$($l.Fichier) : signature invalide ($($signature.Status))." }
    }
    Write-Host "  vérifié : $($l.Fichier)"
}

$date = Get-Date -Format "yyyyMMdd-HHmm"
$sortie = Join-Path $Destination "lakoli-ecole-$date"
New-Item -ItemType Directory -Force $sortie | Out-Null

Etape "Backend : export du dernier commit"
$modifs = git -C $Backend status --porcelain
if ($modifs) { Write-Host "Attention : modifications non commitées dans le backend, elles ne seront PAS dans le paquet." -ForegroundColor Yellow }
$archive = Join-Path $env:TEMP "lakoli-backend-$date.zip"
git -C $Backend archive --format=zip -o $archive HEAD
Expand-Archive -Path $archive -DestinationPath (Join-Path $sortie "lakoli")
Remove-Item $archive

Etape "Backend : dépendances de production (composer)"
Push-Location (Join-Path $sortie "lakoli")
composer install --no-dev --optimize-autoloader --no-interaction --quiet
if ($LASTEXITCODE -ne 0) { throw "composer install a échoué." }
Pop-Location

Etape "Application web : compilation (API sur /api)"
$web = Join-Path $env:TEMP "lakoli-web-$date"
Push-Location $Frontend
$env:VITE_API_URL = "/api"
npx vite build --outDir $web --emptyOutDir
$code = $LASTEXITCODE
Remove-Item Env:\VITE_API_URL
Pop-Location
if ($code -ne 0) { throw "La compilation de l'application web a échoué." }
Copy-Item -Path (Join-Path $web "*") -Destination (Join-Path $sortie "lakoli\public") -Recurse -Force
Remove-Item $web -Recurse -Force

Etape "PHP, Caddy et outils"
Expand-Archive -Path (Join-Path $Outils $logiciels[0].Fichier) -DestinationPath (Join-Path $sortie "php")
Expand-Archive -Path (Join-Path $Outils $logiciels[1].Fichier) -DestinationPath (Join-Path $sortie "caddy")
New-Item -ItemType Directory -Force (Join-Path $sortie "outils") | Out-Null
foreach ($f in $logiciels[2].Fichier, $logiciels[3].Fichier) { Copy-Item (Join-Path $Outils $f) (Join-Path $sortie "outils") }

Etape "Scripts et guide"
foreach ($f in "installer.ps1", "sauvegarde.ps1", "restaurer.ps1", "verifier.ps1", "desinstaller-lakoli.ps1", "reinitialiser-mot-de-passe-postgres.ps1", "GUIDE-INSTALLATION.md") {
    Copy-Item (Join-Path $PSScriptRoot $f) $sortie
}

Etape "Archive .zip"
Compress-Archive -Path (Join-Path $sortie "*") -DestinationPath "$sortie.zip"

Write-Host ""
Write-Host "Paquet prêt : $sortie.zip" -ForegroundColor Green
Write-Host "Copiez-le sur une clé USB, puis suivez GUIDE-INSTALLATION.md sur le PC de l'école."
