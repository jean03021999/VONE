# Réinitialise le mot de passe du compte « postgres » oublié (à lancer EN ADMINISTRATEUR).
#
# Les bases et les données ne sont pas touchées. Le script :
#   1. ouvre un court instant l'accès sans mot de passe, depuis ce PC seulement (méthode « trust ») ;
#   2. enregistre le nouveau mot de passe ;
#   3. remet TOUJOURS la protection (scram-sha-256), même en cas d'erreur ou d'interruption ;
#   4. vérifie qu'aucune ligne ne reste ouverte et que le nouveau mot de passe est exigé.
# La protection est réécrite dans pg_hba.conf, elle ne dépend d'aucune sauvegarde.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File reinitialiser-mot-de-passe-postgres.ps1
#
# Essai sur une autre instance : -DossierDonnees, -Port, -SansService (redémarrage par pg_ctl au
# lieu du service Windows). Le nouveau mot de passe peut être fourni par la variable
# LAKOLI_PG_NOUVEAU_MDP (sans saisie).

param(
    [string]$DossierPostgres = "",
    [string]$DossierDonnees = "",
    [int]$Port = 5432,
    [string]$Service = "auto",
    [switch]$SansService,
    [string]$Compte = "postgres"
)

# « Continue » : sous Windows PowerShell 5.1, « Stop » ferait d'un message d'erreur de psql (même
# redirigé) une erreur fatale. Les erreurs sont vérifiées une à une ($LASTEXITCODE, -ErrorAction Stop).
$ErrorActionPreference = "Continue"

function Etape($texte) { Write-Host ""; Write-Host "==> $texte" -ForegroundColor Cyan }
function Echec($texte) { Write-Host ""; Write-Host "ERREUR : $texte" -ForegroundColor Red; exit 1 }

# Lignes de règles (pas les commentaires) : local / host / hostssl / hostnossl ... méthode [options].
$motifRegle = '^(\s*(?:local|host|hostssl|hostnossl|hostgssenc|hostnogssenc)\s.*?\s)(trust|scram-sha-256|md5|password|peer|ident|sspi)(\s*(?:#.*)?)$'

# Ligne locale : type « local », ou adresse 127.0.0.1/32, ::1/128, localhost.
function Est-Locale([string]$debut) {
    $debut -match '^\s*local\s' -or $debut -match '\s(127\.0\.0\.1/32|::1/128|localhost)\s'
}

# Accès temporaire : « trust » sur les seules lignes locales (une règle réseau n'est jamais ouverte).
function Ouvrir-Local([string[]]$lignes) {
    foreach ($l in $lignes) {
        if ($l -match $motifRegle) {
            $debut = $Matches[1]; $fin = $Matches[3]
            if (Est-Locale $debut) { $debut + 'trust' + $fin } else { $l }
        } else { $l }
    }
}

# Protection : chaque ligne reprend sa méthode d'origine, et « trust » devient toujours scram-sha-256.
function Proteger([string[]]$lignes) {
    foreach ($l in $lignes) {
        if ($l -match $motifRegle -and $Matches[2] -eq 'trust') { $Matches[1] + 'scram-sha-256' + $Matches[3] } else { $l }
    }
}

function Ecrire([string]$fichier, $lignes) {
    [IO.File]::WriteAllLines($fichier, [string[]]@($lignes), (New-Object Text.UTF8Encoding $false))
}

function Lignes-Ouvertes([string]$fichier) {
    @([IO.File]::ReadAllLines($fichier) | Where-Object { $_ -match $motifRegle -and $Matches[2] -eq 'trust' })
}

function Redemarrer {
    if ($Service) {
        Restart-Service -Name $Service -ErrorAction Stop
    } else {
        # Processus a part : le serveur relance n'herite pas des sorties de cette console (sinon
        # l'appel ne rendrait jamais la main). On n'attend que pg_ctl, pas le serveur.
        $journal = Join-Path $env:TEMP "lakoli-pg_ctl.log"
        $p = Start-Process -FilePath $pgCtl -ArgumentList @('-D', "`"$DossierDonnees`"", '-w', '-s', '-l', "`"$journal`"", '-o', "`"-p $Port`"", 'restart') -WindowStyle Hidden -PassThru
        $p.WaitForExit()
        if ($p.ExitCode -ne 0) { throw "pg_ctl n'a pas pu redémarrer PostgreSQL (journal : $journal)." }
    }
    # Laisse PostgreSQL accepter les connexions.
    for ($i = 0; $i -lt 30; $i++) {
        & $pgIsReady -h 127.0.0.1 -p $Port -q
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Milliseconds 500
    }
    throw "PostgreSQL ne répond pas après le redémarrage."
}

# --- Repérage --------------------------------------------------------------------------------
if (-not $DossierPostgres) {
    $DossierPostgres = Get-ChildItem "C:\Program Files\PostgreSQL" -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^\d+$' } | Sort-Object { [int]$_.Name } -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $DossierPostgres -or -not (Test-Path (Join-Path $DossierPostgres "bin\psql.exe"))) {
    Echec "PostgreSQL introuvable dans C:\Program Files\PostgreSQL."
}
if (-not $DossierDonnees) { $DossierDonnees = Join-Path $DossierPostgres "data" }
$hba = Join-Path $DossierDonnees "pg_hba.conf"
if (-not (Test-Path $hba)) { Echec "Fichier introuvable : $hba" }
$psql = Join-Path $DossierPostgres "bin\psql.exe"
$pgCtl = Join-Path $DossierPostgres "bin\pg_ctl.exe"
$pgIsReady = Join-Path $DossierPostgres "bin\pg_isready.exe"

if ($SansService) {
    $Service = ""
} elseif ($Service -eq "auto") {
    $Service = Get-Service "postgresql*" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Name
    if (-not $Service) { Echec "Service PostgreSQL introuvable (Services Windows : postgresql-x64-...)." }
}
if ($Service) {
    $admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if (-not $admin) { Echec "lancez PowerShell en administrateur (clic droit > Exécuter en tant qu'administrateur)." }
}

Write-Host "PostgreSQL : $DossierPostgres"
Write-Host "Données    : $DossierDonnees"
Write-Host "Service    : $(if ($Service) { $Service } else { 'pg_ctl (essai)' })   Port : $Port"

# --- Nouveau mot de passe --------------------------------------------------------------------
if ($env:LAKOLI_PG_NOUVEAU_MDP) {
    $mdp = $env:LAKOLI_PG_NOUVEAU_MDP
} else {
    $s1 = Read-Host "Nouveau mot de passe du compte « $Compte »" -AsSecureString
    $s2 = Read-Host "Confirmez le mot de passe" -AsSecureString
    $mdp = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s1))
    $mdp2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s2))
    if ($mdp -ne $mdp2) { Echec "les deux mots de passe sont différents. Rien n'a été modifié." }
}
if ($mdp.Length -lt 8) { Echec "8 caractères minimum. Rien n'a été modifié." }
if ($mdp -match '[^\x20-\x7E]') { Echec "lettres sans accent, chiffres et symboles du clavier uniquement. Rien n'a été modifié." }

# --- Réinitialisation (protection toujours remise) --------------------------------------------
$reussi = $false
$origine = $null
try {
    Etape "Ouverture temporaire de l'accès (ce PC seulement)"
    $origine = [IO.File]::ReadAllLines($hba)
    Ecrire $hba (Ouvrir-Local $origine)
    Redemarrer

    Etape "Nouveau mot de passe"
    $echappe = $mdp.Replace("'", "''")
    $OutputEncoding = New-Object Text.UTF8Encoding $false
    $env:PGCLIENTENCODING = "UTF8"
    "ALTER ROLE `"$Compte`" WITH PASSWORD '$echappe';" | & $psql -h 127.0.0.1 -p $Port -U $Compte -d postgres -v ON_ERROR_STOP=1 -q
    if ($LASTEXITCODE -ne 0) { throw "le mot de passe n'a pas pu être enregistré (psql $LASTEXITCODE)." }
    $reussi = $true
} catch {
    Write-Host "ERREUR : $($_.Exception.Message)" -ForegroundColor Red
} finally {
    # Toujours, même après une erreur ou Ctrl+C : plus aucune ligne en « trust ».
    Etape "Protection remise (scram-sha-256)"
    if ($null -ne $origine) { Ecrire $hba (Proteger $origine) } else { Ecrire $hba (Proteger ([IO.File]::ReadAllLines($hba))) }
    try { Redemarrer } catch { Write-Host "ATTENTION : $($_.Exception.Message) Redémarrez PostgreSQL (Services Windows)." -ForegroundColor Red }
}

# --- Vérifications ---------------------------------------------------------------------------
Etape "Vérification"
$ouvertes = Lignes-Ouvertes $hba
if ($ouvertes.Count -gt 0) {
    Write-Host "DANGER : des lignes restent sans mot de passe dans $hba :" -ForegroundColor Red
    $ouvertes | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    exit 1
}
Write-Host "Aucune ligne sans mot de passe dans pg_hba.conf."

$env:PGPASSWORD = "mauvais-mot-de-passe-de-verification"
& $psql -h 127.0.0.1 -p $Port -U $Compte -d postgres -w -tAc "select 1" 2>$null | Out-Null
$sansMotDePasse = $LASTEXITCODE -eq 0
$env:PGPASSWORD = $mdp
$avecMotDePasse = (& $psql -h 127.0.0.1 -p $Port -U $Compte -d postgres -w -tAc "select 'ok'" 2>$null) -eq 'ok'
Remove-Item Env:\PGPASSWORD

if ($sansMotDePasse) { Write-Host "DANGER : PostgreSQL accepte un mauvais mot de passe." -ForegroundColor Red; exit 1 }
Write-Host "Un mauvais mot de passe est bien refusé."
if (-not $reussi -or -not $avecMotDePasse) {
    Write-Host "Le nouveau mot de passe n'a pas été enregistré. La protection est en place ; relancez le script." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Mot de passe du compte « $Compte » changé, PostgreSQL protégé." -ForegroundColor Green
Write-Host "Notez ce mot de passe en lieu sûr : il est demandé par l'installation et la restauration."
