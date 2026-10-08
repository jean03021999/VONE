# Installer LAKOLI dans une école

Ce guide installe LAKOLI sur **un seul PC de l'école**, qui garde toutes les données. LAKOLI
s'utilise **uniquement sur ce PC** (adresse http://127.0.0.1:8080). Chaque utilisateur (fondateur,
directeur, comptable…) s'y connecte avec son propre compte. Les autres ordinateurs du réseau ne
peuvent pas l'ouvrir (voir section 6).

Durée : environ 1 heure. Internet n'est nécessaire que pour télécharger les logiciels de l'étape 1.

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
- Les installateurs de l'étape 1, déjà téléchargés, sur la même clé.
- **Une deuxième clé USB** (ou un disque externe), qui restera à l'école pour les sauvegardes.

**Le PC** : Windows 10 ou 11 64 bits, 8 Go de mémoire conseillés. Il doit être allumé
pendant les heures de travail. **Un onduleur est fortement conseillé** : une coupure de courant
pendant un enregistrement peut abîmer les données.

---

## 1. Logiciels à installer (une seule fois)

1. **PostgreSQL 18** (base de données) : <https://www.postgresql.org/download/windows/>, installateur EDB.
   - Gardez les choix proposés (port 5432).
   - **Notez le mot de passe du compte `postgres`** : il est demandé à l'étape 2. Gardez-le en lieu sûr.
   - À la fin, décochez « Stack Builder ».
2. **Laragon** (serveur web) : <https://laragon.org/download/>, version « Full », installé dans `C:\laragon`.
3. **Microsoft Visual C++ Redistributable 2015-2022 (x64)** :
   <https://aka.ms/vs/17/release/vc_redist.x64.exe>. PHP en a besoin.

---

## 2. Installer LAKOLI

> LAKOLI est déjà installé sur ce PC (ancienne version d'essai) ? Désinstallez-le d'abord : section 10.

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

## 3. Démarrer le serveur (Laragon)

1. Ouvrez **Laragon**.
2. Menu (clic droit dans la fenêtre) > **PHP** > **Version** > choisissez **php-8.5.8-nts-x64**.
3. Cliquez **Arrêter**, puis **Tout démarrer**.
4. Menu > **Préférences** : cochez **Démarrer Laragon avec Windows** et **Tout démarrer
   automatiquement**. Ainsi, LAKOLI redémarre tout seul après une coupure.

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
1. configurer l'envoi d'e-mails dans `C:\laragon\www\lakoli\.env` (`MAIL_…`), pour que le code de
   vérification parvienne aux utilisateurs ;
2. repasser `OTP_ACTIF=true` et mettre l'adresse réseau du PC dans `APP_URL` ;
3. faire écouter Apache sur le réseau et ouvrir le port 8080 dans le pare-feu.

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
  powershell -ExecutionPolicy Bypass -File C:\laragon\www\lakoli\deploiement\sauvegarde.ps1
  ```

### Restaurer une sauvegarde

Cette opération remplace toutes les données actuelles. L'état actuel est sauvegardé juste avant.

1. Dans Laragon, cliquez **Arrêter**.
2. Dans PowerShell (administrateur) :
   ```
   powershell -ExecutionPolicy Bypass -File C:\laragon\www\lakoli\deploiement\restaurer.ps1
   ```
   Sans autre précision, c'est la dernière sauvegarde qui est restaurée. Pour une autre sauvegarde,
   ajoutez `-Fichier E:\LAKOLI-SAUVEGARDES\lakoli-20261005-1800.dump`.
3. Tapez `OUI` pour confirmer, puis **Tout démarrer** dans Laragon.

---

## 8. Installer une nouvelle version

1. Préparez un nouveau paquet (`deploiement\preparer-paquet.ps1`), puis extrayez-le sur le PC.
2. Dans PowerShell (administrateur), depuis le dossier extrait :
   ```
   powershell -ExecutionPolicy Bypass -File installer.ps1 -MiseAJour
   ```
   Une sauvegarde est faite avant la mise à jour. Les données, les fichiers déposés et la
   configuration sont conservés.
3. Dans Laragon : **Arrêter**, puis **Tout démarrer**.

### Période d'essai (réservé à l'éditeur de LAKOLI)

Une nouvelle installation offre **un mois d'essai gratuit** (30 jours, `installer.ps1 -Essai 30` par
défaut ; `-Essai 0` = abonnement actif d'emblée). Pendant les **7 derniers jours**, un bandeau orange
prévient l'école sur toutes les pages. Une fois l'essai terminé, LAKOLI passe en **lecture seule** :
consultation et impression possibles, plus aucun enregistrement (paiement, élève, note…).

Dans PowerShell, dans `C:\laragon\www\lakoli` :

| Commande | Effet |
|---|---|
| `php artisan lakoli:essai` | Affiche l'état (jours restants) |
| `php artisan lakoli:essai 30` | Essai de 30 jours à partir d'aujourd'hui (sert aussi à prolonger) |
| `php artisan lakoli:essai --jusqu-au=2026-12-31` | Essai jusqu'à cette date incluse |
| `php artisan lakoli:essai --activer` | Abonnement payé : plus aucune limite |
| `php artisan lakoli:essai --suspendre` | Lecture seule immédiate |

L'école voit le changement en rechargeant la page (F5).

---

## 9. En cas de problème

| Problème | Solution |
|---|---|
| La page ne s'ouvre pas | Laragon est-il démarré ? Cliquez **Tout démarrer**. |
| Le raccourci LAKOLI a disparu du Bureau | Menu Démarrer > LAKOLI, ou relancer `installer.ps1 -MiseAJour` (le recrée). |
| Erreur « 500 » ou page blanche | Laragon > PHP > Version : **php-8.5.8-nts-x64** doit être choisi, puis Arrêter / Tout démarrer. |
| « Trop de tentatives » à la connexion | Attendez une minute, puis ressaisissez le mot de passe sans erreur. |
| Les autres ordinateurs n'ouvrent pas LAKOLI | Normal avec cette installation : voir section 6. |
| Un code de vérification est demandé | L'adresse ou l'accès n'est plus local : voir section 6 et le journal. |
| Mot de passe oublié (un utilisateur) | Le fondateur ou le directeur le réinitialise dans Paramètres > Utilisateurs & rôles. |
| Mot de passe PostgreSQL (`postgres`) oublié | Script `reinitialiser-mot-de-passe-postgres.ps1` : voir ci-dessous. |
| Détail technique d'une erreur | Journal : `C:\laragon\www\lakoli\storage\logs\` (fichier du jour). |

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

**Laragon, PostgreSQL et PHP restent installés** : la nouvelle installation les réutilise. Le dossier
des sauvegardes `C:\LAKOLI-Sauvegardes` est conservé lui aussi : supprimez-le à la main si besoin.

### Méthode conseillée : le script

1. PowerShell **en administrateur**, placé dans le dossier extrait du paquet (section 2, point 3).
2. Lancez :
   ```
   powershell -ExecutionPolicy Bypass -File desinstaller-lakoli.ps1
   ```
3. Répondez **O** pour faire d'abord une sauvegarde (conseillé), puis tapez **SUPPRIMER** en majuscules
   pour confirmer. Le mot de passe du compte `postgres` est demandé.

Le script arrête Laragon, supprime la base, l'application, le site Apache, la sauvegarde automatique
de 18 h et les raccourcis, puis affiche un **bilan** : chaque ligne doit être verte. Une ligne rouge
indique ce qui reste ; corrigez-la et relancez le script, qui ne refait que ce qui manque.

### Méthode manuelle (si le script n'est pas disponible)

Chaque étape peut se faire **à la souris (méthode A)** ou **par commande (méthode B)**. Les deux
donnent le même résultat : choisissez la plus confortable.

> **Conseil pour la méthode B** : ne retapez pas les commandes. Copiez chaque ligne depuis ce guide,
> puis collez-la dans PowerShell par un **clic droit** et validez avec **Entrée**. Une seule faute
> de frappe (un guillemet, un espace, une barre `\`) suffit à faire échouer la commande.
> PowerShell doit être ouvert **en administrateur** : menu Démarrer, tapez `PowerShell`, clic droit >
> **Exécuter en tant qu'administrateur**.

### Étape 1 : arrêter Laragon

- **A et B** : dans Laragon, cliquez **Arrêter**, puis fermez Laragon complètement : clic droit sur son
  icône près de l'horloge > **Quitter**. Sinon, des fichiers de LAKOLI restent ouverts et ne
  peuvent pas être supprimés.

### Étape 2 : supprimer la base de données

- **A. Avec pgAdmin** (installé avec PostgreSQL) :
  1. Ouvrez **pgAdmin 4** depuis le menu Démarrer, puis saisissez le mot de passe `postgres`.
  2. À gauche : **Servers** > **PostgreSQL 18** > **Databases**.
  3. Clic droit sur **lakoli_db** > **Delete (Force)** (ou **Delete**), puis confirmez.
  4. Toujours à gauche : **Login/Group Roles** > clic droit sur **lakoli_user** > **Delete**, puis confirmez.
- **B. Par commande** (demande le mot de passe `postgres`) :
  ```
  & "C:\Program Files\PostgreSQL\18\bin\psql.exe" -h 127.0.0.1 -U postgres -c "DROP DATABASE IF EXISTS lakoli_db WITH (FORCE)" -c "DROP ROLE IF EXISTS lakoli_user"
  ```

### Étape 3 : supprimer l'application

- **A. Explorateur de fichiers** : ouvrez `C:\laragon\www`, clic droit sur le dossier **lakoli** >
  **Supprimer**.
- **B. Par commande** :
  ```
  Remove-Item C:\laragon\www\lakoli -Recurse -Force
  ```

En cas de message « fichier utilisé par un autre programme », Laragon n'est pas complètement fermé :
reprenez l'étape 1.

### Étape 4 : supprimer le site Apache de LAKOLI

- **A. Explorateur de fichiers** : ouvrez `C:\laragon\etc\apache2\sites-enabled`, clic droit sur
  **lakoli-ecole.conf** > **Supprimer**.
- **B. Par commande** :
  ```
  Remove-Item C:\laragon\etc\apache2\sites-enabled\lakoli-ecole.conf -Force
  ```

### Étape 5 : supprimer la sauvegarde automatique de 18 h

- **A. Planificateur de tâches** : menu Démarrer, tapez `Planificateur de tâches` et ouvrez-le.
  À gauche, cliquez **Bibliothèque du Planificateur de tâches** ; au centre, clic droit sur
  **LAKOLI - Sauvegarde** > **Supprimer**, puis **Oui**.
- **B. Par commande** :
  ```
  Unregister-ScheduledTask -TaskName "LAKOLI - Sauvegarde" -Confirm:$false
  ```

### Étape 6 : supprimer les raccourcis LAKOLI

- **A. Explorateur de fichiers** : sur le Bureau, clic droit sur **LAKOLI** > **Supprimer**. Pour le
  menu Démarrer, collez cette adresse dans la barre d'adresse de l'Explorateur, puis supprimez
  **LAKOLI** : `C:\ProgramData\Microsoft\Windows\Start Menu\Programs`
- **B. Par commande** :
  ```
  Remove-Item "C:\Users\Public\Desktop\LAKOLI.*", "C:\ProgramData\Microsoft\Windows\Start Menu\Programs\LAKOLI.*" -Force
  ```

### Étape 7 (seulement pour une toute première installation de test) : règle de pare-feu

Les premiers paquets d'essai créaient une règle de pare-feu « LAKOLI (port 8080) ». Les paquets
actuels n'en créent plus. Si elle existe :

- **A. Pare-feu** : menu Démarrer, tapez `Pare-feu Windows Defender avec fonctions avancées de
  sécurité`. À gauche, **Règles de trafic entrant** ; clic droit sur **LAKOLI (port 8080)** >
  **Supprimer**.
- **B. Par commande** :
  ```
  Remove-NetFirewallRule -DisplayName "LAKOLI (port 8080)"
  ```

### Vérifier

- Le dossier `C:\laragon\www\lakoli` n'existe plus ;
- dans pgAdmin, **lakoli_db** n'apparaît plus sous **Databases** ;
- **LAKOLI - Sauvegarde** n'apparaît plus dans le Planificateur de tâches.

Vous pouvez ensuite réinstaller (section 2) puis redémarrer Laragon (section 3).
