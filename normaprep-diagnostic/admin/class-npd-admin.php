<?php
/**
 * Administration du module : import du référentiel et réglages.
 *
 * Menu « NormaPrep Diagnostic », réservé à la capacité npd_gerer :
 *
 *   - Référentiel : version active, contenu en base, fichiers détectés,
 *                   bouton d'import et compte rendu du dernier import ;
 *   - Réglages    : durée de conservation, administrateur de réattribution,
 *                   trace de la dernière purge.
 *
 * Les écrans des consultants (liste des diagnostics, fiche mission…)
 * arriveront au lot 3.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Admin {

    /** Identifiants des pages. */
    const PAGE_REFERENTIEL = 'npd-referentiel';
    const PAGE_REGLAGES    = 'npd-reglages';

    /** Libellés des tables dans le compte rendu d'import. */
    const LIBELLES = [
        'referentiel'     => 'Version du référentiel',
        'theme'           => 'Thèmes',
        'sous_theme'      => 'Sous-thèmes',
        'question'        => 'Questions',
        'recommandation'  => 'Recommandations',
        'prestation'      => 'Prestations',
        'profil_question' => 'Questions de profilage',
        'profil_choix'    => 'Choix de profilage',
        'reglementation'  => 'Réglementations',
        'regle'           => 'Règles de déclenchement',
    ];

    /**
     * Branchements.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menus' ] );
        add_action( 'admin_init', [ __CLASS__, 'enregistrer_reglages' ] );
        add_action( 'admin_post_npd_importer', [ __CLASS__, 'traiter_import' ] );

        // options.php exige par défaut « manage_options » pour enregistrer :
        // on aligne le droit d'enregistrer nos réglages sur celui de les voir.
        add_filter( 'option_page_capability_npd_reglages_groupe', function () {
            return NPD_Roles::CAP_GERER;
        } );
    }

    /**
     * Menu et sous-menus.
     */
    public static function menus() {
        add_menu_page(
            'NormaPrep Diagnostic',
            'NormaPrep Diagnostic',
            NPD_Roles::CAP_GERER,
            self::PAGE_REFERENTIEL,
            [ __CLASS__, 'page_referentiel' ],
            'dashicons-shield',
            31
        );
        add_submenu_page(
            self::PAGE_REFERENTIEL,
            'Référentiel',
            'Référentiel',
            NPD_Roles::CAP_GERER,
            self::PAGE_REFERENTIEL,
            [ __CLASS__, 'page_referentiel' ]
        );
        // Lien vers l'espace consultant (page publique), où se mènent les diagnostics.
        $page = (int) get_option( 'npd_page_espace_id' );
        if ( $page ) {
            global $submenu;
            $submenu[ self::PAGE_REFERENTIEL ][] = [ 'Espace consultant ↗', NPD_Roles::CAP_MENER, get_permalink( $page ) ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
        }
        add_submenu_page(
            self::PAGE_REFERENTIEL,
            'Réglages',
            'Réglages',
            NPD_Roles::CAP_GERER,
            self::PAGE_REGLAGES,
            [ __CLASS__, 'page_reglages' ]
        );
    }

    /* =====================================================================
     * RÉFÉRENTIEL
     * ===================================================================== */

    /**
     * Page « Référentiel ».
     *
     * L'analyse des fichiers est faite à l'affichage, SANS rien écrire : on
     * voit les erreurs à corriger avant même de lancer l'import.
     */
    public static function page_referentiel() {
        if ( ! current_user_can( NPD_Roles::CAP_GERER ) ) {
            wp_die( 'Accès refusé.' );
        }

        $cle     = self::cle_rapport();
        $rapport = get_transient( $cle );
        if ( $rapport ) {
            delete_transient( $cle );
        }

        $dossier = NPD_Importer::dossier();
        $analyse = NPD_Validateur::analyser( $dossier );
        $actif   = self::version_active();
        ?>
        <div class="wrap">
            <h1>NormaPrep Diagnostic — Référentiel</h1>

            <?php if ( $rapport ) : ?>
                <?php self::afficher_rapport( $rapport ); ?>
            <?php endif; ?>

            <h2>Version active</h2>
            <?php if ( $actif ) : ?>
                <p>
                    <strong><?php echo esc_html( $actif->version ); ?></strong>
                    <?php if ( $actif->libelle ) : ?>— <?php echo esc_html( $actif->libelle ); ?><?php endif; ?>,
                    importée le <?php echo esc_html( mysql2date( 'd/m/Y à H:i', $actif->importe_le ) ); ?>.
                </p>
                <?php self::afficher_contenu_en_base(); ?>
            <?php else : ?>
                <p>Aucun référentiel importé pour l'instant.</p>
            <?php endif; ?>

            <h2>Fichiers détectés</h2>
            <p>Dossier : <code><?php echo esc_html( $dossier ); ?></code></p>
            <?php if ( $analyse['fichiers'] ) : ?>
                <ul style="list-style:disc;margin-left:2em">
                    <?php foreach ( $analyse['fichiers'] as $f ) : ?>
                        <li><code><?php echo esc_html( $f ); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><em>Aucun fichier.</em> Le format attendu est décrit dans <code>docs/format-donnees.md</code>.</p>
            <?php endif; ?>

            <?php if ( $analyse['erreurs'] ) : ?>
                <div class="notice notice-error inline">
                    <p><strong><?php echo esc_html( count( $analyse['erreurs'] ) ); ?> erreur(s) à corriger avant l'import :</strong></p>
                    <ul style="list-style:disc;margin-left:2em">
                        <?php foreach ( $analyse['erreurs'] as $e ) : ?>
                            <li><?php echo esc_html( $e ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif ( $analyse['fichiers'] ) : ?>
                <div class="notice notice-success inline">
                    <p>Les fichiers sont valides : version <strong><?php echo esc_html( $analyse['modele']['referentiel']['version'] ); ?></strong> prête à être importée.</p>
                </div>
            <?php endif; ?>

            <?php self::afficher_avertissements( $analyse['avertissements'] ); ?>

            <h2>Importer</h2>
            <p>
                L'import peut être relancé sans créer de doublon : les éléments existants
                sont mis à jour. Un élément retiré des fichiers est supprimé, ou seulement
                désactivé s'il est déjà utilisé par un diagnostic. En cas d'erreur, rien
                n'est modifié.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="npd_importer">
                <?php wp_nonce_field( 'npd_importer', 'npd_nonce' ); ?>
                <p>
                    <button type="submit" class="button button-primary" <?php disabled( ! $analyse['valide'] ); ?>>
                        Importer le référentiel
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Traite le bouton « Importer ».
     */
    public static function traiter_import() {
        if ( ! current_user_can( NPD_Roles::CAP_GERER ) ) {
            wp_die( 'Accès refusé.' );
        }
        check_admin_referer( 'npd_importer', 'npd_nonce' );

        $resultat = NPD_Importer::importer();
        set_transient( self::cle_rapport(), $resultat, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_REFERENTIEL ) );
        exit;
    }

    /**
     * Compte rendu du dernier import.
     *
     * @param array $r Résultat de NPD_Importer::importer().
     */
    private static function afficher_rapport( array $r ) {
        if ( ! $r['succes'] ) {
            ?>
            <div class="notice notice-error">
                <p><strong>Import refusé : aucune modification enregistrée.</strong></p>
                <ul style="list-style:disc;margin-left:2em">
                    <?php foreach ( $r['erreurs'] as $e ) : ?>
                        <li><?php echo esc_html( $e ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-success">
            <p><strong>Import réussi : version <?php echo esc_html( $r['version'] ); ?> active.</strong></p>
        </div>
        <table class="widefat striped" style="max-width:720px">
            <thead>
                <tr>
                    <th>Élément</th>
                    <th>Créés</th>
                    <th>Mis à jour</th>
                    <th>Désactivés</th>
                    <th>Supprimés</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( self::LIBELLES as $table => $libelle ) :
                if ( empty( $r['stats'][ $table ] ) ) {
                    continue;
                }
                $s = $r['stats'][ $table ];
                ?>
                <tr>
                    <td><?php echo esc_html( $libelle ); ?></td>
                    <td><?php echo esc_html( $s['crees'] ); ?></td>
                    <td><?php echo esc_html( $s['mis_a_jour'] ); ?></td>
                    <td><?php echo esc_html( $s['desactives'] ); ?></td>
                    <td><?php echo esc_html( $s['supprimes'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Avertissements de validation (n'empêchent pas l'import).
     *
     * @param string[] $avertissements
     */
    private static function afficher_avertissements( array $avertissements ) {
        if ( ! $avertissements ) {
            return;
        }
        ?>
        <div class="notice notice-warning inline">
            <p><strong><?php echo esc_html( count( $avertissements ) ); ?> avertissement(s), sans effet bloquant :</strong></p>
            <ul style="list-style:disc;margin-left:2em">
                <?php foreach ( $avertissements as $a ) : ?>
                    <li><?php echo esc_html( $a ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Nombre d'éléments actifs en base, par type.
     */
    private static function afficher_contenu_en_base() {
        global $wpdb;
        $lignes = [
            'theme'           => 'Thèmes',
            'sous_theme'      => 'Sous-thèmes',
            'question'        => 'Questions',
            'recommandation'  => 'Recommandations',
            'prestation'      => 'Prestations',
            'profil_question' => 'Questions de profilage',
            'reglementation'  => 'Réglementations',
        ];
        echo '<p>';
        $morceaux = [];
        foreach ( $lignes as $table => $libelle ) {
            $t          = NPD_Installer::table( $table );
            $n          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE actif = 1" );
            $morceaux[] = esc_html( $libelle ) . ' : <strong>' . esc_html( $n ) . '</strong>';
        }
        echo implode( ' · ', $morceaux ); // Morceaux déjà échappés.
        echo '</p>';
    }

    /**
     * @return object|null Version active du référentiel.
     */
    private static function version_active() {
        global $wpdb;
        $t = NPD_Installer::table( 'referentiel' );
        return $wpdb->get_row( "SELECT * FROM {$t} WHERE actif = 1 ORDER BY id DESC LIMIT 1" );
    }

    /**
     * Clé du compte rendu, propre à l'utilisateur : deux administrateurs qui
     * importent en même temps ne lisent pas le rapport l'un de l'autre.
     *
     * @return string
     */
    private static function cle_rapport() {
        return NPD_Importer::TRANSIENT_RAPPORT . '_' . get_current_user_id();
    }

    /* =====================================================================
     * RÉGLAGES
     * ===================================================================== */

    /**
     * Déclare l'option auprès de l'API des réglages de WordPress.
     */
    public static function enregistrer_reglages() {
        register_setting( 'npd_reglages_groupe', NPD_Reglages::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ 'NPD_Reglages', 'nettoyer' ],
            'default'           => NPD_Reglages::defauts(),
        ] );
    }

    /**
     * Page « Réglages ».
     */
    public static function page_reglages() {
        if ( ! current_user_can( NPD_Roles::CAP_GERER ) ) {
            wp_die( 'Accès refusé.' );
        }

        $r      = NPD_Reglages::tous();
        $admins = get_users( [
            'capability' => NPD_Roles::CAP_GERER,
            'orderby'    => 'display_name',
        ] );
        $purge  = get_option( 'npd_derniere_purge' );
        $o      = NPD_Reglages::OPTION;
        ?>
        <div class="wrap">
            <h1>NormaPrep Diagnostic — Réglages</h1>
            <?php
            // Hors du menu « Réglages » de WordPress, le message de
            // confirmation n'est pas affiché automatiquement.
            if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                add_settings_error( NPD_Reglages::OPTION, 'npd_enregistre', 'Réglages enregistrés.', 'success' );
            }
            settings_errors( NPD_Reglages::OPTION );
            ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'npd_reglages_groupe' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="npd_duree">Durée de conservation</label></th>
                        <td>
                            <input type="number" id="npd_duree" class="small-text"
                                name="<?php echo esc_attr( $o ); ?>[duree_conservation]"
                                min="<?php echo esc_attr( NPD_Reglages::DUREE_MIN ); ?>"
                                max="<?php echo esc_attr( NPD_Reglages::DUREE_MAX ); ?>"
                                value="<?php echo esc_attr( $r['duree_conservation'] ); ?>"> mois
                            <p class="description">
                                Comptée depuis la finalisation du diagnostic, ou depuis sa dernière
                                modification s'il n'a jamais été finalisé. À échéance, le diagnostic
                                est supprimé définitivement. Modifier cette durée recalcule l'échéance
                                de tous les diagnostics existants.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="npd_admin">Réattribution</label></th>
                        <td>
                            <select id="npd_admin" name="<?php echo esc_attr( $o ); ?>[admin_reattribution]">
                                <option value="0" <?php selected( (int) $r['admin_reattribution'], 0 ); ?>>Premier administrateur du site</option>
                                <?php foreach ( $admins as $a ) : ?>
                                    <option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( (int) $r['admin_reattribution'], (int) $a->ID ); ?>>
                                        <?php echo esc_html( $a->display_name . ' (' . $a->user_email . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Compte qui reçoit les diagnostics d'un consultant dont le compte est
                                supprimé. Le nom du consultant d'origine est conservé.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Purge automatique</h2>
            <p>
                Prochain passage :
                <?php
                $prochain = wp_next_scheduled( NPD_Purge::EVENEMENT );
                echo $prochain
                    ? esc_html( wp_date( 'd/m/Y à H:i', $prochain ) )
                    : '<em>non planifié</em>';
                ?>
                <?php if ( is_array( $purge ) ) : ?>
                    — dernier passage le <?php echo esc_html( mysql2date( 'd/m/Y à H:i', $purge['date'] ) ); ?>,
                    <?php echo esc_html( (int) $purge['supprimes'] ); ?> diagnostic(s) supprimé(s).
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
