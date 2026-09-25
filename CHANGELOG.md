# Journal des modifications — LAKOLI (backend Laravel)

Historique des évolutions de l'API, de la plus récente à la plus ancienne.
Frontend correspondant : dépôt `lakoli-web`.

## 2026-09-25

### Performances
- Suppression des requêtes N+1 sur le statut de paiement :
  - `EcheanceEleve::montant_paye` utilise la somme préchargée (`withSum('paiements', 'montant')`) ou les paiements déjà chargés, et ne refait une requête qu'en dernier recours.
  - `Eleve` : nouvelle relation `fraisScolarite()` et scope `avecStatutPaiement()` pour calculer `statut_paiement` sur toute une liste en quelques requêtes.
  - Liste des élèves : 2 010 → 7 requêtes (10,7 s → ~1 s) ; statistiques par classe : 10 041 → 6 requêtes (21,5 s → ~1 s). Résultats vérifiés identiques.
- Migration `add_performance_indexes` : index sur les colonnes filtrées ou jointes (PostgreSQL n'indexe pas les clés étrangères) — élèves, inscriptions, échéances, paiements, bulletins, notes, évaluations, affectations, coefficients.
- Note : `montant_paye` est désormais renvoyé en nombre (`1500000`) et non plus en chaîne (`"1500000.00"`).

### Frais de scolarité
- Un paiement peut couvrir plusieurs tranches : l'échéance choisie est soldée d'abord, le surplus est réparti sur les tranches suivantes du même frais (par date limite). Un paiement par tranche touchée, sous une même référence ; la réponse de `POST /frais/paiements` contient `paiements[]` et `reference`. Montant plafonné au reste total du frais.
- `FraisService` : les grilles tarifaires de la classe (scolarité…, hors inscription/réinscription) sont appliquées automatiquement à l'inscription et à la réinscription d'un élève.
- Commande `php artisan lakoli:reparer-echeances [--dry-run]` : complète les échéances manquantes des grilles et des frais élèves, et crée les frais des élèves inscrits qui n'en avaient pas.

### Élèves
- La liste des élèves renvoie la date et le lieu de naissance, ainsi que le nom de l'établissement (pour la liste imprimable).
- Le lieu de naissance devient facultatif (migration).

## 2026-09-24
- Module Salaires des enseignants : migration, modèle, contrôleur et routes.

## 2026-09-22
- Correction de `FraisController::grilles` : classe récupérée via l'inscription active.

## 2026-09-21
- Inscription / réinscription : flux complet, reçu imprimable, statut de scolarité filtré, référence de paiement générée côté serveur.

## 2026-09-15
- Ordre pédagogique des classes et données réelles pour le tableau de bord.
- Étape finale de la migration vers les inscriptions : suppression de `classe_id` et `session_scolaire_id` sur `eleves`.

## 2026-09-14 — Migration vers les inscriptions
- Nouvelles tables `inscriptions` et `historique_classes_eleves`, modèles `Inscription` et `HistoriqueClasseEleve`.
- `InscriptionService` : inscription, réinscription, changement de classe.
- Reprise des données existantes et adaptation des contrôleurs (Élèves, Frais, Bulletins, Évaluations, Import Excel) aux inscriptions actives.

## 2026-09-11
- RBAC multi-établissement strict : 31 permissions, rôles instanciés par établissement, middleware, seeders, matrice de 5 rôles.

## 2026-08-31
- OTP désactivé en environnement local ; corrections des affectations et des bulletins.

## 2026-08-21
- Module Notes complet : saisie, soumission, validation, publication ; contrôleurs Périodes et Évaluations.

## 2026-08-20
- Emploi du temps en tableau avec export Excel ; gestion des classes.
- Module Frais de scolarité ; correction de routes API dupliquées.

## 2026-08-18
- Modules Enseignants et Matières complets.

## 2026-08-14
- Refonte de l'import Excel des élèves : dates multi-formats, détection des doublons dans le fichier.

## 2026-07-31
- Module Élèves complet (liste, fiche, ajout) avec gestion du tuteur ; contrôleur Classes.
- Isolation multi-établissement de l'API Élèves vérifiée.
- Correction : jeton manquant lors de la connexion depuis un appareil de confiance.

## 2026-07-17
- Module Bulletins : versionnement, calcul fidèle au bulletin réel, rang avec égalités.

## 2026-07-14
- Module Frais de scolarité : grilles tarifaires, échéances, calcul du solde et du statut.
- Statut de paiement de l'élève calculé sur les échéances.

## 2026-07-10 → 2026-07-13
- Modules Enseignants (heures supplémentaires), Matières (filières, coefficients), Notes (périodes, évaluations, historique de validation).
- Liaison Enseignant–Utilisateur, rôle Directeur.

## 2026-07-04 → 2026-07-07
- Mise en place Laravel + PostgreSQL, modèles Élèves, authentification et RBAC, middleware de permissions.
