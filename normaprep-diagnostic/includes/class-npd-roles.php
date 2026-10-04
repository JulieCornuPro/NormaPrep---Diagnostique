<?php
/**
 * Rôle consultant et capacités.
 *
 * Deux capacités sur mesure :
 *
 *   - npd_mener_diagnostic : créer, remplir, finaliser, exporter et supprimer
 *     SES diagnostics. Portée par le rôle « Consultant NormaPrep ».
 *   - npd_gerer : tout ce qui précède sur TOUS les diagnostics, plus l'import
 *     du référentiel et les réglages. Ajoutée au rôle administrateur.
 *
 * ATTRIBUTION DU RÔLE
 * -------------------
 * Le diagnostic n'est pas vendu : c'est l'administrateur qui désigne les
 * consultants. L'écran standard de WordPress ne permet de choisir qu'UN rôle
 * par compte ; une personne peut pourtant être à la fois abonnée au quiz et
 * consultante. On ajoute donc une case à cocher sur la fiche utilisateur, qui
 * AJOUTE ou RETIRE le rôle consultant sans toucher aux autres rôles.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Roles {

    /** Identifiant technique du rôle consultant. */
    const ROLE = 'npd_consultant';

    /** Capacité : mener ses propres diagnostics. */
    const CAP_MENER = 'npd_mener_diagnostic';

    /** Capacité : administrer le module (tous les diagnostics, référentiel, réglages). */
    const CAP_GERER = 'npd_gerer';

    /**
     * Crée le rôle et donne les capacités à l'administrateur.
     * Appelée à l'activation ; idempotente.
     */
    public static function creer() {
        // add_role ne recrée pas un rôle déjà présent.
        add_role(
            self::ROLE,
            'Consultant NormaPrep',
            [
                'read'          => true, // capacité minimale pour se connecter
                self::CAP_MENER => true,
            ]
        );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( self::CAP_MENER );
            $admin->add_cap( self::CAP_GERER );
        }
    }

    /**
     * Retire le rôle et les capacités. Réservé à la désinstallation.
     */
    public static function supprimer() {
        remove_role( self::ROLE );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->remove_cap( self::CAP_MENER );
            $admin->remove_cap( self::CAP_GERER );
        }
    }

    /**
     * Branchements : case « Consultant » sur la fiche utilisateur.
     */
    public static function init() {
        add_action( 'show_user_profile', [ __CLASS__, 'afficher_case' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'afficher_case' ] );

        // On enregistre sur « profile_update », qui intervient APRÈS
        // l'enregistrement du rôle choisi dans la liste déroulante. Les crochets
        // « edit_user_profile_update » passent avant : WordPress appliquerait
        // ensuite set_role(), qui remplace tous les rôles et effacerait le nôtre.
        add_action( 'profile_update', [ __CLASS__, 'enregistrer_case' ] );
    }

    /**
     * L'utilisateur porte-t-il le rôle consultant ?
     *
     * @param int|WP_User $utilisateur
     * @return bool
     */
    public static function est_consultant( $utilisateur ) {
        $user = ( $utilisateur instanceof WP_User ) ? $utilisateur : get_userdata( (int) $utilisateur );
        return $user && in_array( self::ROLE, (array) $user->roles, true );
    }

    /**
     * Affiche la case à cocher, uniquement pour qui peut gérer le module.
     *
     * @param WP_User $user Compte affiché.
     */
    public static function afficher_case( $user ) {
        if ( ! current_user_can( self::CAP_GERER ) ) {
            return;
        }
        wp_nonce_field( 'npd_role_consultant', 'npd_role_nonce' );
        ?>
        <h2>NormaPrep Diagnostic</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Consultant</th>
                <td>
                    <label for="npd_consultant">
                        <input type="checkbox" name="npd_consultant" id="npd_consultant" value="1"
                            <?php checked( self::est_consultant( $user ) ); ?>>
                        Ce compte peut mener des diagnostics
                    </label>
                    <p class="description">
                        Ajoute ou retire le rôle « Consultant NormaPrep » sans modifier les autres rôles du compte.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Ajoute ou retire le rôle selon la case.
     *
     * @param int $user_id Compte enregistré.
     */
    public static function enregistrer_case( $user_id ) {
        // Seule la fiche utilisateur porte notre jeton : toute autre mise à
        // jour de compte (programmatique, inscription…) est ignorée.
        if ( ! isset( $_POST['npd_role_nonce'] ) ) {
            return;
        }
        if ( ! current_user_can( self::CAP_GERER ) || ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['npd_role_nonce'] ) ), 'npd_role_consultant' ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $coche = ! empty( $_POST['npd_consultant'] );
        if ( $coche && ! self::est_consultant( $user ) ) {
            $user->add_role( self::ROLE );
        } elseif ( ! $coche && self::est_consultant( $user ) ) {
            // Retirer le rôle ne supprime pas les diagnostics : ils restent
            // rattachés au compte et visibles par l'administrateur.
            $user->remove_role( self::ROLE );
        }
    }
}
