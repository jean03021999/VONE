# Sauvegarde LAKOLI : base de données + fichiers déposés (logos, photos, justificatifs).
#
# Lancée chaque jour à 18 h par la tâche planifiée « LAKOLI - Sauvegarde » (créée par installer.ps1),
# ou à la main :  powershell -ExecutionPolicy Bypass -File sauvegarde.ps1
#
# - Copies dans C:\LAKOLI-Sauvegardes, gardées 30 jours.
# - Copie de sécurité HORS du PC : toute clé USB ou disque externe branché qui contient, à sa racine,
#   un dossier nommé LAKOLI-SAUVEGARDES reçoit aussi la sauvegarde (10 dernières gardées).
# - Journal : C:\LAKOLI-Sauvegardes\journal.txt

param(
    [string]$App = "C:\laragon\www\lakoli",
    [string]$Destination = "C:\LAKOLI-Sauvegardes",
    [int]$JoursConserves = 30,
    [int]$CopiesExternes = 10
)

$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force $Destination | Out-Null
$journal = Join-Path $Destination "journal.txt"
function Journal($texte) {
    $ligne = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $texte"
    Add-Content -Path $journal -Value $ligne -Encoding UTF8
    Write-Host $ligne
}

try {
    # Connexion à la base : lue dans le .env de l'application.
    $config = @{}
    foreach ($ligne in Get-Content (Join-Path $App ".env")) {
        if ($ligne -match '^\s*(DB_[A-Z_]+)\s*=\s*"?(.*?)"?\s*$') { $config[$Matches[1]] = $Matches[2] }
    }
    $pgBin = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\pg_dump.exe" -ErrorAction SilentlyContinue |
        Sort-Object { [int]$_.Directory.Parent.Name } -Descending | Select-Object -First 1 | ForEach-Object { $_.DirectoryName }
    if (-not $pgBin) { throw "pg_dump introuvable (PostgreSQL non installé ?)." }

    $horodatage = Get-Date -Format "yyyyMMdd-HHmm"
    $fichierBase = Join-Path $Destination "lakoli-$horodatage.dump"
    $fichiersDeposes = Join-Path $Destination "fichiers-$horodatage.zip"

    $env:PGPASSWORD = $config["DB_PASSWORD"]
    & (Join-Path $pgBin "pg_dump.exe") -h $config["DB_HOST"] -p $config["DB_PORT"] -U $config["DB_USERNAME"] -F c -f $fichierBase $config["DB_DATABASE"]
    $code = $LASTEXITCODE
    Remove-Item Env:\PGPASSWORD
    if ($code -ne 0 -or -not (Test-Path $fichierBase)) { throw "pg_dump a échoué (code $code)." }

    $prive = Join-Path $App "storage\app\private"
    $aCopier = @($fichierBase)
    if ((Test-Path $prive) -and (Get-ChildItem $prive -Recurse -File | Select-Object -First 1)) {
        Compress-Archive -Path (Join-Path $prive "*") -DestinationPath $fichiersDeposes -Force
        $aCopier += $fichiersDeposes
    }
    $taille = "{0:N1} Mo" -f ((Get-Item $fichierBase).Length / 1MB)
    Journal "OK  base $taille -> $fichierBase"

    # Rotation locale.
    Get-ChildItem $Destination -File | Where-Object { $_.Name -match '^(lakoli|fichiers)-\d{8}-\d{4}\.' -and $_.LastWriteTime -lt (Get-Date).AddDays(-$JoursConserves) } |
        Remove-Item -Force

    # Copie hors du PC : clés USB / disques externes préparés avec un dossier LAKOLI-SAUVEGARDES.
    foreach ($volume in Get-Volume | Where-Object { $_.DriveLetter -and $_.DriveType -in "Removable", "Fixed" }) {
        $cible = "$($volume.DriveLetter):\LAKOLI-SAUVEGARDES"
        if (-not (Test-Path $cible) -or $cible.StartsWith($Destination.Substring(0, 2))) { continue }
        Copy-Item $aCopier $cible -Force
        Get-ChildItem $cible -File -Filter "lakoli-*.dump" | Sort-Object Name -Descending | Select-Object -Skip $CopiesExternes |
            ForEach-Object {
                Remove-Item $_.FullName -Force
                Remove-Item (Join-Path $cible ($_.Name -replace '^lakoli-(.*)\.dump$', 'fichiers-$1.zip')) -Force -ErrorAction SilentlyContinue
            }
        Journal "OK  copie externe -> $cible"
    }
    exit 0
} catch {
    Journal "ECHEC  $($_.Exception.Message)"
    exit 1
}
