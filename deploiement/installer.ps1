# Installation de LAKOLI sur UN SEUL POSTE d'une école (à lancer EN ADMINISTRATEUR, depuis le paquet).
#
# Prérequis (voir GUIDE-INSTALLATION.md) : Laragon installé dans C:\laragon, PostgreSQL installé
# (mot de passe du compte « postgres » connu).
#
# Ce script :
#   1. installe PHP 8.5 dans Laragon et l'application dans C:\laragon\www\lakoli ;
#   2. crée la base PostgreSQL et son utilisateur (mot de passe aléatoire, stocké dans .env) ;
#   3. configure l'application à partir de deploiement\.env.ecole (production, débogage coupé,
#      code de vérification désactivé car accès local uniquement), crée les tables, puis
#      l'établissement et le compte du fondateur ;
#   4. crée le site Apache, à l'écoute sur 127.0.0.1 seulement (aucun accès depuis le réseau) ;
#   5. programme une sauvegarde automatique chaque jour à 18 h et en fait une tout de suite.
#
# Mise à jour d'une installation existante (nouveau paquet) : ajouter -MiseAJour. Le .env, les
# fichiers déposés (logos, photos, justificatifs) et la base sont conservés ; une sauvegarde est
# faite avant.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File installer.ps1 [-MiseAJour]
#
# Essai sur un poste de développement (sans toucher à l'installation existante) : -Laragon et -App
# vers un dossier d'essai, -Base / -UtilisateurBase / -Port dédiés, -AdminBase (rôle PostgreSQL
# autorisé à créer bases et rôles), -DossierSauvegardes, -SansTachePlanifiee. Le mot de passe
# d'administration PostgreSQL peut être fourni par la variable LAKOLI_PG_ADMIN_MDP.
# -DossiersRaccourcis : dossiers où créer le raccourci LAKOLI (par défaut : Bureau commun et menu
# Démarrer commun, visibles par tous les comptes Windows du PC).

param(
    [switch]$MiseAJour,
    [string]$Laragon = "C:\laragon",
    [int]$Port = 8080,
    [string]$Base = "lakoli_db",
    [string]$UtilisateurBase = "lakoli_user",
    [string]$App = "",
    [string]$AdminBase = "postgres",
    [string]$DossierSauvegardes = "C:\LAKOLI-Sauvegardes",
    [switch]$SansTachePlanifiee,
    [string[]]$DossiersRaccourcis = @()
)

$ErrorActionPreference = "Stop"
$paquet = $PSScriptRoot
$app = if ($App) { $App } else { Join-Path $Laragon "www\lakoli" }
$versionPhp = "php-8.5.8-nts-x64"
$dossierPhp = Join-Path $Laragon "bin\php\$versionPhp"
$php = Join-Path $dossierPhp "php.exe"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }
function Artisan { & $php (Join-Path $app "artisan") @args; if ($LASTEXITCODE -ne 0) { Echec "php artisan $($args -join ' ') a échoué." } }

# --- Vérifications ---------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin -and -not $SansTachePlanifiee) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
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
    New-Item -ItemType Directory -Force (Split-Path $dossierPhp) | Out-Null
    Copy-Item (Join-Path $paquet $versionPhp) $dossierPhp -Recurse
}
& $php -r "exit(extension_loaded('pdo_pgsql') ? 0 : 1);" 2>$null
if ($LASTEXITCODE -ne 0) {
    Echec "PHP ne démarre pas ou l'extension PostgreSQL manque. Installez « Microsoft Visual C++ Redistributable 2015-2022 (x64) » puis relancez."
}

if ($MiseAJour) {
    Etape "Sauvegarde avant mise à jour"
    & powershell -ExecutionPolicy Bypass -File (Join-Path $app "deploiement\sauvegarde.ps1") -App $app -Destination $DossierSauvegardes
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
    if ($env:LAKOLI_PG_ADMIN_MDP) {
        $env:PGPASSWORD = $env:LAKOLI_PG_ADMIN_MDP
    } else {
        $mdpPostgres = Read-Host "Mot de passe du compte « $AdminBase » (choisi à l'installation de PostgreSQL)" -AsSecureString
        $env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($mdpPostgres))
    }
    $alphabet = [char[]]"abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789"
    $mdpBase = -join (1..32 | ForEach-Object { $alphabet | Get-Random })

    $roleExiste = & $psql -h 127.0.0.1 -U $AdminBase -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$UtilisateurBase'"
    if ($LASTEXITCODE -ne 0) { Echec "connexion à PostgreSQL impossible : mot de passe « $AdminBase » incorrect ?" }
    $ordre = if ($roleExiste -eq "1") { "ALTER" } else { "CREATE" }
    & $psql -h 127.0.0.1 -U $AdminBase -d postgres -qc "$ordre ROLE $UtilisateurBase LOGIN PASSWORD '$mdpBase'" | Out-Null
    $baseExiste = & $psql -h 127.0.0.1 -U $AdminBase -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$Base'"
    if ($baseExiste -eq "1") { Echec "la base $Base existe déjà. Supprimez-la ou choisissez un autre nom (-Base)." }
    & $psql -h 127.0.0.1 -U $AdminBase -d postgres -qc "CREATE DATABASE $Base OWNER $UtilisateurBase ENCODING 'UTF8' TEMPLATE template0" | Out-Null
    if ($LASTEXITCODE -ne 0) { Echec "création de la base impossible." }
    Remove-Item Env:\PGPASSWORD

    Etape "Configuration (.env, accès local uniquement)"
    $contenuEnv = [IO.File]::ReadAllText((Join-Path $app "deploiement\.env.ecole"))
    $contenuEnv = $contenuEnv.Replace("__PORT__", "$Port").Replace("__BASE__", $Base).Replace("__UTILISATEUR__", $UtilisateurBase).Replace("__MOT_DE_PASSE__", $mdpBase)
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

# --- 4. Site Apache (127.0.0.1 seulement) ---------------------------------------------------
Etape "Site Apache : http://127.0.0.1:$Port (accès depuis ce PC uniquement)"
$racine = ($app -replace "\\", "/") + "/public"
# Écoute sur 127.0.0.1 : aucun autre ordinateur ne peut joindre LAKOLI. Condition exigée par
# OTP_ACTIF=false (sinon le code de vérification est réactivé).
$site = @"
# LAKOLI : application et API sur http://127.0.0.1:$Port, ce PC uniquement (installé par installer.ps1).
Listen 127.0.0.1:$Port
<VirtualHost 127.0.0.1:$Port>
    DocumentRoot "$racine"
    <Directory "$racine">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@
$sites = Join-Path $Laragon "etc\apache2\sites-enabled"
New-Item -ItemType Directory -Force $sites | Out-Null
[IO.File]::WriteAllText((Join-Path $sites "lakoli-ecole.conf"), $site, (New-Object Text.UTF8Encoding $false))

# --- Raccourci « LAKOLI » pour les utilisateurs ---------------------------------------------
Etape "Raccourci LAKOLI (Bureau et menu Démarrer de tous les comptes)"
$adresse = "http://127.0.0.1:$Port"

# Icône Windows (.ico : 16, 32, 48 et 256 px, images PNG) tirée de l'icône de l'application.
$icone = Join-Path $app "public\icones\lakoli.ico"
try {
    Add-Type -AssemblyName System.Drawing
    $source = [Drawing.Image]::FromFile((Join-Path $app "public\icones\icone-512.png"))
    $images = foreach ($taille in 16, 32, 48, 256) {
        $bitmap = New-Object Drawing.Bitmap $taille, $taille
        $dessin = [Drawing.Graphics]::FromImage($bitmap)
        $dessin.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $dessin.DrawImage($source, 0, 0, $taille, $taille)
        $flux = New-Object IO.MemoryStream
        $bitmap.Save($flux, [Drawing.Imaging.ImageFormat]::Png)
        $dessin.Dispose(); $bitmap.Dispose()
        , @($taille, $flux.ToArray())
    }
    $source.Dispose()
    $ico = New-Object IO.BinaryWriter([IO.File]::Create($icone))
    $ico.Write([uint16]0); $ico.Write([uint16]1); $ico.Write([uint16]$images.Count)
    $decalage = 6 + 16 * $images.Count
    foreach ($img in $images) {
        $cote = if ($img[0] -ge 256) { 0 } else { $img[0] }
        $ico.Write([byte]$cote); $ico.Write([byte]$cote); $ico.Write([byte]0); $ico.Write([byte]0)
        $ico.Write([uint16]1); $ico.Write([uint16]32); $ico.Write([uint32]$img[1].Length); $ico.Write([uint32]$decalage)
        $decalage += $img[1].Length
    }
    foreach ($img in $images) { $ico.Write($img[1]) }
    $ico.Close()
} catch {
    Write-Host "Icône non créée ($($_.Exception.Message)) : le raccourci aura l'icône du navigateur." -ForegroundColor Yellow
    $icone = $null
}

# Fenêtre d'application (sans barre d'adresse) : Edge, présent sur tout Windows 10/11, sinon Chrome ;
# à défaut, lien ouvert dans le navigateur par défaut.
$navigateur = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

$dossiers = if ($DossiersRaccourcis.Count) { $DossiersRaccourcis } else {
    @([Environment]::GetFolderPath("CommonDesktopDirectory"), [Environment]::GetFolderPath("CommonPrograms"))
}
$shell = New-Object -ComObject WScript.Shell
foreach ($dossier in $dossiers) {
    try {
        New-Item -ItemType Directory -Force $dossier | Out-Null
        if ($navigateur) {
            $lien = $shell.CreateShortcut((Join-Path $dossier "LAKOLI.lnk"))
            $lien.TargetPath = $navigateur
            $lien.Arguments = "--app=$adresse"
            $lien.WorkingDirectory = Split-Path $navigateur
            $lien.Description = "LAKOLI - Gestion scolaire"
            if ($icone) { $lien.IconLocation = "$icone,0" }
            $lien.Save()
        } else {
            $url = "[InternetShortcut]`r`nURL=$adresse/`r`n" + $(if ($icone) { "IconFile=$icone`r`nIconIndex=0`r`n" } else { "" })
            [IO.File]::WriteAllText((Join-Path $dossier "LAKOLI.url"), $url, [Text.Encoding]::ASCII)
        }
    } catch {
        Write-Host "Raccourci non créé dans $dossier : $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

# --- 5. Sauvegardes ------------------------------------------------------------------------
$script = Join-Path $app "deploiement\sauvegarde.ps1"
if (-not $SansTachePlanifiee) {
    Etape "Sauvegarde automatique (tous les jours à 18 h)"
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$script`" -App `"$app`" -Destination `"$DossierSauvegardes`""
    $declencheur = New-ScheduledTaskTrigger -Daily -At "18:00"
    # StartWhenAvailable : si le PC était éteint à 18 h, la sauvegarde se fait au prochain démarrage.
    $reglages = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
    Register-ScheduledTask -TaskName "LAKOLI - Sauvegarde" -Action $action -Trigger $declencheur -Settings $reglages -User "SYSTEM" -RunLevel Highest -Force | Out-Null
}

if (-not $MiseAJour) {
    Etape "Première sauvegarde"
    & powershell -ExecutionPolicy Bypass -File $script -App $app -Destination $DossierSauvegardes
    if ($LASTEXITCODE -ne 0) { Write-Host "La première sauvegarde a échoué : vérifiez le journal dans $DossierSauvegardes." -ForegroundColor Yellow }
}

# --- Fin ------------------------------------------------------------------------------------
Write-Host ""
Write-Host "Installation terminée." -ForegroundColor Green
Write-Host ""
Write-Host "Dernières étapes dans Laragon :"
Write-Host "  1. Menu > PHP > Version : choisir $versionPhp"
Write-Host "  2. Cliquer « Arrêter » puis « Tout démarrer »"
Write-Host ""
Write-Host "Adresse (sur ce PC uniquement) : http://127.0.0.1:$Port"
Write-Host "Raccourci « LAKOLI » : sur le Bureau et dans le menu Démarrer de chaque compte Windows."
