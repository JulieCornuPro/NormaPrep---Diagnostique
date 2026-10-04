# NormaPrep Diagnostic — Note de cadrage

> Statut : **projet**, à valider avant tout développement.
> Version 0.1, octobre 2026.

---

## 1. Objectif

Outil de **diagnostic de la maturité cyber** d'une organisation, sur trois grands
thèmes : **Gouvernance**, **Infrastructure**, **Applicatif**.

Il est mené **par un consultant**, en entretien avec le client. Le client ne
passe jamais le diagnostic lui-même.

Le diagnostic sert de **brique d'entrée** : il produit une photographie de la
maturité et une feuille de route priorisée, d'où découlent d'autres prestations.

### Principe directeur : mesurer un écart, pas seulement un score

Un score isolé (« 2,3 / 5 ») parle peu à un dirigeant. Un écart à une cible
justifiée (« 2,3 alors que NIS2 vous impose au moins 3 ») déclenche une
décision. Le diagnostic produit donc, pour chaque sous-thème :

- une **maturité constatée** (réponses aux questions) ;
- une **maturité cible**, déduite du profil réglementaire du client ;
- l'**écart** entre les deux, qui ordonne les recommandations.

---

## 2. Périmètre

### Inclus dans la première version

| Fonction | Détail |
|---|---|
| Fiche mission | Client (nom facultatif ou pseudonyme), périmètre, interlocuteurs, date |
| Profilage réglementaire | Questions sur l'organisation → réglementations applicables → profondeur et cibles par sous-thème |
| Questionnaire | Une réponse 0–5 par question, un commentaire, une case « Non applicable » ; sauvegarde au fil de l'eau |
| Restitution écran | Radar (une couleur par grand thème, tracé cible), scores par sous-thème, par thème, global |
| Recommandations | Déclenchées par seuil, priorisées par écart, reliées au catalogue de prestations |
| Rapport PDF | Charte CARTO, généré à la demande, jamais stocké |
| Rôle consultant | Chaque consultant ne voit que ses diagnostics ; l'administrateur voit tout |
| Confidentialité | Suppression définitive, durée de conservation configurable, purge automatique |

### Reporté à plus tard

- Historique et comparaison de deux diagnostics d'un même client.
- Indicateur « niveau vérifié / déclaré » par réponse.
- Choix de plusieurs réponses par question, réponses pondérées plus fines.
- Accès du client à son propre rapport en ligne.

---

## 3. Acteurs et rôles

| Rôle | Identifiant WordPress | Droits |
|---|---|---|
| Consultant | rôle `npd_consultant`, capacité `npd_mener_diagnostic` | Créer, remplir, finaliser, exporter, supprimer **ses** diagnostics |
| Administrateur | capacité `npd_gerer` (ajoutée à `administrator`) | Tout ce qui précède sur tous les diagnostics, import du référentiel, réglages (conservation, prestations) |

Le rôle consultant est **ajouté** à l'utilisateur (comme le fait NormaPrep Quiz
pour son propre rôle), sans retirer ses autres rôles. Une personne peut donc
être à la fois cliente du quiz et consultante.

Il n'y a **pas de vente** du diagnostic via WooCommerce : l'attribution du rôle
est manuelle, par l'administrateur.

---

## 4. Parcours

```
 1. Fiche mission ──► 2. Profilage ──► 3. Questionnaire ──► 4. Résultats ──► 5. Rapport PDF
                         │                    ▲
                         └── profondeur ──────┘
                             et cibles
```

1. **Fiche mission** — créée par le consultant ; le diagnostic est en statut
   `brouillon`.
2. **Profilage réglementaire** — une dizaine de questions sur l'organisation.
   Le résultat est affiché et **modifiable** par le consultant (il peut forcer
   ou retirer une réglementation, avec justification).
3. **Questionnaire** — navigation par grand thème puis par sous-thème, sur le
   modèle de l'écran d'examen du quiz. Indicateur de progression. Chaque
   réponse est enregistrée immédiatement.
4. **Résultats** — accessibles dès que toutes les questions retenues ont une
   réponse ou sont marquées N/A. Le consultant **finalise** : le diagnostic
   passe en statut `finalise` et n'est plus modifiable (il peut être rouvert
   explicitement).
5. **Rapport PDF** — téléchargé à la demande.

---

## 5. Profilage réglementaire

Inspiré des simulateurs réglementaires du marché (ex. Holirisk). Il remplit
deux rôles :

1. **déterminer la profondeur** du questionnaire par sous-thème ;
2. **fixer la maturité cible** par sous-thème.

> Le résultat est **indicatif** et ne constitue pas un avis juridique ; le
> rapport le mentionne.

### 5.1 Questions de profilage (première liste)

| Code | Question | Type |
|---|---|---|
| P01 | Effectif | tranches : < 50 / 50–249 / ≥ 250 |
| P02 | Chiffre d'affaires annuel | tranches : < 10 M€ / 10–50 M€ / > 50 M€ |
| P03 | Secteur d'activité | liste (secteurs des annexes I et II de NIS2 + « autre ») |
| P04 | Entité financière au sens de DORA (banque, assurance, PSP, prestataire TIC critique…) | oui / non |
| P05 | Traite des données personnelles | oui / non |
| P06 | Héberge des données de santé pour le compte de tiers | oui / non |
| P07 | Met sur le marché des produits comportant des éléments numériques | oui / non |
| P08 | Traite, stocke ou transmet des données de cartes de paiement | oui / non |
| P09 | Désignée OIV / OSE, ou fournisseur d'un tel opérateur | oui / non |
| P10 | Développe ou fait développer des applications | non / interne uniquement / exposées à des clients |
| P11 | Recours significatif au cloud ou à l'infogérance | oui / non |

P10 et P11 ne déclenchent pas de réglementation : ils ajustent la **profondeur**
(ex. P10 = « non » propose de marquer tout le thème Applicatif N/A).

### 5.2 Réglementations et référentiels couverts

| Code | Déclenchement (simplifié, à affiner) |
|---|---|
| `SOCLE` | Toujours — hygiène de base (guide ANSSI) |
| `RGPD` | P05 = oui |
| `NIS2-EI` | Secteur annexe I ou II et (P01 ≥ 50 ou P02 ≥ 10 M€) |
| `NIS2-EE` | Secteur annexe I et (P01 ≥ 250 ou P02 > 50 M€), ou cas désignés |
| `DORA` | P04 = oui |
| `HDS` | P06 = oui |
| `CRA` | P07 = oui |
| `PCI-DSS` | P08 = oui |
| `LPM` | P09 = oui |

### 5.3 Profondeur et cible

Chaque réglementation porte, pour chaque sous-thème concerné, un couple
**(profondeur requise, cible)** :

- **Profondeur** : `essentiel` < `standard` < `renforce`. Une question est
  posée si son niveau de profondeur est inférieur ou égal à la profondeur
  requise pour son sous-thème.
- **Cible** : niveau de maturité 0–5 attendu.

Quand plusieurs réglementations s'appliquent, on retient pour chaque
sous-thème **le maximum** des profondeurs et **le maximum** des cibles, et l'on
garde la trace de la réglementation qui fixe la cible (affichée dans le
rapport : « cible 4 — DORA »).

Exemple illustratif (valeurs à construire dans le pipeline de contenu) :

| Sous-thème | SOCLE | RGPD | NIS2-EI | NIS2-EE | DORA |
|---|---|---|---|---|---|
| G3 Gestion des risques | essentiel / 2 | standard / 3 | standard / 3 | renforce / 4 | renforce / 4 |
| I6 Sauvegardes | essentiel / 2 | — | standard / 3 | standard / 3 | renforce / 4 |
| A7 Protection des données applicatives | essentiel / 2 | standard / 3 | standard / 3 | standard / 3 | standard / 3 |

---

## 6. Arborescence des thèmes (proposition)

Les références indiquent d'où viennent les exigences ; elles figureront dans
le référentiel et pourront être citées dans le rapport.

### G — Gouvernance

| Code | Sous-thème | Références principales |
|---|---|---|
| G1 | Pilotage et organisation de la sécurité | ISO 27001 §5 ; NIST CSF GV.RR |
| G2 | Politiques et cadre documentaire | ISO 27002 5.1 ; GV.PO |
| G3 | Gestion des risques | ISO 27001 §6.1, §8.2 ; GV.RM ; EBIOS RM |
| G4 | Conformité et obligations légales | ISO 27002 5.31–5.36 ; GV.OC |
| G5 | Ressources humaines et sensibilisation | ISO 27002 6.x ; PR.AT ; ANSSI 1–3 |
| G6 | Fournisseurs et chaîne d'approvisionnement | ISO 27002 5.19–5.23 ; GV.SC |
| G7 | Gestion des incidents | ISO 27002 5.24–5.28 ; RS |
| G8 | Continuité et résilience | ISO 27002 5.29–5.30 ; RC |
| G9 | Mesure, audit et amélioration continue | ISO 27001 §9–10 ; GV.OV |

### I — Infrastructure

| Code | Sous-thème | Références principales |
|---|---|---|
| I1 | Inventaire et cartographie | ISO 27002 5.9 ; ID.AM ; ANSSI 4–5 |
| I2 | Identités, accès et comptes à privilèges | ISO 27002 5.15–5.18, 8.2–8.5 ; PR.AA ; ANSSI 7–13 |
| I3 | Sécurité des postes et serveurs | ISO 27002 8.1, 8.7, 8.9 ; PR.PS |
| I4 | Vulnérabilités et correctifs | ISO 27002 8.8 ; ID.RA ; ANSSI 34–35 |
| I5 | Sécurité réseau et accès distants | ISO 27002 8.20–8.22 ; PR.IR |
| I6 | Sauvegardes et restauration | ISO 27002 8.13 ; ANSSI 37 |
| I7 | Journalisation et supervision | ISO 27002 8.15–8.16 ; DE.CM |
| I8 | Cloud et services externalisés | ISO 27002 5.23 |
| I9 | Sécurité physique | ISO 27002 7.x |

### A — Applicatif

Aligné sur les cinq fonctions d'OWASP SAMM, complété par ASVS.

| Code | Sous-thème | Références principales |
|---|---|---|
| A1 | Exigences et conception sécurisée | SAMM Design ; ISO 27002 8.25–8.27 |
| A2 | Pratiques de développement sécurisé | SAMM Governance/Implementation ; ISO 27002 8.28 |
| A3 | Chaîne logicielle et dépendances (CI/CD, SCA, SBOM) | SAMM Implementation ; CRA |
| A4 | Gestion des secrets et de la configuration | ASVS V14 ; ISO 27002 8.9 |
| A5 | Tests de sécurité (SAST, DAST, tests d'intrusion) | SAMM Verification ; ISO 27002 8.29 |
| A6 | Authentification, sessions et contrôle d'accès applicatifs | ASVS V2–V4 |
| A7 | Protection des données applicatives | ASVS V6, V8 ; RGPD art. 25, 32 |
| A8 | Exploitation et vulnérabilités applicatives (divulgation, WAF, logs) | SAMM Operations ; CRA |

**Volume visé** : 3 à 6 questions par sous-thème, soit environ 80 à 120
questions dans le référentiel complet, dont **30 à 60 posées** selon le profil.

---

## 7. Questions et échelle de maturité

### 7.1 Échelle 0–5

| Niveau | Libellé | Sens général |
|---|---|---|
| 0 | Inexistant | Rien n'est fait |
| 1 | Initial | Pratiques ponctuelles, dépendantes des personnes |
| 2 | Reproductible | Pratiques régulières mais non formalisées |
| 3 | Défini | Formalisé, documenté, appliqué à l'ensemble du périmètre |
| 4 | Géré | Mesuré, contrôlé, revu périodiquement |
| 5 | Optimisé | Amélioration continue, automatisation, intégration aux processus métier |

### 7.2 Règle de rédaction

**Chaque question décrit concrètement ses six niveaux.** Le consultant choisit
le niveau dont la description correspond à la situation observée. C'est ce
qui rend deux diagnostics comparables d'un consultant à l'autre.

Chaque question porte aussi : une référence stable, sa profondeur
(`essentiel` / `standard` / `renforce`), un poids (1 par défaut), ses
références normatives, une aide à l'entretien (ce qu'il faut demander à voir).

### 7.3 Exemples

**G3-Q01 — Analyse de risques** (essentiel)

| Niveau | Description |
|---|---|
| 0 | Aucune analyse de risques n'a été réalisée |
| 1 | Les risques sont identifiés de manière informelle, au cas par cas |
| 2 | Une analyse a été réalisée une fois, sans méthode reconnue ni mise à jour |
| 3 | Analyse selon une méthode reconnue (EBIOS RM, ISO 27005), documentée, avec plan de traitement |
| 4 | Mise à jour au moins annuelle et à chaque changement majeur ; plan de traitement suivi |
| 5 | Intégrée aux projets et décisions métier ; indicateurs de risque suivis par la direction |

*À demander à voir : le dernier rapport d'analyse et le plan de traitement.*

**I6-Q01 — Sauvegardes** (essentiel)

| Niveau | Description |
|---|---|
| 0 | Aucune sauvegarde |
| 1 | Sauvegardes ponctuelles et manuelles |
| 2 | Sauvegardes automatisées des systèmes principaux |
| 3 | Automatisées, avec au moins une copie hors ligne ou immuable |
| 4 | Restaurations testées régulièrement, résultats consignés |
| 5 | Testées, supervisées, objectifs RPO/RTO définis et intégrés au plan de continuité |

**A4-Q01 — Secrets applicatifs** (standard)

| Niveau | Description |
|---|---|
| 0 | Secrets en clair dans le code source |
| 1 | Secrets sortis du code mais stockés dans des fichiers de configuration non protégés |
| 2 | Variables d'environnement ou fichiers protégés, sans rotation |
| 3 | Coffre-fort de secrets centralisé, accès restreint |
| 4 | Rotation régulière, détection automatique de secrets dans les dépôts |
| 5 | Secrets dynamiques à durée de vie courte, audit des accès, rotation automatisée |

---

## 8. Règles de calcul

1. **Question** : niveau 0–5 saisi. Une question **N/A** est exclue de tous les
   calculs. Une question non posée (profondeur insuffisante) n'existe pas pour
   ce diagnostic.
2. **Sous-thème** : moyenne pondérée (par le poids des questions) des niveaux
   des questions applicables. Si toutes sont N/A, le sous-thème est N/A.
3. **Grand thème** : moyenne pondérée des sous-thèmes non N/A (poids des
   sous-thèmes, 1 par défaut).
4. **Score global** : moyenne des trois grands thèmes non N/A (poids égaux par
   défaut, réglables).
5. **Cible** : voir §5.3. Cible d'un thème = moyenne des cibles de ses
   sous-thèmes non N/A ; même règle pour la cible globale.
6. **Écart** = cible − score (positif = en dessous de la cible).
7. Affichage arrondi à **une décimale** ; les calculs se font sans arrondi.

**Figer le contexte.** Au moment de la finalisation, le diagnostic enregistre
la version du référentiel utilisée et une copie des cibles calculées. Une
évolution ultérieure du référentiel ne modifie donc jamais un diagnostic
finalisé.

---

## 9. Restitution

### 9.1 Écran de résultats

- **Radar** : un axe par sous-thème, regroupés par grand thème, chaque groupe
  dans sa couleur ; deux tracés : constaté (plein) et cible (pointillé).
- **Trois cartes** : score et cible par grand thème, plus le score global.
- **Tableau** par sous-thème : score, cible, écart, réglementation qui fixe la
  cible.
- **Recommandations** priorisées.

Couleurs proposées, tirées des tokens CARTO :

| Grand thème | Token | Valeur |
|---|---|---|
| Gouvernance | `--carto-amber` | `#E8B84B` |
| Infrastructure | `--carto-teal` | `#00CFCF` |
| Applicatif | `--carto-orange-lt` | `#FF9466` |

> Point de vigilance : avec 26 sous-thèmes, le radar est dense. Si la lecture
> est difficile, on ajoutera un radar par grand thème (8–9 axes) en plus du
> radar global.

### 9.2 Recommandations

Bibliothèque gérée dans le référentiel. Chaque recommandation :

- est rattachée à une **question** (ou à un sous-thème) ;
- se déclenche quand le niveau constaté est **inférieur à un seuil** ;
- porte une **priorité** calculée (écart × poids), un **effort** estimé
  (faible / moyen / élevé) et un indicateur **quick win** ;
- peut être reliée à une **prestation** du catalogue (PSSI, analyse de risques
  EBIOS RM, test d'intrusion, accompagnement ISO 27001, mise en conformité
  NIS2, sensibilisation…).

Le rapport présente ainsi une **feuille de route** : quick wins, puis actions
à 6 mois, puis actions à 12 mois et plus, chacune avec l'offre correspondante.

### 9.3 Rapport PDF

- Généré **côté serveur** avec Dompdf, embarqué dans le plugin (dossier
  `vendor/`, aucune dépendance externe à l'exécution).
- **Charte CARTO** : polices Syncopate (titres), Rajdhani (texte), Inconsolata
  (références), embarquées dans le plugin ; mêmes couleurs d'accent.
- Variante **fond clair** pour l'impression (le fond navy du site consomme
  beaucoup d'encre), avec bandeaux et accents navy/teal. *À valider.*
- Radar produit en **SVG simple** (polygones) côté serveur. Le support SVG de
  Dompdf est limité : à valider tôt par un prototype.
- Contenu : page de garde, synthèse dirigeant (une page), profil
  réglementaire, résultats, détail par thème, feuille de route, annexes
  (réponses et commentaires, mention « indicatif »).
- **Jamais stocké** : produit en mémoire et envoyé au navigateur.

---

## 10. Confidentialité et sécurité

Les réponses décrivent les faiblesses de sécurité d'une organisation : ce sont
des données sensibles.

| Mesure | Détail |
|---|---|
| Minimisation | Nom du client facultatif ; un pseudonyme suffit. Pas de données nominatives sur les interlocuteurs au-delà de la fonction |
| Cloisonnement | Toute lecture ou écriture vérifie que le diagnostic appartient au consultant connecté (ou que l'utilisateur a `npd_gerer`) |
| Suppression | Suppression **définitive** (diagnostic et réponses) par le consultant, avec confirmation |
| Conservation | Durée réglable par l'administrateur (proposition : 12 mois après finalisation) ; purge quotidienne par WP-Cron, avec préavis affiché au consultant |
| PDF | Jamais stocké sur le serveur |
| Saisie | Avertissement dans le champ commentaire : ne pas y saisir de mot de passe, d'adresse IP ou d'identifiant technique |
| Protections WordPress | Nonces sur toutes les actions, échappement des sorties, requêtes préparées |
| Désinstallation | Les tables et données sont supprimées à la désinstallation du plugin (pas à la simple désactivation) |

À vérifier hors code : mentions légales et registre des traitements (le site
agit en sous-traitant pour le compte des consultants), information du client.

---

## 11. Architecture technique

### 11.1 Plugin autonome

- Plugin **`normaprep-diagnostic`**, dans ce dépôt, **sans dépendance à
  NormaPrep Quiz** : il doit continuer à fonctionner si le quiz disparaît.
- Préfixe des classes `NPD_`, des tables `wp_npd_*`, des options `npd_*`.
- Les composants visuels utiles du quiz (écran de questions, sélecteurs,
  confirmations, cartes) sont **copiés et adaptés**, pas importés.
- Les styles utilisent les tokens du thème **avec une valeur de repli**, par
  exemple `var(--carto-teal, #00CFCF)` : ils suivent le thème CARTO s'il est
  actif, et restent corrects sans lui.
- Même organisation que le quiz : `admin/`, `public/`, `includes/`,
  `database/`, `assets/`, `data/`.

### 11.2 Modèle de données (première version)

**Référentiel** (alimenté par l'import) :

| Table | Contenu |
|---|---|
| `npd_referentiel` | Version du référentiel importé, date |
| `npd_theme` | G, I, A : libellé, couleur, ordre, poids |
| `npd_sous_theme` | Code, thème, libellé, ordre, poids |
| `npd_question` | Référence stable (`G3-Q01`), sous-thème, énoncé, aide, profondeur, poids, références normatives |
| `npd_niveau` | Question, niveau 0–5, description |
| `npd_reglementation` | Code, libellé, texte d'explication |
| `npd_profil_question` | Questions de profilage et leurs choix |
| `npd_regle` | Conditions sur les réponses de profilage → réglementation |
| `npd_exigence` | Réglementation × sous-thème → profondeur, cible |
| `npd_recommandation` | Question ou sous-thème, seuil, texte, effort, quick win, prestation |
| `npd_prestation` | Catalogue des offres (libellé, description, lien) |

**Diagnostics** (saisis par les consultants) :

| Table | Contenu |
|---|---|
| `npd_diagnostic` | Consultant, client (pseudonyme), périmètre, statut, version du référentiel, réponses de profilage, réglementations retenues et ajustements, cibles figées, dates (création, finalisation, purge prévue) |
| `npd_reponse` | Diagnostic, question, niveau, N/A, commentaire, date |

### 11.3 Alimentation du contenu

Même principe que les scénarios du quiz : des **fichiers JSON** dans `data/`,
un **import rejouable** fondé sur des références stables (`G3`, `G3-Q01`,
`G3-Q01-R1`), et un **pipeline de production du contenu**. Ce pipeline fera
l'objet d'une conversation dédiée.

Découpage envisagé :

```
data/
  _referentiel.json        <- version, thèmes, échelle
  _profilage.json          <- questions de profilage, règles, réglementations
  _exigences.json          <- matrice réglementation × sous-thème
  _prestations.json        <- catalogue des offres
  G_gouvernance/
    G1_pilotage.json       <- sous-thème : questions, niveaux, recommandations
    ...
  I_infrastructure/
  A_applicatif/
```

---

## 12. Découpage en lots

| Lot | Contenu |
|---|---|
| 0 | Cadrage (ce document) |
| 1 | Socle du plugin : activation, tables, rôle consultant, réglages, import du référentiel, suppression et purge |
| 2 | Contenu : pipeline et premier référentiel (conversation dédiée, peut avancer en parallèle) |
| 3 | Espace consultant : liste des diagnostics, fiche mission, profilage |
| 4 | Questionnaire : navigation, saisie, sauvegarde, progression, finalisation |
| 5 | Calcul et restitution écran : scores, cibles, radar, recommandations |
| 6 | Rapport PDF |
| Plus tard | Historique et comparaison, niveau de preuve, accès client |

---

## 13. Points à trancher

1. Variante **fond clair** du PDF : d'accord ?
2. **Couleurs** des trois grands thèmes (§9.1).
3. **Durée de conservation** par défaut : 12 mois après finalisation ?
4. **Arborescence** des sous-thèmes (§6) : à valider ou ajuster.
5. **Catalogue de prestations** : liste des offres à relier aux
   recommandations.
6. Seuils et règles de **déclenchement réglementaire** (§5.2) : à préciser
   dans le pipeline de contenu.
