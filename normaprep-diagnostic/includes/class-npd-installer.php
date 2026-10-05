<?php
/**
 * Installation du plugin NormaPrep Diagnostic.
 *
 * Crée les tables à partir du modèle conceptuel validé (docs/mcd.md du dépôt). Deux
 * blocs :
 *
 *   - le RÉFÉRENTIEL, alimenté par l'import des fichiers JSON de data/ ;
 *   - les DIAGNOSTICS, saisis par les consultants.
 *
 * MÉCANISME DE MISE À NIVEAU
 * --------------------------
 * Repris de NormaPrep Quiz : le schéma est rejoué dès que le TEXTE des
 * définitions change (empreinte md5), indépendamment du numéro de version.
 * dbDelta() est non destructif : il crée ce qui manque et ajoute colonnes et
 * index, sans jamais supprimer de données.
 *
 * CLÉS ÉTRANGÈRES
 * ---------------
 * Aucune n'est déclarée en base : dbDelta les gère mal. Les liens sont des
 * colonnes « xxx_id » indexées, et l'intégrité (suppression en cascade,
 * contrôles) est assurée par le code (voir NPD_Diagnostics::supprimer).
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPD_Installer {

    /** Option portant l'empreinte du schéma effectivement appliqué. */
    const OPT_EMPREINTE = 'npd_schema_empreinte';

    /** Liste des tables du plugin, sans préfixe. Sert aussi à la désinstallation. */
    const TABLES = [
        // Référentiel
        'referentiel',
        'theme',
        'sous_theme',
        'question',
        'niveau',
        'prestation',
        'recommandation',
        'reglementation',
        'regle',
        'exigence',
        'profil_question',
        'profil_choix',
        // Diagnostics
        'diagnostic',
        'profil_reponse',
        'diag_reglementation',
        'reponse',
        'score',
    ];

    /**
     * Applique le schéma s'il a changé depuis la dernière fois.
     * Coût du cas courant : une lecture d'option déjà en cache.
     */
    public static function verifier_schema() {
        if ( get_option( self::OPT_EMPREINTE ) === self::empreinte() ) {
            return;
        }
        self::creer_tables();
    }

    /**
     * Crée ou met à niveau toutes les tables.
     */
    public static function creer_tables() {
        // dbDelta() vit dans un fichier WordPress qui n'est pas chargé par défaut.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $definitions = self::definitions();
        foreach ( $definitions as $requete ) {
            dbDelta( $requete );
        }

        update_option( self::OPT_EMPREINTE, self::empreinte( $definitions ) );

        // Purement informatif (affiché en administration), jamais utilisé pour décider.
        update_option( 'npd_db_version', NPD_VERSION );
    }

    /**
     * Empreinte du schéma : condensé du texte des définitions.
     *
     * @param string[]|null $definitions
     * @return string
     */
    public static function empreinte( $definitions = null ) {
        if ( null === $definitions ) {
            $definitions = self::definitions();
        }
        return md5( implode( "\n", $definitions ) );
    }

    /**
     * Nom complet d'une table : préfixe WordPress + préfixe du plugin + nom.
     *
     * @param string $nom Nom court, ex. « diagnostic ».
     * @return string Ex. « wp_npd_diagnostic ».
     */
    public static function table( $nom ) {
        global $wpdb;
        return $wpdb->prefix . NPD_TABLE_PREFIX . $nom;
    }

    /**
     * Définitions CREATE TABLE au format exigé par dbDelta (deux espaces après
     * PRIMARY KEY, une clé par ligne).
     *
     * @return string[]
     */
    private static function definitions() {
        global $wpdb;

        $p       = $wpdb->prefix . NPD_TABLE_PREFIX;
        $charset = $wpdb->get_charset_collate();
        $sql     = [];

        /* =====================================================================
         * RÉFÉRENTIEL
         * =====================================================================
         * Chaque élément porte un code stable (« G3 », « G3-Q01 », « NIS2-EE »)
         * qui sert de clé à l'import rejouable, et un indicateur « actif » :
         * un élément retiré des fichiers mais déjà utilisé par un diagnostic
         * est désactivé, jamais effacé.
         */

        // Versions du référentiel importées. Une seule est active : la dernière.
        $sql[] = "CREATE TABLE {$p}referentiel (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version VARCHAR(30) NOT NULL,
            libelle VARCHAR(190) NOT NULL DEFAULT '',
            importe_le DATETIME NOT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY version (version)
        ) $charset;";

        // Grands thèmes : G (Gouvernance), I (Infrastructure), A (Applicatif).
        $sql[] = "CREATE TABLE {$p}theme (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(10) NOT NULL,
            libelle VARCHAR(190) NOT NULL,
            couleur VARCHAR(40) NOT NULL DEFAULT '',
            ordre INT NOT NULL DEFAULT 0,
            poids DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset;";

        // Sous-thèmes : G1 à G9, I1 à I9, A1 à A8.
        $sql[] = "CREATE TABLE {$p}sous_theme (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            theme_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(20) NOT NULL,
            libelle VARCHAR(190) NOT NULL,
            description TEXT NULL,
            ordre INT NOT NULL DEFAULT 0,
            poids DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY theme_id (theme_id)
        ) $charset;";

        // Questions de maturité. « refs_normatives » : liste JSON des sources
        // (ISO 27002 8.13, NIST PR.DS…). Le mot « references » est réservé en SQL.
        $sql[] = "CREATE TABLE {$p}question (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sous_theme_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(30) NOT NULL,
            enonce TEXT NOT NULL,
            aide TEXT NULL,
            profondeur VARCHAR(20) NOT NULL DEFAULT 'essentiel',
            poids DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            refs_normatives TEXT NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY ref (ref),
            KEY sous_theme_id (sous_theme_id)
        ) $charset;";

        // Description concrète de chacun des six niveaux (0 à 5) d'une question.
        $sql[] = "CREATE TABLE {$p}niveau (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED NOT NULL,
            niveau TINYINT UNSIGNED NOT NULL,
            description TEXT NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY question_niveau (question_id,niveau)
        ) $charset;";

        // Catalogue des offres. Vide en première version : la liaison existe
        // déjà, il suffira de le remplir.
        $sql[] = "CREATE TABLE {$p}prestation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            libelle VARCHAR(190) NOT NULL,
            description TEXT NULL,
            lien VARCHAR(255) NOT NULL DEFAULT '',
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset;";

        // Recommandations : déclenchées quand le niveau constaté est sous le
        // seuil. Rattachées à un sous-thème, et éventuellement à une question.
        $sql[] = "CREATE TABLE {$p}recommandation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sous_theme_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NULL,
            prestation_id BIGINT UNSIGNED NULL,
            ref VARCHAR(40) NOT NULL,
            texte TEXT NOT NULL,
            seuil TINYINT UNSIGNED NOT NULL DEFAULT 3,
            effort VARCHAR(10) NOT NULL DEFAULT 'moyen',
            quick_win TINYINT(1) NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY ref (ref),
            KEY sous_theme_id (sous_theme_id),
            KEY question_id (question_id),
            KEY prestation_id (prestation_id)
        ) $charset;";

        // Réglementations et référentiels (NIS2-EE, DORA, RGPD, ISO27001…).
        // « actions » : liste JSON des grandes actions attendues.
        $sql[] = "CREATE TABLE {$p}reglementation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            libelle VARCHAR(190) NOT NULL,
            nature VARCHAR(20) NOT NULL DEFAULT 'legale',
            description TEXT NULL,
            echeance VARCHAR(255) NOT NULL DEFAULT '',
            actions TEXT NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset;";

        // Règles de déclenchement : conditions JSON sur les réponses de
        // profilage, et niveau d'assujettissement qui en résulte.
        $sql[] = "CREATE TABLE {$p}regle (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reglementation_id BIGINT UNSIGNED NOT NULL,
            ref VARCHAR(40) NOT NULL,
            niveau VARCHAR(20) NOT NULL,
            conditions LONGTEXT NOT NULL,
            critere TEXT NULL,
            priorite INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY ref (ref),
            KEY reglementation_id (reglementation_id)
        ) $charset;";

        // Exigence : association réglementation × sous-thème, porteuse de la
        // profondeur requise et de la maturité cible.
        $sql[] = "CREATE TABLE {$p}exigence (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reglementation_id BIGINT UNSIGNED NOT NULL,
            sous_theme_id BIGINT UNSIGNED NOT NULL,
            profondeur VARCHAR(20) NOT NULL,
            cible DECIMAL(3,1) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reglementation_sous_theme (reglementation_id,sous_theme_id),
            KEY sous_theme_id (sous_theme_id)
        ) $charset;";

        // Questions de profilage (P01 à P15). « condition_affichage » : JSON
        // facultatif (ex. P07B n'est posée que si P07 ≠ non).
        $sql[] = "CREATE TABLE {$p}profil_question (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(20) NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            aide TEXT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'choix_unique',
            condition_affichage TEXT NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset;";

        // Réponses possibles aux questions de profilage. Code complet : « P01-energie ».
        $sql[] = "CREATE TABLE {$p}profil_choix (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profil_question_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(80) NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY profil_question_id (profil_question_id)
        ) $charset;";

        /* =====================================================================
         * DIAGNOSTICS
         * =====================================================================
         * Toutes ces tables dépendent de « diagnostic » : supprimer un
         * diagnostic efface tout ce qui s'y rattache.
         */

        // Une mission, menée par un consultant (utilisateur WordPress).
        // « purge_le » : date à laquelle la purge automatique le supprimera.
        // « consultant_initial » / « reattribue_le » : trace d'une
        // réattribution à l'administrateur après suppression du consultant.
        $sql[] = "CREATE TABLE {$p}diagnostic (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consultant_id BIGINT UNSIGNED NOT NULL,
            referentiel_id BIGINT UNSIGNED NULL,
            client VARCHAR(190) NOT NULL DEFAULT '',
            perimetre TEXT NULL,
            interlocuteurs TEXT NULL,
            date_entretien DATE NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'brouillon',
            cree_le DATETIME NOT NULL,
            modifie_le DATETIME NOT NULL,
            finalise_le DATETIME NULL,
            purge_le DATETIME NULL,
            consultant_initial VARCHAR(190) NULL,
            reattribue_le DATETIME NULL,
            PRIMARY KEY  (id),
            KEY consultant_id (consultant_id),
            KEY statut (statut),
            KEY purge_le (purge_le)
        ) $charset;";

        // Choix cochés au profilage : une ligne par choix (gère le choix multiple).
        $sql[] = "CREATE TABLE {$p}profil_reponse (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            diagnostic_id BIGINT UNSIGNED NOT NULL,
            profil_choix_id BIGINT UNSIGNED NOT NULL,
            saisi_le DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY diagnostic_choix (diagnostic_id,profil_choix_id),
            KEY profil_choix_id (profil_choix_id)
        ) $charset;";

        // Résultat du profilage et arbitrage du consultant, par réglementation.
        $sql[] = "CREATE TABLE {$p}diag_reglementation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            diagnostic_id BIGINT UNSIGNED NOT NULL,
            reglementation_id BIGINT UNSIGNED NOT NULL,
            niveau_calcule VARCHAR(20) NOT NULL,
            decision VARCHAR(20) NOT NULL DEFAULT 'en_attente',
            origine VARCHAR(20) NOT NULL DEFAULT 'calcul',
            critere TEXT NULL,
            justification TEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY diagnostic_reglementation (diagnostic_id,reglementation_id),
            KEY reglementation_id (reglementation_id)
        ) $charset;";

        // Réponse à une question de maturité. « niveau » vide si non applicable.
        $sql[] = "CREATE TABLE {$p}reponse (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            diagnostic_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            niveau TINYINT UNSIGNED NULL,
            non_applicable TINYINT(1) NOT NULL DEFAULT 0,
            commentaire TEXT NULL,
            modifie_le DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY diagnostic_question (diagnostic_id,question_id),
            KEY question_id (question_id)
        ) $charset;";

        // Résultats figés à la finalisation : une ligne par sous-thème, par
        // thème, et une pour le global (portee = sous_theme | theme | global).
        $sql[] = "CREATE TABLE {$p}score (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            diagnostic_id BIGINT UNSIGNED NOT NULL,
            portee VARCHAR(20) NOT NULL,
            code VARCHAR(20) NOT NULL,
            score DECIMAL(4,2) NULL,
            cible DECIMAL(4,2) NULL,
            ecart DECIMAL(4,2) NULL,
            reglementation_cible VARCHAR(30) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY diagnostic_portee_code (diagnostic_id,portee,code)
        ) $charset;";

        return $sql;
    }
}
