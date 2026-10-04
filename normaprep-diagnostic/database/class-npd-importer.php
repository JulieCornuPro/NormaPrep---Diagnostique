<?php
/**
 * Import du référentiel (thèmes, sous-thèmes, questions, niveaux,
 * recommandations, prestations, profilage, réglementations, règles,
 * exigences) depuis les fichiers JSON du dossier data/.
 *
 * PRINCIPES
 * ---------
 * 1. Tout ou rien. Les fichiers sont d'abord lus et validés en entier
 *    (NPD_Validateur). À la moindre erreur, rien n'est écrit. L'écriture se
 *    fait ensuite dans une transaction : une panne en cours de route ne
 *    laisse pas un référentiel à moitié importé.
 *
 * 2. Rejouable. Chaque élément est repéré par son code stable (« G3 »,
 *    « G3-Q01 », « NIS2-EE-R1 », « P01-energie ») : un élément existant est
 *    mis à jour, un nouveau est créé. Relancer l'import ne crée aucun doublon.
 *
 * 3. Jamais de perte de diagnostic. Un élément retiré des fichiers est :
 *      - DÉSACTIVÉ s'il est utilisé par un diagnostic (question répondue,
 *        choix de profilage coché, réglementation ressortie) ou s'il porte
 *        encore des éléments désactivés ;
 *      - SUPPRIMÉ sinon.
 *    Un élément désactivé qui réapparaît dans les fichiers est réactivé.
 *
 * Les niveaux, règles et exigences ne sont référencés par aucun diagnostic :
 * ils sont simplement remplacés par le contenu des fichiers.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Importer {

    /** Clé du transient qui transporte le compte rendu jusqu'à l'affichage. */
    const TRANSIENT_RAPPORT = 'npd_rapport_import';

    /**
     * Compteurs par type d'élément : créés, mis à jour, désactivés, supprimés.
     *
     * @var array<string,array<string,int>>
     */
    private $stats = [];

    /**
     * Identifiants rencontrés dans les fichiers, par table.
     *
     * @var array<string,int[]>
     */
    private $vus = [];

    /**
     * Dossier des données. Filtrable, pour les tests ou un dossier externe.
     *
     * @return string
     */
    public static function dossier() {
        return (string) apply_filters( 'npd_dossier_donnees', NPD_PATH . 'data' );
    }

    /**
     * Point d'entrée : valide puis importe.
     *
     * @param string|null $dossier Dossier à importer ; par défaut data/ du plugin.
     * @return array {
     *     @type bool     $succes
     *     @type string[] $erreurs
     *     @type string[] $avertissements
     *     @type array    $stats
     *     @type string   $version
     * }
     */
    public static function importer( $dossier = null ) {
        $analyse = NPD_Validateur::analyser( $dossier ? $dossier : self::dossier() );

        $resultat = [
            'succes'         => false,
            'erreurs'        => $analyse['erreurs'],
            'avertissements' => $analyse['avertissements'],
            'stats'          => [],
            'version'        => '',
        ];
        if ( ! $analyse['valide'] ) {
            return $resultat;
        }

        $importeur = new self();
        $erreur    = $importeur->ecrire( $analyse['modele'] );
        if ( null !== $erreur ) {
            $resultat['erreurs'][] = $erreur;
            return $resultat;
        }

        $resultat['succes']  = true;
        $resultat['stats']   = $importeur->stats;
        $resultat['version'] = $analyse['modele']['referentiel']['version'];
        return $resultat;
    }

    /* =====================================================================
     * ÉCRITURE
     * ===================================================================== */

    /**
     * Écrit le modèle validé, dans une transaction.
     *
     * @param array $modele
     * @return string|null Message d'erreur, ou null si tout s'est bien passé.
     */
    private function ecrire( array $modele ) {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );
        try {
            $this->ecrire_referentiel( $modele['referentiel'] );

            $themes = [];
            foreach ( $modele['themes'] as $code => $t ) {
                $themes[ $code ] = $this->upsert( 'theme', 'code', $code, [
                    'libelle' => $t['libelle'],
                    'couleur' => $t['couleur'],
                    'ordre'   => $t['ordre'],
                    'poids'   => $t['poids'],
                ] );
            }

            $prestations = [];
            foreach ( $modele['prestations'] as $code => $p ) {
                $prestations[ $code ] = $this->upsert( 'prestation', 'code', $code, [
                    'libelle'     => $p['libelle'],
                    'description' => $p['description'],
                    'lien'        => $p['lien'],
                ] );
            }

            $sous_themes = [];
            foreach ( $modele['sous_themes'] as $code => $st ) {
                $sous_themes[ $code ] = $this->ecrire_sous_theme( $st, $themes[ $st['theme'] ], $prestations );
            }

            foreach ( $modele['profil'] as $code => $q ) {
                $this->ecrire_profil_question( $q );
            }

            foreach ( $modele['reglementations'] as $code => $r ) {
                $this->ecrire_reglementation( $r, $sous_themes );
            }

            $this->nettoyer();

        } catch ( RuntimeException $e ) {
            $wpdb->query( 'ROLLBACK' );
            return 'Import annulé, aucune modification enregistrée : ' . $e->getMessage();
        }

        $wpdb->query( 'COMMIT' );
        return null;
    }

    /**
     * Enregistre la version importée et en fait la version active.
     */
    private function ecrire_referentiel( array $r ) {
        global $wpdb;
        $t = NPD_Installer::table( 'referentiel' );

        $id = $this->upsert( 'referentiel', 'version', $r['version'], [
            'libelle'    => $r['libelle'],
            'importe_le' => current_time( 'mysql' ),
        ], false );

        $this->verifier( $wpdb->query( "UPDATE {$t} SET actif = 0" ) );
        $this->verifier( $wpdb->update( $t, [ 'actif' => 1 ], [ 'id' => $id ] ) );
    }

    /**
     * Sous-thème, ses questions avec leurs niveaux, et ses recommandations.
     *
     * @return int Identifiant du sous-thème.
     */
    private function ecrire_sous_theme( array $st, $theme_id, array $prestations ) {
        global $wpdb;

        $st_id = $this->upsert( 'sous_theme', 'code', $st['code'], [
            'theme_id'    => $theme_id,
            'libelle'     => $st['libelle'],
            'description' => $st['description'],
            'ordre'       => $st['ordre'],
            'poids'       => $st['poids'],
        ] );

        $questions = [];
        foreach ( $st['questions'] as $ref => $q ) {
            $q_id = $this->upsert( 'question', 'ref', $ref, [
                'sous_theme_id'   => $st_id,
                'enonce'          => $q['enonce'],
                'aide'            => $q['aide'],
                'profondeur'      => $q['profondeur'],
                'poids'           => $q['poids'],
                'refs_normatives' => self::json( $q['references'] ),
                'ordre'           => $q['ordre'],
            ] );
            $questions[ $ref ] = $q_id;

            // Niveaux : remplacés en bloc (aucun diagnostic n'y fait référence).
            $tn = NPD_Installer::table( 'niveau' );
            $this->verifier( $wpdb->delete( $tn, [ 'question_id' => $q_id ] ) );
            foreach ( $q['niveaux'] as $n => $description ) {
                $this->verifier( $wpdb->insert( $tn, [
                    'question_id' => $q_id,
                    'niveau'      => $n,
                    'description' => $description,
                ] ) );
            }
        }

        foreach ( $st['recommandations'] as $ref => $r ) {
            $this->upsert( 'recommandation', 'ref', $ref, [
                'sous_theme_id' => $st_id,
                'question_id'   => $r['question'] ? $questions[ $r['question'] ] : null,
                'prestation_id' => '' !== $r['prestation'] ? $prestations[ $r['prestation'] ] : null,
                'texte'         => $r['texte'],
                'seuil'         => $r['seuil'],
                'effort'        => $r['effort'],
                'quick_win'     => $r['quick_win'] ? 1 : 0,
            ] );
        }

        return $st_id;
    }

    /**
     * Question de profilage et ses choix. Le code d'un choix est préfixé par
     * celui de sa question (« P01-energie ») pour être unique.
     */
    private function ecrire_profil_question( array $q ) {
        $q_id = $this->upsert( 'profil_question', 'code', $q['code'], [
            'libelle'             => $q['libelle'],
            'aide'                => $q['aide'],
            'type'                => $q['type'],
            'condition_affichage' => null === $q['condition'] ? null : self::json( $q['condition'] ),
            'ordre'               => $q['ordre'],
        ] );

        foreach ( $q['choix'] as $code => $c ) {
            $this->upsert( 'profil_choix', 'code', $q['code'] . '-' . $code, [
                'profil_question_id' => $q_id,
                'libelle'            => $c['libelle'],
                'ordre'              => $c['ordre'],
            ] );
        }
    }

    /**
     * Réglementation, ses règles et ses exigences.
     */
    private function ecrire_reglementation( array $r, array $sous_themes ) {
        global $wpdb;

        $r_id = $this->upsert( 'reglementation', 'code', $r['code'], [
            'libelle'     => $r['libelle'],
            'nature'      => $r['nature'],
            'description' => $r['description'],
            'echeance'    => $r['echeance'],
            'actions'     => self::json( $r['actions'] ),
            'ordre'       => $r['ordre'],
        ] );

        foreach ( $r['regles'] as $ref => $rg ) {
            $this->upsert( 'regle', 'ref', $ref, [
                'reglementation_id' => $r_id,
                'niveau'            => $rg['niveau'],
                'conditions'        => self::json( $rg['conditions'] ),
                'critere'           => $rg['critere'],
                'priorite'          => $rg['priorite'],
            ], false );
        }

        // Exigences : remplacées en bloc pour cette réglementation.
        $te = NPD_Installer::table( 'exigence' );
        $this->verifier( $wpdb->delete( $te, [ 'reglementation_id' => $r_id ] ) );
        foreach ( $r['exigences'] as $st => $e ) {
            $this->verifier( $wpdb->insert( $te, [
                'reglementation_id' => $r_id,
                'sous_theme_id'     => $sous_themes[ $st ],
                'profondeur'        => $e['profondeur'],
                'cible'             => $e['cible'],
            ] ) );
        }
    }

    /* =====================================================================
     * NETTOYAGE DES ÉLÉMENTS RETIRÉS DES FICHIERS
     * ===================================================================== */

    /**
     * Désactive ou supprime ce qui n'apparaît plus dans les fichiers.
     *
     * L'ordre va des feuilles vers la racine : on décide du sort d'un
     * sous-thème une fois connu celui de ses questions.
     */
    private function nettoyer() {
        $t_reponse        = NPD_Installer::table( 'reponse' );
        $t_question       = NPD_Installer::table( 'question' );
        $t_sous_theme     = NPD_Installer::table( 'sous_theme' );
        $t_recommandation = NPD_Installer::table( 'recommandation' );
        $t_profil_reponse = NPD_Installer::table( 'profil_reponse' );
        $t_profil_choix   = NPD_Installer::table( 'profil_choix' );
        $t_diag_regl      = NPD_Installer::table( 'diag_reglementation' );

        // Recommandations : jamais référencées par un diagnostic.
        $this->retirer( 'recommandation', null );

        // Questions : conservées si un diagnostic y a répondu. Les niveaux
        // d'une question supprimée partent avec elle.
        $this->retirer(
            'question',
            "SELECT 1 FROM {$t_reponse} WHERE question_id = %d LIMIT 1",
            [ 'niveau' => 'question_id' ]
        );

        // Sous-thèmes : conservés s'ils portent encore une question.
        $this->retirer(
            'sous_theme',
            "SELECT 1 FROM {$t_question} WHERE sous_theme_id = %d LIMIT 1",
            [ 'exigence' => 'sous_theme_id' ]
        );

        // Thèmes : conservés s'ils portent encore un sous-thème.
        $this->retirer( 'theme', "SELECT 1 FROM {$t_sous_theme} WHERE theme_id = %d LIMIT 1" );

        // Prestations : conservées si une recommandation y renvoie encore.
        $this->retirer( 'prestation', "SELECT 1 FROM {$t_recommandation} WHERE prestation_id = %d LIMIT 1" );

        // Choix de profilage : conservés si un diagnostic les a cochés.
        $this->retirer( 'profil_choix', "SELECT 1 FROM {$t_profil_reponse} WHERE profil_choix_id = %d LIMIT 1" );

        // Questions de profilage : conservées si elles portent encore un choix.
        $this->retirer( 'profil_question', "SELECT 1 FROM {$t_profil_choix} WHERE profil_question_id = %d LIMIT 1" );

        // Règles absentes : supprimées.
        $this->retirer( 'regle', null );

        // Réglementations : conservées si un diagnostic les a fait ressortir.
        // Désactivées ou supprimées, elles perdent leurs règles et exigences :
        // le calcul ne doit plus les proposer.
        $this->retirer(
            'reglementation',
            "SELECT 1 FROM {$t_diag_regl} WHERE reglementation_id = %d LIMIT 1",
            [ 'regle' => 'reglementation_id', 'exigence' => 'reglementation_id' ],
            true
        );
    }

    /**
     * Traite les lignes d'une table absentes des fichiers.
     *
     * @param string      $table         Nom court.
     * @param string|null $sql_usage     Requête (avec %d) qui renvoie une ligne si l'élément est utilisé ;
     *                                   null si la table n'a pas de colonne « actif » à préserver.
     * @param array       $dependances   [ table => colonne ] à supprimer avec l'élément.
     * @param bool        $dependances_aussi_si_desactive Supprimer les dépendances même en cas de désactivation.
     */
    private function retirer( $table, $sql_usage, array $dependances = [], $dependances_aussi_si_desactive = false ) {
        global $wpdb;
        $t   = NPD_Installer::table( $table );
        $vus = $this->vus[ $table ] ?? [];

        $tous    = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$t}" ) );
        $absents = array_diff( $tous, $vus );

        foreach ( $absents as $id ) {
            $utilise = $sql_usage && $wpdb->get_var( $wpdb->prepare( $sql_usage, $id ) );

            if ( $utilise ) {
                $deja_inactif = '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT actif FROM {$t} WHERE id = %d", $id ) );
                if ( ! $deja_inactif ) {
                    $this->verifier( $wpdb->update( $t, [ 'actif' => 0 ], [ 'id' => $id ] ) );
                    $this->compter( $table, 'desactives' );
                }
                if ( $dependances_aussi_si_desactive ) {
                    $this->supprimer_dependances( $dependances, $id );
                }
                continue;
            }

            $this->supprimer_dependances( $dependances, $id );
            $this->verifier( $wpdb->delete( $t, [ 'id' => $id ] ) );
            $this->compter( $table, 'supprimes' );
        }
    }

    private function supprimer_dependances( array $dependances, $id ) {
        global $wpdb;
        foreach ( $dependances as $table => $colonne ) {
            $this->verifier( $wpdb->delete( NPD_Installer::table( $table ), [ $colonne => $id ] ) );
        }
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /**
     * Crée ou met à jour une ligne repérée par sa clé unique, et la note
     * comme « vue ». Une ligne mise à jour est réactivée.
     *
     * @param string $table    Nom court.
     * @param string $colonne  Colonne de la clé unique (code, ref, version).
     * @param string $valeur   Valeur de la clé.
     * @param array  $donnees  Autres colonnes.
     * @param bool   $a_actif  La table possède-t-elle une colonne « actif » ?
     * @return int Identifiant.
     */
    private function upsert( $table, $colonne, $valeur, array $donnees, $a_actif = true ) {
        global $wpdb;
        $t = NPD_Installer::table( $table );

        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE {$colonne} = %s", $valeur ) );

        if ( $a_actif ) {
            $donnees['actif'] = 1;
        }

        if ( $id ) {
            $this->verifier( $wpdb->update( $t, $donnees, [ 'id' => $id ] ) );
            $this->compter( $table, 'mis_a_jour' );
        } else {
            $donnees[ $colonne ] = $valeur;
            $this->verifier( $wpdb->insert( $t, $donnees ) );
            $id = (int) $wpdb->insert_id;
            $this->compter( $table, 'crees' );
        }

        $this->vus[ $table ][] = $id;
        return $id;
    }

    /**
     * Interrompt l'import si une requête a échoué : la transaction sera annulée.
     *
     * @param mixed $retour Valeur renvoyée par $wpdb (false = échec).
     */
    private function verifier( $retour ) {
        global $wpdb;
        if ( false === $retour ) {
            throw new RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'erreur de base de données' );
        }
    }

    /**
     * Encode en JSON en gardant les accents lisibles (« § », « é ») : ces
     * colonnes sont relues par des humains dans la base.
     *
     * @param mixed $valeur
     * @return string
     */
    private static function json( $valeur ) {
        return (string) wp_json_encode( $valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    private function compter( $table, $quoi ) {
        if ( ! isset( $this->stats[ $table ] ) ) {
            $this->stats[ $table ] = [ 'crees' => 0, 'mis_a_jour' => 0, 'desactives' => 0, 'supprimes' => 0 ];
        }
        $this->stats[ $table ][ $quoi ]++;
    }
}
