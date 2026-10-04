<?php
/**
 * Réglages du module.
 *
 * Deux réglages en première version, regroupés dans une seule option
 * « npd_reglages » :
 *
 *   - duree_conservation  : nombre de mois de conservation d'un diagnostic,
 *     comptés depuis sa finalisation (ou, pour un brouillon jamais finalisé,
 *     depuis sa dernière modification). 12 par défaut.
 *   - admin_reattribution : compte administrateur qui reçoit les diagnostics
 *     d'un consultant supprimé. 0 = le premier administrateur trouvé.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Reglages {

    /** Nom de l'option WordPress. */
    const OPTION = 'npd_reglages';

    /** Bornes de la durée de conservation, en mois. */
    const DUREE_MIN = 1;
    const DUREE_MAX = 120;

    /**
     * Valeurs par défaut.
     *
     * @return array
     */
    public static function defauts() {
        return [
            'duree_conservation'  => 12,
            'admin_reattribution' => 0,
        ];
    }

    /**
     * Crée l'option si elle n'existe pas. Appelée à l'activation.
     */
    public static function initialiser() {
        if ( false === get_option( self::OPTION ) ) {
            add_option( self::OPTION, self::defauts() );
        }
    }

    /**
     * Tous les réglages, complétés par les valeurs par défaut.
     *
     * @return array
     */
    public static function tous() {
        $valeurs = get_option( self::OPTION, [] );
        return array_merge( self::defauts(), is_array( $valeurs ) ? $valeurs : [] );
    }

    /**
     * Durée de conservation en mois.
     *
     * @return int
     */
    public static function duree_conservation() {
        $duree = (int) self::tous()['duree_conservation'];
        return max( self::DUREE_MIN, min( self::DUREE_MAX, $duree ) );
    }

    /**
     * Identifiant du compte qui reçoit les diagnostics réattribués.
     *
     * Le compte réglé est retenu s'il existe encore, n'est pas exclu, et
     * possède la capacité de gestion. Sinon, repli sur le premier
     * administrateur valide : une réattribution ne doit jamais échouer faute
     * de destinataire, sans quoi les diagnostics deviendraient orphelins.
     *
     * @param int $exclu Compte à écarter (celui que l'on est en train de supprimer).
     * @return int Identifiant, ou 0 si aucun administrateur n'existe.
     */
    public static function admin_reattribution( $exclu = 0 ) {
        $exclu  = (int) $exclu;
        $choisi = (int) self::tous()['admin_reattribution'];

        if ( $choisi && $choisi !== $exclu && user_can( $choisi, NPD_Roles::CAP_GERER ) ) {
            return $choisi;
        }

        $admins = get_users( [
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ] );
        foreach ( $admins as $id ) {
            if ( (int) $id !== $exclu ) {
                return (int) $id;
            }
        }
        return 0;
    }

    /**
     * Nettoie les valeurs soumises par le formulaire de réglages.
     *
     * @param mixed $entree
     * @return array
     */
    public static function nettoyer( $entree ) {
        $entree  = is_array( $entree ) ? $entree : [];
        $propres = self::defauts();

        if ( isset( $entree['duree_conservation'] ) ) {
            $propres['duree_conservation'] = max(
                self::DUREE_MIN,
                min( self::DUREE_MAX, absint( $entree['duree_conservation'] ) )
            );
        }

        if ( isset( $entree['admin_reattribution'] ) ) {
            $id = absint( $entree['admin_reattribution'] );
            // On n'accepte qu'un compte capable de gérer le module.
            $propres['admin_reattribution'] = ( $id && user_can( $id, NPD_Roles::CAP_GERER ) ) ? $id : 0;
        }

        return $propres;
    }
}
