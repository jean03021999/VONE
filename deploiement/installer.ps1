# Installation de LAKOLI sur le PC serveur d'une école (à lancer EN ADMINISTRATEUR, depuis le paquet).
#
# Prérequis (voir GUIDE-INSTALLATION.md) : Laragon installé dans C:\laragon, PostgreSQL installé
# (mot de passe du compte « postgres » connu).
#
# Ce script :
#   1. installe PHP 8.5 dans Laragon et l'application dans C:\laragon\www\lakoli ;
#   2. crée la base PostgreSQL et son utilisateur (mot de passe aléatoire, stocké dans .env) ;
#   3. configure l'application (production, débogage coupé), crée les tables, puis l'établissement
#      et le compte du fondateur ;
#   4. ouvre le port 8080 sur le réseau local (pare-feu) et crée le site Apache ;
#   5. programme une sauvegarde automatique chaque jour à 18 h et en fait une tout de suite.
#
# Mise à jour d'une installation existante (nouveau paquet) : ajouter -MiseAJour. Le .env, les
# fichiers déposés (logos, photos, justificatifs) et la base sont conservés ; une sauvegarde est
# faite avant.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File installer.ps1 [-MiseAJour]

param(
    [switch]$MiseAJour,
    [string]$Laragon = "C:\laragon",
    [int]$Port = 8080,
    [string]$Base = "lakoli_db",
    [string]$UtilisateurBase = "lakoli_user"
)

$ErrorActionPreference = "Stop"
$paquet = $PSScriptRoot
$app = Join-Path $Laragon "www\lakoli"
$versionPhp = "php-8.5.8-nts-x64"
$dossierPhp = Join-Path $Laragon "bin\php\$versionPhp"
$php = Join-Path $dossierPhp "php.exe"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }
function Artisan { & $php (Join-Path $app "artisan") @args; if ($LASTEXITCODE -ne 0) { Echec "php artisan $($args -join ' ') a échoué." } }

# --- Vérifications ---------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
if (-not (Test-Path (Join-Path $Laragon "laragon.exe"))) { Echec "Laragon introuvable dans $Laragon. Installez-le d'abord (voir le guide)." }

$pgBin = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\psql.exe" -ErrorAction SilentlyContinue |
    Sort-Object { [int]$_.Directory.Parent.Name } -Descending | Select-Object -First 1 | ForEach-Object { $_.DirectoryName }
if (-not $pgBin) { Echec "PostgreSQL introuvable dans C:\Program Files\PostgreSQL. Installez-le d'abord (voir le guide)." }
$psql = Join-Path $pgBin "psql.exe"

if ($MiseAJour) {
    if (-not (Test-Path (Join-Path $app ".env"))) { Echec "aucune installation trouvée dans $app : relancez sans -MiseAJour." }
} elseif (Test-Path (Join-Path $app ".env")) {
    Echec "LAKOLI est déjà installé dans $app. Pour installer une nouvelle version : -MiseAJour."
}

# --- 1. PHP et application -----------------------------------------------------------------
Etape "PHP 8.5"
if (-not (Test-Path $php)) {
    Copy-Item (Join-Path $paquet $versionPhp) $dossierPhp -Recurse
}
& $php -r "exit(extension_loaded('pdo_pgsql') ? 0 : 1);" 2>$null
if ($LASTEXITCODE -ne 0) {
    Echec "PHP ne démarre pas ou l'extension PostgreSQL manque. Installez « Microsoft Visual C++ Redistributable 2015-2022 (x64) » puis relancez."
}

if ($MiseAJour) {
    Etape "Sauvegarde avant mise à jour"
    & powershell -ExecutionPolicy Bypass -File (Join-Path $app "deploiement\sauvegarde.ps1")
    if ($LASTEXITCODE -ne 0) { Echec "la sauvegarde a échoué : mise à jour annulée." }
    Artisan down
}

Etape "Copie de l'application dans $app"
# /XD storage /XF .env : en mise à jour, on garde la configuration et les fichiers déposés.
robocopy (Join-Path $paquet "lakoli") $app /E /NFL /NDL /NJH /NJS /NP /XD (Join-Path $paquet "lakoli\storage") /XF .env | Out-Null
if ($LASTEXITCODE -ge 8) { Echec "la copie de l'application a échoué (robocopy $LASTEXITCODE)." }
if (-not $MiseAJour) {
    robocopy (Join-Path $paquet "lakoli\storage") (Join-Path $app "storage") /E /NFL /NDL /NJH /NJS /NP | Out-Null
}
foreach ($d in "storage\app\private", "storage\framework\cache\data", "storage\framework\sessions", "storage\framework\views", "storage\logs", "bootstrap\cache") {
    New-Item -ItemType Directory -Force (Join-Path $app $d) | Out-Null
}

# --- 2 et 3. Base et configuration (première installation) ----------------------------------
if (-not $MiseAJour) {
    Etape "Base de données PostgreSQL"
    $mdpPostgres = Read-Host "Mot de passe du compte « postgres » (choisi à l'installation de PostgreSQL)" -AsSecureString
    $env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($mdpPostgres))
    $alphabet = [char[]]"abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"
    $mdpBase = -join (1..32 | ForEach-Object { $alphabet | Get-Random })

    $roleExiste = & $psql -h 127.0.0.1 -U postgres -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$UtilisateurBase'"
    if ($LASTEXITCODE -ne 0) { Echec "connexion à PostgreSQL impossible : mot de passe « postgres » incorrect ?" }
    $ordre = if ($roleExiste -eq "1") { "ALTER" } else { "CREATE" }
    & $psql -h 127.0.0.1 -U postgres -d postgres -qc "$ordre ROLE $UtilisateurBase LOGIN PASSWORD '$mdpBase'" | Out-Null
    $baseExiste = & $psql -h 127.0.0.1 -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$Base'"
    if ($baseExiste -eq "1") { Echec "la base $Base existe déjà. Supprimez-la ou choisissez un autre nom (-Base)." }
    & $psql -h 127.0.0.1 -U postgres -d postgres -qc "CREATE DATABASE $Base OWNER $UtilisateurBase ENCODING 'UTF8' TEMPLATE template0" | Out-Null
    if ($LASTEXITCODE -ne 0) { Echec "création de la base impossible." }
    Remove-Item Env:\PGPASSWORD

    Etape "Configuration (.env)"
    $contenuEnv = @"
APP_NAME=LAKOLI
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:$Port
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=$Base
DB_USERNAME=$UtilisateurBase
DB_PASSWORD=$mdpBase
DB_PERSISTENT=true

SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=log
"@
    [IO.File]::WriteAllText((Join-Path $app ".env"), $contenuEnv, (New-Object Text.UTF8Encoding $false))
    Artisan key:generate --force
}

Etape "Tables de la base"
Artisan config:clear
Artisan migrate --force

if (-not $MiseAJour) {
    Etape "Établissement et compte du fondateur"
    & $php (Join-Path $app "artisan") lakoli:installer
    if ($LASTEXITCODE -ne 0) { Echec "création de l'établissement interrompue. Relancez : php artisan lakoli:installer (dans $app)." }
}

Etape "Optimisation"
Artisan config:cache
Artisan route:cache
Artisan view:cache
if ($MiseAJour) { Artisan up }

# --- 4. Site Apache et pare-feu ------------------------------------------------------------
Etape "Site Apache (port $Port) et pare-feu"
$racine = ($app -replace "\\", "/") + "/public"
$site = @"
# LAKOLI : application et API sur http://<adresse du PC>:$Port (installé par installer.ps1).
Listen $Port
<VirtualHost *:$Port>
    DocumentRoot "$racine"
    <Directory "$racine">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@
[IO.File]::WriteAllText((Join-Path $Laragon "etc\apache2\sites-enabled\lakoli-ecole.conf"), $site, (New-Object Text.UTF8Encoding $false))

if (-not (Get-NetFirewallRule -DisplayName "LAKOLI (port $Port)" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName "LAKOLI (port $Port)" -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow -Profile Private, Domain | Out-Null
}

# --- 5. Sauvegardes ------------------------------------------------------------------------
Etape "Sauvegarde automatique (tous les jours à 18 h)"
$script = Join-Path $app "deploiement\sauvegarde.ps1"
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$script`""
$declencheur = New-ScheduledTaskTrigger -Daily -At "18:00"
# StartWhenAvailable : si le PC était éteint à 18 h, la sauvegarde se fait au prochain démarrage.
$reglages = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName "LAKOLI - Sauvegarde" -Action $action -Trigger $declencheur -Settings $reglages -User "SYSTEM" -RunLevel Highest -Force | Out-Null

if (-not $MiseAJour) {
    & powershell -ExecutionPolicy Bypass -File $script
    if ($LASTEXITCODE -ne 0) { Write-Host "La première sauvegarde a échoué : vérifiez le journal dans C:\LAKOLI-Sauvegardes." -ForegroundColor Yellow }
}

# --- Fin ------------------------------------------------------------------------------------
$adresses = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" } | ForEach-Object { $_.IPAddress }

Write-Host ""
Write-Host "Installation terminée." -ForegroundColor Green
Write-Host ""
Write-Host "Dernières étapes dans Laragon :"
Write-Host "  1. Menu > PHP > Version : choisir $versionPhp"
Write-Host "  2. Cliquer « Arrêter » puis « Tout démarrer »"
Write-Host ""
Write-Host "Adresse sur ce PC        : http://127.0.0.1:$Port"
foreach ($ip in $adresses) { Write-Host "Adresse depuis les autres : http://${ip}:$Port" }
