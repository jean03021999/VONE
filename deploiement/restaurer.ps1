# Restauration LAKOLI depuis une sauvegarde (fichier lakoli-AAAAMMJJ-HHMM.dump).
#
# ATTENTION : remplace TOUTES les données actuelles par celles de la sauvegarde. Une sauvegarde de
# l'état actuel est faite juste avant, pour pouvoir revenir en arrière.
#
# Utilisation (en administrateur, Laragon arrêté de préférence) :
#   powershell -ExecutionPolicy Bypass -File restaurer.ps1                     (dernière sauvegarde)
#   powershell -ExecutionPolicy Bypass -File restaurer.ps1 -Fichier E:\LAKOLI-SAUVEGARDES\lakoli-20261005-1800.dump

param(
    [string]$Fichier,
    [string]$App = "C:\laragon\www\lakoli",
    [string]$Dossier = "C:\LAKOLI-Sauvegardes"
)

$ErrorActionPreference = "Stop"

if (-not $Fichier) {
    $Fichier = Get-ChildItem $Dossier -File -Filter "lakoli-*.dump" -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending | Select-Object -First 1 | ForEach-Object { $_.FullName }
}
if (-not $Fichier -or -not (Test-Path $Fichier)) { Write-Host "Aucune sauvegarde trouvée." -ForegroundColor Red; exit 1 }

Write-Host "Sauvegarde à restaurer : $Fichier ($((Get-Item $Fichier).LastWriteTime))"
Write-Host "Toutes les données actuelles de LAKOLI seront remplacées." -ForegroundColor Yellow
if ((Read-Host "Tapez OUI pour continuer") -ne "OUI") { Write-Host "Annulé."; exit 0 }

Write-Host "Sauvegarde de l'état actuel..."
& powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "sauvegarde.ps1") -App $App
if ($LASTEXITCODE -ne 0) { Write-Host "La sauvegarde de sécurité a échoué : restauration annulée." -ForegroundColor Red; exit 1 }

$config = @{}
foreach ($ligne in Get-Content (Join-Path $App ".env")) {
    if ($ligne -match '^\s*(DB_[A-Z_]+)\s*=\s*"?(.*?)"?\s*$') { $config[$Matches[1]] = $Matches[2] }
}
$pgBin = Get-ChildItem "C:\Program Files\PostgreSQL\*\bin\pg_restore.exe" |
    Sort-Object { [int]$_.Directory.Parent.Name } -Descending | Select-Object -First 1 | ForEach-Object { $_.DirectoryName }

$env:PGPASSWORD = $config["DB_PASSWORD"]
& (Join-Path $pgBin "pg_restore.exe") -h $config["DB_HOST"] -p $config["DB_PORT"] -U $config["DB_USERNAME"] -d $config["DB_DATABASE"] --clean --if-exists --no-owner --single-transaction $Fichier
$code = $LASTEXITCODE
Remove-Item Env:\PGPASSWORD
if ($code -ne 0) { Write-Host "La restauration de la base a échoué (code $code). Les données n'ont pas été modifiées." -ForegroundColor Red; exit 1 }

# Fichiers déposés (logos, photos, justificatifs) de la même sauvegarde, s'ils existent.
$zip = Join-Path (Split-Path $Fichier) ((Split-Path $Fichier -Leaf) -replace '^lakoli-(.*)\.dump$', 'fichiers-$1.zip')
if (Test-Path $zip) {
    $prive = Join-Path $App "storage\app\private"
    if (Test-Path $prive) { Remove-Item (Join-Path $prive "*") -Recurse -Force }
    Expand-Archive -Path $zip -DestinationPath $prive -Force
}

Write-Host "Restauration terminée. Redémarrez Laragon (Tout démarrer)." -ForegroundColor Green
