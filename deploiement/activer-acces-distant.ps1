# Active l'accès à LAKOLI à distance par Tailscale (à lancer EN ADMINISTRATEUR, depuis le paquet).
# À annuler par desactiver-acces-distant.ps1. Voir GUIDE-INSTALLATION.md, section 6.
#
# Ce script :
#   1. détecte l'adresse Tailscale de ce PC (tailscale ip -4) ;
#   2. met TAILSCALE_ACTIF=true dans le .env : les connexions venues de 100.64.0.0/10 passent par le
#      mot de passe et la limitation des tentatives, sans code de vérification ;
#   3. crée le service « LAKOLI-Distant », un second serveur web à l'écoute sur cette seule adresse ;
#   4. ouvre le pare-feu pour ce seul serveur, cette adresse et ce port, depuis l'interface Tailscale.
#
# Le service LAKOLI-Web (127.0.0.1) n'est pas touché. L'écoute distante est un service à part car
# un serveur web s'arrête entièrement si l'une de ses adresses n'existe pas : au démarrage de
# Windows, Tailscale peut ne pas être encore connecté, et LAKOLI doit rester utilisable sur ce PC.
# Le service LAKOLI-Distant, lui, réessaie toutes les 30 secondes jusqu'à ce que l'adresse existe.
#
# Si l'adresse Tailscale du PC change : relancer ce script.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File activer-acces-distant.ps1

param(
    [string]$Racine = "C:\LAKOLI",
    [string]$Nom = "LAKOLI",
    [int]$Port = 8080,
    [int]$PortPhp = 9101
)

$ErrorActionPreference = "Stop"
$app = Join-Path $Racine "app"
$dossierCaddy = Join-Path $Racine "caddy"
$dossierServices = Join-Path $Racine "services"
$journaux = Join-Path $Racine "logs"
$php = Join-Path $Racine "php\php.exe"
$cheminEnv = Join-Path $app ".env"
$service = "$Nom-Distant"
$regle = "$Nom acces distant Tailscale"
$cheminCaddyfile = Join-Path $dossierCaddy "Caddyfile-distant"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }
function EcrireTexte($chemin, $texte) { [IO.File]::WriteAllText($chemin, $texte, (New-Object Text.UTF8Encoding $false)) }
# Sous PowerShell 5.1, lire la sortie d'erreur avec ErrorActionPreference=Stop ferait échouer le script.
function Externe($programme) {
    $ErrorActionPreference = "Continue"
    & $programme @args 2>&1 | ForEach-Object { "$_" }
}

# --- Vérifications (rien n'est modifié si l'une échoue) --------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
$winsw = Join-Path $dossierServices "$Nom-Web.exe"
foreach ($f in $cheminEnv, $php, (Join-Path $dossierCaddy "caddy.exe"), $winsw) {
    if (-not (Test-Path $f)) { Echec "installation LAKOLI introuvable : $f manquant." }
}

Etape "Adresse Tailscale de ce PC"
$tailscale = (Get-Command tailscale -ErrorAction SilentlyContinue).Source
if (-not $tailscale) { $tailscale = Join-Path $env:ProgramFiles "Tailscale\tailscale.exe" }
if (-not (Test-Path $tailscale)) { Echec "Tailscale n'est pas installé sur ce PC." }
$sortie = @(Externe $tailscale ip -4)
if ($LASTEXITCODE -ne 0) { Echec "Tailscale ne donne pas d'adresse (connecté ?) : $($sortie -join ' ')" }
$adresse = ($sortie -join "").Trim()
$parties = $adresse -split '\.'
$valide = $adresse -match '^\d{1,3}(\.\d{1,3}){3}$' -and [int]$parties[0] -eq 100 -and [int]$parties[1] -ge 64 -and [int]$parties[1] -le 127
if (-not $valide) { Echec "adresse inattendue : « $adresse » (attendue dans 100.64.0.0/10)." }
Write-Host "  $adresse"
$interface = (Get-NetIPAddress -IPAddress $adresse -ErrorAction SilentlyContinue | Select-Object -First 1).InterfaceAlias
if (-not $interface) { Echec "aucune interface réseau ne porte l'adresse $adresse. Tailscale est-il connecté ?" }

# Le port doit être libre sur cette adresse (hors notre propre service, recréé plus bas).
$occupe = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalAddress -in @($adresse, "0.0.0.0", "::") }
$actuel = Get-Service $service -ErrorAction SilentlyContinue
if ($occupe -and -not $actuel) { Echec "le port $Port est déjà utilisé sur $adresse par $((Get-Process -Id ($occupe | Select-Object -First 1).OwningProcess -ErrorAction SilentlyContinue).Path)." }

# --- Serveur web distant ---------------------------------------------------------------------
Etape "Configuration du serveur web distant"
$a = { param($chemin) ($chemin -replace '\\', '/') }
$racinePublique = & $a (Join-Path $app "public")
$caddyfile = @"
# LAKOLI : accès distant par Tailscale, sur $adresse uniquement (créé par activer-acces-distant.ps1).
# Ne pas changer « bind » : les connexions de 100.64.0.0/10 sont admises sans code de vérification,
# ce serveur ne doit donc écouter sur aucune autre adresse.
{
	admin off
	auto_https off
	storage file_system "$(& $a (Join-Path $dossierCaddy 'donnees'))"
	log {
		output file "$(& $a (Join-Path $journaux 'caddy-distant.log'))" {
			roll_size 5MiB
			roll_keep 3
		}
		level WARN
	}
}

http://:$Port {
	bind $adresse
	root * "$racinePublique"
	encode gzip
	php_fastcgi 127.0.0.1:$PortPhp 127.0.0.1:$($PortPhp + 1) {
		lb_policy least_conn
		lb_try_duration 15s
		# Aucun intermédiaire : l'adresse lue par LAKOLI est celle de la connexion, et un visiteur
		# ne peut pas en annoncer une autre par ces en-têtes.
		header_up -X-Forwarded-For
		header_up -X-Forwarded-Host
		header_up -X-Forwarded-Proto
	}
	file_server {
		hide .htaccess web.config
	}
}
"@
EcrireTexte $cheminCaddyfile $caddyfile
$sortie = Externe (Join-Path $dossierCaddy "caddy.exe") validate --config $cheminCaddyfile --adapter caddyfile
if ($LASTEXITCODE -ne 0) { Echec "configuration du serveur web invalide ($cheminCaddyfile) : $($sortie | Select-Object -Last 3)" }

# --- Réglage TAILSCALE_ACTIF -----------------------------------------------------------------
Etape "Réglage TAILSCALE_ACTIF=true"
$lignes = [Collections.Generic.List[string]]([IO.File]::ReadAllLines($cheminEnv))
$trouve = $false
for ($i = 0; $i -lt $lignes.Count; $i++) {
    if ($lignes[$i] -match '^\s*TAILSCALE_ACTIF\s*=') { $lignes[$i] = "TAILSCALE_ACTIF=true"; $trouve = $true }
}
if (-not $trouve) { $lignes.Add("TAILSCALE_ACTIF=true") }
[IO.File]::WriteAllLines($cheminEnv, $lignes, (New-Object Text.UTF8Encoding $false))
& $php (Join-Path $app "artisan") config:cache
if ($LASTEXITCODE -ne 0) { Echec "php artisan config:cache a échoué. Lancez desactiver-acces-distant.ps1." }

# --- Service Windows -------------------------------------------------------------------------
Etape "Service Windows $service"
$exe = Join-Path $dossierServices "$service.exe"
if ($actuel) {
    Stop-Service $service -Force -ErrorAction SilentlyContinue
    & $exe uninstall | Out-Null
    $attente = 0
    while ((Get-Service $service -ErrorAction SilentlyContinue) -and $attente -lt 20) { Start-Sleep -Milliseconds 500; $attente++ }
}
$xml = { param($texte) [Security.SecurityElement]::Escape($texte) }
$config = @"
<service>
  <id>$service</id>
  <name>$(& $xml "$Nom Distant")</name>
  <description>$(& $xml "LAKOLI : accès distant par Tailscale, http://${adresse}:$Port.")</description>
  <executable>$(& $xml (Join-Path $dossierCaddy "caddy.exe"))</executable>
  <arguments>run --config "$(& $xml $cheminCaddyfile)" --adapter caddyfile</arguments>
  <workingdirectory>$(& $xml $app)</workingdirectory>
  <env name="XDG_DATA_HOME" value="$(& $xml (Join-Path $dossierCaddy 'donnees'))"/>
  <env name="XDG_CONFIG_HOME" value="$(& $xml (Join-Path $dossierCaddy 'config'))"/>
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
Copy-Item $winsw $exe -Force
EcrireTexte (Join-Path $dossierServices "$service.xml") $config
& $exe install | Out-Null
if ($LASTEXITCODE -ne 0) { Echec "service $service non créé. Lancez desactiver-acces-distant.ps1." }
$compte = Get-CimInstance Win32_Service -Filter "Name='$service'" |
    Invoke-CimMethod -MethodName Change -Arguments @{ StartName = "NT AUTHORITY\LocalService"; StartPassword = "" }
if ($compte.ReturnValue -ne 0) { Echec "compte du service $service non modifié (code $($compte.ReturnValue))." }
Start-Service $service

# --- Pare-feu --------------------------------------------------------------------------------
Etape "Pare-feu : port $Port sur $adresse, depuis l'interface « $interface » seulement"
Remove-NetFirewallRule -DisplayName $regle -ErrorAction SilentlyContinue
New-NetFirewallRule -DisplayName $regle -Direction Inbound -Action Allow -Profile Any -Protocol TCP `
    -LocalAddress $adresse -LocalPort $Port -RemoteAddress "100.64.0.0/10" -InterfaceAlias $interface `
    -Program (Join-Path $dossierCaddy "caddy.exe") | Out-Null

# --- Vérification ----------------------------------------------------------------------------
Etape "Vérification"
$url = "http://${adresse}:$Port"
$pret = $false
for ($i = 0; $i -lt 20 -and -not $pret; $i++) {
    try {
        $pret = (Invoke-WebRequest "$url/" -UseBasicParsing -TimeoutSec 5).Content -match 'id="root"'
    } catch { Start-Sleep -Seconds 1 }
}
if (-not $pret) { Echec "LAKOLI ne répond pas sur $url. Journaux : $journaux. Pour annuler : desactiver-acces-distant.ps1." }
try {
    if (-not ((Invoke-WebRequest "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 5).Content -match 'id="root"')) { throw "page inattendue" }
} catch { Echec "LAKOLI ne répond plus sur http://127.0.0.1:$Port : $($_.Exception.Message)" }

$ecoutes = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | ForEach-Object { $_.LocalAddress } | Sort-Object -Unique)
$autres = @($ecoutes | Where-Object { $_ -notin @("127.0.0.1", $adresse) })
if ($autres.Count) { Echec "le port $Port écoute aussi sur : $($autres -join ', '). Ce n'est pas prévu : lancez desactiver-acces-distant.ps1." }
Write-Host "  écoute sur : $($ecoutes -join ', ')"

Write-Host ""
Write-Host "Accès distant ACTIVÉ." -ForegroundColor Green
Write-Host "Depuis un autre appareil de votre réseau Tailscale : $url"
Write-Host "Pour le couper : powershell -ExecutionPolicy Bypass -File desactiver-acces-distant.ps1"
