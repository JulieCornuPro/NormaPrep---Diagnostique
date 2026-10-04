<?php
/**
 * Plugin Name:       NormaPrep Diagnostic
 * Description:       Diagnostic de la maturité cyber (Gouvernance, Infrastructure, Applicatif) mené par un consultant : profilage réglementaire, questionnaire de maturité 0 à 5, scores, recommandations et rapport.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NormaPrep
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       normaprep-diagnostic
 */

/* -------------------------------------------------------------------------
 * 1. Garde-fou de sécurité
 * -------------------------------------------------------------------------
 * Empêche l'accès direct au fichier via l'URL : si WordPress n'est pas
 * chargé, la constante ABSPATH n'existe pas et l'on s'arrête.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 2. Constantes du plugin
 * -------------------------------------------------------------------------
 * Le plugin est AUTONOME : il ne dépend pas de NormaPrep Quiz et n'en
 * réutilise aucune constante, classe ni table. Son préfixe (NPD / npd_) est
 * distinct de celui du quiz (NPQ / npq_), les deux peuvent cohabiter.
 */

// Version courante. Doit rester synchronisée avec la ligne « Version: » ci-dessus.
define( 'NPD_VERSION', '0.1.0' );

// Chemin absolu du dossier du plugin (pour charger des fichiers PHP).
define( 'NPD_PATH', plugin_dir_path( __FILE__ ) );

// URL du dossier du plugin (pour charger des styles ou des scripts).
define( 'NPD_URL', plugin_dir_url( __FILE__ ) );

// Préfixe de nos tables, combiné au préfixe WordPress : « wp_npd_diagnostic ».
define( 'NPD_TABLE_PREFIX', 'npd_' );

/* -------------------------------------------------------------------------
 * 3. Activation
 * -------------------------------------------------------------------------
 * S'exécute une seule fois, au clic sur « Activer » : création des tables,
 * du rôle consultant, des réglages par défaut et de la purge quotidienne.
 */
function npd_activation() {
    require_once NPD_PATH . 'includes/class-npd-installer.php';
    NPD_Installer::creer_tables();

    require_once NPD_PATH . 'includes/class-npd-roles.php';
    NPD_Roles::creer();

    require_once NPD_PATH . 'includes/class-npd-reglages.php';
    NPD_Reglages::initialiser();

    require_once NPD_PATH . 'includes/class-npd-diagnostics.php';
    require_once NPD_PATH . 'includes/class-npd-purge.php';
    NPD_Purge::planifier();
}
register_activation_hook( __FILE__, 'npd_activation' );

/* -------------------------------------------------------------------------
 * 4. Désactivation
 * -------------------------------------------------------------------------
 * Désactiver n'est pas désinstaller : on NE touche PAS aux données. On
 * décroche seulement la purge planifiée, qu'un plugin inactif ne doit plus
 * réveiller. Le nettoyage complet vit dans uninstall.php.
 */
function npd_desactivation() {
    require_once NPD_PATH . 'includes/class-npd-diagnostics.php';
    require_once NPD_PATH . 'includes/class-npd-purge.php';
    NPD_Purge::deplanifier();
}
register_deactivation_hook( __FILE__, 'npd_desactivation' );

/* -------------------------------------------------------------------------
 * 5. Chargement
 * -------------------------------------------------------------------------
 * Point de départ du fonctionnement normal, à chaque requête.
 */
function npd_init() {

    // Schéma de la base : rejoué seulement si le texte des définitions a
    // changé (empreinte), sur le modèle de NormaPrep Quiz.
    require_once NPD_PATH . 'includes/class-npd-installer.php';
    NPD_Installer::verifier_schema();

    // Rôle consultant et capacités.
    require_once NPD_PATH . 'includes/class-npd-roles.php';
    NPD_Roles::init();

    // Réglages (durée de conservation, administrateur de réattribution).
    require_once NPD_PATH . 'includes/class-npd-reglages.php';

    // Cycle de vie des diagnostics : suppression, échéances, réattribution.
    require_once NPD_PATH . 'includes/class-npd-diagnostics.php';
    NPD_Diagnostics::init();

    // Purge quotidienne des diagnostics échus.
    require_once NPD_PATH . 'includes/class-npd-purge.php';
    NPD_Purge::init();

    // Administration : import du référentiel et réglages.
    if ( is_admin() ) {
        require_once NPD_PATH . 'database/class-npd-validateur.php';
        require_once NPD_PATH . 'database/class-npd-importer.php';
        require_once NPD_PATH . 'admin/class-npd-admin.php';
        NPD_Admin::init();
    }
}
add_action( 'plugins_loaded', 'npd_init' );
