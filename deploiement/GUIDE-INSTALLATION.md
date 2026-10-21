# Installer LAKOLI dans une école

Ce guide installe LAKOLI sur **un seul PC de l'école**, qui garde toutes les données. LAKOLI
s'utilise **uniquement sur ce PC** (adresse http://127.0.0.1:8080). Chaque utilisateur (fondateur,
directeur, comptable…) s'y connecte avec son propre compte. Les autres ordinateurs du réseau ne
peuvent pas l'ouvrir (voir section 6).

Durée : environ 45 minutes. Internet n'est nécessaire que pour télécharger PostgreSQL (étape 1) :
tout le reste (PHP, serveur web) est dans le paquet. Aucun logiciel payant n'est utilisé, et aucune
fenêtre de licence ne s'affiche.

> **Avant de taper une commande PowerShell**
> - **Ne copiez jamais une commande depuis un téléphone** (WhatsApp, SMS, e-mail lu sur téléphone) :
>   les guillemets y sont transformés et la commande échoue (invite `>>` qui attend la suite, ou
>   « … n'est pas reconnu »). Ouvrez ce guide **sur le PC lui-même** et copiez depuis lui.
> - Copiez **la ligne entière**, du premier au dernier caractère, puis collez par **clic droit** dans
>   PowerShell et validez par **Entrée**. Si l'invite `>>` apparaît, appuyez sur **Ctrl+C** et recommencez.
> - **Ne fermez pas la fenêtre PowerShell** entre deux commandes d'une même étape.
> - Préférez les scripts fournis dans le paquet (`installer.ps1`, `desinstaller-lakoli.ps1`,
>   `reinitialiser-mot-de-passe-postgres.ps1`) : ils font tout, sans rien taper d'autre.

---

## 0. Avant de partir

- **Le paquet** LAKOLI sur une clé USB : un fichier dont le nom commence par `lakoli-ecole-` et se
  termine par `.zip`, préparé avec `deploiement\preparer-paquet.ps1`. Son nom contient la date et
  l'heure de préparation (par exemple `lakoli-ecole-20261008-1232.zip` = 8 octobre 2026, 12 h 32) :
  **prenez toujours le plus récent**.
- L'installateur de PostgreSQL (étape 1), déjà téléchargé, sur la même clé.
- **Une deuxième clé USB** (ou un disque externe), qui restera à l'école pour les sauvegardes.

**Le PC** : Windows 10 ou 11 64 bits, 8 Go de mémoire conseillés. Il doit être allumé
pendant les heures de travail. **Un onduleur est fortement conseillé** : une coupure de courant
pendant un enregistrement peut abîmer les données.

---

## 1. Logiciel à installer (une seule fois)

**PostgreSQL 18** (base de données) : <https://www.postgresql.org/download/windows/>, installateur EDB.
- Gardez les choix proposés (port 5432).
- **Notez le mot de passe du compte `postgres`** : il est demandé à l'étape 2. Gardez-le en lieu sûr.
- À la fin, décochez « Stack Builder ».

C'est tout : **Laragon n'est plus nécessaire**. Le paquet contient PHP (version officielle,
windows.php.net), le serveur web Caddy et Microsoft Visual C++, tous gratuits même pour un usage
commercial. L'installation les met en place toute seule.

---

## 2. Installer LAKOLI

> LAKOLI est déjà installé sur ce PC **avec Laragon** ? Ne désinstallez rien : suivez la section 8,
> « PC installé avec Laragon ». Les données sont conservées.

1. Copiez le paquet `.zip` sur le PC, par exemple sur le Bureau, puis : clic droit > **Extraire tout**.
2. Ouvrez le menu Démarrer, tapez `PowerShell`, puis clic droit > **Exécuter en tant qu'administrateur**.
3. Placez PowerShell dans le dossier extrait, **sans taper son nom** : tapez `cd` suivi d'**un espace**,
   puis faites glisser le dossier extrait (depuis l'Explorateur) dans la fenêtre PowerShell. Son
   chemin complet s'écrit tout seul. Appuyez sur **Entrée**.
4. Lancez l'installation :

   ```
   powershell -ExecutionPolicy Bypass -File installer.ps1
   ```

   « … installer.ps1 n'existe pas » : PowerShell n'est pas dans le bon dossier, reprenez le point 3.

5. Répondez aux questions :
   - le mot de passe du compte `postgres` (étape 1). Oublié ? Voir section 9, « Mot de passe
     PostgreSQL oublié » ;
   - le nom de l'établissement, la ville et le téléphone ;
   - le nom, l'e-mail et le mot de passe **du fondateur**. C'est le premier compte : il crée ensuite
     les autres ;
   - les **cycles** de l'école : tapez les numéros séparés par des virgules (par exemple `1,2` pour
     Primaire et Collège), ou Entrée pour tous ;
   - l'**année scolaire en cours** : Entrée accepte l'année proposée (par exemple 2026 pour 2026-2027) ;
   - **créer les classes courantes** : Entrée (oui). Les classes habituelles de ces cycles sont créées
     (Petite Section… 6ème Année, 7ème… 10ème Année, 11ème/12ème Année par série, Terminales).
     Elles pourront être renommées ou supprimées ensuite.

À la fin, le script affiche l'adresse de LAKOLI : **http://127.0.0.1:8080**. Il crée aussi un
raccourci **« LAKOLI »** (icône bleue avec le « L ») sur le Bureau et dans le menu Démarrer, pour
**tous les comptes Windows** du PC. Ce raccourci ouvre LAKOLI dans sa propre fenêtre, sans barre
d'adresse.

Avec cette installation, la connexion se fait avec **l'e-mail et le mot de passe**, sans code de
vérification : l'accès est limité à ce PC. Après 5 essais erronés en une minute, la connexion est
bloquée une minute.

## 3. Démarrage automatique : rien à faire

LAKOLI fonctionne dès la fin de l'installation et **démarre tout seul avec Windows**, avant même
l'ouverture de session, sans aucune fenêtre. Il n'y a aucun logiciel à lancer : le comptable
double-clique seulement sur le raccourci **LAKOLI**. Après une coupure de courant, LAKOLI revient
au redémarrage du PC.

En coulisses, trois **services Windows** font tourner LAKOLI : **LAKOLI Web** (serveur web) et
**LAKOLI PHP 1** et **2**. S'ils s'arrêtent, Windows les relance de lui-même en quelques secondes.
On peut les voir dans l'outil **Services** de Windows (menu Démarrer, tapez `Services`).

Pour vérifier que tout marche (par exemple après un redémarrage), dans PowerShell, sans droits
d'administrateur :

```
powershell -ExecutionPolicy Bypass -File C:\LAKOLI\app\deploiement\verifier.ps1
```

Chaque ligne doit être verte, et la dernière dire « LAKOLI fonctionne. ».

## 4. Premiers pas : qui fait quoi, dans quel ordre

L'installation a déjà créé **l'année scolaire en cours (active)** et **les classes courantes**. Chaque
compte ne voit que les menus de son rôle : le fondateur ne gère ni les classes ni les frais. Suivez cet
ordre.

1. **Fondateur** : double-cliquez sur le raccourci **LAKOLI** du Bureau (ou ouvrez Chrome ou Edge à
   l'adresse **http://127.0.0.1:8080**), choisissez le profil
   **Fondateur**, puis saisissez l'e-mail et le mot de passe de l'étape 2. Dans **Paramètres** :
   - **Mon établissement** : complétez la fiche (logo, adresse, agrément, slogan, WhatsApp de la comptabilité) ;
   - **Session scolaire** : vérifiez que l'année en cours est bien active ;
   - **Utilisateurs & rôles** : créez au moins un compte **Directeur** ou **Proviseur**, et un compte
     **Comptable**. Un mot de passe provisoire s'affiche pour chacun : transmettez-le à la personne.
2. **Directeur ou Proviseur** : menu **Gestion des Classes** ; renommez, supprimez ou ajoutez des
   classes selon l'école (par exemple « 7ème Année A » et « 7ème Année B »).
3. **Comptable** : menu **Frais de Scolarité** ; créez les types de frais (Scolarité, Inscription…), puis une grille
   tarifaire par classe ; inscrivez ensuite les élèves (**Gestion des Élèves**) et enregistrez les paiements.

| Rôle | Peut faire |
|---|---|
| Fondateur | Paramètres (établissement, année scolaire, comptes), statistiques financières, consultation |
| Directeur | Classes, matières, emploi du temps, notes et bulletins (validation) |
| Proviseur | Comme le directeur, plus enseignants, salaires et frais |
| Censeur | Classes, emploi du temps, saisie des notes |
| Comptable | Élèves (inscription), frais, paiements, salaires |
---

## 5. Sécurité : indispensable sur un poste partagé

Sans code de vérification, **le mot de passe est la seule protection** des comptes. Tout le monde
utilise le même PC : il faut donc protéger aussi l'accès à Windows.

1. **Un mot de passe fort pour chaque compte, en particulier celui du comptable**, qui enregistre
   les paiements et les salaires :
   - au moins 12 caractères, en mélangeant majuscules, minuscules, chiffres et un symbole ;
   - ni date de naissance, ni nom de l'école, ni « 123456 » ;
   - propre à LAKOLI (pas le même que pour la messagerie ou WhatsApp) ;
   - connu de la seule personne concernée : chacun change le mot de passe provisoire à sa première
     connexion (Paramètres > Sécurité).
2. **Protéger la session Windows du PC par un mot de passe** : Paramètres Windows > Comptes >
   Options de connexion > Mot de passe (ou code PIN).
3. **Verrouiller le PC en le quittant** : touches **Windows + L**. Réglez aussi le verrouillage
   automatique : Paramètres Windows > Personnalisation > Écran de verrouillage > Écran de veille,
   avec « À la reprise, afficher l'écran d'ouverture de session » coché (5 minutes, par exemple).
4. **Se déconnecter de LAKOLI** en fin de travail, surtout avant de laisser le PC à un collègue.

## 6. Accès depuis d'autres ordinateurs (plus tard)

Cette installation n'accepte que les connexions venues du PC lui-même. Le serveur n'écoute que sur
127.0.0.1. Si on l'ouvre au réseau sans autre changement, LAKOLI réactive de lui-même le code de
vérification et le signale dans son journal.

Pour ouvrir LAKOLI aux autres postes de l'école, il faudra, avec l'équipe LAKOLI :
1. configurer l'envoi d'e-mails dans `C:\LAKOLI\app\.env` (`MAIL_…`), pour que le code de
   vérification parvienne aux utilisateurs ;
2. repasser `OTP_ACTIF=true` et mettre l'adresse réseau du PC dans `APP_URL` ;
3. faire écouter le serveur web sur le réseau (`bind` dans `C:\LAKOLI\caddy\Caddyfile`) et ouvrir le
   port 8080 dans le pare-feu.

---
## 7. Sauvegardes

- **Automatiques** chaque jour à 18 h, dans `C:\LAKOLI-Sauvegardes`. Elles sont gardées 30 jours.
  Si le PC était éteint à 18 h, la sauvegarde se fait au démarrage suivant.
- **Hors du PC (indispensable)** : si le PC tombe en panne ou est volé, les sauvegardes en
  `C:\LAKOLI-Sauvegardes` sont perdues avec lui. Pour s'en protéger :
  1. Créez à la racine de la clé USB de sauvegarde un dossier nommé exactement **`LAKOLI-SAUVEGARDES`**.
  2. Laissez la clé branchée sur le PC. Chaque sauvegarde y est aussi copiée (10 dernières gardées).
  3. Idéalement, une fois par semaine, emportez la clé hors de l'école et échangez-la avec une seconde clé.
- **Vérifier** : le fichier `C:\LAKOLI-Sauvegardes\journal.txt` indique « OK » pour chaque sauvegarde.
- **Sauvegarder à la main** (par exemple avant une opération importante) : dans PowerShell (administrateur),
  ```
  powershell -ExecutionPolicy Bypass -File C:\LAKOLI\app\deploiement\sauvegarde.ps1
  ```

### Restaurer une sauvegarde

Cette opération remplace toutes les données actuelles. L'état actuel est sauvegardé juste avant.

1. Dans PowerShell (administrateur) :
   ```
   powershell -ExecutionPolicy Bypass -File C:\LAKOLI\app\deploiement\restaurer.ps1
   ```
   Sans autre précision, c'est la dernière sauvegarde qui est restaurée. Pour une autre sauvegarde,
   ajoutez `-Fichier E:\LAKOLI-SAUVEGARDES\lakoli-20261005-1800.dump`.
2. Tapez `OUI` pour confirmer. LAKOLI est arrêté pendant la restauration, puis redémarré tout seul.

---

## 8. Installer une nouvelle version

1. Préparez un nouveau paquet (`deploiement\preparer-paquet.ps1`), puis extrayez-le sur le PC.
2. Dans PowerShell (administrateur), depuis le dossier extrait :
   ```
   powershell -ExecutionPolicy Bypass -File installer.ps1 -MiseAJour
   ```
   Une sauvegarde est faite avant la mise à jour. Les données, les fichiers déposés et la
   configuration sont conservés. LAKOLI est indisponible une à deux minutes, puis redémarre tout seul.

### PC installé avec Laragon : passage à la nouvelle méthode

Les premières installations utilisaient Laragon, devenu payant (fenêtre de licence au démarrage).
La même commande `installer.ps1 -MiseAJour` (ci-dessus) fait le passage, **sans perte de données** :

1. une sauvegarde complète est faite d'abord ; si elle échoue, rien n'est modifié ;
2. Laragon est arrêté et ne démarre plus avec Windows ;
3. l'application passe de `C:\laragon\www\lakoli` à `C:\LAKOLI\app`, avec **la même base de
   données, le même `.env`** et tous les fichiers déposés (logos, photos, justificatifs) ;
4. les services Windows, la sauvegarde de 18 h et le raccourci LAKOLI sont mis en place.

À la fin, ouvrez LAKOLI par le raccourci et vérifiez quelques élèves et paiements. Par précaution,
Laragon et l'ancienne copie `C:\laragon\www\lakoli` restent sur le disque, inactifs. Quand tout
fonctionne depuis quelques jours, désinstallez Laragon : Paramètres Windows > Applications >
**Laragon** > Désinstaller (ou supprimez le dossier `C:\laragon` s'il n'apparaît pas dans la liste).

### Période d'essai (réservé à l'éditeur de LAKOLI)

Une nouvelle installation offre **un mois d'essai gratuit** (30 jours, `installer.ps1 -Essai 30` par
défaut ; `-Essai 0` = abonnement actif d'emblée). Pendant les **7 derniers jours**, un bandeau orange
prévient l'école sur toutes les pages. Une fois l'essai terminé, LAKOLI passe en **lecture seule** :
consultation et impression possibles, plus aucun enregistrement (paiement, élève, note…).

Dans PowerShell **en administrateur** (seul un administrateur peut lire la configuration), placé dans
`C:\LAKOLI\app` (`cd C:\LAKOLI\app`) :

| Commande | Effet |
|---|---|
| `..\php\php.exe artisan lakoli:essai` | Affiche l'état (jours restants) |
| `..\php\php.exe artisan lakoli:essai 30` | Essai de 30 jours à partir d'aujourd'hui (sert aussi à prolonger) |
| `..\php\php.exe artisan lakoli:essai --jusqu-au=2026-12-31` | Essai jusqu'à cette date incluse |
| `..\php\php.exe artisan lakoli:essai --activer` | Abonnement payé : plus aucune limite |
| `..\php\php.exe artisan lakoli:essai --suspendre` | Lecture seule immédiate |

L'école voit le changement en rechargeant la page (F5).

---

## 9. En cas de problème

| Problème | Solution |
|---|---|
| La page ne s'ouvre pas | Lancez `verifier.ps1` (section 3). Un service arrêté : outil **Services** de Windows, clic droit sur **LAKOLI Web** (ou **LAKOLI PHP 1**, **2**) > **Démarrer**. Sinon, redémarrez le PC. |
| Une fenêtre de licence Laragon apparaît | Ancienne installation : faites le passage à la nouvelle méthode (section 8). |
| Le raccourci LAKOLI a disparu du Bureau | Menu Démarrer > LAKOLI, ou relancer `installer.ps1 -MiseAJour` (le recrée). |
| Erreur « 500 » ou page blanche | Redémarrez le PC ; si l'erreur reste, voir le journal ci-dessous. |
| « Trop de tentatives » à la connexion | Attendez une minute, puis ressaisissez le mot de passe sans erreur. |
| Les autres ordinateurs n'ouvrent pas LAKOLI | Normal avec cette installation : voir section 6. |
| Un code de vérification est demandé | L'adresse ou l'accès n'est plus local : voir section 6 et le journal. |
| Mot de passe oublié (un utilisateur) | Le fondateur ou le directeur le réinitialise dans Paramètres > Utilisateurs & rôles. |
| Mot de passe PostgreSQL (`postgres`) oublié | Script `reinitialiser-mot-de-passe-postgres.ps1` : voir ci-dessous. |
| Détail technique d'une erreur | Journaux : `C:\LAKOLI\app\storage\logs\` (application, fichier du jour) et `C:\LAKOLI\logs\` (serveur web, PHP, services). |

### Mot de passe PostgreSQL oublié

Le mot de passe du compte `postgres` (choisi à l'installation de PostgreSQL) est demandé par
l'installation de LAKOLI, la restauration et la désinstallation. S'il est oublié, **les données ne
sont pas perdues** : le script du paquet en définit un nouveau.

1. PowerShell **en administrateur**, placé dans le dossier extrait du paquet (section 2, point 3).
2. Lancez :
   ```
   powershell -ExecutionPolicy Bypass -File reinitialiser-mot-de-passe-postgres.ps1
   ```
3. Tapez deux fois le nouveau mot de passe (rien ne s'affiche pendant la saisie) : 8 caractères au
   moins, lettres sans accent, chiffres et symboles du clavier.

Le script ouvre un court instant l'accès sans mot de passe **depuis ce PC seulement**, enregistre le
nouveau mot de passe, puis remet **toujours** la protection, même en cas d'erreur. Il termine par
« Mot de passe du compte « postgres » changé, PostgreSQL protégé. » ; sinon, il affiche en rouge ce
qui reste à faire. **Notez le nouveau mot de passe en lieu sûr.**

---

## 10. Désinstaller LAKOLI (ou repartir d'une installation propre)

À faire avant de réinstaller sur un PC où LAKOLI est déjà installé : l'installateur refuse d'écraser
une installation existante.

> **Attention : la désinstallation efface définitivement toutes les données** (élèves, paiements,
> comptes). Faites d'abord une sauvegarde (section 7) et copiez-la sur une clé USB si ces données
> doivent être conservées.

**PostgreSQL reste installé** : la nouvelle installation le réutilise. Le dossier des sauvegardes
`C:\LAKOLI-Sauvegardes` est conservé lui aussi : supprimez-le à la main si besoin.

### Méthode conseillée : le script

1. PowerShell **en administrateur**, placé dans le dossier extrait du paquet (section 2, point 3).
2. Lancez :
   ```
   powershell -ExecutionPolicy Bypass -File desinstaller-lakoli.ps1
   ```
3. Répondez **O** pour faire d'abord une sauvegarde (conseillé), puis tapez **SUPPRIMER** en majuscules
   pour confirmer. Le mot de passe du compte `postgres` est demandé.

Le script arrête et supprime les services LAKOLI, la base, le dossier `C:\LAKOLI`, la sauvegarde
automatique de 18 h et les raccourcis. Il supprime aussi une ancienne installation Laragon de LAKOLI
(`C:\laragon\www\lakoli` et son site Apache) si elle est encore là, sans toucher à Laragon lui-même.
Il affiche enfin un **bilan** : chaque ligne doit être verte. Une ligne rouge indique ce qui reste ;
corrigez-la et relancez le script, qui ne refait que ce qui manque.

### Méthode manuelle (si le script n'est pas disponible)

Toutes les commandes se tapent dans PowerShell **en administrateur** : menu Démarrer, tapez
`PowerShell`, clic droit > **Exécuter en tant qu'administrateur**.

> Ne retapez pas les commandes : copiez chaque ligne depuis ce guide, collez-la dans PowerShell par
> un **clic droit** et validez avec **Entrée**. Une seule faute de frappe (un guillemet, un espace,
> une barre `\`) suffit à faire échouer la commande.

**Étape 1 : arrêter et supprimer les services LAKOLI**
```
foreach ($s in "LAKOLI-Web", "LAKOLI-PHP-1", "LAKOLI-PHP-2") { Stop-Service $s -Force; sc.exe delete $s }
```

**Étape 2 : supprimer la base de données** (demande le mot de passe `postgres`). Ou, à la souris,
dans **pgAdmin 4** : Databases > clic droit sur **lakoli_db** > **Delete (Force)**, puis Login/Group
Roles > clic droit sur **lakoli_user** > **Delete**.
```
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -h 127.0.0.1 -U postgres -c "DROP DATABASE IF EXISTS lakoli_db WITH (FORCE)" -c "DROP ROLE IF EXISTS lakoli_user"
```

**Étape 3 : supprimer le dossier de LAKOLI** (message « fichier utilisé » : redémarrez le PC et
recommencez à l'étape 1)
```
Remove-Item C:\LAKOLI -Recurse -Force
```

**Étape 4 : supprimer la sauvegarde automatique de 18 h** (ou, à la souris : Planificateur de tâches >
Bibliothèque > clic droit sur **LAKOLI - Sauvegarde** > **Supprimer**)
```
Unregister-ScheduledTask -TaskName "LAKOLI - Sauvegarde" -Confirm:$false
```

**Étape 5 : supprimer les raccourcis LAKOLI**
```
Remove-Item "C:\Users\Public\Desktop\LAKOLI.*", "C:\ProgramData\Microsoft\Windows\Start Menu\Programs\LAKOLI.*" -Force
```

**Étape 6 (seulement pour une toute première installation de test) : règle de pare-feu** des
premiers paquets d'essai
```
Remove-NetFirewallRule -DisplayName "LAKOLI (port 8080)"
```

**Vérifier** : le dossier `C:\LAKOLI` n'existe plus, **LAKOLI Web** n'apparaît plus dans l'outil
**Services**, et **LAKOLI - Sauvegarde** n'apparaît plus dans le Planificateur de tâches. Vous pouvez
ensuite réinstaller (section 2).
