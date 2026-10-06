# Installer LAKOLI dans une école

Ce guide installe LAKOLI sur **un seul PC de l'école**, qui garde toutes les données. LAKOLI
s'utilise **uniquement sur ce PC** (adresse http://127.0.0.1:8080). Chaque utilisateur (fondateur,
directeur, comptable…) s'y connecte avec son propre compte. Les autres ordinateurs du réseau ne
peuvent pas l'ouvrir (voir section 6).

Durée : environ 1 heure. Internet n'est nécessaire que pour télécharger les logiciels de l'étape 1.

---

## 0. Avant de partir

- **Le paquet** `lakoli-ecole-AAAAMMJJ-HHMM.zip` (préparé avec `deploiement\preparer-paquet.ps1`),
  sur une clé USB.
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

1. Copiez le paquet `.zip` sur le PC, par exemple sur le Bureau, puis : clic droit > **Extraire tout**.
2. Ouvrez le menu Démarrer, tapez `PowerShell`, puis clic droit > **Exécuter en tant qu'administrateur**.
3. Tapez (en adaptant le chemin du dossier extrait) :

   ```
   cd "$env:USERPROFILE\Desktop\lakoli-ecole-AAAAMMJJ-HHMM"
   powershell -ExecutionPolicy Bypass -File installer.ps1
   ```

4. Répondez aux questions :
   - le mot de passe du compte `postgres` (étape 1) ;
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
| Détail technique d'une erreur | Journal : `C:\laragon\www\lakoli\storage\logs\` (fichier du jour). |
