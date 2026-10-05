# Installer LAKOLI dans une école

Ce guide installe LAKOLI sur **un PC de l'école qui sert de serveur**. Ce PC garde toutes les
données. On peut utiliser LAKOLI directement sur ce PC, ou depuis d'autres ordinateurs (direction,
comptabilité…) connectés au même réseau (câble ou Wi-Fi), avec un simple navigateur.

Durée : environ 1 heure. Internet n'est nécessaire que pour télécharger les logiciels de l'étape 1.

---

## 0. Avant de partir

- **Le paquet** `lakoli-ecole-AAAAMMJJ-HHMM.zip` (préparé avec `deploiement\preparer-paquet.ps1`),
  sur une clé USB.
- Les installateurs de l'étape 1, déjà téléchargés, sur la même clé.
- **Une deuxième clé USB** (ou un disque externe), qui restera à l'école pour les sauvegardes.

**Le PC serveur** : Windows 10 ou 11 64 bits, 8 Go de mémoire conseillés. Il doit être allumé
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
     les autres.

À la fin, le script affiche les **adresses de LAKOLI**. Notez-les.

## 3. Démarrer le serveur (Laragon)

1. Ouvrez **Laragon**.
2. Menu (clic droit dans la fenêtre) > **PHP** > **Version** > choisissez **php-8.5.8-nts-x64**.
3. Cliquez **Arrêter**, puis **Tout démarrer**.
4. Menu > **Préférences** : cochez **Démarrer Laragon avec Windows** et **Tout démarrer
   automatiquement**. Ainsi, LAKOLI redémarre tout seul après une coupure.

## 4. Première connexion

1. Sur le PC serveur, ouvrez Chrome ou Edge à l'adresse **http://127.0.0.1:8080**.
2. Choisissez le profil **Fondateur**, puis saisissez l'e-mail et le mot de passe de l'étape 2.
3. Dans **Paramètres** :
   - **Établissement** : complétez la fiche (logo, adresse, agrément, slogan, WhatsApp de la comptabilité) ;
   - **Sessions** : créez l'année scolaire en cours ;
   - **Utilisateurs** : créez les comptes du directeur, du comptable, etc. Un mot de passe provisoire
     s'affiche pour chacun : transmettez-le à la personne.
4. Créez ensuite les classes, les frais, puis inscrivez les élèves.

---

## 5. Utiliser LAKOLI depuis d'autres ordinateurs

1. Les ordinateurs doivent être sur **le même réseau** (même Wi-Fi ou même routeur) que le PC serveur.
2. Sur le PC serveur, le réseau doit être déclaré **privé** : Paramètres Windows > Réseau et Internet
   > (votre réseau) > Type de profil réseau : **Privé**. Sinon, le pare-feu bloque les autres postes.
3. Sur chaque autre ordinateur, ouvrez l'adresse affichée à la fin de l'installation, par exemple
   **http://192.168.1.10:8080**. Ajoutez-la aux favoris.
4. **Adresse fixe conseillée** : l'adresse du PC serveur peut changer après un redémarrage du routeur.
   Pour l'éviter, réservez-lui une adresse dans la configuration du routeur (« réservation DHCP »).
   Pour retrouver l'adresse actuelle : sur le PC serveur, tapez `ipconfig` dans PowerShell, ligne
   « Adresse IPv4 ».

---

## 6. Sauvegardes

- **Automatiques** chaque jour à 18 h, dans `C:\LAKOLI-Sauvegardes`. Elles sont gardées 30 jours.
  Si le PC était éteint à 18 h, la sauvegarde se fait au démarrage suivant.
- **Hors du PC (indispensable)** : si le PC tombe en panne ou est volé, les sauvegardes en
  `C:\LAKOLI-Sauvegardes` sont perdues avec lui. Pour s'en protéger :
  1. Créez à la racine de la clé USB de sauvegarde un dossier nommé exactement **`LAKOLI-SAUVEGARDES`**.
  2. Laissez la clé branchée sur le PC serveur. Chaque sauvegarde y est aussi copiée (10 dernières gardées).
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

## 7. Installer une nouvelle version

1. Préparez un nouveau paquet (`deploiement\preparer-paquet.ps1`), puis extrayez-le sur le PC serveur.
2. Dans PowerShell (administrateur), depuis le dossier extrait :
   ```
   powershell -ExecutionPolicy Bypass -File installer.ps1 -MiseAJour
   ```
   Une sauvegarde est faite avant la mise à jour. Les données, les fichiers déposés et la
   configuration sont conservés.
3. Dans Laragon : **Arrêter**, puis **Tout démarrer**.

---

## 8. En cas de problème

| Problème | Solution |
|---|---|
| La page ne s'ouvre pas sur le PC serveur | Laragon est-il démarré ? Cliquez **Tout démarrer**. |
| Erreur « 500 » ou page blanche | Laragon > PHP > Version : **php-8.5.8-nts-x64** doit être choisi, puis Arrêter / Tout démarrer. |
| Les autres postes n'accèdent pas à LAKOLI | Réseau du PC serveur en **Privé** (section 5) ; même Wi-Fi ; adresse IP à jour (`ipconfig`). |
| Mot de passe oublié (un utilisateur) | Le fondateur ou le directeur le réinitialise dans Paramètres > Utilisateurs. |
| Détail technique d'une erreur | Journal : `C:\laragon\www\lakoli\storage\logs\` (fichier du jour). |
