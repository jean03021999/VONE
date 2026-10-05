# Prépare le paquet d'installation LAKOLI pour une école (à lancer sur le poste de développement).
#
# Contenu du paquet (dossier + archive .zip dans C:\projets\paquets) :
#   lakoli\                 backend Laravel (dernier commit, dépendances de production)
#                           + application web compilée dans lakoli\public (API sur la même adresse : /api)
#   php-8.5.8-nts-x64\      PHP utilisé par Apache (Laragon ne fournit que PHP 8.3, insuffisant)
#   installer.ps1, sauvegarde.ps1, restaurer.ps1, GUIDE-INSTALLATION.md
#
# Seuls les fichiers COMMITÉS du backend sont pris (git archive) : jamais le .env ni les données locales.
#
# Utilisation (PowerShell) :  powershell -ExecutionPolicy Bypass -File deploiement\preparer-paquet.ps1

param(
    [string]$Backend = "C:\projets\lakoli",
    [string]$Frontend = "C:\projets\lakoli-web",
    [string]$Php = "C:\laragon\bin\php\php-8.5.8-nts-x64",
    [string]$Destination = "C:\projets\paquets"
)

$ErrorActionPreference = "Stop"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }

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

Etape "PHP 8.5 pour Apache"
Copy-Item -Path $Php -Destination (Join-Path $sortie (Split-Path $Php -Leaf)) -Recurse

Etape "Scripts et guide"
foreach ($f in "installer.ps1", "sauvegarde.ps1", "restaurer.ps1", "GUIDE-INSTALLATION.md") {
    Copy-Item (Join-Path $PSScriptRoot $f) $sortie
}

Etape "Archive .zip"
Compress-Archive -Path (Join-Path $sortie "*") -DestinationPath "$sortie.zip"

Write-Host ""
Write-Host "Paquet prêt : $sortie.zip" -ForegroundColor Green
Write-Host "Copiez-le sur une clé USB, puis suivez GUIDE-INSTALLATION.md sur le PC de l'école."
