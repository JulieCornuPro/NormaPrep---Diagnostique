<?php
/**
 * Désinstallation complète de NormaPrep Diagnostic.
 *
 * Exécuté par WordPress uniquement lorsque l'on clique sur « Supprimer »
 * pour l'extension (pas à la simple désactivation). Conformément au cadrage,
 * TOUTES les données du module disparaissent : tables (référentiel et
 * diagnostics), options, rôle consultant, capacités et tâche planifiée.
 *
 * @package NormaPrep_Diagnostic
 */

// Garde-fou : ce fichier ne s'exécute que dans le cadre d'une désinstallation.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! defined( 'NPD_PATH' ) ) {
    define( 'NPD_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'NPD_TABLE_PREFIX' ) ) {
    define( 'NPD_TABLE_PREFIX', 'npd_' );
}

require_once NPD_PATH . 'includes/class-npd-installer.php';
require_once NPD_PATH . 'includes/class-npd-roles.php';
require_once NPD_PATH . 'includes/class-npd-purge.php';

global $wpdb;

// 1. Tâche planifiée.
NPD_Purge::deplanifier();

// 2. Tables.
foreach ( NPD_Installer::TABLES as $nom ) {
    $table = NPD_Installer::table( $nom );
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// 3. Page « Espace consultant » (supprimée définitivement, pas mise à la corbeille).
$npd_page = (int) get_option( 'npd_page_espace_id' );
if ( $npd_page ) {
    wp_delete_post( $npd_page, true );
}
delete_option( 'npd_page_espace_id' );

// 4. Options et comptes rendus (dont les messages de l'espace consultant).
delete_option( NPD_Installer::OPT_EMPREINTE );
delete_option( 'npd_db_version' );
delete_option( 'npd_reglages' );
delete_option( 'npd_derniere_purge' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_npd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_npd\\_%'" );

// 5. Rôle et capacités.
NPD_Roles::supprimer();
