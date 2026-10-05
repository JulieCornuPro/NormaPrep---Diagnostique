<?php
/**
 * Limitation du nombre de tentatives (connexion, mot de passe oublié).
 *
 * Copie de NPQ_Limitation (NormaPrep Quiz), renommée : le plugin reste
 * autonome. Préfixe de transients distinct (npd_lim_) : les compteurs des
 * deux plugins ne se mélangent pas.
 *
 * POURQUOI
 *
 * WordPress ne limite PAS nativement les tentatives de connexion. Sans garde,
 * un formulaire de connexion accepte des milliers d'essais par minute : c'est
 * ce qui rend praticables le bourrage d'identifiants (rejouer des couples
 * email/mot de passe fuités ailleurs) et l'attaque par dictionnaire.
 *
 * Aucun captcha ne remplace cette protection. Un captcha à l'inscription
 * n'empêche pas de forcer un compte qui existe déjà : les deux traitent des
 * problèmes différents.
 *
 * DEUX COMPTEURS, ET C'EST VOULU
 *
 * Ne compter que par email permettrait de VERROUILLER le compte de quelqu'un
 * d'autre en échouant volontairement — on transformerait une protection en
 * outil de nuisance. Ne compter que par IP laisserait un attaquant balayer
 * beaucoup de comptes à raison de peu d'essais chacun.
 *
 * On combine donc :
 *   - par IP, seuil large : arrête le martèlement depuis une source ;
 *   - par IP + email, seuil serré : arrête l'acharnement sur un compte, sans
 *     qu'une autre IP puisse en bloquer le propriétaire légitime.
 *
 * LIMITE CONNUE
 *
 * L'adresse est lue dans REMOTE_ADDR. Derrière un proxy ou un CDN mal
 * configuré, toutes les requêtes semblent venir de la même adresse — la
 * protection devient alors grossière. On ne lit délibérément PAS
 * X-Forwarded-For : cet en-tête est fourni par le client, donc falsifiable, et
 * s'y fier rendrait le contournement trivial.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Limitation {

    /** Préfixe des transients. */
    const PREFIXE = 'npd_lim_';

    /** Durée d'observation et de blocage, en secondes. */
    const FENETRE = 900; // 15 minutes

    /** Échecs tolérés depuis une même adresse, tous comptes confondus. */
    const MAX_PAR_IP = 10;

    /** Échecs tolérés depuis une même adresse sur UN compte donné. */
    const MAX_PAR_COMPTE = 5;

    /** Demandes de réinitialisation de mot de passe depuis une même adresse, par fenêtre. */
    const MAX_REINITIALISATIONS = 5;

    /**
     * Adresse de l'appelant, réduite à une empreinte.
     *
     * On stocke une empreinte plutôt que l'adresse : un transient n'a pas à
     * conserver de donnée personnelle en clair pour remplir cet office.
     *
     * @return string
     */
    private static function empreinte_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'inconnue';
        return substr( md5( $ip . wp_salt() ), 0, 16 );
    }

    /**
     * Clé de comptage.
     *
     * @param string $portee   'ip', 'compte' ou 'reinit'.
     * @param string $sujet    Email concerné, pour la portée 'compte'.
     * @return string
     */
    private static function cle( $portee, $sujet = '' ) {
        $cle = self::PREFIXE . $portee . '_' . self::empreinte_ip();
        if ( $sujet !== '' ) {
            $cle .= '_' . substr( md5( strtolower( $sujet ) ), 0, 12 );
        }
        return $cle;
    }

    /**
     * Nombre d'échecs enregistrés pour une clé.
     */
    private static function compteur( $cle ) {
        return (int) get_transient( $cle );
    }

    /**
     * Enregistre un échec.
     *
     * La fenêtre est réarmée à chaque échec : quelqu'un qui essaie sans relâche
     * reste bloqué tant qu'il insiste, plutôt que d'être libéré à heure fixe.
     */
    private static function incrementer( $cle ) {
        set_transient( $cle, self::compteur( $cle ) + 1, self::FENETRE );
    }

    /* =====================================================================
     * CONNEXION
     * ===================================================================== */

    /**
     * La connexion est-elle bloquée pour cet appelant et ce compte ?
     *
     * @param string $email
     * @return bool
     */
    public static function connexion_bloquee( $email ) {
        if ( self::compteur( self::cle( 'ip' ) ) >= self::MAX_PAR_IP ) {
            return true;
        }
        return ( self::compteur( self::cle( 'compte', $email ) ) >= self::MAX_PAR_COMPTE );
    }

    /**
     * Enregistre une tentative de connexion infructueuse.
     *
     * À appeler pour TOUT échec, y compris quand l'email n'existe pas : sinon
     * le temps de réponse trahirait l'existence du compte, et l'attaquant
     * pourrait balayer les adresses sans jamais être ralenti.
     *
     * @param string $email
     */
    public static function connexion_echouee( $email ) {
        self::incrementer( self::cle( 'ip' ) );
        self::incrementer( self::cle( 'compte', $email ) );
    }

    /**
     * Efface les compteurs après une connexion réussie.
     *
     * @param string $email
     */
    public static function connexion_reussie( $email ) {
        delete_transient( self::cle( 'ip' ) );
        delete_transient( self::cle( 'compte', $email ) );
    }

    /**
     * Minutes restantes avant déblocage, pour informer sans trop en dire.
     *
     * @return int
     */
    public static function minutes_restantes() {
        return (int) ceil( self::FENETRE / 60 );
    }

    /* =====================================================================
     * MOT DE PASSE OUBLIÉ
     * ===================================================================== */

    /**
     * Trop de demandes de réinitialisation depuis cette adresse ?
     *
     * Sans limite, le formulaire servirait à inonder de courriels la boîte
     * d'un consultant, ou à balayer des adresses.
     */
    public static function reinitialisation_bloquee() {
        return ( self::compteur( self::cle( 'reinit' ) ) >= self::MAX_REINITIALISATIONS );
    }

    /**
     * Enregistre une demande (aboutie ou non : la réponse est la même).
     */
    public static function reinitialisation_demandee() {
        self::incrementer( self::cle( 'reinit' ) );
    }
}
