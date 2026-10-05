<?php
/**
 * Cycle de vie des diagnostics : création, accès, échéances de conservation,
 * suppression définitive et réattribution.
 *
 * Le lot 1 pose le socle. Les écrans (fiche mission, questionnaire,
 * résultats) viendront aux lots suivants et s'appuieront sur ces fonctions.
 *
 * ÉCHÉANCE DE CONSERVATION
 * ------------------------
 * Chaque diagnostic porte sa date de purge (purge_le) :
 *
 *   - finalisé      : finalise_le + durée de conservation ;
 *   - non finalisé  : modifie_le  + durée de conservation, pour qu'un
 *                     brouillon oublié ne reste pas indéfiniment en base.
 *
 * Elle est recalculée à chaque modification, et pour tous les diagnostics
 * quand l'administrateur change la durée. La purge n'a plus qu'à supprimer
 * ce qui est échu.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Diagnostics {

    /** Statuts possibles. */
    const STATUT_BROUILLON = 'brouillon';
    const STATUT_EN_COURS  = 'en_cours';
    const STATUT_FINALISE  = 'finalise';

    /** Tables rattachées à un diagnostic, à vider avant lui. */
    const TABLES_LIEES = [ 'score', 'reponse', 'diag_reglementation', 'profil_reponse' ];

    /**
     * Branchements.
     */
    public static function init() {
        // Suppression d'un compte : on réattribue AVANT que WordPress ne
        // l'efface (« delete_user » passe avant la suppression effective).
        add_action( 'delete_user', [ __CLASS__, 'reattribuer_avant_suppression' ], 10, 1 );
        add_action( 'wpmu_delete_user', [ __CLASS__, 'reattribuer_avant_suppression' ], 10, 1 );

        // Changement de durée de conservation : on recalcule toutes les échéances.
        add_action( 'update_option_' . NPD_Reglages::OPTION, [ __CLASS__, 'apres_changement_reglages' ], 10, 2 );
    }

    /* =====================================================================
     * LECTURE ET ACCÈS
     * ===================================================================== */

    /**
     * Charge un diagnostic.
     *
     * @param int $id
     * @return object|null
     */
    public static function obtenir( $id ) {
        global $wpdb;
        $t = NPD_Installer::table( 'diagnostic' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
    }

    /**
     * L'utilisateur peut-il accéder à ce diagnostic ?
     *
     * Règle de cloisonnement : un consultant ne voit que SES diagnostics ;
     * la capacité de gestion donne accès à tous. Toute lecture ou écriture
     * d'un diagnostic doit passer par ce contrôle.
     *
     * @param int      $diagnostic_id
     * @param int|null $user_id Compte à contrôler ; par défaut le compte connecté.
     * @return bool
     */
    public static function peut_acceder( $diagnostic_id, $user_id = null ) {
        $user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
        if ( ! $user_id ) {
            return false;
        }
        if ( user_can( $user_id, NPD_Roles::CAP_GERER ) ) {
            return (bool) self::obtenir( $diagnostic_id );
        }
        if ( ! user_can( $user_id, NPD_Roles::CAP_MENER ) ) {
            return false;
        }
        $diag = self::obtenir( $diagnostic_id );
        return $diag && (int) $diag->consultant_id === $user_id;
    }

    /* =====================================================================
     * CRÉATION ET MODIFICATION
     * ===================================================================== */

    /**
     * Crée un diagnostic en brouillon, rattaché à la version active du référentiel.
     *
     * @param int   $consultant_id
     * @param array $champs client, perimetre, interlocuteurs, date_entretien.
     * @return int Identifiant créé, 0 en cas d'échec.
     */
    public static function creer( $consultant_id, array $champs = [] ) {
        global $wpdb;
        $maintenant = current_time( 'mysql' );

        $ok = $wpdb->insert( NPD_Installer::table( 'diagnostic' ), [
            'consultant_id'  => (int) $consultant_id,
            'referentiel_id' => self::referentiel_actif_id(),
            'client'         => (string) ( $champs['client'] ?? '' ),
            'perimetre'      => (string) ( $champs['perimetre'] ?? '' ),
            'interlocuteurs' => (string) ( $champs['interlocuteurs'] ?? '' ),
            'date_entretien' => $champs['date_entretien'] ?? null,
            'statut'         => self::STATUT_BROUILLON,
            'cree_le'        => $maintenant,
            'modifie_le'     => $maintenant,
            'purge_le'       => self::echeance( $maintenant ),
        ] );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Marque un diagnostic comme modifié et repousse son échéance s'il n'est
     * pas finalisé. À appeler après toute saisie.
     *
     * @param int $id
     */
    public static function toucher( $id ) {
        global $wpdb;
        $diag = self::obtenir( $id );
        if ( ! $diag ) {
            return;
        }
        $maintenant = current_time( 'mysql' );
        $wpdb->update(
            NPD_Installer::table( 'diagnostic' ),
            [
                'modifie_le' => $maintenant,
                'purge_le'   => self::echeance( $maintenant, $diag->finalise_le ),
            ],
            [ 'id' => (int) $id ]
        );
    }

    /**
     * Met à jour la fiche mission.
     *
     * @param int   $id
     * @param array $champs client, perimetre, interlocuteurs, date_entretien.
     */
    public static function mettre_a_jour_mission( $id, array $champs ) {
        global $wpdb;
        $wpdb->update( NPD_Installer::table( 'diagnostic' ), [
            'client'         => (string) ( $champs['client'] ?? '' ),
            'perimetre'      => (string) ( $champs['perimetre'] ?? '' ),
            'interlocuteurs' => (string) ( $champs['interlocuteurs'] ?? '' ),
            'date_entretien' => $champs['date_entretien'] ?? null,
        ], [ 'id' => (int) $id ] );
        self::toucher( $id );
    }

    /**
     * Change le statut (brouillon → en_cours…).
     *
     * @param int    $id
     * @param string $statut
     */
    public static function changer_statut( $id, $statut ) {
        global $wpdb;
        $wpdb->update( NPD_Installer::table( 'diagnostic' ), [ 'statut' => $statut ], [ 'id' => (int) $id ] );
    }

    /**
     * Un diagnostic finalisé n'est plus modifiable (il faudra le rouvrir).
     *
     * @param object $diag
     * @return bool
     */
    public static function est_modifiable( $diag ) {
        return $diag && self::STATUT_FINALISE !== $diag->statut;
    }

    /**
     * Diagnostics visibles par un utilisateur : les siens, ou tous pour qui
     * peut gérer le module. Les plus récemment modifiés d'abord.
     *
     * @param int $user_id
     * @return object[] Avec, en plus, le nom du consultant.
     */
    public static function lister( $user_id ) {
        global $wpdb;
        $t = NPD_Installer::table( 'diagnostic' );

        if ( user_can( $user_id, NPD_Roles::CAP_GERER ) ) {
            $lignes = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY modifie_le DESC, id DESC" );
        } elseif ( user_can( $user_id, NPD_Roles::CAP_MENER ) ) {
            $lignes = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$t} WHERE consultant_id = %d ORDER BY modifie_le DESC, id DESC",
                $user_id
            ) );
        } else {
            return [];
        }

        $noms = [];
        foreach ( $lignes as $l ) {
            $cid = (int) $l->consultant_id;
            if ( ! isset( $noms[ $cid ] ) ) {
                $u            = get_userdata( $cid );
                $noms[ $cid ] = $u ? $u->display_name : '#' . $cid;
            }
            $l->consultant_nom = $noms[ $cid ];
        }
        return $lignes;
    }

    /**
     * Identifiant de la version active du référentiel, ou null si aucun import.
     *
     * @return int|null
     */
    public static function referentiel_actif_id() {
        global $wpdb;
        $t  = NPD_Installer::table( 'referentiel' );
        $id = $wpdb->get_var( "SELECT id FROM {$t} WHERE actif = 1 ORDER BY id DESC LIMIT 1" );
        return $id ? (int) $id : null;
    }

    /* =====================================================================
     * ÉCHÉANCES DE CONSERVATION
     * ===================================================================== */

    /**
     * Date de purge d'un diagnostic.
     *
     * @param string      $modifie_le  Date de dernière modification (Y-m-d H:i:s).
     * @param string|null $finalise_le Date de finalisation, si finalisé.
     * @param int|null    $duree       Durée en mois ; par défaut le réglage.
     * @return string Date (Y-m-d H:i:s).
     */
    public static function echeance( $modifie_le, $finalise_le = null, $duree = null ) {
        $duree  = ( null === $duree ) ? NPD_Reglages::duree_conservation() : (int) $duree;
        $depart = $finalise_le ? $finalise_le : $modifie_le;

        $date = new DateTime( $depart );
        $date->modify( '+' . $duree . ' months' );
        return $date->format( 'Y-m-d H:i:s' );
    }

    /**
     * Recalcule l'échéance de tous les diagnostics.
     *
     * Fait en PHP, ligne par ligne, plutôt qu'en une requête SQL : le calcul
     * de dates reste ainsi le même que dans echeance(), quelle que soit la
     * base. Le volume (quelques centaines de diagnostics) le permet.
     *
     * @return int Nombre de diagnostics mis à jour.
     */
    public static function recalculer_echeances() {
        global $wpdb;
        $t     = NPD_Installer::table( 'diagnostic' );
        $lignes = $wpdb->get_results( "SELECT id, modifie_le, finalise_le FROM {$t}" );
        $duree = NPD_Reglages::duree_conservation();

        foreach ( $lignes as $l ) {
            $wpdb->update(
                $t,
                [ 'purge_le' => self::echeance( $l->modifie_le, $l->finalise_le, $duree ) ],
                [ 'id' => (int) $l->id ]
            );
        }
        return count( $lignes );
    }

    /**
     * Après enregistrement des réglages : recalcul si la durée a changé.
     *
     * @param mixed $ancien
     * @param mixed $nouveau
     */
    public static function apres_changement_reglages( $ancien, $nouveau ) {
        $avant = is_array( $ancien ) ? (int) ( $ancien['duree_conservation'] ?? 0 ) : 0;
        $apres = is_array( $nouveau ) ? (int) ( $nouveau['duree_conservation'] ?? 0 ) : 0;
        if ( $avant !== $apres ) {
            self::recalculer_echeances();
        }
    }

    /* =====================================================================
     * SUPPRESSION DÉFINITIVE
     * ===================================================================== */

    /**
     * Supprime définitivement un diagnostic et tout ce qui s'y rattache.
     *
     * Le contrôle d'accès est de la responsabilité de l'appelant (écran,
     * purge) : cette fonction est aussi utilisée par la purge automatique,
     * qui n'agit au nom d'aucun utilisateur.
     *
     * Exécutée dans une transaction : soit tout disparaît, soit rien.
     *
     * @param int $id
     * @return bool Vrai si le diagnostic existait et a été supprimé.
     */
    public static function supprimer( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::obtenir( $id ) ) {
            return false;
        }

        $wpdb->query( 'START TRANSACTION' );

        $ok = true;
        foreach ( self::TABLES_LIEES as $table ) {
            if ( false === $wpdb->delete( NPD_Installer::table( $table ), [ 'diagnostic_id' => $id ] ) ) {
                $ok = false;
                break;
            }
        }
        if ( $ok && ! $wpdb->delete( NPD_Installer::table( 'diagnostic' ), [ 'id' => $id ] ) ) {
            $ok = false;
        }

        $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
        return $ok;
    }

    /* =====================================================================
     * RÉATTRIBUTION
     * ===================================================================== */

    /**
     * Avant la suppression d'un compte, transfère ses diagnostics à
     * l'administrateur désigné, pour la traçabilité.
     *
     * Le nom du consultant d'origine et la date sont conservés. Si le
     * diagnostic avait déjà été réattribué, on garde le PREMIER consultant :
     * c'est lui qui a mené la mission.
     *
     * @param int $user_id Compte en cours de suppression.
     * @return int Nombre de diagnostics réattribués.
     */
    public static function reattribuer_avant_suppression( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        $t       = NPD_Installer::table( 'diagnostic' );

        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE consultant_id = %d", $user_id ) );
        if ( ! $ids ) {
            return 0;
        }

        $destinataire = NPD_Reglages::admin_reattribution( $user_id );
        if ( ! $destinataire ) {
            // Aucun administrateur ne subsisterait : situation qui ne se
            // produit pas dans WordPress (on ne peut supprimer le dernier
            // administrateur depuis l'interface). On laisse les données en
            // place plutôt que de les perdre.
            return 0;
        }

        $user = get_userdata( $user_id );
        $nom  = $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id;
        $quand = current_time( 'mysql' );

        foreach ( $ids as $id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$t}
                    SET consultant_id = %d,
                        consultant_initial = COALESCE(consultant_initial, %s),
                        reattribue_le = %s
                  WHERE id = %d",
                $destinataire, $nom, $quand, (int) $id
            ) );
        }
        return count( $ids );
    }
}
