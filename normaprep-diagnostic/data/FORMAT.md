# Format des fichiers du référentiel

> Contrat entre le **pipeline de contenu** et le plugin. Le validateur
> (`database/class-npd-validateur.php`) applique exactement ces règles.
> Exemple complet : les fichiers livrés dans ce dossier `data/` (référentiel
> de démonstration, version `demo-0.1`).

## Organisation

```
normaprep-diagnostic/data/
  _referentiel.json        version, thèmes                         obligatoire
  _profilage.json          questions de profilage et choix          obligatoire
  _reglementations.json    réglementations, règles, exigences       obligatoire
  _prestations.json        catalogue des offres                     facultatif
  G_gouvernance/           un dossier par thème (nom libre)
    G1_pilotage.json       un fichier par sous-thème (nom libre)
    ...
  I_infrastructure/
  A_applicatif/
```

- Les fichiers de racine commencent par `_`.
- Tous les `.json` des sous-dossiers sont lus comme des sous-thèmes (sauf
  ceux qui commencent par `_` ou `.`). Le nom des dossiers et des fichiers
  n'a pas d'importance : c'est le contenu qui fait foi.
- Encodage UTF-8.

## Règles générales

- **Codes et références** : lettres, chiffres, `-`, `_` et `.` uniquement.
  Ils sont **stables** : c'est par eux que l'import retrouve un élément
  existant. Renommer un code revient à supprimer l'élément et à en créer un
  autre.
- **Tout ou rien** : à la moindre erreur, l'import est refusé et la base
  n'est pas modifiée. La page *NormaPrep Diagnostic › Référentiel* affiche la
  liste des erreurs, avec le fichier et l'emplacement.
- **Retrait** : un élément absent des fichiers est supprimé, ou désactivé
  s'il est déjà utilisé par un diagnostic. Il est réactivé s'il réapparaît.
- **Avertissements** : signalés mais non bloquants (sous-thème sans
  question, sous-thème qu'aucune réglementation n'exige…).

## `_referentiel.json`

```json
{
  "version": "2026.1",
  "libelle": "Référentiel NormaPrep Diagnostic",
  "themes": [
    { "code": "G", "libelle": "Gouvernance",    "couleur": "--carto-amber",     "ordre": 1, "poids": 1 },
    { "code": "I", "libelle": "Infrastructure", "couleur": "--carto-teal",      "ordre": 2 },
    { "code": "A", "libelle": "Applicatif",     "couleur": "--carto-orange-lt", "ordre": 3 }
  ]
}
```

| Champ | Obligatoire | Règle |
|---|---|---|
| `version` | oui | 30 caractères max. Chaque import enregistre sa version et en fait la version active ; un nouveau diagnostic s'y rattache |
| `themes[].code` | oui | 10 caractères max, unique |
| `themes[].libelle` | oui | |
| `themes[].couleur`, `ordre`, `poids` | non | `poids` > 0, 1 par défaut |

## Fichier de sous-thème

```json
{
  "sous_theme": { "code": "G3", "theme": "G", "libelle": "Gestion des risques", "description": "", "ordre": 3, "poids": 1 },
  "questions": [
    {
      "ref": "G3-Q01",
      "enonce": "Comment l'organisation analyse-t-elle ses risques de sécurité ?",
      "aide": "Demander le dernier rapport d'analyse et le plan de traitement.",
      "profondeur": "essentiel",
      "poids": 1,
      "references": ["ISO 27001 §6.1", "NIST CSF GV.RM"],
      "niveaux": {
        "0": "Aucune analyse de risques n'a été réalisée",
        "1": "…", "2": "…", "3": "…", "4": "…", "5": "…"
      },
      "recommandations": [
        { "ref": "G3-Q01-R1", "texte": "…", "seuil": 3, "effort": "moyen", "quick_win": false, "prestation": "EBIOS-RM" }
      ]
    }
  ],
  "recommandations": [
    { "ref": "G3-R1", "texte": "…", "seuil": 2, "effort": "faible", "quick_win": true }
  ]
}
```

| Champ | Obligatoire | Règle |
|---|---|---|
| `sous_theme.code` | oui | 20 caractères max, unique sur tout le référentiel |
| `sous_theme.theme` | oui | code d'un thème de `_referentiel.json` |
| `sous_theme.libelle` | oui | |
| `questions[].ref` | oui | commence par `<code du sous-thème>-Q` (ex. `G3-Q01`) |
| `questions[].enonce` | oui | |
| `questions[].profondeur` | oui | `essentiel`, `standard` ou `renforce` |
| `questions[].niveaux` | oui | les six clés `"0"` à `"5"`, toutes renseignées, aucune autre |
| `questions[].references` | non | liste de textes |
| `questions[].poids` | non | > 0, 1 par défaut |
| L'ordre des questions | | celui du fichier |

**Recommandations** — de question (dans la question) ou de sous-thème (à la
racine du fichier) :

| Champ | Obligatoire | Règle |
|---|---|---|
| `ref` | oui | commence par `<ref de la question>-R` ou `<code du sous-thème>-R` ; unique sur tout le référentiel |
| `texte` | oui | |
| `seuil` | oui | entier de 1 à 5 : déclenchée si le niveau constaté est **inférieur** au seuil |
| `effort` | oui | `faible`, `moyen` ou `eleve` |
| `quick_win` | non | `true` / `false` |
| `prestation` | non | code d'une prestation de `_prestations.json` |

## `_profilage.json`

```json
{
  "questions": [
    {
      "code": "P01",
      "libelle": "Secteur d'activité principal",
      "aide": "",
      "type": "choix_unique",
      "choix": [
        { "code": "energie", "libelle": "Énergie" },
        { "code": "autre",   "libelle": "Autre secteur" }
      ]
    },
    {
      "code": "P07B",
      "libelle": "Population de la collectivité",
      "type": "choix_unique",
      "condition": { "non": { "P07": ["non"] } },
      "choix": [ … ]
    }
  ]
}
```

| Champ | Obligatoire | Règle |
|---|---|---|
| `code` | oui | 20 caractères max, unique |
| `libelle` | oui | |
| `type` | oui | `choix_unique` ou `choix_multiple` |
| `condition` | non | condition d'affichage (grammaire ci-dessous) |
| `choix[].code` | oui | unique dans la question ; stocké préfixé (`P01-energie`) |
| L'ordre | | celui du fichier |

## `_reglementations.json`

```json
{
  "reglementations": [
    {
      "code": "NIS2-EE",
      "libelle": "NIS2 : entité essentielle",
      "nature": "legale",
      "description": "…",
      "echeance": "…",
      "actions": ["Gouvernance cyber au niveau de la direction", "…"],
      "regles": [
        {
          "ref": "R1",
          "niveau": "obligatoire",
          "critere": "Secteur de l'annexe I et taille ETI ou grande entreprise.",
          "priorite": 0,
          "conditions": { "tous": [ { "P01": ["energie", "transport"] }, { "P02": ["eti", "ge"] } ] }
        }
      ],
      "exigences": {
        "G3": { "profondeur": "renforce", "cible": 4 },
        "I6": { "profondeur": "standard", "cible": 3 }
      }
    }
  ]
}
```

| Champ | Obligatoire | Règle |
|---|---|---|
| `code` | oui | 30 caractères max, unique |
| `libelle` | oui | |
| `nature` | oui | `legale` ou `referentiel` |
| `description`, `echeance`, `actions` | non | alimentent le rapport (« Pourquoi », échéance, actions attendues) |
| `regles[].ref` | oui | unique dans la réglementation ; stockée préfixée (`NIS2-EE-R1`) |
| `regles[].niveau` | oui | `obligatoire`, `a_verifier` ou `recommande` |
| `regles[].conditions` | oui | grammaire ci-dessous |
| `regles[].critere` | non | le « Pourquoi » affiché au consultant et dans le rapport |
| `exigences` | non | objet `{ code de sous-thème : { profondeur, cible } }` ; `cible` de 0 à 5 |

Les exigences sont portées par chaque réglementation : c'est la matrice
réglementation × sous-thème de la note de cadrage, lue ligne par ligne.

## Grammaire des conditions

| Forme | Sens |
|---|---|
| `{ "toujours": true }` | toujours vraie |
| `{ "P01": ["energie", "transport"] }` | la question P01 a reçu l'un de ces choix |
| `{ "tous": [ c1, c2, … ] }` | toutes les conditions sont vraies |
| `{ "un_parmi": [ c1, c2, … ] }` | au moins une est vraie |
| `{ "non": c }` | la condition est fausse |

Les formes s'imbriquent librement. Chaque question et chaque choix cités
doivent exister dans `_profilage.json`.

## `_prestations.json` (facultatif)

```json
{
  "prestations": [
    { "code": "EBIOS-RM", "libelle": "Analyse de risques EBIOS RM", "description": "", "lien": "" }
  ]
}
```

Vide ou absent en première version : les recommandations restent
déclaratives.
