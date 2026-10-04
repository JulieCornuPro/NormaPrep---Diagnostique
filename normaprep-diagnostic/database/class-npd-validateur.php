<?php
/**
 * Lecture et validation des fichiers du référentiel.
 *
 * Le référentiel forme un tout cohérent : une exigence cite un sous-thème,
 * une règle cite des choix de profilage, une recommandation cite une
 * prestation. Un import partiel laisserait des références cassées. Le
 * validateur lit donc TOUS les fichiers, vérifie TOUTES les références, et
 * l'import n'écrit rien si la moindre erreur subsiste.
 *
 * Le format des fichiers est décrit dans docs/format-donnees.md.
 *
 * Le résultat est un « modèle » normalisé (tableaux PHP aux clés fixes) que
 * NPD_Importer écrit en base sans avoir à revérifier quoi que ce soit.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Validateur {

    /** Valeurs admises. */
    const PROFONDEURS    = [ 'essentiel', 'standard', 'renforce' ];
    const NIVEAUX_REGLE  = [ 'obligatoire', 'a_verifier', 'recommande' ];
    const NATURES        = [ 'legale', 'referentiel' ];
    const EFFORTS        = [ 'faible', 'moyen', 'eleve' ];
    const TYPES_PROFIL   = [ 'choix_unique', 'choix_multiple' ];

    /** Fichiers réservés à la racine de data/. */
    const F_REFERENTIEL     = '_referentiel.json';
    const F_PROFILAGE       = '_profilage.json';
    const F_REGLEMENTATIONS = '_reglementations.json';
    const F_PRESTATIONS     = '_prestations.json';

    /** @var string[] */
    private $erreurs = [];

    /** @var string[] */
    private $avertissements = [];

    /**
     * Lit et valide le dossier.
     *
     * @param string $dossier Chemin absolu du dossier data/.
     * @return array {
     *     @type bool     $valide
     *     @type string[] $erreurs
     *     @type string[] $avertissements
     *     @type array    $modele   Modèle normalisé (vide si invalide).
     *     @type string[] $fichiers Fichiers lus, relatifs au dossier.
     * }
     */
    public static function analyser( $dossier ) {
        $v = new self();
        return $v->executer( rtrim( $dossier, '/' ) );
    }

    /**
     * @param string $dossier
     * @return array
     */
    private function executer( $dossier ) {
        $modele = [
            'referentiel'     => [],
            'themes'          => [],
            'sous_themes'     => [],
            'prestations'     => [],
            'profil'          => [],
            'reglementations' => [],
        ];
        $fichiers = [];

        if ( ! is_dir( $dossier ) ) {
            $this->erreur( 'data/', 'Dossier introuvable.' );
            return $this->resultat( [], $fichiers );
        }

        /* ---- Fichiers de racine ---- */
        $ref = $this->lire( $dossier, self::F_REFERENTIEL, true, $fichiers );
        $pro = $this->lire( $dossier, self::F_PROFILAGE, true, $fichiers );
        $reg = $this->lire( $dossier, self::F_REGLEMENTATIONS, true, $fichiers );
        $pre = $this->lire( $dossier, self::F_PRESTATIONS, false, $fichiers );

        if ( null !== $ref ) {
            $this->valider_referentiel( $ref, $modele );
        }
        if ( null !== $pre ) {
            $this->valider_prestations( $pre, $modele );
        }

        /* ---- Un fichier par sous-thème, dans des sous-dossiers ---- */
        foreach ( $this->fichiers_sous_themes( $dossier ) as $relatif ) {
            $contenu = $this->lire( $dossier, $relatif, true, $fichiers );
            if ( null !== $contenu ) {
                $this->valider_sous_theme( $contenu, $relatif, $modele );
            }
        }

        if ( null !== $pro ) {
            $this->valider_profilage( $pro, $modele );
        }
        if ( null !== $reg ) {
            $this->valider_reglementations( $reg, $modele );
        }

        $this->controles_globaux( $modele );

        return $this->resultat( $modele, $fichiers );
    }

    /* =====================================================================
     * FICHIERS
     * ===================================================================== */

    /**
     * Lit un fichier JSON.
     *
     * @param string   $dossier
     * @param string   $relatif
     * @param bool     $obligatoire
     * @param string[] $fichiers Liste des fichiers lus (complétée).
     * @return array|null Contenu décodé, ou null s'il manque ou est illisible.
     */
    private function lire( $dossier, $relatif, $obligatoire, array &$fichiers ) {
        $chemin = $dossier . '/' . $relatif;
        if ( ! file_exists( $chemin ) ) {
            if ( $obligatoire ) {
                $this->erreur( $relatif, 'Fichier manquant.' );
            }
            return null;
        }
        $fichiers[] = $relatif;

        $brut    = file_get_contents( $chemin );
        $contenu = json_decode( (string) $brut, true );
        if ( ! is_array( $contenu ) ) {
            $this->erreur( $relatif, 'JSON illisible : ' . json_last_error_msg() . '.' );
            return null;
        }
        return $contenu;
    }

    /**
     * Fichiers de sous-thèmes : tous les .json des sous-dossiers, hors
     * fichiers commençant par « _ ». Triés pour un import reproductible.
     *
     * @param string $dossier
     * @return string[] Chemins relatifs (« G_gouvernance/G1_pilotage.json »).
     */
    private function fichiers_sous_themes( $dossier ) {
        $liste = [];
        foreach ( (array) scandir( $dossier ) as $sous ) {
            if ( '.' === $sous[0] || '_' === $sous[0] || ! is_dir( $dossier . '/' . $sous ) ) {
                continue;
            }
            foreach ( (array) scandir( $dossier . '/' . $sous ) as $f ) {
                if ( '_' === $f[0] || '.' === $f[0] || '.json' !== substr( $f, -5 ) ) {
                    continue;
                }
                $liste[] = $sous . '/' . $f;
            }
        }
        sort( $liste );
        return $liste;
    }

    /* =====================================================================
     * RÉFÉRENTIEL ET THÈMES
     * ===================================================================== */

    private function valider_referentiel( array $d, array &$modele ) {
        $f = self::F_REFERENTIEL;

        $version = $this->texte( $d, 'version' );
        if ( '' === $version ) {
            $this->erreur( $f, 'Champ « version » obligatoire.' );
        } elseif ( strlen( $version ) > 30 ) {
            $this->erreur( $f, 'La version dépasse 30 caractères.' );
        }
        $modele['referentiel'] = [
            'version' => $version,
            'libelle' => $this->texte( $d, 'libelle' ),
        ];

        $themes = $d['themes'] ?? null;
        if ( ! is_array( $themes ) || ! $themes ) {
            $this->erreur( $f, 'Liste « themes » obligatoire et non vide.' );
            return;
        }
        foreach ( array_values( $themes ) as $i => $t ) {
            $ou   = $f . ' › themes[' . $i . ']';
            $code = $this->code( $t, 'code', $ou, 10 );
            if ( null === $code ) {
                continue;
            }
            if ( isset( $modele['themes'][ $code ] ) ) {
                $this->erreur( $ou, 'Thème « ' . $code . ' » déclaré deux fois.' );
                continue;
            }
            $modele['themes'][ $code ] = [
                'code'    => $code,
                'libelle' => $this->texte_obligatoire( $t, 'libelle', $ou ),
                'couleur' => $this->texte( $t, 'couleur' ),
                'ordre'   => (int) ( $t['ordre'] ?? ( $i + 1 ) ),
                'poids'   => $this->poids( $t, $ou ),
            ];
        }
    }

    /* =====================================================================
     * PRESTATIONS
     * ===================================================================== */

    private function valider_prestations( array $d, array &$modele ) {
        $f     = self::F_PRESTATIONS;
        $liste = $d['prestations'] ?? [];
        if ( ! is_array( $liste ) ) {
            $this->erreur( $f, '« prestations » doit être une liste.' );
            return;
        }
        foreach ( array_values( $liste ) as $i => $p ) {
            $ou   = $f . ' › prestations[' . $i . ']';
            $code = $this->code( $p, 'code', $ou, 50 );
            if ( null === $code ) {
                continue;
            }
            if ( isset( $modele['prestations'][ $code ] ) ) {
                $this->erreur( $ou, 'Prestation « ' . $code . ' » déclarée deux fois.' );
                continue;
            }
            $modele['prestations'][ $code ] = [
                'code'        => $code,
                'libelle'     => $this->texte_obligatoire( $p, 'libelle', $ou ),
                'description' => $this->texte( $p, 'description' ),
                'lien'        => $this->texte( $p, 'lien' ),
            ];
        }
    }

    /* =====================================================================
     * SOUS-THÈMES, QUESTIONS, NIVEAUX, RECOMMANDATIONS
     * ===================================================================== */

    private function valider_sous_theme( array $d, $f, array &$modele ) {
        $st = $d['sous_theme'] ?? null;
        if ( ! is_array( $st ) ) {
            $this->erreur( $f, 'Bloc « sous_theme » obligatoire.' );
            return;
        }

        $code = $this->code( $st, 'code', $f, 20 );
        if ( null === $code ) {
            return;
        }
        if ( isset( $modele['sous_themes'][ $code ] ) ) {
            $this->erreur( $f, 'Sous-thème « ' . $code . ' » déjà déclaré dans ' . $modele['sous_themes'][ $code ]['fichier'] . '.' );
            return;
        }

        $theme = $this->texte( $st, 'theme' );
        if ( ! isset( $modele['themes'][ $theme ] ) ) {
            $this->erreur( $f, 'Thème « ' . $theme . ' » inconnu (voir ' . self::F_REFERENTIEL . ').' );
        }

        $sous_theme = [
            'code'            => $code,
            'theme'           => $theme,
            'libelle'         => $this->texte_obligatoire( $st, 'libelle', $f ),
            'description'     => $this->texte( $st, 'description' ),
            'ordre'           => (int) ( $st['ordre'] ?? 0 ),
            'poids'           => $this->poids( $st, $f ),
            'fichier'         => $f,
            'questions'       => [],
            'recommandations' => [],
        ];

        /* ---- Questions ---- */
        $questions = $d['questions'] ?? [];
        if ( ! is_array( $questions ) ) {
            $this->erreur( $f, '« questions » doit être une liste.' );
            $questions = [];
        }
        foreach ( array_values( $questions ) as $i => $q ) {
            $ou  = $f . ' › questions[' . $i . ']';
            $ref = $this->code( $q, 'ref', $ou, 30 );
            if ( null === $ref ) {
                continue;
            }
            $ou = $f . ' › ' . $ref;
            if ( 0 !== strpos( $ref, $code . '-Q' ) ) {
                $this->erreur( $ou, 'La référence doit commencer par « ' . $code . '-Q ».' );
            }
            if ( isset( $sous_theme['questions'][ $ref ] ) ) {
                $this->erreur( $ou, 'Question déclarée deux fois.' );
                continue;
            }

            $sous_theme['questions'][ $ref ] = [
                'ref'             => $ref,
                'enonce'          => $this->texte_obligatoire( $q, 'enonce', $ou ),
                'aide'            => $this->texte( $q, 'aide' ),
                'profondeur'      => $this->parmi( $q, 'profondeur', self::PROFONDEURS, $ou ),
                'poids'           => $this->poids( $q, $ou ),
                'references'      => $this->liste_textes( $q, 'references', $ou ),
                'ordre'           => $i + 1,
                'niveaux'         => $this->niveaux( $q, $ou ),
            ];

            foreach ( (array) ( $q['recommandations'] ?? [] ) as $j => $r ) {
                $this->recommandation( $r, $ref, $ref, $f . ' › ' . $ref . ' › recommandations[' . $j . ']', $sous_theme );
            }
        }
        if ( ! $sous_theme['questions'] ) {
            $this->avertir( $f, 'Aucune question.' );
        }

        /* ---- Recommandations de niveau sous-thème ---- */
        foreach ( (array) ( $d['recommandations'] ?? [] ) as $j => $r ) {
            $this->recommandation( $r, $code, null, $f . ' › recommandations[' . $j . ']', $sous_theme );
        }

        $modele['sous_themes'][ $code ] = $sous_theme;
    }

    /**
     * Les six niveaux 0 à 5, tous obligatoires et non vides.
     *
     * @return array<int,string>
     */
    private function niveaux( array $q, $ou ) {
        $niveaux = $q['niveaux'] ?? null;
        if ( ! is_array( $niveaux ) ) {
            $this->erreur( $ou, 'Bloc « niveaux » obligatoire (descriptions des niveaux 0 à 5).' );
            return [];
        }
        $propres = [];
        for ( $n = 0; $n <= 5; $n++ ) {
            $texte = isset( $niveaux[ (string) $n ] ) ? trim( (string) $niveaux[ (string) $n ] ) : '';
            if ( '' === $texte ) {
                $this->erreur( $ou, 'Description du niveau ' . $n . ' manquante.' );
            }
            $propres[ $n ] = $texte;
        }
        foreach ( array_keys( $niveaux ) as $cle ) {
            if ( ! in_array( (string) $cle, [ '0', '1', '2', '3', '4', '5' ], true ) ) {
                $this->erreur( $ou, 'Niveau « ' . $cle . ' » inattendu : seuls 0 à 5 existent.' );
            }
        }
        return $propres;
    }

    /**
     * Une recommandation, rattachée à une question ou au sous-thème.
     *
     * @param mixed       $r
     * @param string      $prefixe       Préfixe attendu de la référence (code de question ou de sous-thème).
     * @param string|null $question_ref  Question de rattachement, ou null.
     * @param string      $ou
     * @param array       $sous_theme    Sous-thème en construction (complété).
     */
    private function recommandation( $r, $prefixe, $question_ref, $ou, array &$sous_theme ) {
        if ( ! is_array( $r ) ) {
            $this->erreur( $ou, 'Recommandation mal formée.' );
            return;
        }
        $ref = $this->code( $r, 'ref', $ou, 40 );
        if ( null === $ref ) {
            return;
        }
        if ( 0 !== strpos( $ref, $prefixe . '-R' ) ) {
            $this->erreur( $ou, 'La référence « ' . $ref . ' » doit commencer par « ' . $prefixe . '-R ».' );
        }
        if ( isset( $sous_theme['recommandations'][ $ref ] ) ) {
            $this->erreur( $ou, 'Recommandation « ' . $ref . ' » déclarée deux fois.' );
            return;
        }

        $seuil = $r['seuil'] ?? null;
        if ( ! is_int( $seuil ) || $seuil < 1 || $seuil > 5 ) {
            $this->erreur( $ou, '« seuil » doit être un entier de 1 à 5 (déclenchée si niveau < seuil).' );
        }

        $sous_theme['recommandations'][ $ref ] = [
            'ref'        => $ref,
            'question'   => $question_ref,
            'texte'      => $this->texte_obligatoire( $r, 'texte', $ou ),
            'seuil'      => (int) $seuil,
            'effort'     => $this->parmi( $r, 'effort', self::EFFORTS, $ou ),
            'quick_win'  => ! empty( $r['quick_win'] ),
            'prestation' => $this->texte( $r, 'prestation' ),
            'ou'         => $ou,
        ];
    }

    /* =====================================================================
     * PROFILAGE
     * ===================================================================== */

    private function valider_profilage( array $d, array &$modele ) {
        $f         = self::F_PROFILAGE;
        $questions = $d['questions'] ?? null;
        if ( ! is_array( $questions ) || ! $questions ) {
            $this->erreur( $f, 'Liste « questions » obligatoire et non vide.' );
            return;
        }

        foreach ( array_values( $questions ) as $i => $q ) {
            $ou   = $f . ' › questions[' . $i . ']';
            $code = $this->code( $q, 'code', $ou, 20 );
            if ( null === $code ) {
                continue;
            }
            $ou = $f . ' › ' . $code;
            if ( isset( $modele['profil'][ $code ] ) ) {
                $this->erreur( $ou, 'Question de profilage déclarée deux fois.' );
                continue;
            }

            $choix = [];
            foreach ( array_values( (array) ( $q['choix'] ?? [] ) ) as $j => $c ) {
                $ouc = $ou . ' › choix[' . $j . ']';
                $cc  = $this->code( $c, 'code', $ouc, 59 );
                if ( null === $cc ) {
                    continue;
                }
                if ( isset( $choix[ $cc ] ) ) {
                    $this->erreur( $ouc, 'Choix « ' . $cc . ' » déclaré deux fois.' );
                    continue;
                }
                $choix[ $cc ] = [
                    'code'    => $cc,
                    'libelle' => $this->texte_obligatoire( $c, 'libelle', $ouc ),
                    'ordre'   => $j + 1,
                ];
            }
            if ( ! $choix ) {
                $this->erreur( $ou, 'Au moins un choix est obligatoire.' );
            }

            $modele['profil'][ $code ] = [
                'code'      => $code,
                'libelle'   => $this->texte_obligatoire( $q, 'libelle', $ou ),
                'aide'      => $this->texte( $q, 'aide' ),
                'type'      => $this->parmi( $q, 'type', self::TYPES_PROFIL, $ou ),
                'condition' => $q['condition'] ?? null,
                'ordre'     => $i + 1,
                'choix'     => $choix,
            ];
        }

        // Conditions d'affichage : contrôlées une fois toutes les questions connues.
        foreach ( $modele['profil'] as $code => $q ) {
            if ( null !== $q['condition'] ) {
                $this->condition( $q['condition'], $f . ' › ' . $code . ' › condition', $modele );
            }
        }
    }

    /* =====================================================================
     * RÉGLEMENTATIONS, RÈGLES, EXIGENCES
     * ===================================================================== */

    private function valider_reglementations( array $d, array &$modele ) {
        $f     = self::F_REGLEMENTATIONS;
        $liste = $d['reglementations'] ?? null;
        if ( ! is_array( $liste ) || ! $liste ) {
            $this->erreur( $f, 'Liste « reglementations » obligatoire et non vide.' );
            return;
        }

        foreach ( array_values( $liste ) as $i => $r ) {
            $ou   = $f . ' › reglementations[' . $i . ']';
            $code = $this->code( $r, 'code', $ou, 30 );
            if ( null === $code ) {
                continue;
            }
            $ou = $f . ' › ' . $code;
            if ( isset( $modele['reglementations'][ $code ] ) ) {
                $this->erreur( $ou, 'Réglementation déclarée deux fois.' );
                continue;
            }

            /* ---- Règles de déclenchement ---- */
            $regles = [];
            foreach ( array_values( (array) ( $r['regles'] ?? [] ) ) as $j => $rg ) {
                $our = $ou . ' › regles[' . $j . ']';
                $rr  = $this->code( $rg, 'ref', $our, 9 );
                if ( null === $rr ) {
                    continue;
                }
                $complet = $code . '-' . $rr;
                if ( isset( $regles[ $complet ] ) ) {
                    $this->erreur( $our, 'Règle « ' . $rr . ' » déclarée deux fois.' );
                    continue;
                }
                if ( ! array_key_exists( 'conditions', $rg ) ) {
                    $this->erreur( $our, 'Bloc « conditions » obligatoire.' );
                } else {
                    $this->condition( $rg['conditions'], $our . ' › conditions', $modele );
                }
                $regles[ $complet ] = [
                    'ref'        => $complet,
                    'niveau'     => $this->parmi( $rg, 'niveau', self::NIVEAUX_REGLE, $our ),
                    'conditions' => $rg['conditions'] ?? null,
                    'critere'    => $this->texte( $rg, 'critere' ),
                    'priorite'   => (int) ( $rg['priorite'] ?? 0 ),
                ];
            }
            if ( ! $regles ) {
                $this->avertir( $ou, 'Aucune règle : cette réglementation ne sera jamais proposée par le calcul.' );
            }

            /* ---- Exigences par sous-thème ---- */
            $exigences = [];
            $brutes    = $r['exigences'] ?? [];
            if ( ! is_array( $brutes ) ) {
                $this->erreur( $ou, '« exigences » doit être un objet { sous-thème: {profondeur, cible} }.' );
                $brutes = [];
            }
            foreach ( $brutes as $st => $e ) {
                $oue = $ou . ' › exigences › ' . $st;
                if ( ! isset( $modele['sous_themes'][ $st ] ) ) {
                    $this->erreur( $oue, 'Sous-thème « ' . $st . ' » inconnu.' );
                    continue;
                }
                if ( ! is_array( $e ) ) {
                    $this->erreur( $oue, 'Exigence mal formée.' );
                    continue;
                }
                $cible = $e['cible'] ?? null;
                if ( ! is_numeric( $cible ) || $cible < 0 || $cible > 5 ) {
                    $this->erreur( $oue, '« cible » doit être un nombre de 0 à 5.' );
                }
                $exigences[ $st ] = [
                    'profondeur' => $this->parmi( $e, 'profondeur', self::PROFONDEURS, $oue ),
                    'cible'      => round( (float) $cible, 1 ),
                ];
            }

            $modele['reglementations'][ $code ] = [
                'code'        => $code,
                'libelle'     => $this->texte_obligatoire( $r, 'libelle', $ou ),
                'nature'      => $this->parmi( $r, 'nature', self::NATURES, $ou ),
                'description' => $this->texte( $r, 'description' ),
                'echeance'    => $this->texte( $r, 'echeance' ),
                'actions'     => $this->liste_textes( $r, 'actions', $ou ),
                'ordre'       => $i + 1,
                'regles'      => $regles,
                'exigences'   => $exigences,
            ];
        }
    }

    /**
     * Valide une condition sur les réponses de profilage.
     *
     * Grammaire :
     *   { "toujours": true }
     *   { "tous":     [ condition, … ] }   toutes vraies
     *   { "un_parmi": [ condition, … ] }   au moins une vraie
     *   { "non":      condition }
     *   { "P01": [ "energie", "transport" ] }  P01 a reçu l'un de ces choix
     *
     * @param mixed  $c
     * @param string $ou
     * @param array  $modele
     */
    private function condition( $c, $ou, array $modele ) {
        if ( ! is_array( $c ) || 1 !== count( $c ) ) {
            $this->erreur( $ou, 'Une condition est un objet à une seule clé.' );
            return;
        }
        $cle    = (string) array_key_first( $c );
        $valeur = $c[ $cle ];

        switch ( $cle ) {
            case 'toujours':
                if ( true !== $valeur ) {
                    $this->erreur( $ou, '« toujours » doit valoir true.' );
                }
                return;

            case 'tous':
            case 'un_parmi':
                if ( ! is_array( $valeur ) || ! $valeur || array_keys( $valeur ) !== range( 0, count( $valeur ) - 1 ) ) {
                    $this->erreur( $ou, '« ' . $cle . ' » attend une liste non vide de conditions.' );
                    return;
                }
                foreach ( $valeur as $i => $sous ) {
                    $this->condition( $sous, $ou . ' › ' . $cle . '[' . $i . ']', $modele );
                }
                return;

            case 'non':
                $this->condition( $valeur, $ou . ' › non', $modele );
                return;
        }

        // Feuille : code de question de profilage → liste de choix.
        if ( ! isset( $modele['profil'][ $cle ] ) ) {
            $this->erreur( $ou, 'Question de profilage « ' . $cle . ' » inconnue.' );
            return;
        }
        if ( ! is_array( $valeur ) || ! $valeur ) {
            $this->erreur( $ou, '« ' . $cle . ' » attend une liste non vide de choix.' );
            return;
        }
        foreach ( $valeur as $choix ) {
            if ( ! is_string( $choix ) || ! isset( $modele['profil'][ $cle ]['choix'][ $choix ] ) ) {
                $this->erreur( $ou, 'Choix « ' . ( is_scalar( $choix ) ? $choix : '?' ) . ' » inconnu pour ' . $cle . '.' );
            }
        }
    }

    /* =====================================================================
     * CONTRÔLES GLOBAUX
     * ===================================================================== */

    private function controles_globaux( array $modele ) {
        // Recommandations : la prestation citée doit exister.
        foreach ( $modele['sous_themes'] as $st ) {
            foreach ( $st['recommandations'] as $r ) {
                if ( '' !== $r['prestation'] && ! isset( $modele['prestations'][ $r['prestation'] ] ) ) {
                    $this->erreur( $r['ou'], 'Prestation « ' . $r['prestation'] . ' » inconnue (voir ' . self::F_PRESTATIONS . ').' );
                }
            }
        }

        // Références de recommandation uniques sur tout le référentiel.
        $vues = [];
        foreach ( $modele['sous_themes'] as $st ) {
            foreach ( $st['recommandations'] as $ref => $r ) {
                if ( isset( $vues[ $ref ] ) ) {
                    $this->erreur( $r['ou'], 'Référence « ' . $ref . ' » déjà utilisée dans ' . $vues[ $ref ] . '.' );
                }
                $vues[ $ref ] = $st['fichier'];
            }
        }

        // Thèmes sans sous-thème.
        foreach ( $modele['themes'] as $code => $t ) {
            $utilise = false;
            foreach ( $modele['sous_themes'] as $st ) {
                if ( $st['theme'] === $code ) {
                    $utilise = true;
                    break;
                }
            }
            if ( ! $utilise ) {
                $this->avertir( self::F_REFERENTIEL, 'Le thème « ' . $code . ' » n\'a aucun sous-thème.' );
            }
        }

        // Sous-thèmes qu'aucune réglementation n'exige : jamais de cible.
        foreach ( $modele['sous_themes'] as $code => $st ) {
            $exige = false;
            foreach ( $modele['reglementations'] as $r ) {
                if ( isset( $r['exigences'][ $code ] ) ) {
                    $exige = true;
                    break;
                }
            }
            if ( ! $exige && $modele['reglementations'] ) {
                $this->avertir( $st['fichier'], 'Aucune réglementation n\'exige ce sous-thème : il n\'aura pas de cible.' );
            }
        }
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    private function erreur( $ou, $message ) {
        $this->erreurs[] = $ou . ' — ' . $message;
    }

    private function avertir( $ou, $message ) {
        $this->avertissements[] = $ou . ' — ' . $message;
    }

    private function resultat( array $modele, array $fichiers ) {
        $valide = ! $this->erreurs;
        return [
            'valide'         => $valide,
            'erreurs'        => $this->erreurs,
            'avertissements' => $this->avertissements,
            'modele'         => $valide ? $modele : [],
            'fichiers'       => $fichiers,
        ];
    }

    /** Texte facultatif, nettoyé des espaces de bord. */
    private function texte( $d, $cle ) {
        return ( is_array( $d ) && isset( $d[ $cle ] ) && is_scalar( $d[ $cle ] ) ) ? trim( (string) $d[ $cle ] ) : '';
    }

    /** Texte obligatoire. */
    private function texte_obligatoire( $d, $cle, $ou ) {
        $t = $this->texte( $d, $cle );
        if ( '' === $t ) {
            $this->erreur( $ou, 'Champ « ' . $cle . ' » obligatoire.' );
        }
        return $t;
    }

    /**
     * Code ou référence : obligatoire, sans espace, longueur bornée.
     *
     * @return string|null Null si invalide.
     */
    private function code( $d, $cle, $ou, $max ) {
        if ( ! is_array( $d ) ) {
            $this->erreur( $ou, 'Élément mal formé.' );
            return null;
        }
        $c = $this->texte( $d, $cle );
        if ( '' === $c ) {
            $this->erreur( $ou, 'Champ « ' . $cle . ' » obligatoire.' );
            return null;
        }
        if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $c ) ) {
            $this->erreur( $ou, '« ' . $c . ' » : seuls lettres, chiffres, « - », « _ » et « . » sont admis.' );
            return null;
        }
        if ( strlen( $c ) > $max ) {
            $this->erreur( $ou, '« ' . $c . ' » dépasse ' . $max . ' caractères.' );
            return null;
        }
        return $c;
    }

    /** Valeur obligatoire parmi une liste. */
    private function parmi( $d, $cle, array $admis, $ou ) {
        $v = $this->texte( $d, $cle );
        if ( ! in_array( $v, $admis, true ) ) {
            $this->erreur( $ou, '« ' . $cle . ' » doit valoir : ' . implode( ', ', $admis ) . '.' );
        }
        return $v;
    }

    /** Poids facultatif, strictement positif, 1 par défaut. */
    private function poids( $d, $ou ) {
        if ( ! isset( $d['poids'] ) ) {
            return 1.0;
        }
        if ( ! is_numeric( $d['poids'] ) || $d['poids'] <= 0 ) {
            $this->erreur( $ou, '« poids » doit être un nombre strictement positif.' );
            return 1.0;
        }
        return (float) $d['poids'];
    }

    /** Liste de textes facultative. */
    private function liste_textes( $d, $cle, $ou ) {
        if ( ! isset( $d[ $cle ] ) ) {
            return [];
        }
        if ( ! is_array( $d[ $cle ] ) ) {
            $this->erreur( $ou, '« ' . $cle . ' » doit être une liste de textes.' );
            return [];
        }
        $liste = [];
        foreach ( $d[ $cle ] as $t ) {
            if ( is_scalar( $t ) && '' !== trim( (string) $t ) ) {
                $liste[] = trim( (string) $t );
            }
        }
        return $liste;
    }
}
