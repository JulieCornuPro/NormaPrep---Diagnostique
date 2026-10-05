# NormaPrep Diagnostic

Plugin WordPress de diagnostic de la maturité cyber (Gouvernance, Infrastructure,
Applicatif), mené par un consultant.

## Documentation

- [Note de cadrage](docs/cadrage.md)
- [Modèle conceptuel de données](docs/mcd.md)
- [Format des fichiers du référentiel](docs/format-donnees.md)

## Contenu du dépôt

```
normaprep-diagnostic/      le plugin (à copier dans wp-content/plugins/)
  normaprep-diagnostic.php point d'entrée : activation, chargement
  uninstall.php            désinstallation complète (tables, rôle, options)
  includes/                installateur, rôle consultant, réglages,
                           cycle de vie des diagnostics, purge, profilage
  database/                validation et import du référentiel
  admin/                   écrans « Référentiel » et « Réglages »
  public/                  espace consultant (page publique) et ses écrans
  assets/                  styles et scripts de l'espace consultant
  data/                    fichiers JSON du référentiel (pipeline de contenu)
docs/                      cadrage, MCD, format des données
tests/                     tests de bout en bout et référentiel d'exemple
```

## Tests

Sur un WordPress **de test** (jamais le site réel : le script remplace le
référentiel en place) où le plugin est activé :

```
wp eval-file tests/test-lot1.php --user=<administrateur>
wp eval-file tests/test-lot3.php --user=<administrateur>
```

Le script vérifie l'activation, l'import (rejouable, tout ou rien, retrait
d'éléments), le cloisonnement, les échéances et la purge, la suppression
définitive, la réattribution et la case « Consultant » de la fiche
utilisateur. Le second couvre l'espace consultant : conditions de profilage,
calcul des réglementations, préservation de l'arbitrage quand le profilage
change, justifications, forçage, effet sur le questionnaire, cloisonnement
entre consultants et verrou des diagnostics finalisés.
