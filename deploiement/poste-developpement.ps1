# Serveur du PC de DÉVELOPPEMENT sans Laragon (à lancer EN ADMINISTRATEUR). Pas dans le paquet école.
#
# Même PHP officiel, même Caddy et mêmes services Windows que dans les écoles (voir installer.ps1),
# mais l'application servie est le dépôt lui-même : chaque modification est en ligne aussitôt, sans
# copie ni cache. Le .env et la base du dépôt ne sont pas touchés.
#
# Ce script :
#   1. installe PHP, Caddy et les services dans C:\LAKOLI-Dev, depuis C:\projets\paquets\outils
#      (rempli par preparer-paquet.ps1) ;
#   2. arrête Laragon et l'empêche de démarrer avec Windows (il reste sur le disque) ;
#   3. donne au compte « Service local » la lecture du dépôt et l'écriture dans storage et
#      bootstrap\cache ;
#   4. crée trois services (serveur web + deux PHP) : http://localhost, une seule adresse
#      comme à l'école, /api servi par Laravel et l'écran par Vite (npm run dev dans lakoli-web,
#      à lancer à part ; VITE_API_URL=/api comme dans le paquet).
#
# Relancer le script refait l'installation (nouvelle version de PHP ou de Caddy, par exemple).
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File deploiement\poste-developpement.ps1

param(
    [string]$Depot = (Split-Path $PSScriptRoot -Parent),
    [string]$Outils = "C:\projets\paquets\outils",
    [string]$Racine = "C:\LAKOLI-Dev",
    [string]$Nom = "LAKOLI-Dev",
    # 80 : http://localhost, sans numéro de port.
    [int]$Port = 80,
    # Deux PHP : $PortPhp et $PortPhp + 1.
    [int]$PortPhp = 9101,
    # Vite (npm run dev de lakoli-web), fixé dans son vite.config.js.
    [int]$PortVite = 5173,
    [string]$Laragon = "C:\laragon"
)

$ErrorActionPreference = "Stop"
$dossierPhp = Join-Path $Racine "php"
$php = Join-Path $dossierPhp "php.exe"
$dossierCaddy = Join-Path $Racine "caddy"
$dossierServices = Join-Path $Racine "services"
$journaux = Join-Path $Racine "logs"
$tmpPhp = Join-Path $Racine "tmp\php"
$services = @(
    @{ Id = "$Nom-PHP-1"; Libelle = "$Nom PHP 1"; Port = $PortPhp },
    @{ Id = "$Nom-PHP-2"; Libelle = "$Nom PHP 2"; Port = $PortPhp + 1 },
    @{ Id = "$Nom-Web"; Libelle = "$Nom Web"; Port = $Port }
)
$adresse = if ($Port -eq 80) { "http://localhost" } else { "http://localhost:$Port" }
$sidAdmins = "*S-1-5-32-544"; $sidSysteme = "*S-1-5-18"; $sidUtilisateurs = "*S-1-5-32-545"; $sidServiceLocal = "*S-1-5-19"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }
function EcrireTexte($chemin, $texte) { [IO.File]::WriteAllText($chemin, $texte, (New-Object Text.UTF8Encoding $false)) }
function Externe($programme) {
    $ErrorActionPreference = "Continue"
    & $programme @args 2>&1 | ForEach-Object { "$_" }
}

# --- Vérifications ---------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Echec "lancez PowerShell en administrateur." }
if (-not (Test-Path (Join-Path $Depot "artisan"))) { Echec "dépôt LAKOLI introuvable : $Depot" }
if (-not (Test-Path (Join-Path $Depot ".env"))) { Echec "pas de .env dans $Depot." }
$zipPhp = Get-ChildItem (Join-Path $Outils "php-*-nts-Win32-*-x64.zip") -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
$zipCaddy = Get-ChildItem (Join-Path $Outils "caddy_*_windows_amd64.zip") -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
$winsw = Join-Path $Outils "WinSW.NET461.exe"
if (-not $zipPhp -or -not $zipCaddy -or -not (Test-Path $winsw)) { Echec "PHP, Caddy ou WinSW absent de $Outils : lancez d'abord deploiement\preparer-paquet.ps1." }

# --- 1. Arrêt de Laragon et des anciens services ---------------------------------------------
Etape "Arrêt de Laragon (il ne démarrera plus avec Windows)"
if (Test-Path $Laragon) {
    $racineLaragon = (Resolve-Path $Laragon).Path
    Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -and $_.Path.StartsWith($racineLaragon, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force
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
    # Site Apache sur le port $Port : il le reprendrait si Laragon était rouvert à la main.
    $siteApache = Join-Path $Laragon "etc\apache2\sites-enabled\lakoli-localhost.conf"
    if (Test-Path $siteApache) { Move-Item $siteApache "$siteApache.desactive" -Force; Write-Host "  site Apache du port $Port désactivé" }
} else {
    Write-Host "  Laragon absent."
}

foreach ($s in $services) {
    $service = Get-Service $s.Id -ErrorAction SilentlyContinue
    if ($service -and $service.Status -ne "Stopped") { Stop-Service $s.Id -Force -ErrorAction SilentlyContinue }
}
Get-Process php-cgi, caddy -ErrorAction SilentlyContinue |
    Where-Object { $_.Path -and $_.Path.StartsWith($Racine, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force

$occupe = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -in @($Port, $PortPhp, ($PortPhp + 1)) }
if ($occupe) {
    $programme = (Get-Process -Id ($occupe | Select-Object -First 1).OwningProcess -ErrorAction SilentlyContinue).Path
    Echec "le port $(($occupe | Select-Object -First 1).LocalPort) est déjà utilisé par $programme."
}

# --- 2. PHP et Caddy -----------------------------------------------------------------------
Etape "PHP ($($zipPhp.Name)) et Caddy ($($zipCaddy.Name)) dans $Racine"
foreach ($d in $Racine, $journaux, $tmpPhp, (Join-Path $dossierCaddy "donnees"), (Join-Path $dossierCaddy "config"), $dossierServices) {
    New-Item -ItemType Directory -Force $d | Out-Null
}
if (Test-Path $dossierPhp) { Remove-Item $dossierPhp -Recurse -Force }
Expand-Archive -Path $zipPhp.FullName -DestinationPath $dossierPhp
$tmpCaddy = Join-Path $Racine "tmp\caddy-zip"
if (Test-Path $tmpCaddy) { Remove-Item $tmpCaddy -Recurse -Force }
Expand-Archive -Path $zipCaddy.FullName -DestinationPath $tmpCaddy
Copy-Item (Join-Path $tmpCaddy "caddy.exe") $dossierCaddy -Force
Remove-Item $tmpCaddy -Recurse -Force

# php.ini : celui des écoles (modèle « production » + réglages de LAKOLI), sauf le cache du code
# revérifié à chaque requête (revalidate_freq = 0) : une modification est prise aussitôt.
$ini = [IO.File]::ReadAllText((Join-Path $dossierPhp "php.ini-production"))
$a = { param($chemin) ($chemin -replace '\\', '/') }
$reglages = [ordered]@{
    extension_dir = "`"$(& $a (Join-Path $dossierPhp 'ext'))`""
    memory_limit = "256M"
    max_execution_time = "60"
    upload_max_filesize = "10M"
    post_max_size = "12M"
    realpath_cache_size = "4096k"
    upload_tmp_dir = "`"$(& $a $tmpPhp)`""
    sys_temp_dir = "`"$(& $a $tmpPhp)`""
    error_log = "`"$(& $a (Join-Path $journaux 'php-erreurs.log'))`""
    "opcache.enable" = "1"
    "opcache.memory_consumption" = "192"
    "opcache.max_accelerated_files" = "20000"
    "opcache.validate_timestamps" = "1"
    "opcache.revalidate_freq" = "0"
    # Mémoire du cache propre à cette installation (voir installer.ps1).
    "opcache.cache_id" = $Nom
}
foreach ($cle in $reglages.Keys) {
    $ligne = "$cle = $($reglages[$cle])"
    $motif = "(?m)^[ \t;]*$([regex]::Escape($cle))[ \t]*=[^\r\n]*"
    if ([regex]::IsMatch($ini, $motif)) { $ini = ([regex]$motif).Replace($ini, $ligne, 1) } else { $ini += "`r`n$ligne`r`n" }
}
foreach ($ext in "curl", "fileinfo", "gd", "mbstring", "openssl", "pdo_pgsql", "pgsql", "zip") {
    $motif = "(?m)^[ \t;]*extension[ \t]*=[ \t]*$ext[ \t]*(?=\r?$)"
    if ([regex]::IsMatch($ini, $motif)) { $ini = ([regex]$motif).Replace($ini, "extension=$ext", 1) } else { $ini += "`r`nextension=$ext`r`n" }
}
EcrireTexte (Join-Path $dossierPhp "php.ini") $ini

$manquantes = Externe $php -r "echo implode(' ', array_filter(['pgsql','pdo_pgsql','mbstring','openssl','fileinfo','gd','zip','curl','Zend OPcache'], fn(`$e) => !extension_loaded(`$e)));"
if ($LASTEXITCODE -ne 0) { Echec "PHP ne démarre pas (Visual C++ manquant ?) : $manquantes" }
if ($manquantes) { Echec "extensions PHP manquantes : $manquantes" }

# Configuration compilée avec les chemins de Laragon : l'application relit le .env.
& $php (Join-Path $Depot "artisan") optimize:clear | Out-Null

# --- 3. Droits -----------------------------------------------------------------------------
Etape "Droits du compte « Service local » sur $Depot"
icacls $Racine /inheritance:r /grant:r "${sidAdmins}:(OI)(CI)F" "${sidSysteme}:(OI)(CI)F" "${sidUtilisateurs}:(OI)(CI)RX" "${sidServiceLocal}:(OI)(CI)RX" /Q | Out-Null
foreach ($d in (Join-Path $Racine "tmp"), $journaux, (Join-Path $dossierCaddy "donnees"), (Join-Path $dossierCaddy "config")) {
    icacls $d /grant "${sidServiceLocal}:(OI)(CI)M" /Q | Out-Null
}
# Lecture du dépôt (droit hérité par les fichiers créés ensuite), écriture là où Laravel écrit.
icacls $Depot /grant "${sidServiceLocal}:(OI)(CI)RX" /Q | Out-Null
if ($LASTEXITCODE -ne 0) { Echec "droits sur $Depot non appliqués (icacls $LASTEXITCODE)." }
foreach ($d in "storage", "bootstrap\cache") {
    icacls (Join-Path $Depot $d) /grant "${sidServiceLocal}:(OI)(CI)M" /Q | Out-Null
}

# --- 4. Services Windows -------------------------------------------------------------------
Etape "Services Windows (démarrage avec Windows, sans fenêtre)"
$racinePublique = & $a (Join-Path $Depot "public")
$caddyfile = @"
# LAKOLI (développement) : $Depot sur $adresse (installé par poste-developpement.ps1).
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

# Une seule adresse, comme à l'école : /api vers Laravel, tout le reste (l'écran) vers Vite
# (npm run dev dans lakoli-web, 127.0.0.1:$PortVite), modifications visibles aussitôt.
http://:$Port {
	# localhost : 127.0.0.1 et ::1 (IPv6, essayé en premier par les navigateurs), ce PC uniquement.
	bind 127.0.0.1 [::1]
	root * "$racinePublique"
	encode gzip
	@laravel path /api /api/* /sanctum/*
	handle @laravel {
		php_fastcgi 127.0.0.1:$PortPhp 127.0.0.1:$($PortPhp + 1) {
			lb_policy least_conn
			lb_try_duration 15s
			# Comme à l'école (voir installer.ps1) : pas d'en-têtes d'intermédiaire.
			header_up -X-Forwarded-For
			header_up -X-Forwarded-Host
			header_up -X-Forwarded-Proto
		}
	}
	handle {
		reverse_proxy 127.0.0.1:$PortVite
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
        $description = "LAKOLI (développement) : serveur web, $adresse."
        $variables = "  <env name=`"XDG_DATA_HOME`" value=`"$(& $xml (Join-Path $dossierCaddy 'donnees'))`"/>`r`n  <env name=`"XDG_CONFIG_HOME`" value=`"$(& $xml (Join-Path $dossierCaddy 'config'))`"/>"
    } else {
        $executable = Join-Path $dossierPhp "php-cgi.exe"
        $arguments = "-b 127.0.0.1:$($s.Port) -c `"$(Join-Path $dossierPhp 'php.ini')`""
        $description = "LAKOLI (développement) : PHP (127.0.0.1:$($s.Port))."
        $variables = "  <env name=`"PHP_FCGI_MAX_REQUESTS`" value=`"0`"/>"
    }
    $config = @"
<service>
  <id>$($s.Id)</id>
  <name>$(& $xml $s.Libelle)</name>
  <description>$(& $xml $description)</description>
  <executable>$(& $xml $executable)</executable>
  <arguments>$(& $xml $arguments)</arguments>
  <workingdirectory>$(& $xml $Depot)</workingdirectory>
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
    Copy-Item $winsw $exe -Force
    EcrireTexte (Join-Path $dossierServices "$($s.Id).xml") $config
    & $exe install | Out-Null
    if ($LASTEXITCODE -ne 0) { Echec "service $($s.Id) non créé." }
    $compte = Get-CimInstance Win32_Service -Filter "Name='$($s.Id)'" |
        Invoke-CimMethod -MethodName Change -Arguments @{ StartName = "NT AUTHORITY\LocalService"; StartPassword = "" }
    if ($compte.ReturnValue -ne 0) { Echec "compte du service $($s.Id) non modifié (code $($compte.ReturnValue))." }
    Start-Service $s.Id
}

# L'API seule : l'écran dépend de Vite, lancé à part. Sans connexion, /api/user répond 401.
Write-Host "  vérification de http://127.0.0.1:$Port/api"
$pret = $false
for ($i = 0; $i -lt 30 -and -not $pret; $i++) {
    try {
        Invoke-WebRequest "http://127.0.0.1:$Port/api/user" -Headers @{ Accept = "application/json" } -UseBasicParsing -TimeoutSec 5 | Out-Null
        $pret = $true
    } catch {
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 401) { $pret = $true } else { Start-Sleep -Seconds 1 }
    }
}
if (-not $pret) { Echec "rien ne répond sur $adresse. Journaux : $journaux et $Depot\storage\logs." }

Write-Host ""
Write-Host "Terminé : LAKOLI sur $adresse, démarré avec Windows (l'écran : npm run dev dans lakoli-web)." -ForegroundColor Green
Write-Host "Laragon est arrêté et ne démarre plus avec Windows (il reste dans $Laragon)."
