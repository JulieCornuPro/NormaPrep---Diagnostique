<?php
/**
 * Espace consultant : page publique où le consultant mène ses diagnostics.
 *
 * Une seule page WordPress (« Espace consultant », créée à l'activation)
 * porte tous les écrans. L'écran affiché dépend de deux paramètres d'URL :
 *
 *   ?npd_vue=liste                         liste des diagnostics (par défaut)
 *   ?npd_vue=mission                       nouveau diagnostic (fiche mission)
 *   ?npd_vue=mission&npd_diag=12           fiche mission du diagnostic 12
 *   ?npd_vue=profilage&npd_diag=12         profilage réglementaire
 *   ?npd_vue=reglementations&npd_diag=12   arbitrage des réglementations
 *
 * Les formulaires sont envoyés à la page elle-même et traités AVANT
 * l'affichage (crochet template_redirect) : en cas de succès, on redirige
 * (le rechargement de la page ne renvoie pas le formulaire) ; en cas
 * d'erreur, l'écran est réaffiché avec les valeurs saisies et les messages.
 *
 * Le passage par la page elle-même, plutôt que par wp-admin/admin-post.php,
 * permet d'interdire complètement l'administration WordPress aux
 * consultants.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Espace {

    /** Option portant l'identifiant de la page « Espace consultant ». */
    const OPT_PAGE = 'npd_page_espace_id';

    /** Écrans disponibles. « mdp_oublie » est le seul accessible sans connexion. */
    const VUES = [ 'liste', 'mission', 'profilage', 'reglementations', 'mdp_oublie' ];

    /** Message unique, quelle que soit la cause de l'échec de connexion. */
    const MESSAGE_ECHEC_CONNEXION = 'Identifiants incorrects.';

    /** Seuil d'affichage du préavis de purge, en jours. */
    const PREAVIS_JOURS = 30;

    /**
     * Erreurs du formulaire soumis dans la requête en cours.
     *
     * @var string[]
     */
    private static $erreurs = [];

    /* =====================================================================
     * BRANCHEMENTS
     * ===================================================================== */

    public static function init() {
        add_filter( 'template_include', [ __CLASS__, 'charger_template' ] );
        add_action( 'template_redirect', [ __CLASS__, 'controler_et_traiter' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_ressources' ] );

        // Cloisonnement : un consultant « pur » ne voit pas l'administration.
        add_action( 'after_setup_theme', [ __CLASS__, 'masquer_barre_admin' ] );
        add_action( 'admin_init', [ __CLASS__, 'bloquer_acces_admin' ] );
        add_filter( 'login_redirect', [ __CLASS__, 'rediriger_apres_connexion' ], 10, 3 );

        // Mise à jour d'un site où le plugin était déjà actif : l'activation
        // ne se rejoue pas, la page est donc créée à la première visite d'un
        // administrateur dans wp-admin.
        add_action( 'admin_init', function () {
            if ( current_user_can( NPD_Roles::CAP_GERER ) && ! get_option( self::OPT_PAGE ) ) {
                self::creer_page();
            }
        } );

        // La page ne doit pas être indexée par les moteurs de recherche.
        add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
    }

    /**
     * Crée la page à l'activation, si elle n'existe pas déjà.
     */
    public static function creer_page() {
        $id = (int) get_option( self::OPT_PAGE );
        if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
            return;
        }
        $id = wp_insert_post( [
            'post_title'   => 'Espace consultant',
            'post_name'    => 'espace-consultant',
            'post_content' => '',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $id && ! is_wp_error( $id ) ) {
            update_option( self::OPT_PAGE, (int) $id );
        }
    }

    /**
     * Sommes-nous sur la page de l'espace consultant ?
     *
     * @return bool
     */
    public static function est_sur_espace() {
        $id = (int) get_option( self::OPT_PAGE );
        return $id && is_page( $id );
    }

    /* =====================================================================
     * URL ET MESSAGES
     * ===================================================================== */

    /**
     * URL d'un écran de l'espace.
     *
     * @param string $vue
     * @param int    $diagnostic_id
     * @return string
     */
    public static function url( $vue = 'liste', $diagnostic_id = 0 ) {
        $id   = (int) get_option( self::OPT_PAGE );
        $base = $id ? get_permalink( $id ) : home_url( '/' );
        $args = [];
        if ( 'liste' !== $vue ) {
            $args['npd_vue'] = $vue;
        }
        if ( $diagnostic_id ) {
            $args['npd_diag'] = (int) $diagnostic_id;
        }
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    /** Écran demandé. */
    public static function vue_courante() {
        $vue = isset( $_GET['npd_vue'] ) ? sanitize_key( wp_unslash( $_GET['npd_vue'] ) ) : 'liste'; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array( $vue, self::VUES, true ) ? $vue : 'liste';
    }

    /** Diagnostic demandé (0 si aucun). */
    public static function diagnostic_courant() {
        return isset( $_GET['npd_diag'] ) ? absint( $_GET['npd_diag'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Message à afficher après la prochaine redirection.
     *
     * @param string $texte
     * @param string $type succes | erreur
     */
    public static function flash( $texte, $type = 'succes' ) {
        set_transient( 'npd_flash_' . self::cle_visiteur(), [ 'texte' => $texte, 'type' => $type ], MINUTE_IN_SECONDS );
    }

    /**
     * Clé rattachant un message au visiteur : son compte s'il est connecté,
     * sinon une empreinte de son adresse (jamais l'adresse en clair). Sans
     * cela, tous les visiteurs non connectés partageraient le même message.
     *
     * @return string
     */
    private static function cle_visiteur() {
        if ( is_user_logged_in() ) {
            return 'u' . get_current_user_id();
        }
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'inconnue';
        return 'v' . substr( md5( $ip . wp_salt() ), 0, 16 );
    }

    /**
     * Lit (et consomme) le message en attente.
     *
     * @return array|null
     */
    public static function lire_flash() {
        $cle = 'npd_flash_' . self::cle_visiteur();
        $msg = get_transient( $cle );
        if ( $msg ) {
            delete_transient( $cle );
        }
        return $msg ?: null;
    }

    /** Erreurs du formulaire en cours. */
    public static function erreurs() {
        return self::$erreurs;
    }

    /* =====================================================================
     * CLOISONNEMENT
     * ===================================================================== */

    /**
     * Consultant sans autre droit d'administration (ni rédacteur, ni
     * gestionnaire du module) : c'est lui qu'on tient hors de wp-admin.
     *
     * @return bool
     */
    private static function est_consultant_simple() {
        return is_user_logged_in()
            && current_user_can( NPD_Roles::CAP_MENER )
            && ! current_user_can( NPD_Roles::CAP_GERER )
            && ! current_user_can( 'edit_posts' );
    }

    public static function masquer_barre_admin() {
        if ( self::est_consultant_simple() ) {
            show_admin_bar( false );
        }
    }

    public static function bloquer_acces_admin() {
        if ( self::est_consultant_simple() && ! wp_doing_ajax() ) {
            wp_safe_redirect( self::url() );
            exit;
        }
    }

    /**
     * Après connexion par wp-login.php, un consultant arrive sur son espace
     * plutôt que sur le tableau de bord WordPress.
     */
    public static function rediriger_apres_connexion( $redirection, $demandee, $user ) {
        if ( ! ( $user instanceof WP_User ) || ! NPD_Roles::est_consultant( $user ) ) {
            return $redirection;
        }
        if ( user_can( $user, 'edit_posts' ) || user_can( $user, NPD_Roles::CAP_GERER ) ) {
            return $redirection;
        }
        // Une destination explicite (autre que l'administration) est respectée.
        if ( $demandee && false === strpos( $demandee, '/wp-admin' ) ) {
            return $redirection;
        }
        return self::url();
    }

    public static function robots( $robots ) {
        if ( self::est_sur_espace() ) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function charger_template( $template ) {
        if ( self::est_sur_espace() ) {
            return NPD_PATH . 'public/page-espace-consultant.php';
        }
        return $template;
    }

    public static function charger_ressources() {
        if ( ! self::est_sur_espace() ) {
            return;
        }
        wp_enqueue_style( 'npd-espace', NPD_URL . 'assets/npd-espace.css', [], NPD_VERSION );
        wp_enqueue_script( 'npd-espace', NPD_URL . 'assets/npd-espace.js', [], NPD_VERSION, true );
        wp_enqueue_style( 'npd-confirm', NPD_URL . 'assets/npd-confirm.css', [], NPD_VERSION );
        wp_enqueue_script( 'npd-confirm', NPD_URL . 'assets/npd-confirm.js', [], NPD_VERSION, true );
    }

    /**
     * Le diagnostic demandé, si l'utilisateur y a accès.
     *
     * Un diagnostic inexistant et un diagnostic d'un autre consultant donnent
     * le même résultat (null) : on ne révèle pas l'existence de ce qu'on ne
     * peut pas voir.
     *
     * @return object|null
     */
    public static function diagnostic_accessible( $id ) {
        return ( $id && NPD_Diagnostics::peut_acceder( $id ) ) ? NPD_Diagnostics::obtenir( $id ) : null;
    }

    /* =====================================================================
     * CONTRÔLE D'ACCÈS ET FORMULAIRES
     * ===================================================================== */

    /**
     * Avant tout affichage de la page : connexion exigée, puis traitement du
     * formulaire éventuellement soumis.
     */
    public static function controler_et_traiter() {
        if ( ! self::est_sur_espace() ) {
            return;
        }
        // Visiteur non connecté : la page affiche la mire de connexion (ou le
        // formulaire « mot de passe oublié »). Seuls ces deux formulaires
        // sont traités.
        if ( ! is_user_logged_in() ) {
            nocache_headers();
            self::traiter_formulaire_public();
            return;
        }
        if ( ! current_user_can( NPD_Roles::CAP_MENER ) ) {
            return; // le gabarit affiche « accès réservé »
        }

        // Pas de mise en cache d'écrans contenant des données clients.
        nocache_headers();

        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['npd_action'] ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['npd_action'] ) );
        $diag   = absint( $_POST['npd_diag'] ?? 0 );

        if ( ! isset( $_POST['npd_nonce'] )
            || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['npd_nonce'] ) ), self::nonce_action( $action, $diag ) ) ) {
            self::$erreurs[] = 'La page a expiré. Rechargez-la puis recommencez.';
            return;
        }

        $donnees = wp_unslash( $_POST );

        switch ( $action ) {
            case 'mission':
                self::traiter_mission( $diag, $donnees );
                break;
            case 'profilage':
                self::traiter_profilage( $diag, $donnees );
                break;
            case 'reglementations':
                self::traiter_reglementations( $diag, $donnees );
                break;
            case 'supprimer':
                self::traiter_suppression( $diag );
                break;
        }
    }

    /* =====================================================================
     * MIRE DE CONNEXION ET MOT DE PASSE OUBLIÉ
     * =====================================================================
     * Reprise de la mire de NormaPrep Quiz : adresse email + mot de passe,
     * limitation des tentatives, message d'échec unique. Une différence : les
     * comptes consultants sont créés par l'administrateur, il n'y a donc ni
     * inscription ni validation d'adresse.
     */

    /**
     * Formulaires accessibles sans connexion.
     */
    private static function traiter_formulaire_public() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['npd_action'] ) ) {
            return;
        }
        $action = sanitize_key( wp_unslash( $_POST['npd_action'] ) );
        if ( ! in_array( $action, [ 'connexion', 'mdp_oublie' ], true ) ) {
            return;
        }
        if ( ! isset( $_POST['npd_nonce'] )
            || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['npd_nonce'] ) ), self::nonce_action( $action, 0 ) ) ) {
            self::$erreurs[] = 'La page a expiré. Rechargez-la puis recommencez.';
            return;
        }

        $donnees = wp_unslash( $_POST );
        if ( 'connexion' === $action ) {
            self::traiter_connexion( $donnees );
        } else {
            self::traiter_mdp_oublie( $donnees );
        }
    }

    /**
     * Connexion d'un consultant.
     *
     * Seuls les comptes autorisés à mener des diagnostics peuvent se
     * connecter ici. Sans cette restriction, un abonné du quiz dont l'adresse
     * n'est pas encore validée contournerait la validation par ce formulaire.
     *
     * Compte inconnu, compte non consultant, mot de passe faux : un seul
     * message, pour ne pas révéler quelles adresses existent.
     *
     * @param array $donnees $_POST déséchappé.
     * @return bool Vrai si la connexion a réussi.
     */
    public static function traiter_connexion( array $donnees ) {
        $email = sanitize_email( (string) ( $donnees['email'] ?? '' ) );
        $mdp   = (string) ( $donnees['mdp'] ?? '' );

        if ( NPD_Limitation::connexion_bloquee( $email ) ) {
            self::$erreurs[] = sprintf(
                'Trop de tentatives de connexion. Réessayez dans %d minutes.',
                NPD_Limitation::minutes_restantes()
            );
            return false;
        }

        $user = $email ? get_user_by( 'email', $email ) : false;
        if ( ! $user || ! user_can( $user, NPD_Roles::CAP_MENER ) ) {
            NPD_Limitation::connexion_echouee( $email );
            self::$erreurs[] = self::MESSAGE_ECHEC_CONNEXION;
            return false;
        }

        $resultat = wp_signon( [
            'user_login'    => $user->user_login,
            'user_password' => $mdp,
            'remember'      => ! empty( $donnees['souvenir'] ),
        ], is_ssl() );

        if ( is_wp_error( $resultat ) ) {
            NPD_Limitation::connexion_echouee( $email );
            self::$erreurs[] = self::MESSAGE_ECHEC_CONNEXION;
            return false;
        }

        NPD_Limitation::connexion_reussie( $email );
        wp_set_current_user( $resultat->ID );

        // Retour à l'écran demandé (un lien vers un diagnostic, par exemple),
        // à condition qu'il reste sur l'espace consultant.
        $retour = (string) ( $donnees['retour'] ?? '' );
        $base   = self::url();
        $cible  = ( $retour && 0 === strpos( $retour, $base ) ) ? $retour : $base;
        self::rediriger( self::sans_vue_publique( $cible ) );
        return true;
    }

    /**
     * Après connexion, on ne renvoie pas vers l'écran « mot de passe oublié ».
     */
    private static function sans_vue_publique( $url ) {
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
        return ( isset( $args['npd_vue'] ) && 'mdp_oublie' === $args['npd_vue'] ) ? self::url() : $url;
    }

    /**
     * Mot de passe oublié : envoi du lien de réinitialisation de WordPress.
     *
     * La réponse est toujours la même, que l'adresse corresponde ou non à un
     * consultant. Seuls les consultants reçoivent un courriel.
     *
     * @param array $donnees
     */
    public static function traiter_mdp_oublie( array $donnees ) {
        if ( NPD_Limitation::reinitialisation_bloquee() ) {
            self::$erreurs[] = sprintf(
                'Trop de demandes. Réessayez dans %d minutes.',
                NPD_Limitation::minutes_restantes()
            );
            return;
        }
        NPD_Limitation::reinitialisation_demandee();

        $email = sanitize_email( (string) ( $donnees['email'] ?? '' ) );
        $user  = $email ? get_user_by( 'email', $email ) : false;
        if ( $user && user_can( $user, NPD_Roles::CAP_MENER ) ) {
            retrieve_password( $user->user_login );
        }

        self::flash( 'Si cette adresse correspond à un compte consultant, un courriel contenant un lien pour choisir un nouveau mot de passe vient d\'être envoyé.' );
        self::rediriger( self::url() );
    }

    /**
     * Nom d'action du jeton anti-rejeu, propre à l'action ET au diagnostic.
     */
    public static function nonce_action( $action, $diagnostic_id ) {
        return 'npd_' . $action . '_' . (int) $diagnostic_id;
    }

    /**
     * Champs cachés communs à tous les formulaires de l'espace.
     */
    public static function champs_formulaire( $action, $diagnostic_id ) {
        printf( '<input type="hidden" name="npd_action" value="%s">', esc_attr( $action ) );
        printf( '<input type="hidden" name="npd_diag" value="%d">', (int) $diagnostic_id );
        wp_nonce_field( self::nonce_action( $action, $diagnostic_id ), 'npd_nonce' );
    }

    /**
     * Contrôle commun : diagnostic accessible et modifiable.
     *
     * @return object|null Le diagnostic, ou null (erreur déjà enregistrée).
     */
    private static function diagnostic_modifiable( $id ) {
        $diag = self::diagnostic_accessible( $id );
        if ( ! $diag ) {
            self::$erreurs[] = 'Diagnostic introuvable.';
            return null;
        }
        if ( ! NPD_Diagnostics::est_modifiable( $diag ) ) {
            self::$erreurs[] = 'Ce diagnostic est finalisé : il n\'est plus modifiable.';
            return null;
        }
        return $diag;
    }

    /**
     * Fiche mission : création ou mise à jour.
     *
     * @param int   $id      0 pour une création.
     * @param array $donnees $_POST déséchappé.
     */
    public static function traiter_mission( $id, array $donnees ) {
        $champs = [
            'client'         => sanitize_text_field( $donnees['client'] ?? '' ),
            'perimetre'      => sanitize_textarea_field( $donnees['perimetre'] ?? '' ),
            'interlocuteurs' => sanitize_textarea_field( $donnees['interlocuteurs'] ?? '' ),
            'date_entretien' => null,
        ];

        if ( '' === $champs['client'] ) {
            self::$erreurs[] = 'Indiquez le client, ou un pseudonyme.';
        } elseif ( mb_strlen( $champs['client'] ) > 190 ) {
            self::$erreurs[] = 'Le nom du client ne doit pas dépasser 190 caractères.';
        }

        $date = trim( (string) ( $donnees['date_entretien'] ?? '' ) );
        if ( '' !== $date ) {
            $d = DateTime::createFromFormat( '!Y-m-d', $date );
            if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
                self::$erreurs[] = 'La date d\'entretien n\'est pas valide.';
            } else {
                $champs['date_entretien'] = $date;
            }
        }

        if ( self::$erreurs ) {
            return;
        }

        if ( $id ) {
            if ( ! self::diagnostic_modifiable( $id ) ) {
                return;
            }
            NPD_Diagnostics::mettre_a_jour_mission( $id, $champs );
            self::flash( 'Fiche mission enregistrée.' );
            self::rediriger( self::url( 'mission', $id ) );
            return;
        }

        $nouveau = NPD_Diagnostics::creer( get_current_user_id(), $champs );
        if ( ! $nouveau ) {
            self::$erreurs[] = 'Le diagnostic n\'a pas pu être créé.';
            return;
        }
        self::flash( 'Diagnostic créé. Étape suivante : le profilage réglementaire.' );
        self::rediriger( self::url( 'profilage', $nouveau ) );
    }

    /**
     * Profilage : enregistre les réponses et rejoue le calcul.
     */
    public static function traiter_profilage( $id, array $donnees ) {
        $diag = self::diagnostic_modifiable( $id );
        if ( ! $diag ) {
            return;
        }
        $soumises = isset( $donnees['profil'] ) && is_array( $donnees['profil'] ) ? $donnees['profil'] : [];
        $reponses = NPD_Profilage::enregistrer( $id, $soumises );

        if ( NPD_Diagnostics::STATUT_BROUILLON === $diag->statut ) {
            NPD_Diagnostics::changer_statut( $id, NPD_Diagnostics::STATUT_EN_COURS );
        }
        NPD_Diagnostics::toucher( $id );

        $manquantes = NPD_Profilage::questions_sans_reponse( $reponses, NPD_Profilage::questions() );
        self::flash(
            $manquantes
                ? 'Profilage enregistré, mais ' . count( $manquantes ) . ' question(s) restent sans réponse : le calcul ne peut en tenir compte.'
                : 'Profilage enregistré. Vérifiez les réglementations proposées.',
            $manquantes ? 'erreur' : 'succes'
        );
        self::rediriger( self::url( 'reglementations', $id ) );
    }

    /**
     * Arbitrage des réglementations.
     */
    public static function traiter_reglementations( $id, array $donnees ) {
        if ( ! self::diagnostic_modifiable( $id ) ) {
            return;
        }
        $decisions = isset( $donnees['reg'] ) && is_array( $donnees['reg'] ) ? $donnees['reg'] : [];
        $propres   = [];
        foreach ( $decisions as $reg_id => $d ) {
            if ( ! is_array( $d ) ) {
                continue;
            }
            $propres[ absint( $reg_id ) ] = [
                'decision'      => sanitize_key( $d['decision'] ?? '' ),
                'justification' => sanitize_textarea_field( $d['justification'] ?? '' ),
            ];
        }

        $erreurs = NPD_Profilage::arbitrer(
            $id,
            $propres,
            absint( $donnees['ajout'] ?? 0 ),
            sanitize_textarea_field( $donnees['ajout_justification'] ?? '' )
        );
        if ( $erreurs ) {
            self::$erreurs = $erreurs;
            return;
        }

        NPD_Diagnostics::toucher( $id );
        $attente = NPD_Profilage::nb_en_attente( $id );
        self::flash(
            $attente
                ? 'Arbitrage enregistré. ' . $attente . ' réglementation(s) restent à trancher.'
                : 'Arbitrage enregistré.'
        );
        self::rediriger( self::url( 'reglementations', $id ) );
    }

    /**
     * Suppression définitive (après confirmation dans l'interface).
     */
    public static function traiter_suppression( $id ) {
        $diag = self::diagnostic_accessible( $id );
        if ( ! $diag ) {
            self::$erreurs[] = 'Diagnostic introuvable.';
            return;
        }
        NPD_Diagnostics::supprimer( $id );
        self::flash( 'Diagnostic « ' . $diag->client . ' » supprimé définitivement.' );
        self::rediriger( self::url() );
    }

    /**
     * Redirige puis arrête la requête. Un filtre permet aux tests de
     * l'intercepter.
     */
    private static function rediriger( $url ) {
        if ( apply_filters( 'npd_espace_rediriger', true, $url ) ) {
            wp_safe_redirect( $url );
            exit;
        }
    }

    /* =====================================================================
     * OUTILS D'AFFICHAGE PARTAGÉS PAR LES ÉCRANS
     * ===================================================================== */

    /** Libellés des statuts. */
    public static function libelle_statut( $statut ) {
        $libelles = [
            NPD_Diagnostics::STATUT_BROUILLON => 'Brouillon',
            NPD_Diagnostics::STATUT_EN_COURS  => 'En cours',
            NPD_Diagnostics::STATUT_FINALISE  => 'Finalisé',
        ];
        return $libelles[ $statut ] ?? $statut;
    }

    /** Libellés des niveaux d'assujettissement. */
    public static function libelle_niveau( $niveau ) {
        $libelles = [
            'obligatoire' => 'Obligatoire',
            'a_verifier'  => 'À vérifier',
            'recommande'  => 'Recommandé',
        ];
        return $libelles[ $niveau ] ?? $niveau;
    }

    /** Libellés des profondeurs. */
    public static function libelle_profondeur( $profondeur ) {
        $libelles = [
            'essentiel' => 'Essentiel',
            'standard'  => 'Standard',
            'renforce'  => 'Renforcé',
        ];
        return $libelles[ $profondeur ] ?? $profondeur;
    }

    /**
     * Nombre de jours avant la purge, ou null si elle est lointaine.
     *
     * @param object $diag
     * @return int|null
     */
    public static function jours_avant_purge( $diag ) {
        if ( ! $diag->purge_le ) {
            return null;
        }
        $maintenant = new DateTime( current_time( 'mysql' ) );
        $purge      = new DateTime( $diag->purge_le );
        $jours      = (int) floor( ( $purge->getTimestamp() - $maintenant->getTimestamp() ) / DAY_IN_SECONDS );
        return $jours <= self::PREAVIS_JOURS ? max( 0, $jours ) : null;
    }

    /**
     * Barre latérale de l'espace (reprise de la coquille du quiz).
     *
     * @param string $active Entrée surlignée.
     * @return string
     */
    public static function barre_laterale( $active = 'liste' ) {
        $user = wp_get_current_user();
        $nom  = $user->display_name ? $user->display_name : $user->user_email;

        $initiales = strtoupper( mb_substr( preg_replace( '/[^A-Za-z0-9]/', '', remove_accents( $nom ) ), 0, 2 ) );
        if ( '' === $initiales ) {
            $initiales = 'ND';
        }

        $cls = function ( $cle ) use ( $active ) {
            return $active === $cle ? ' active' : '';
        };

        ob_start();
        ?>
        <aside class="sidebar">
          <div class="side-user">
            <div class="avatar"><?php echo esc_html( $initiales ); ?></div>
            <div class="su-meta">
              <div class="su-name"><?php echo esc_html( $nom ); ?></div>
              <div class="su-status mono">&#9679; <?php echo current_user_can( NPD_Roles::CAP_GERER ) ? 'Administrateur' : 'Consultant'; ?></div>
            </div>
          </div>

          <nav class="side-nav" aria-label="Espace consultant">
            <div class="side-group-label">Diagnostics</div>

            <a class="side-link<?php echo esc_attr( $cls( 'liste' ) ); ?>" href="<?php echo esc_url( self::url() ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg></span>
              <span class="lbl">Mes diagnostics</span>
            </a>

            <a class="side-link<?php echo esc_attr( $cls( 'nouveau' ) ); ?>" href="<?php echo esc_url( self::url( 'mission' ) ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
              <span class="lbl">Nouveau diagnostic</span>
            </a>

            <?php if ( current_user_can( NPD_Roles::CAP_GERER ) ) : ?>
              <div class="side-divider"></div>
              <div class="side-group-label">Administration</div>
              <a class="side-link" href="<?php echo esc_url( admin_url( 'admin.php?page=npd-referentiel' ) ); ?>">
                <span class="icon"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span>
                <span class="lbl">Référentiel</span>
              </a>
            <?php endif; ?>

            <div class="side-divider"></div>
            <a class="side-link" href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/></svg></span>
              <span class="lbl">Se déconnecter</span>
            </a>
          </nav>

          <div class="side-bottom">
            <button class="collapse-btn" id="npdCollapseToggle" type="button">
              <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></span>
              <span class="lbl">Réduire le menu</span>
            </button>
          </div>
        </aside>
        <?php
        return ob_get_clean();
    }

    /**
     * Étapes d'un diagnostic (fil d'Ariane cliquable).
     *
     * @param object|null $diag
     * @param string      $courante
     * @return string
     */
    public static function etapes( $diag, $courante ) {
        $a_profil = $diag && NPD_Diagnostics::STATUT_BROUILLON !== $diag->statut;
        $etapes   = [
            'mission'         => [ 'Fiche mission', (bool) $diag || 'mission' === $courante ],
            'profilage'       => [ 'Profilage', (bool) $diag ],
            'reglementations' => [ 'Réglementations', $a_profil ],
            'questionnaire'   => [ 'Questionnaire', false ],
            'resultats'       => [ 'Résultats', false ],
        ];

        ob_start();
        echo '<ol class="npd-etapes">';
        $n = 1;
        foreach ( $etapes as $cle => $e ) {
            list( $libelle, $accessible ) = $e;
            $classes = 'npd-etape' . ( $cle === $courante ? ' courante' : '' ) . ( $accessible ? '' : ' inactive' );
            echo '<li class="' . esc_attr( $classes ) . '">';
            $contenu = '<span class="num">' . $n . '</span><span class="lbl">' . esc_html( $libelle ) . '</span>';
            if ( in_array( $cle, [ 'questionnaire', 'resultats' ], true ) ) {
                $contenu .= '<span class="bientot">à venir</span>';
            }
            if ( $accessible && $cle !== $courante && $diag ) {
                echo '<a href="' . esc_url( self::url( $cle, (int) $diag->id ) ) . '">' . $contenu . '</a>'; // contenu déjà échappé
            } else {
                echo '<span>' . $contenu . '</span>'; // contenu déjà échappé
            }
            echo '</li>';
            $n++;
        }
        echo '</ol>';
        return ob_get_clean();
    }

    /**
     * Messages (flash et erreurs de formulaire).
     *
     * @return string
     */
    public static function messages() {
        ob_start();
        $flash = self::lire_flash();
        if ( $flash ) {
            printf(
                '<div class="npd-msg npd-msg-%s" role="status">%s</div>',
                esc_attr( 'erreur' === $flash['type'] ? 'erreur' : 'succes' ),
                esc_html( $flash['texte'] )
            );
        }
        if ( self::$erreurs ) {
            echo '<div class="npd-msg npd-msg-erreur" role="alert"><ul>';
            foreach ( self::$erreurs as $e ) {
                echo '<li>' . esc_html( $e ) . '</li>';
            }
            echo '</ul></div>';
        }
        return ob_get_clean();
    }
}
