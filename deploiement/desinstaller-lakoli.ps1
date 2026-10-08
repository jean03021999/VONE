# Désinstalle LAKOLI de ce PC (à lancer EN ADMINISTRATEUR), sans rien taper à la main.
#
# ATTENTION : toutes les données de LAKOLI sont effacées (élèves, paiements, comptes). Le script
# propose d'abord une sauvegarde, puis demande de taper SUPPRIMER pour continuer.
#
# Ce script :
#   1. arrête Laragon (Apache et PHP) ;
#   2. supprime la base PostgreSQL de LAKOLI et son utilisateur (mot de passe « postgres » demandé) ;
#   3. supprime l'application (C:\laragon\www\lakoli) et le site Apache ;
#   4. supprime la sauvegarde automatique de 18 h, les raccourcis LAKOLI et l'ancienne règle de
#      pare-feu des premiers paquets d'essai ;
#   5. vérifie que tout a disparu.
# Laragon, PostgreSQL, PHP et le dossier des sauvegardes (C:\LAKOLI-Sauvegardes) sont conservés.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File desinstaller-lakoli.ps1
#
# Essai sur un poste de développement : mêmes paramètres que installer.ps1 (-Laragon, -App, -Base,
# -UtilisateurBase, -PortBase, -AdminBase, -DossiersRaccourcis, -SansTachePlanifiee : tâche planifiée
# et pare-feu non touchés, droits d'administrateur alors inutiles), -NomTache, et -Oui pour ne poser
# aucune question (sans sauvegarde). Mot de passe « postgres » : variable LAKOLI_PG_ADMIN_MDP.

param(
    [string]$Laragon = "C:\laragon",
    [string]$App = "",
    [string]$Base = "lakoli_db",
    [string]$UtilisateurBase = "lakoli_user",
    [int]$PortBase = 5432,
    [string]$AdminBase = "postgres",
    [string]$DossierSauvegardes = "C:\LAKOLI-Sauvegardes",
    [string]$NomTache = "LAKOLI - Sauvegarde",
    [string[]]$DossiersRaccourcis = @(),
    [switch]$SansTachePlanifiee,
    [switch]$Oui
)

# « Continue » : les erreurs sont vérifiées étape par étape (voir le bilan final).
$ErrorActionPreference = "Continue"
$app = if ($App) { $App } else { Join-Path $Laragon "www\lakoli" }
$site = Join-Path $Laragon "etc\apache2\sites-enabled\lakoli-ecole.conf"
$dossiers = if ($DossiersRaccourcis.Count) { $DossiersRaccourcis } else {
    @([Environment]::GetFolderPath("CommonDesktopDirectory"), [Environment]::GetFolderPath("CommonPrograms"))
}
$bilan = [ordered]@{}

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }

# --- Vérifications ---------------------------------------------------------------------------
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin -and -not $SansTachePlanifiee) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }

$pgBin = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\psql.exe" -ErrorAction SilentlyContinue |
    Sort-Object { [int]$_.Directory.Parent.Name } -Descending | Select-Object -First 1 | ForEach-Object { $_.DirectoryName }
if (-not $pgBin) { Echec "PostgreSQL introuvable dans C:\Program Files\PostgreSQL." }
$psql = Join-Path $pgBin "psql.exe"

Write-Host ""
Write-Host "Désinstallation de LAKOLI" -ForegroundColor Yellow
Write-Host "  Application : $app"
Write-Host "  Base        : $Base (utilisateur $UtilisateurBase, port $PortBase)"
Write-Host "  Conservés   : Laragon, PostgreSQL, PHP et les sauvegardes ($DossierSauvegardes)"
Write-Host ""
Write-Host "TOUTES LES DONNÉES DE LAKOLI SERONT EFFACÉES (élèves, paiements, comptes)." -ForegroundColor Red

# --- Sauvegarde avant effacement ---------------------------------------------------------------
$scriptSauvegarde = Join-Path $app "deploiement\sauvegarde.ps1"
if (-not $Oui -and (Test-Path $scriptSauvegarde) -and (Test-Path (Join-Path $app ".env"))) {
    $reponse = Read-Host "Faire d'abord une sauvegarde dans $DossierSauvegardes ? (O/N, conseillé : O)"
    if ($reponse -notmatch '^[nN]') {
        Etape "Sauvegarde avant désinstallation"
        # $null | : la sauvegarde ne doit pas consommer les réponses de ce script.
        $null | & powershell -NoProfile -ExecutionPolicy Bypass -File $scriptSauvegarde -App $app -Destination $DossierSauvegardes
        if ($LASTEXITCODE -ne 0) {
            Echec "la sauvegarde a échoué : désinstallation annulée, rien n'a été supprimé."
        }
        Write-Host "Sauvegarde faite. Copiez-la sur une clé USB si ces données doivent être conservées." -ForegroundColor Green
    }
}

if (-not $Oui) {
    if ((Read-Host "Tapez SUPPRIMER (en majuscules) pour désinstaller LAKOLI") -cne "SUPPRIMER") {
        Write-Host "Annulé : rien n'a été supprimé."
        exit 0
    }
}

# --- 1. Laragon ----------------------------------------------------------------------------
Etape "Arrêt de Laragon (Apache et PHP)"
$racineLaragon = (Resolve-Path $Laragon -ErrorAction SilentlyContinue).Path
$processus = if ($racineLaragon) {
    Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($racineLaragon, [StringComparison]::OrdinalIgnoreCase) }
} else { @() }
foreach ($p in $processus) { Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2
$bilan["Laragon arrêté"] = if ($processus) { "$(@($processus).Count) processus arrêté(s)" } else { "déjà arrêté" }

# --- 2. Base de données ---------------------------------------------------------------------
Etape "Suppression de la base de données"
if ($env:LAKOLI_PG_ADMIN_MDP) {
    $env:PGPASSWORD = $env:LAKOLI_PG_ADMIN_MDP
} else {
    $mdp = Read-Host "Mot de passe du compte « $AdminBase » (choisi à l'installation de PostgreSQL)" -AsSecureString
    $env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($mdp))
}
& $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -w -v ON_ERROR_STOP=1 -q `
    -c "DROP DATABASE IF EXISTS $Base WITH (FORCE)" -c "DROP ROLE IF EXISTS $UtilisateurBase"
$codeBase = $LASTEXITCODE
$resteBase = & $psql -h 127.0.0.1 -p $PortBase -U $AdminBase -d postgres -w -tAc "SELECT count(*) FROM pg_database WHERE datname = '$Base'" 2>$null
$env:PGPASSWORD = $null
$bilan["Base $Base"] = if ($codeBase -eq 0 -and "$resteBase".Trim() -eq "0") { "supprimée" } else {
    "ÉCHEC (mot de passe « $AdminBase » incorrect ? voir reinitialiser-mot-de-passe-postgres.ps1)"
}

# --- 3. Application et site Apache ----------------------------------------------------------
Etape "Suppression de l'application et du site Apache"
if (Test-Path $app) { Remove-Item $app -Recurse -Force -ErrorAction SilentlyContinue }
$bilan["Application"] = if (Test-Path $app) { "ÉCHEC : $app existe encore (un fichier est ouvert ? fermez Laragon et relancez)" } else { "supprimée" }
if (Test-Path $site) { Remove-Item $site -Force -ErrorAction SilentlyContinue }
$bilan["Site Apache"] = if (Test-Path $site) { "ÉCHEC : $site existe encore" } else { "supprimé" }

# --- 4. Tâche planifiée, raccourcis, pare-feu ------------------------------------------------
Etape "Sauvegarde automatique, raccourcis et pare-feu"
if (-not $SansTachePlanifiee) {
    if (Get-ScheduledTask -TaskName $NomTache -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $NomTache -Confirm:$false -ErrorAction SilentlyContinue
    }
    $bilan["Tâche « $NomTache »"] = if (Get-ScheduledTask -TaskName $NomTache -ErrorAction SilentlyContinue) { "ÉCHEC : toujours présente" } else { "supprimée" }
}

$raccourcisRestants = @()
foreach ($dossier in $dossiers) {
    foreach ($nom in "LAKOLI.lnk", "LAKOLI.url") {
        $chemin = Join-Path $dossier $nom
        if (Test-Path $chemin) { Remove-Item $chemin -Force -ErrorAction SilentlyContinue }
        if (Test-Path $chemin) { $raccourcisRestants += $chemin }
    }
}
$bilan["Raccourcis LAKOLI"] = if ($raccourcisRestants) { "ÉCHEC : $($raccourcisRestants -join ', ')" } else { "supprimés" }

if (-not $SansTachePlanifiee) {
    $regles = Get-NetFirewallRule -DisplayName "LAKOLI (port *" -ErrorAction SilentlyContinue
    if ($regles) { $regles | Remove-NetFirewallRule -ErrorAction SilentlyContinue }
    $bilan["Règle de pare-feu (anciens paquets)"] = if (Get-NetFirewallRule -DisplayName "LAKOLI (port *" -ErrorAction SilentlyContinue) { "ÉCHEC : toujours présente" } else { "aucune" }
}

# --- 5. Bilan ----------------------------------------------------------------------------------
Etape "Bilan"
$echecs = 0
foreach ($cle in $bilan.Keys) {
    $valeur = $bilan[$cle]
    $couleur = if ($valeur -like "ÉCHEC*") { $echecs++; "Red" } else { "Green" }
    Write-Host ("  {0,-38} {1}" -f $cle, $valeur) -ForegroundColor $couleur
}
Write-Host ""
if ($echecs) {
    Write-Host "Désinstallation incomplète : corrigez les lignes en rouge, puis relancez ce script (il ne refait que ce qui reste)." -ForegroundColor Red
    exit 1
}
Write-Host "LAKOLI est désinstallé. Laragon, PostgreSQL, PHP et les sauvegardes ($DossierSauvegardes) sont conservés." -ForegroundColor Green
Write-Host "Pour réinstaller : installer.ps1 depuis un paquet extrait, puis Laragon > Tout démarrer."
