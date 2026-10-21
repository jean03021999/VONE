# Installation de LAKOLI sur UN SEUL POSTE d'une école (à lancer EN ADMINISTRATEUR, depuis le paquet).
#
# Prérequis (voir GUIDE-INSTALLATION.md) : PostgreSQL installé (mot de passe du compte « postgres »
# connu). Tout le reste est dans le paquet, en logiciels gratuits même pour un usage commercial :
# PHP officiel (windows.php.net), Caddy (serveur web), WinSW (services Windows), Visual C++.
#
# Ce script :
#   1. installe Visual C++ (nécessaire à PHP), PHP, Caddy et l'application dans C:\LAKOLI ;
#   2. crée la base PostgreSQL et son utilisateur (mot de passe aléatoire, stocké dans .env) ;
#   3. configure l'application à partir de deploiement\.env.ecole (production, débogage coupé,
#      code de vérification désactivé car accès local uniquement), crée les tables, puis
#      l'établissement et le compte du fondateur ;
#   4. crée trois services Windows (serveur web + deux PHP), à l'écoute sur 127.0.0.1 seulement,
#      démarrés avec Windows avant même l'ouverture de session, sans aucune fenêtre ;
#   5. crée le raccourci « LAKOLI » et programme une sauvegarde chaque jour à 18 h.
#
# Mise à jour (nouveau paquet) : ajouter -MiseAJour. Le .env, les fichiers déposés (logos, photos,
# justificatifs) et la base sont conservés ; une sauvegarde est faite avant. Sur un PC installé avec
# Laragon (C:\laragon\www\lakoli), -MiseAJour fait le passage à la nouvelle méthode : mêmes données,
# même .env, Laragon arrêté et désactivé (il reste sur le disque, on peut le désinstaller ensuite).
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File installer.ps1 [-MiseAJour]
#
# Essai sur un poste de développement (sans toucher à l'installation existante) : -Racine vers un
# dossier d'essai, -Nom (préfixe des services et de la tâche), -Port / -PortPhp / -Base /
# -UtilisateurBase / -PortBase dédiés, -AdminBase, -DossierSauvegardes, -DossiersRaccourcis,
# -Laragon vers une fausse installation Laragon. Mots de passe sans saisie : variables
# LAKOLI_PG_ADMIN_MDP (compte postgres) et LAKOLI_MDP_FONDATEUR, options de lakoli:installer dans
# -OptionsEtablissement.

param(
    [switch]$MiseAJour,
    [string]$Racine = "C:\LAKOLI",
    [string]$Nom = "LAKOLI",
    [int]$Port = 8080,
    # Deux PHP : $PortPhp et $PortPhp + 1.
    [int]$PortPhp = 9101,
    [string]$Base = "lakoli_db",
    [string]$UtilisateurBase = "lakoli_user",
    [string]$AdminBase = "postgres",
    # Port de PostgreSQL (5432 par défaut) : une autre instance pour les essais.
    [int]$PortBase = 5432,
    [string]$DossierSauvegardes = "C:\LAKOLI-Sauvegardes",
    [string[]]$DossiersRaccourcis = @(),
    # Ancienne installation par Laragon, reprise par -MiseAJour.
    [string]$Laragon = "C:\laragon",
    # Jours d'essai gratuit de la nouvelle école (0 = abonnement actif d'emblée). Ensuite :
    # php artisan lakoli:essai pour prolonger, activer ou suspendre.
    [int]$Essai = 30,
    [string[]]$OptionsEtablissement = @()
)

$ErrorActionPreference = "Stop"
$paquet = $PSScriptRoot
$app = Join-Path $Racine "app"
$dossierPhp = Join-Path $Racine "php"
$php = Join-Path $dossierPhp "php.exe"
$dossierCaddy = Join-Path $Racine "caddy"
$dossierServices = Join-Path $Racine "services"
$journaux = Join-Path $Racine "logs"
$tmpPhp = Join-Path $Racine "tmp\php"
$ancienneApp = Join-Path $Laragon "www\lakoli"
$nomTache = "$Nom - Sauvegarde"
$services = @(
    @{ Id = "$Nom-PHP-1"; Libelle = "$Nom PHP 1"; Port = $PortPhp },
    @{ Id = "$Nom-PHP-2"; Libelle = "$Nom PHP 2"; Port = $PortPhp + 1 },
    @{ Id = "$Nom-Web"; Libelle = "$Nom Web"; Port = $Port }
)
# Comptes Windows par identifiant (SID), valables quelle que soit la langue de Windows.
$sidAdmins = "*S-1-5-32-544"; $sidSysteme = "*S-1-5-18"; $sidUtilisateurs = "*S-1-5-32-545"; $sidServiceLocal = "*S-1-5-19"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }
function Artisan { & $php (Join-Path $app "artisan") @args; if ($LASTEXITCODE -ne 0) { Echec "php artisan $($args -join ' ') a échoué." } }
function EcrireTexte($chemin, $texte) { [IO.File]::WriteAllText($chemin, $texte, (New-Object Text.UTF8Encoding $false)) }
function Copier($source, $cible, [string[]]$options = @()) {
    robocopy $source $cible /E /NFL /NDL /NJH /NJS /NP @options | Out-Null
    if ($LASTEXITCODE -ge 8) { Echec "la copie de $source vers $cible a échoué (robocopy $LASTEXITCODE)." }
}
# Programme externe dont la sortie d'erreur est lue : sous PowerShell 5.1, « 2>&1 » avec
# ErrorActionPreference=Stop transformerait la moindre ligne d'erreur (même un simple journal) en échec.
function Externe($programme) {
    $ErrorActionPreference = "Continue"
    & $programme @args 2>&1 | ForEach-Object { "$_" }
}
function ArreterServices {
    foreach ($s in $services) {
        $service = Get-Service $s.Id -ErrorAction SilentlyContinue
        if ($service -and $service.Status -ne "Stopped") { Stop-Service $s.Id -Force -ErrorAction SilentlyContinue }
    }
    # Un php-cgi resté bloqué garderait les DLL de PHP ouvertes (copie impossible).
    Get-Process php-cgi, caddy -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.StartsWith($Racine, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force
}

# --- Vérifications ---------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
foreach ($f in "php\php-cgi.exe", "caddy\caddy.exe", "outils\WinSW.NET461.exe", "outils\vc_redist.x64.exe", "lakoli\artisan") {
    if (-not (Test-Path (Join-Path $paquet $f))) { Echec "paquet incomplet : $f manquant. Lancez installer.ps1 depuis le dossier du paquet extrait." }
}

$pgBin = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\psql.exe" -ErrorAction SilentlyContinue |
    Sort-Object { [int]$_.Directory.Parent.Name } -Descending | Select-Object -First 1 | ForEach-Object { $_.DirectoryName }
if (-not $pgBin) { Echec "PostgreSQL introuvable dans C:\Program Files\PostgreSQL. Installez-le d'abord (voir le guide)." }
$psql = Join-Path $pgBin "psql.exe"

$installee = Test-Path (Join-Path $app ".env")
$migration = $false
if ($MiseAJour) {
    if (-not $installee) {
        if (-not (Test-Path (Join-Path $ancienneApp ".env"))) { Echec "aucune installation trouvée ($app ou $ancienneApp) : relancez sans -MiseAJour." }
        $migration = $true
    }
} elseif ($installee) {
    Echec "LAKOLI est déjà installé dans $app. Pour installer une nouvelle version : -MiseAJour."
} elseif (Test-Path (Join-Path $ancienneApp ".env")) {
    Echec "LAKOLI est déjà installé avec Laragon ($ancienneApp). Relancez avec -MiseAJour : il passera à la nouvelle méthode en gardant toutes les données."
}
# Mise à jour : l'application en service, à sauvegarder avant toute modification.
$appEnService = if ($migration) { $ancienneApp } else { $app }
if ($migration) {
    Write-Host ""
    Write-Host "Passage de Laragon à la nouvelle méthode : $ancienneApp -> $app (données conservées)." -ForegroundColor Yellow
}

# Le port de LAKOLI doit être libre (hors services LAKOLI eux-mêmes, arrêtés plus bas).
$pidsLakoli = Get-Process php-cgi, caddy -ErrorAction SilentlyContinue |
    Where-Object { $_.Path -and $_.Path.StartsWith($Racine, [StringComparison]::OrdinalIgnoreCase) } | ForEach-Object { $_.Id }
$occupe = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in @($Port, $PortPhp, ($PortPhp + 1)) -and $pidsLakoli -notcontains $_.OwningProcess -and
        -not ($migration -and (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).Path -like "$Laragon\*") }
if ($occupe) {
    $programme = (Get-Process -Id ($occupe | Select-Object -First 1).OwningProcess -ErrorAction SilentlyContinue).Path
    Echec "le port $(($occupe | Select-Object -First 1).LocalPort) est déjà utilisé par $programme. Arrêtez ce programme (Laragon ?) puis relancez."
}

# --- 1. Visual C++, sauvegarde, arrêt -------------------------------------------------------
Etape "Microsoft Visual C++ (nécessaire à PHP)"
$vc = Start-Process (Join-Path $paquet "outils\vc_redist.x64.exe") -ArgumentList "/install", "/quiet", "/norestart" -Wait -PassThru
# 1638 : version égale ou plus récente déjà installée ; 3010 : redémarrage conseillé, PHP marche déjà.
if ($vc.ExitCode -notin 0, 1638, 3010) { Echec "installation de Visual C++ impossible (code $($vc.ExitCode))." }

if ($MiseAJour) {
    Etape "Sauvegarde avant mise à jour"
    $null | & powershell -ExecutionPolicy Bypass -File (Join-Path $paquet "sauvegarde.ps1") -App $appEnService -Destination $DossierSauvegardes
    if ($LASTEXITCODE -ne 0) { Echec "la sauvegarde a échoué : mise à jour annulée, rien n'a été modifié." }
}

if ($migration) {
    Etape "Arrêt et désactivation de Laragon"
    $racineLaragon = (Resolve-Path $Laragon).Path
    Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.StartsWith($racineLaragon, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force
    # Démarrage avec Windows : entrées « Run » de la machine et des comptes ouverts, raccourcis du
    # dossier Démarrage de chaque compte.
    $cles = @("HKLM:\Software\Microsoft\Windows\CurrentVersion\Run", "HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Run") +
        (Get-ChildItem Registry::HKEY_USERS -ErrorAction SilentlyContinue | ForEach-Object { "Registry::$($_.Name)\Software\Microsoft\Windows\CurrentVersion\Run" })
    foreach ($cle in $cles) {
        $valeurs = Get-ItemProperty $cle -ErrorAction SilentlyContinue
        if (-not $valeurs) { continue }
        foreach ($p in $valeurs.PSObject.Properties) {
            if ($p.Name -notlike "PS*" -and "$($p.Value)" -like "*laragon.exe*") {
                Remove-ItemProperty $cle -Name $p.Name -ErrorAction SilentlyContinue
                Write-Host "  démarrage automatique retiré : $($p.Name)"
            }
        }
    }
    Get-ChildItem "C:\Users\*\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup\*", "$env:ProgramData\Microsoft\Windows\Start Menu\Programs\StartUp\*" -Include "*laragon*" -ErrorAction SilentlyContinue |
        ForEach-Object { Remove-Item $_.FullName -Force; Write-Host "  démarrage automatique retiré : $($_.FullName)" }
    # Si un compte où l'entrée n'a pas pu être vue lance encore laragon.exe, il ne le trouve plus.
    $exeLaragon = Join-Path $Laragon "laragon.exe"
    if (Test-Path $exeLaragon) { Rename-Item $exeLaragon "laragon.exe.desactive" -Force }
    # Ancien site Apache (il prendrait le port $Port si Laragon était relancé).
    $siteApache = Join-Path $Laragon "etc\apache2\sites-enabled\lakoli-ecole.conf"
    if (Test-Path $siteApache) { Move-Item $siteApache "$siteApache.desactive" -Force }
}

if ($installee) {
    Etape "Arrêt des services LAKOLI"
    ArreterServices
}

# --- 2. PHP, Caddy, application ------------------------------------------------------------
Etape "PHP, serveur web et application dans $Racine"
foreach ($d in $Racine, $journaux, $tmpPhp, (Join-Path $dossierCaddy "donnees"), (Join-Path $dossierCaddy "config"), $dossierServices) {
    New-Item -ItemType Directory -Force $d | Out-Null
}
# /MIR : PHP exactement celui du paquet (anciennes DLL retirées) ; php.ini refait juste après.
robocopy (Join-Path $paquet "php") $dossierPhp /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Echec "la copie de PHP a échoué (robocopy $LASTEXITCODE)." }
Copy-Item (Join-Path $paquet "caddy\*") $dossierCaddy -Recurse -Force

# php.ini : modèle « production » de PHP + réglages de LAKOLI.
$ini = [IO.File]::ReadAllText((Join-Path $dossierPhp "php.ini-production"))
$a = { param($chemin) ($chemin -replace '\\', '/') }
$reglages = [ordered]@{
    extension_dir = "`"$(& $a (Join-Path $dossierPhp 'ext'))`""
    memory_limit = "256M"
    max_execution_time = "60"
    upload_max_filesize = "10M"
    post_max_size = "12M"
    realpath_cache_size = "4096k"
    # Fichiers envoyés (photos, logo, justificatifs) dans un dossier propre à LAKOLI (cf. le
    # problème du 7 octobre : C:\Windows\Temp illisible par le compte du serveur).
    upload_tmp_dir = "`"$(& $a $tmpPhp)`""
    sys_temp_dir = "`"$(& $a $tmpPhp)`""
    error_log = "`"$(& $a (Join-Path $journaux 'php-erreurs.log'))`""
    "opcache.enable" = "1"
    "opcache.memory_consumption" = "192"
    "opcache.max_accelerated_files" = "20000"
    "opcache.validate_timestamps" = "1"
    "opcache.revalidate_freq" = "2"
    # Mémoire du cache propre à cette installation : sans cela, deux installations du même PHP
    # sous le même compte (essai et poste de dev, par exemple) partagent la mémoire du cache, et
    # le PHP démarré en second s'arrête aussitôt (« Opcode handlers are unusable due to ASLR »).
    "opcache.cache_id" = $Nom
}
foreach ($cle in $reglages.Keys) {
    $ligne = "$cle = $($reglages[$cle])"
    $motif = "(?m)^[ \t;]*$([regex]::Escape($cle))[ \t]*=[^\r\n]*"
    if ([regex]::IsMatch($ini, $motif)) { $ini = ([regex]$motif).Replace($ini, $ligne, 1) } else { $ini += "`r`n$ligne`r`n" }
}
foreach ($ext in "curl", "fileinfo", "gd", "mbstring", "openssl", "pdo_pgsql", "pgsql", "zip") {
    # (?=\r?$) : fin de ligne Windows (\r\n) dans php.ini-production.
    $motif = "(?m)^[ \t;]*extension[ \t]*=[ \t]*$ext[ \t]*(?=\r?$)"
    if ([regex]::IsMatch($ini, $motif)) { $ini = ([regex]$motif).Replace($ini, "extension=$ext", 1) } else { $ini += "`r`nextension=$ext`r`n" }
}
EcrireTexte (Join-Path $dossierPhp "php.ini") $ini

$manquantes = Externe $php -r "echo implode(' ', array_filter(['pgsql','pdo_pgsql','mbstring','openssl','fileinfo','gd','zip','curl','Zend OPcache'], fn(`$e) => !extension_loaded(`$e)));"
if ($LASTEXITCODE -ne 0) { Echec "PHP ne démarre pas : $manquantes" }
if ($manquantes) { Echec "extensions PHP manquantes : $manquantes" }

Write-Host "  application"
# /XD storage /XF .env : en mise à jour, on garde la configuration et les fichiers déposés.
Copier (Join-Path $paquet "lakoli") $app @("/XD", (Join-Path $paquet "lakoli\storage"), "/XF", ".env")
if ($migration) {
    Write-Host "  reprise du .env et des fichiers déposés de $ancienneApp"
    Copy-Item (Join-Path $ancienneApp ".env") (Join-Path $app ".env") -Force
    Copier (Join-Path $ancienneApp "storage") (Join-Path $app "storage") @("/XD", (Join-Path $ancienneApp "storage\framework"))
} elseif (-not $MiseAJour) {
    Copier (Join-Path $paquet "lakoli\storage") (Join-Path $app "storage")
}
foreach ($d in "storage\app\private", "storage\framework\cache\data", "storage\framework\sessions", "storage\framework\views", "storage\logs", "bootstrap\cache") {
    New-Item -ItemType Directory -Force (Join-Path $app $d) | Out-Null
}

if ($MiseAJour) {
    # Le .env est conservé ; on y ajoute seulement les réglages apparus depuis l'installation
    # (présents dans le nouveau .env.ecole), sans jamais modifier une valeur existante, sauf la
    # langue : les installations faites avant la traduction française avaient APP_LOCALE=en.
    Etape "Configuration (.env) : réglages ajoutés depuis l'installation"
    $cheminEnv = Join-Path $app ".env"
    $lignesEnv = [Collections.Generic.List[string]]([IO.File]::ReadAllLines($cheminEnv))
    $clesEnv = foreach ($l in $lignesEnv) { if ($l -match '^\s*([A-Z0-9_]+)\s*=') { $Matches[1] } }
    $ajouts = @()
    foreach ($ligne in [IO.File]::ReadAllLines((Join-Path $app "deploiement\.env.ecole"))) {
        if ($ligne -match '^([A-Z0-9_]+)=(.*)$' -and $clesEnv -notcontains $Matches[1] -and $Matches[2] -notmatch '__') {
            $ajouts += $ligne
        }
    }
    if ($ajouts.Count) {
        $lignesEnv.Add("")
        $lignesEnv.Add("# Ajouté par la mise à jour du $(Get-Date -Format 'dd/MM/yyyy') (voir deploiement\.env.ecole)")
        foreach ($x in $ajouts) { $lignesEnv.Add($x); Write-Host "  ajouté : $x" }
    }
    $iLangue = -1
    for ($i = 0; $i -lt $lignesEnv.Count; $i++) { if ($lignesEnv[$i] -match '^APP_LOCALE=en\s*$') { $iLangue = $i } }
    if ($iLangue -ge 0 -and (Test-Path (Join-Path $app "lang\fr\validation.php"))) {
        $lignesEnv[$iLangue] = "APP_LOCALE=fr"
        Write-Host "  langue : APP_LOCALE=fr (messages d'erreur en français)"
    }
    [IO.File]::WriteAllLines($cheminEnv, $lignesEnv, (New-Object Text.UTF8Encoding $false))
    if (-not $ajouts.Count -and $iLangue -lt 0) { Write-Host "  rien à ajouter." }

    # Règle de pare-feu des tout premiers paquets : LAKOLI n'écoute que sur 127.0.0.1.
    Remove-NetFirewallRule -DisplayName "LAKOLI (port $Port)" -ErrorAction SilentlyContinue
}

# --- 3. Base et configuration (première installation) ----------------------------------------
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

    $roleExiste = & $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$UtilisateurBase'"
    if ($LASTEXITCODE -ne 0) { Echec "connexion à PostgreSQL impossible : mot de passe « $AdminBase » incorrect ?" }
    $ordre = if ($roleExiste -eq "1") { "ALTER" } else { "CREATE" }
    & $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -qc "$ordre ROLE $UtilisateurBase LOGIN PASSWORD '$mdpBase'" | Out-Null
    $baseExiste = & $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$Base'"
    if ($baseExiste -eq "1") { Echec "la base $Base existe déjà. Supprimez-la ou choisissez un autre nom (-Base)." }
    & $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -qc "CREATE DATABASE $Base OWNER $UtilisateurBase ENCODING 'UTF8' TEMPLATE template0" | Out-Null
    if ($LASTEXITCODE -ne 0) { Echec "création de la base impossible." }
    Remove-Item Env:\PGPASSWORD

    Etape "Configuration (.env, accès local uniquement)"
    $contenuEnv = [IO.File]::ReadAllText((Join-Path $app "deploiement\.env.ecole"))
    $contenuEnv = $contenuEnv.Replace("__PORT__", "$Port").Replace("__BASE__", $Base).Replace("__UTILISATEUR__", $UtilisateurBase).Replace("__MOT_DE_PASSE__", $mdpBase).Replace("DB_PORT=5432", "DB_PORT=$PortBase")
    EcrireTexte (Join-Path $app ".env") $contenuEnv
    Artisan key:generate --force
}

Etape "Tables de la base"
# Caches de l'ancienne installation : chemins de Laragon dans la configuration compilée.
Artisan config:clear
Artisan route:clear
Artisan view:clear
Artisan migrate --force

if (-not $MiseAJour) {
    Etape "Établissement et compte du fondateur"
    & $php (Join-Path $app "artisan") lakoli:installer "--essai=$Essai" @OptionsEtablissement
    if ($LASTEXITCODE -ne 0) { Echec "création de l'établissement interrompue. Relancez : $php artisan lakoli:installer (dans $app)." }
} else {
    # Installations faites avant que l'installateur ne crée les classes : année scolaire et classes
    # courantes proposées si l'année active n'a aucune classe (sinon rien n'est modifié).
    Etape "Année scolaire et classes"
    & $php (Join-Path $app "artisan") lakoli:classes-courantes
    if ($LASTEXITCODE -ne 0) { Write-Host "Classes non créées : relancez plus tard « php artisan lakoli:classes-courantes » dans $app." -ForegroundColor Yellow }
}

Etape "Optimisation"
Artisan config:cache
Artisan route:cache
Artisan view:cache

# --- 4. Droits sur les dossiers ------------------------------------------------------------
# Les services tournent sous « Service local » (droits réduits) : lecture seule partout, écriture
# seulement là où LAKOLI écrit. Les comptes Windows ordinaires lisent l'application mais ne
# peuvent rien y modifier, ni lire le .env (mot de passe de la base).
Etape "Droits sur $Racine"
icacls $Racine /inheritance:r /grant:r "${sidAdmins}:(OI)(CI)F" "${sidSysteme}:(OI)(CI)F" "${sidUtilisateurs}:(OI)(CI)RX" "${sidServiceLocal}:(OI)(CI)RX" /Q | Out-Null
if ($LASTEXITCODE -ne 0) { Echec "droits sur $Racine non appliqués (icacls $LASTEXITCODE)." }
foreach ($d in (Join-Path $app "storage"), (Join-Path $app "bootstrap\cache"), (Join-Path $Racine "tmp"), $journaux, (Join-Path $dossierCaddy "donnees"), (Join-Path $dossierCaddy "config")) {
    icacls $d /grant "${sidServiceLocal}:(OI)(CI)M" /Q | Out-Null
}
icacls (Join-Path $app ".env") /inheritance:r /grant:r "${sidAdmins}:F" "${sidSysteme}:F" "${sidServiceLocal}:R" /Q | Out-Null

# --- 5. Services Windows -------------------------------------------------------------------
Etape "Services Windows (démarrage avec Windows, sans fenêtre)"
$racinePublique = & $a (Join-Path $app "public")
$caddyfile = @"
# LAKOLI : application et API sur http://127.0.0.1:$Port, ce PC uniquement (installé par installer.ps1).
{
	admin off
	auto_https off
	storage file_system "$(& $a (Join-Path $dossierCaddy 'donnees'))"
	log {
		output file "$(& $a (Join-Path $journaux 'caddy.log'))" {
			roll_size 5MiB
			roll_keep 3
		}
		level WARN
	}
}

# Écoute sur 127.0.0.1 : aucun autre ordinateur ne peut joindre LAKOLI. Condition exigée par
# OTP_ACTIF=false (sinon le code de vérification est réactivé).
http://:$Port {
	bind 127.0.0.1
	root * "$racinePublique"
	encode gzip
	php_fastcgi 127.0.0.1:$PortPhp 127.0.0.1:$($PortPhp + 1) {
		lb_policy least_conn
		lb_try_duration 15s
		# Caddy est le seul serveur (aucun intermédiaire) : sans ces lignes, il ajoute X-Forwarded-For,
		# que le garde-fou d'OTP_ACTIF=false prend pour un accès par un intermédiaire (code réactivé).
		header_up -X-Forwarded-For
		header_up -X-Forwarded-Host
		header_up -X-Forwarded-Proto
	}
	file_server {
		hide .htaccess web.config
	}
}
"@
$cheminCaddyfile = Join-Path $dossierCaddy "Caddyfile"
EcrireTexte $cheminCaddyfile $caddyfile
$sortie = Externe (Join-Path $dossierCaddy "caddy.exe") validate --config $cheminCaddyfile --adapter caddyfile
if ($LASTEXITCODE -ne 0) { Echec "configuration du serveur web invalide ($cheminCaddyfile) : $($sortie | Select-Object -Last 3)" }

$xml = { param($texte) [Security.SecurityElement]::Escape($texte) }
foreach ($s in $services) {
    if ($s.Id -like "*-Web") {
        $executable = Join-Path $dossierCaddy "caddy.exe"
        $arguments = "run --config `"$cheminCaddyfile`" --adapter caddyfile"
        $description = "LAKOLI : serveur web, http://127.0.0.1:$Port (ce PC uniquement)."
        $variables = "  <env name=`"XDG_DATA_HOME`" value=`"$(& $xml (Join-Path $dossierCaddy 'donnees'))`"/>`r`n  <env name=`"XDG_CONFIG_HOME`" value=`"$(& $xml (Join-Path $dossierCaddy 'config'))`"/>"
    } else {
        $executable = Join-Path $dossierPhp "php-cgi.exe"
        $arguments = "-b 127.0.0.1:$($s.Port) -c `"$(Join-Path $dossierPhp 'php.ini')`""
        $description = "LAKOLI : PHP de l'application (127.0.0.1:$($s.Port))."
        # 0 : php-cgi ne s'arrête jamais de lui-même (sinon il quitte après 500 requêtes).
        $variables = "  <env name=`"PHP_FCGI_MAX_REQUESTS`" value=`"0`"/>"
    }
    $config = @"
<service>
  <id>$($s.Id)</id>
  <name>$(& $xml $s.Libelle)</name>
  <description>$(& $xml $description)</description>
  <executable>$(& $xml $executable)</executable>
  <arguments>$(& $xml $arguments)</arguments>
  <workingdirectory>$(& $xml $app)</workingdirectory>
$variables
  <startmode>Automatic</startmode>
  <onfailure action="restart" delay="3 sec"/>
  <onfailure action="restart" delay="10 sec"/>
  <onfailure action="restart" delay="30 sec"/>
  <resetfailure>1 hour</resetfailure>
  <stoptimeout>10 sec</stoptimeout>
  <logpath>$(& $xml $journaux)</logpath>
  <log mode="roll-by-size">
    <sizeThreshold>2048</sizeThreshold>
    <keepFiles>3</keepFiles>
  </log>
</service>
"@
    $exe = Join-Path $dossierServices "$($s.Id).exe"
    if (Get-Service $s.Id -ErrorAction SilentlyContinue) {
        & $exe uninstall | Out-Null
        $attente = 0
        while ((Get-Service $s.Id -ErrorAction SilentlyContinue) -and $attente -lt 20) { Start-Sleep -Milliseconds 500; $attente++ }
    }
    Copy-Item (Join-Path $paquet "outils\WinSW.NET461.exe") $exe -Force
    EcrireTexte (Join-Path $dossierServices "$($s.Id).xml") $config
    & $exe install | Out-Null
    if ($LASTEXITCODE -ne 0) { Echec "service $($s.Id) non créé." }
    $compte = Get-CimInstance Win32_Service -Filter "Name='$($s.Id)'" |
        Invoke-CimMethod -MethodName Change -Arguments @{ StartName = "NT AUTHORITY\LocalService"; StartPassword = "" }
    if ($compte.ReturnValue -ne 0) { Echec "compte du service $($s.Id) non modifié (code $($compte.ReturnValue))." }
    Start-Service $s.Id
}

Write-Host "  vérification de http://127.0.0.1:$Port"
$pret = $false
for ($i = 0; $i -lt 30 -and -not $pret; $i++) {
    try {
        $reponse = Invoke-WebRequest "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 5
        $pret = $reponse.StatusCode -eq 200 -and $reponse.Content -match 'id="root"'
    } catch { Start-Sleep -Seconds 1 }
}
if (-not $pret) { Echec "LAKOLI ne répond pas sur http://127.0.0.1:$Port. Journaux : $journaux et $app\storage\logs." }

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

# --- 6. Sauvegardes ------------------------------------------------------------------------
$script = Join-Path $app "deploiement\sauvegarde.ps1"
Etape "Sauvegarde automatique (tous les jours à 18 h)"
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script`" -App `"$app`" -Destination `"$DossierSauvegardes`""
$declencheur = New-ScheduledTaskTrigger -Daily -At "18:00"
# StartWhenAvailable : si le PC était éteint à 18 h, la sauvegarde se fait au prochain démarrage.
$reglagesTache = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName $nomTache -Action $action -Trigger $declencheur -Settings $reglagesTache -User "SYSTEM" -RunLevel Highest -Force | Out-Null

if (-not $MiseAJour) {
    Etape "Première sauvegarde"
    $null | & powershell -ExecutionPolicy Bypass -File $script -App $app -Destination $DossierSauvegardes
    if ($LASTEXITCODE -ne 0) { Write-Host "La première sauvegarde a échoué : vérifiez le journal dans $DossierSauvegardes." -ForegroundColor Yellow }
}

# --- Fin ------------------------------------------------------------------------------------
Write-Host ""
Write-Host "Installation terminée : LAKOLI fonctionne." -ForegroundColor Green
Write-Host ""
Write-Host "Adresse (sur ce PC uniquement) : $adresse"
Write-Host "Raccourci « LAKOLI » : sur le Bureau et dans le menu Démarrer de chaque compte Windows."
Write-Host "LAKOLI démarre tout seul avec Windows : rien d'autre à lancer."
if ($migration) {
    Write-Host ""
    Write-Host "Laragon est arrêté et ne démarre plus avec Windows. L'ancienne copie ($ancienneApp) est" -ForegroundColor Yellow
    Write-Host "conservée par précaution : quand tout fonctionne, désinstallez Laragon (voir le guide)." -ForegroundColor Yellow
}
