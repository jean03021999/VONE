# Coupe l'accès à LAKOLI à distance par Tailscale (à lancer EN ADMINISTRATEUR, depuis le paquet).
# Retour exact au fonctionnement normal : LAKOLI sur http://127.0.0.1:8080, ce PC uniquement.
# Sans effet si l'accès distant n'était pas activé. Tailscale n'a pas besoin d'être connecté.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File desactiver-acces-distant.ps1

param(
    [string]$Racine = "C:\LAKOLI",
    [string]$Nom = "LAKOLI",
    [int]$Port = 8080
)

$ErrorActionPreference = "Stop"
$app = Join-Path $Racine "app"
$php = Join-Path $Racine "php\php.exe"
$cheminEnv = Join-Path $app ".env"
$service = "$Nom-Distant"
$regle = "$Nom acces distant Tailscale"
$dossierServices = Join-Path $Racine "services"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
if (-not (Test-Path $cheminEnv)) { Echec "installation LAKOLI introuvable : $cheminEnv manquant." }

Etape "Arrêt et suppression du service $service"
if (Get-Service $service -ErrorAction SilentlyContinue) {
    Stop-Service $service -Force -ErrorAction SilentlyContinue
    $exe = Join-Path $dossierServices "$service.exe"
    if (Test-Path $exe) { & $exe uninstall | Out-Null } else { sc.exe delete $service | Out-Null }
    $attente = 0
    while ((Get-Service $service -ErrorAction SilentlyContinue) -and $attente -lt 20) { Start-Sleep -Milliseconds 500; $attente++ }
    if (Get-Service $service -ErrorAction SilentlyContinue) { Echec "service $service toujours présent : redémarrez le PC puis relancez ce script." }
    Write-Host "  supprimé"
} else { Write-Host "  absent" }
foreach ($f in (Join-Path $dossierServices "$service.exe"), (Join-Path $dossierServices "$service.xml"), (Join-Path $Racine "caddy\Caddyfile-distant")) {
    Remove-Item $f -Force -ErrorAction SilentlyContinue
}

Etape "Pare-feu"
Remove-NetFirewallRule -DisplayName $regle -ErrorAction SilentlyContinue
if (Get-NetFirewallRule -DisplayName $regle -ErrorAction SilentlyContinue) { Echec "la règle de pare-feu « $regle » n'a pas pu être retirée." }
Write-Host "  règle retirée"

Etape "Réglage TAILSCALE_ACTIF=false"
$lignes = [Collections.Generic.List[string]]([IO.File]::ReadAllLines($cheminEnv))
$trouve = $false
for ($i = 0; $i -lt $lignes.Count; $i++) {
    if ($lignes[$i] -match '^\s*TAILSCALE_ACTIF\s*=') { $lignes[$i] = "TAILSCALE_ACTIF=false"; $trouve = $true }
}
if (-not $trouve) { $lignes.Add("TAILSCALE_ACTIF=false") }
[IO.File]::WriteAllLines($cheminEnv, $lignes, (New-Object Text.UTF8Encoding $false))
& $php (Join-Path $app "artisan") config:cache
if ($LASTEXITCODE -ne 0) { Echec "php artisan config:cache a échoué : TAILSCALE_ACTIF n'est pas pris en compte." }

Etape "Vérification"
$ecoutes = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | ForEach-Object { $_.LocalAddress } | Sort-Object -Unique)
$autres = @($ecoutes | Where-Object { $_ -ne "127.0.0.1" })
if ($autres.Count) { Echec "le port $Port écoute encore sur : $($autres -join ', ')." }
try {
    if (-not ((Invoke-WebRequest "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 10).Content -match 'id="root"')) { throw "page inattendue" }
} catch { Echec "LAKOLI ne répond pas sur http://127.0.0.1:$Port : $($_.Exception.Message)" }
Write-Host "  écoute sur : $($ecoutes -join ', ')"

Write-Host ""
Write-Host "Accès distant DÉSACTIVÉ. LAKOLI reste disponible sur http://127.0.0.1:$Port (ce PC uniquement)." -ForegroundColor Green
