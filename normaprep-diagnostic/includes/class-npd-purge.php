<?php
/**
 * Purge automatique des diagnostics échus.
 *
 * Une tâche planifiée WordPress (WP-Cron) passe une fois par jour et
 * supprime définitivement les diagnostics dont l'échéance (purge_le) est
 * dépassée. L'échéance elle-même est tenue à jour par NPD_Diagnostics.
 *
 * Rappel : WP-Cron se déclenche lors des visites du site. Sur un site peu
 * fréquenté, la purge peut donc intervenir avec un peu de retard ; elle
 * n'intervient jamais en avance.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Purge {

    /** Nom de la tâche planifiée. */
    const EVENEMENT = 'npd_purge_quotidienne';

    /**
     * Branchements.
     */
    public static function init() {
        add_action( self::EVENEMENT, [ __CLASS__, 'executer' ] );

        // Filet de sécurité : si la tâche a disparu (restauration de base,
        // extension de nettoyage…), on la replanifie.
        if ( ! wp_next_scheduled( self::EVENEMENT ) ) {
            self::planifier();
        }
    }

    /**
     * Planifie la tâche quotidienne si elle ne l'est pas déjà.
     */
    public static function planifier() {
        if ( ! wp_next_scheduled( self::EVENEMENT ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENEMENT );
        }
    }

    /**
     * Retire la tâche planifiée (désactivation, désinstallation).
     */
    public static function deplanifier() {
        wp_clear_scheduled_hook( self::EVENEMENT );
    }

    /**
     * Supprime les diagnostics échus.
     *
     * @param string|null $maintenant Date de référence (Y-m-d H:i:s) ; par défaut l'heure du site.
     * @return int Nombre de diagnostics supprimés.
     */
    public static function executer( $maintenant = null ) {
        global $wpdb;
        $maintenant = $maintenant ? $maintenant : current_time( 'mysql' );
        $t          = NPD_Installer::table( 'diagnostic' );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE purge_le IS NOT NULL AND purge_le <= %s",
            $maintenant
        ) );

        $supprimes = 0;
        foreach ( $ids as $id ) {
            if ( NPD_Diagnostics::supprimer( (int) $id ) ) {
                $supprimes++;
            }
        }

        // Trace de la dernière exécution, affichée dans les réglages.
        update_option( 'npd_derniere_purge', [
            'date'      => $maintenant,
            'supprimes' => $supprimes,
        ], false );

        return $supprimes;
    }
}
