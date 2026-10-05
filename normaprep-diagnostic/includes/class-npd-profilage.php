<?php
/**
 * Profilage réglementaire : réponses, calcul des réglementations applicables
 * et arbitrage du consultant.
 *
 * LE CALCUL
 * ---------
 * Chaque réglementation porte des règles (conditions sur les réponses de
 * profilage). Une réglementation ressort dès qu'une de ses règles est vraie,
 * avec le niveau le plus fort parmi les règles vraies :
 *
 *     obligatoire  >  à vérifier  >  recommandé
 *
 * À niveau égal, la règle de plus forte « priorité » fournit le « Pourquoi ».
 *
 * L'ARBITRAGE
 * -----------
 * Le calcul PROPOSE, le consultant DÉCIDE. Pour chaque réglementation
 * ressortie, une ligne diag_reglementation garde :
 *
 *   - niveau_calcule : ce que propose le calcul ;
 *   - decision       : retenue, ecartee ou en_attente ;
 *   - origine        : calcul (proposée par le calcul) ou forcage (ajoutée
 *                      à la main par le consultant) ;
 *   - justification  : obligatoire quand la décision s'écarte du calcul.
 *
 * Décision par défaut, tant que le consultant n'a rien changé :
 *
 *     obligatoire → retenue      à vérifier → en_attente      recommandé → ecartee
 *
 * Quand le profilage change, le calcul est rejoué sans écraser l'arbitrage :
 * une décision prise par le consultant est conservée ; une décision restée
 * à sa valeur par défaut suit le nouveau calcul.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Profilage {

    /** Force des niveaux, pour retenir le plus fort. */
    const RANG_NIVEAU = [ 'recommande' => 1, 'a_verifier' => 2, 'obligatoire' => 3 ];

    /** Profondeurs du questionnaire, de la plus légère à la plus complète. */
    const RANG_PROFONDEUR = [ 'essentiel' => 1, 'standard' => 2, 'renforce' => 3 ];

    /** Décisions possibles. */
    const DECISIONS = [ 'retenue', 'ecartee', 'en_attente' ];

    /* =====================================================================
     * RÉFÉRENTIEL DE PROFILAGE
     * ===================================================================== */

    /**
     * Questions de profilage actives, avec leurs choix actifs, dans l'ordre.
     *
     * @return array<string,array> Indexé par code de question. Chaque choix
     *                             porte son code LOCAL (« energie ») et son id.
     */
    public static function questions() {
        global $wpdb;
        $tq = NPD_Installer::table( 'profil_question' );
        $tc = NPD_Installer::table( 'profil_choix' );

        $questions = [];
        foreach ( $wpdb->get_results( "SELECT * FROM {$tq} WHERE actif = 1 ORDER BY ordre, id" ) as $q ) {
            $questions[ $q->code ] = [
                'id'        => (int) $q->id,
                'code'      => $q->code,
                'libelle'   => $q->libelle,
                'aide'      => (string) $q->aide,
                'type'      => $q->type,
                'condition' => $q->condition_affichage ? json_decode( $q->condition_affichage, true ) : null,
                'choix'     => [],
            ];
        }
        $ids = wp_list_pluck( $questions, 'code', 'id' );

        foreach ( $wpdb->get_results( "SELECT * FROM {$tc} WHERE actif = 1 ORDER BY ordre, id" ) as $c ) {
            $code_q = $ids[ (int) $c->profil_question_id ] ?? null;
            if ( null === $code_q ) {
                continue;
            }
            $local = substr( $c->code, strlen( $code_q ) + 1 );
            $questions[ $code_q ]['choix'][ $local ] = [
                'id'      => (int) $c->id,
                'code'    => $local,
                'libelle' => $c->libelle,
            ];
        }

        // Une question sans aucun choix actif n'est pas posable.
        return array_filter( $questions, function ( $q ) {
            return ! empty( $q['choix'] );
        } );
    }

    /* =====================================================================
     * RÉPONSES
     * ===================================================================== */

    /**
     * Réponses de profilage d'un diagnostic.
     *
     * @param int $diagnostic_id
     * @return array<string,string[]> [ 'P01' => [ 'energie' ], 'P14' => [ 'dns', … ] ]
     */
    public static function reponses( $diagnostic_id ) {
        global $wpdb;
        $tr = NPD_Installer::table( 'profil_reponse' );
        $tc = NPD_Installer::table( 'profil_choix' );
        $tq = NPD_Installer::table( 'profil_question' );

        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.code AS question, c.code AS choix
               FROM {$tr} r
               JOIN {$tc} c ON c.id = r.profil_choix_id
               JOIN {$tq} q ON q.id = c.profil_question_id
              WHERE r.diagnostic_id = %d AND c.actif = 1 AND q.actif = 1
              ORDER BY q.ordre, c.ordre",
            $diagnostic_id
        ) );

        $reponses = [];
        foreach ( $lignes as $l ) {
            $reponses[ $l->question ][] = substr( $l->choix, strlen( $l->question ) + 1 );
        }
        return $reponses;
    }

    /**
     * Nettoie des réponses soumises : ne garde que les questions VISIBLES
     * (condition d'affichage remplie), les choix existants, et un seul choix
     * pour une question à choix unique.
     *
     * Les questions sont traitées dans l'ordre : une condition ne peut
     * dépendre que des réponses déjà retenues.
     *
     * @param array $soumises  [ code question => code choix | code choix[] ]
     * @param array $questions Résultat de questions().
     * @return array<string,string[]>
     */
    public static function nettoyer_reponses( array $soumises, array $questions ) {
        $propres = [];
        foreach ( $questions as $code => $q ) {
            if ( null !== $q['condition'] && ! self::evaluer( $q['condition'], $propres ) ) {
                continue;
            }
            $valeurs = $soumises[ $code ] ?? [];
            $valeurs = is_array( $valeurs ) ? $valeurs : [ $valeurs ];
            $valeurs = array_values( array_unique( array_filter( array_map( 'strval', $valeurs ), function ( $v ) use ( $q ) {
                return isset( $q['choix'][ $v ] );
            } ) ) );
            if ( 'choix_unique' === $q['type'] ) {
                $valeurs = array_slice( $valeurs, 0, 1 );
            }
            if ( $valeurs ) {
                $propres[ $code ] = $valeurs;
            }
        }
        return $propres;
    }

    /**
     * Questions visibles qui n'ont pas reçu de réponse.
     *
     * @param array $reponses
     * @param array $questions
     * @return string[] Codes des questions.
     */
    public static function questions_sans_reponse( array $reponses, array $questions ) {
        $manquantes = [];
        foreach ( $questions as $code => $q ) {
            if ( null !== $q['condition'] && ! self::evaluer( $q['condition'], $reponses ) ) {
                continue;
            }
            if ( empty( $reponses[ $code ] ) ) {
                $manquantes[] = $code;
            }
        }
        return $manquantes;
    }

    /**
     * Enregistre les réponses de profilage (remplacent les précédentes), puis
     * rejoue le calcul des réglementations.
     *
     * @param int   $diagnostic_id
     * @param array $soumises Réponses brutes du formulaire.
     * @return array Réponses retenues après nettoyage.
     */
    public static function enregistrer( $diagnostic_id, array $soumises ) {
        global $wpdb;
        $questions = self::questions();
        $reponses  = self::nettoyer_reponses( $soumises, $questions );
        $tr        = NPD_Installer::table( 'profil_reponse' );
        $maintenant = current_time( 'mysql' );

        $wpdb->query( 'START TRANSACTION' );
        $wpdb->delete( $tr, [ 'diagnostic_id' => (int) $diagnostic_id ] );
        foreach ( $reponses as $code => $choix ) {
            foreach ( $choix as $c ) {
                $wpdb->insert( $tr, [
                    'diagnostic_id'   => (int) $diagnostic_id,
                    'profil_choix_id' => $questions[ $code ]['choix'][ $c ]['id'],
                    'saisi_le'        => $maintenant,
                ] );
            }
        }
        self::synchroniser( $diagnostic_id, self::calculer( $reponses ) );
        $wpdb->query( 'COMMIT' );

        return $reponses;
    }

    /* =====================================================================
     * CONDITIONS ET CALCUL
     * ===================================================================== */

    /**
     * Évalue une condition (grammaire : docs/format-donnees.md).
     *
     * Une forme inconnue est FAUSSE : en cas de doute, on ne fait pas
     * ressortir une réglementation.
     *
     * @param mixed $condition
     * @param array $reponses [ 'P01' => [ 'energie' ] ]
     * @return bool
     */
    public static function evaluer( $condition, array $reponses ) {
        if ( ! is_array( $condition ) || 1 !== count( $condition ) ) {
            return false;
        }
        $cle    = (string) array_key_first( $condition );
        $valeur = $condition[ $cle ];

        switch ( $cle ) {
            case 'toujours':
                return true === $valeur;

            case 'tous':
                if ( ! is_array( $valeur ) || ! $valeur ) {
                    return false;
                }
                foreach ( $valeur as $sous ) {
                    if ( ! self::evaluer( $sous, $reponses ) ) {
                        return false;
                    }
                }
                return true;

            case 'un_parmi':
                if ( ! is_array( $valeur ) ) {
                    return false;
                }
                foreach ( $valeur as $sous ) {
                    if ( self::evaluer( $sous, $reponses ) ) {
                        return true;
                    }
                }
                return false;

            case 'non':
                return ! self::evaluer( $valeur, $reponses );
        }

        // Feuille : la question a reçu au moins un des choix cités.
        if ( ! is_array( $valeur ) || empty( $reponses[ $cle ] ) ) {
            return false;
        }
        return (bool) array_intersect( (array) $reponses[ $cle ], $valeur );
    }

    /**
     * Réglementations qui ressortent des réponses.
     *
     * @param array $reponses
     * @return array<int,array> Indexé par id de réglementation :
     *                          [ 'niveau' => …, 'critere' => … ].
     */
    public static function calculer( array $reponses ) {
        global $wpdb;
        $tg = NPD_Installer::table( 'regle' );
        $tr = NPD_Installer::table( 'reglementation' );

        $regles = $wpdb->get_results(
            "SELECT g.reglementation_id, g.niveau, g.conditions, g.critere, g.priorite
               FROM {$tg} g
               JOIN {$tr} r ON r.id = g.reglementation_id
              WHERE r.actif = 1
              ORDER BY g.reglementation_id, g.priorite DESC, g.id"
        );

        $resultat = [];
        foreach ( $regles as $g ) {
            if ( ! self::evaluer( json_decode( $g->conditions, true ), $reponses ) ) {
                continue;
            }
            $id      = (int) $g->reglementation_id;
            $rang    = self::RANG_NIVEAU[ $g->niveau ] ?? 0;
            $actuel  = isset( $resultat[ $id ] ) ? self::RANG_NIVEAU[ $resultat[ $id ]['niveau'] ] : 0;

            // Strictement plus fort seulement : à niveau égal, la première
            // règle rencontrée (plus forte priorité) garde la main.
            if ( $rang > $actuel ) {
                $resultat[ $id ] = [ 'niveau' => $g->niveau, 'critere' => (string) $g->critere ];
            }
        }
        return $resultat;
    }

    /**
     * Décision par défaut pour un niveau calculé.
     *
     * @param string $niveau
     * @return string
     */
    public static function decision_par_defaut( $niveau ) {
        switch ( $niveau ) {
            case 'obligatoire':
                return 'retenue';
            case 'a_verifier':
                return 'en_attente';
            default:
                return 'ecartee';
        }
    }

    /**
     * Une justification est-elle exigée pour cette décision ?
     *
     *   - réglementation ajoutée à la main (forçage) ;
     *   - « à vérifier » tranchée (retenue ou écartée) ;
     *   - obligatoire écartée.
     *
     * Retenir ou écarter une réglementation recommandée est un choix
     * d'objectif, sans justification.
     *
     * @param string $origine
     * @param string $niveau   Niveau calculé.
     * @param string $decision
     * @return bool
     */
    public static function justification_requise( $origine, $niveau, $decision ) {
        if ( 'forcage' === $origine ) {
            return 'retenue' === $decision;
        }
        if ( 'a_verifier' === $niveau ) {
            return 'en_attente' !== $decision;
        }
        if ( 'obligatoire' === $niveau ) {
            return 'ecartee' === $decision;
        }
        return false;
    }

    /**
     * La décision de cette ligne a-t-elle été prise par le consultant ?
     *
     * Oui si elle s'écarte de la valeur par défaut du niveau calculé, ou si
     * elle porte une justification (une confirmation argumentée d'une
     * réglementation devenue obligatoire reste une décision du consultant).
     *
     * @param object $l Ligne diag_reglementation.
     * @return bool
     */
    public static function decision_du_consultant( $l ) {
        return 'forcage' === $l->origine
            || $l->decision !== self::decision_par_defaut( $l->niveau_calcule )
            || '' !== trim( (string) $l->justification );
    }

    /**
     * Reporte un nouveau calcul dans diag_reglementation sans écraser
     * l'arbitrage du consultant.
     *
     * @param int   $diagnostic_id
     * @param array $calcul Résultat de calculer().
     */
    public static function synchroniser( $diagnostic_id, array $calcul ) {
        global $wpdb;
        $t = NPD_Installer::table( 'diag_reglementation' );

        $existantes = [];
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE diagnostic_id = %d", $diagnostic_id ) ) as $l ) {
            $existantes[ (int) $l->reglementation_id ] = $l;
        }

        // 1. Réglementations proposées par le calcul.
        foreach ( $calcul as $reg_id => $c ) {
            $l = $existantes[ $reg_id ] ?? null;

            if ( ! $l ) {
                $wpdb->insert( $t, [
                    'diagnostic_id'     => (int) $diagnostic_id,
                    'reglementation_id' => $reg_id,
                    'niveau_calcule'    => $c['niveau'],
                    'decision'          => self::decision_par_defaut( $c['niveau'] ),
                    'origine'           => 'calcul',
                    'critere'           => $c['critere'],
                ] );
                continue;
            }

            // Décision restée à sa valeur par défaut : elle suit le calcul.
            // Décision prise par le consultant (ou forçage) : elle est gardée.
            $decision = self::decision_du_consultant( $l ) ? $l->decision : self::decision_par_defaut( $c['niveau'] );

            $wpdb->update( $t, [
                'niveau_calcule' => $c['niveau'],
                'decision'       => $decision,
                'origine'        => 'calcul',
                'critere'        => $c['critere'],
            ], [ 'id' => (int) $l->id ] );
        }

        // 2. Réglementations que le calcul ne propose plus.
        foreach ( $existantes as $reg_id => $l ) {
            if ( isset( $calcul[ $reg_id ] ) || 'forcage' === $l->origine ) {
                continue;
            }
            if ( 'retenue' === $l->decision && self::decision_du_consultant( $l ) ) {
                // Le consultant l'avait retenue de lui-même (confirmation,
                // objectif choisi) : on ne la retire pas en silence. Elle
                // devient un forçage, qu'il justifiera ou retirera.
                $wpdb->update( $t, [
                    'origine' => 'forcage',
                    'critere' => 'Ne ressort plus du profilage ; maintenue jusqu\'à décision du consultant.',
                ], [ 'id' => (int) $l->id ] );
            } else {
                // Valeur par défaut, ou écartée : rien à préserver.
                $wpdb->delete( $t, [ 'id' => (int) $l->id ] );
            }
        }
    }

    /* =====================================================================
     * LECTURE DE L'ARBITRAGE
     * ===================================================================== */

    /**
     * Réglementations d'un diagnostic, avec leur fiche.
     *
     * @param int $diagnostic_id
     * @return object[] Triées par niveau (obligatoire d'abord) puis ordre.
     */
    public static function reglementations( $diagnostic_id ) {
        global $wpdb;
        $t  = NPD_Installer::table( 'diag_reglementation' );
        $tr = NPD_Installer::table( 'reglementation' );

        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, r.code, r.libelle, r.nature, r.description, r.echeance, r.actions, r.ordre, r.actif
               FROM {$t} d
               JOIN {$tr} r ON r.id = d.reglementation_id
              WHERE d.diagnostic_id = %d",
            $diagnostic_id
        ) );

        usort( $lignes, function ( $a, $b ) {
            $ra = 'forcage' === $a->origine ? 0 : self::RANG_NIVEAU[ $a->niveau_calcule ] ?? 0;
            $rb = 'forcage' === $b->origine ? 0 : self::RANG_NIVEAU[ $b->niveau_calcule ] ?? 0;
            return ( $rb <=> $ra ) ?: ( (int) $a->ordre <=> (int) $b->ordre );
        } );
        return $lignes;
    }

    /**
     * Réglementations actives qui ne figurent pas encore dans le diagnostic
     * (candidates à un forçage).
     *
     * @param int $diagnostic_id
     * @return object[]
     */
    public static function reglementations_ajoutables( $diagnostic_id ) {
        global $wpdb;
        $t  = NPD_Installer::table( 'diag_reglementation' );
        $tr = NPD_Installer::table( 'reglementation' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.code, r.libelle FROM {$tr} r
              WHERE r.actif = 1
                AND r.id NOT IN ( SELECT reglementation_id FROM {$t} WHERE diagnostic_id = %d )
              ORDER BY r.ordre",
            $diagnostic_id
        ) );
    }

    /**
     * Nombre de réglementations encore « en attente ».
     *
     * @param int $diagnostic_id
     * @return int
     */
    public static function nb_en_attente( $diagnostic_id ) {
        global $wpdb;
        $t = NPD_Installer::table( 'diag_reglementation' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE diagnostic_id = %d AND decision = 'en_attente'",
            $diagnostic_id
        ) );
    }

    /**
     * Enregistre l'arbitrage du consultant.
     *
     * @param int   $diagnostic_id
     * @param array $decisions [ reglementation_id => [ 'decision' => …, 'justification' => … ] ]
     * @param int   $ajout     Réglementation à ajouter par forçage (0 = aucune).
     * @param string $justification_ajout
     * @return string[] Erreurs (vide si tout est enregistré). Rien n'est
     *                  enregistré s'il y a une erreur.
     */
    public static function arbitrer( $diagnostic_id, array $decisions, $ajout = 0, $justification_ajout = '' ) {
        global $wpdb;
        $t       = NPD_Installer::table( 'diag_reglementation' );
        $erreurs = [];
        $maj     = [];

        foreach ( self::reglementations( $diagnostic_id ) as $l ) {
            $id = (int) $l->reglementation_id;
            if ( ! isset( $decisions[ $id ] ) ) {
                continue;
            }
            $decision      = (string) ( $decisions[ $id ]['decision'] ?? $l->decision );
            $justification = trim( (string) ( $decisions[ $id ]['justification'] ?? '' ) );

            // « En attente » n'a de sens que pour une réglementation à vérifier
            // proposée par le calcul ; les autres sont retenues ou écartées.
            $admises = ( 'calcul' === $l->origine && 'a_verifier' === $l->niveau_calcule )
                ? self::DECISIONS
                : [ 'retenue', 'ecartee' ];
            if ( ! in_array( $decision, $admises, true ) ) {
                $erreurs[] = $l->libelle . ' : décision invalide.';
                continue;
            }
            if ( self::justification_requise( $l->origine, $l->niveau_calcule, $decision ) && '' === $justification ) {
                $erreurs[] = $l->libelle . ' : une justification est nécessaire pour cette décision.';
                continue;
            }
            $maj[ (int) $l->id ] = [ 'decision' => $decision, 'justification' => $justification ];
        }

        $ajout = (int) $ajout;
        if ( $ajout ) {
            $possibles = wp_list_pluck( self::reglementations_ajoutables( $diagnostic_id ), 'libelle', 'id' );
            if ( ! isset( $possibles[ $ajout ] ) ) {
                $erreurs[] = 'Réglementation à ajouter introuvable.';
            } elseif ( '' === trim( $justification_ajout ) ) {
                $erreurs[] = $possibles[ $ajout ] . ' : une justification est nécessaire pour ajouter une réglementation.';
            }
        }

        if ( $erreurs ) {
            return $erreurs;
        }

        $origines = wp_list_pluck( self::reglementations( $diagnostic_id ), 'origine', 'id' );

        $wpdb->query( 'START TRANSACTION' );
        foreach ( $maj as $ligne_id => $champs ) {
            // Écarter une réglementation ajoutée à la main revient à la retirer.
            if ( 'forcage' === ( $origines[ $ligne_id ] ?? '' ) && 'ecartee' === $champs['decision'] ) {
                $wpdb->delete( $t, [ 'id' => $ligne_id ] );
                continue;
            }
            $wpdb->update( $t, $champs, [ 'id' => $ligne_id ] );
        }
        if ( $ajout ) {
            $wpdb->insert( $t, [
                'diagnostic_id'     => (int) $diagnostic_id,
                'reglementation_id' => $ajout,
                'niveau_calcule'    => 'recommande',
                'decision'          => 'retenue',
                'origine'           => 'forcage',
                'critere'           => 'Ajoutée par le consultant.',
                'justification'     => trim( $justification_ajout ),
            ] );
        }
        $wpdb->query( 'COMMIT' );

        return [];
    }

    /* =====================================================================
     * EFFET SUR LE QUESTIONNAIRE
     * ===================================================================== */

    /**
     * Profondeur et cible de chaque sous-thème, d'après les réglementations
     * RETENUES (cadrage §5.4) : maximum des profondeurs, maximum des cibles,
     * et réglementation qui fixe la cible.
     *
     * Un sous-thème qu'aucune réglementation retenue n'exige est posé en
     * profondeur « essentiel », sans cible.
     *
     * @param int $diagnostic_id
     * @return array<string,array> Indexé par code de sous-thème, dans l'ordre
     *                             des thèmes puis des sous-thèmes.
     */
    public static function exigences( $diagnostic_id ) {
        global $wpdb;
        $tt  = NPD_Installer::table( 'theme' );
        $ts  = NPD_Installer::table( 'sous_theme' );
        $te  = NPD_Installer::table( 'exigence' );
        $td  = NPD_Installer::table( 'diag_reglementation' );
        $tr  = NPD_Installer::table( 'reglementation' );

        $resultat = [];
        $sous_themes = $wpdb->get_results(
            "SELECT s.id, s.code, s.libelle, t.code AS theme, t.libelle AS theme_libelle
               FROM {$ts} s JOIN {$tt} t ON t.id = s.theme_id
              WHERE s.actif = 1 AND t.actif = 1
              ORDER BY t.ordre, t.id, s.ordre, s.id"
        );
        foreach ( $sous_themes as $s ) {
            $resultat[ $s->code ] = [
                'code'           => $s->code,
                'libelle'        => $s->libelle,
                'theme'          => $s->theme,
                'theme_libelle'  => $s->theme_libelle,
                'profondeur'     => 'essentiel',
                'cible'          => null,
                'reglementation' => null,
            ];
        }

        $exigences = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.code AS sous_theme, e.profondeur, e.cible, r.libelle AS reglementation
               FROM {$td} d
               JOIN {$tr} r ON r.id = d.reglementation_id
               JOIN {$te} e ON e.reglementation_id = d.reglementation_id
               JOIN {$ts} s ON s.id = e.sous_theme_id
              WHERE d.diagnostic_id = %d AND d.decision = 'retenue'
              ORDER BY r.ordre",
            $diagnostic_id
        ) );

        foreach ( $exigences as $e ) {
            if ( ! isset( $resultat[ $e->sous_theme ] ) ) {
                continue;
            }
            $r = &$resultat[ $e->sous_theme ];
            if ( self::RANG_PROFONDEUR[ $e->profondeur ] > self::RANG_PROFONDEUR[ $r['profondeur'] ] ) {
                $r['profondeur'] = $e->profondeur;
            }
            if ( null === $r['cible'] || (float) $e->cible > $r['cible'] ) {
                $r['cible']          = (float) $e->cible;
                $r['reglementation'] = $e->reglementation;
            }
            unset( $r );
        }

        return $resultat;
    }
}
