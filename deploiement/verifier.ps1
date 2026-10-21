# Vérifie que LAKOLI fonctionne sur ce PC (par exemple juste après un redémarrage de Windows).
# Aucun droit d'administrateur nécessaire, rien n'est modifié.
#
# Utilisation :  powershell -ExecutionPolicy Bypass -File verifier.ps1
# Essai : -Nom et -Port de l'installation d'essai.

param(
    [string]$Nom = "LAKOLI",
    [int]$Port = 8080
)

$resultats = [ordered]@{}

foreach ($s in "$Nom-Web", "$Nom-PHP-1", "$Nom-PHP-2") {
    $service = Get-Service $s -ErrorAction SilentlyContinue
    $resultats["Service $s"] = if (-not $service) { "ÉCHEC : absent" }
        elseif ($service.Status -ne "Running") { "ÉCHEC : $($service.Status)" }
        elseif ($service.StartType -ne "Automatic") { "ÉCHEC : démarrage $($service.StartType), pas automatique" }
        else { "démarré, automatique" }
}

$adresse = "http://127.0.0.1:$Port"
try {
    $page = Invoke-WebRequest "$adresse/" -UseBasicParsing -TimeoutSec 10
    $resultats["Page $adresse"] = if ($page.Content -match 'id="root"') { "OK" } else { "ÉCHEC : page inattendue" }
} catch { $resultats["Page $adresse"] = "ÉCHEC : $($_.Exception.Message)" }

# Base de données : une connexion refusée (401/422) prouve que PHP a lu la table des comptes ;
# une erreur 500 signale une base injoignable.
try {
    Invoke-WebRequest "$adresse/api/auth/login" -Method Post -UseBasicParsing -TimeoutSec 10 -ContentType "application/json" `
        -Headers @{ Accept = "application/json" } -Body '{"identifiant":"verification@lakoli.invalid","mot_de_passe":"x"}' | Out-Null
    $resultats["Application et base"] = "ÉCHEC : connexion acceptée sans compte valide ?"
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    $resultats["Application et base"] = if ($code -in 401, 403, 422, 429) { "OK (réponse $code attendue)" } else { "ÉCHEC : réponse $code" }
}

$echecs = 0
Write-Host ""
foreach ($cle in $resultats.Keys) {
    $valeur = $resultats[$cle]
    $couleur = if ($valeur -like "ÉCHEC*") { $echecs++; "Red" } else { "Green" }
    Write-Host ("  {0,-28} {1}" -f $cle, $valeur) -ForegroundColor $couleur
}
Write-Host ""
if ($echecs) { Write-Host "LAKOLI ne fonctionne pas correctement (lignes en rouge)." -ForegroundColor Red; exit 1 }
Write-Host "LAKOLI fonctionne." -ForegroundColor Green
