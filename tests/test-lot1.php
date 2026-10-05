<?php
/**
 * Tests de bout en bout du lot 1 (socle du plugin).
 *
 * À exécuter dans un WordPress de TEST où le plugin est activé :
 *
 *     wp eval-file tests/test-lot1.php --user=<administrateur>
 *
 * Le script crée ses propres comptes et diagnostics, et importe le
 * référentiel d'exemple (tests/fixtures/referentiel-exemple). Il REMPLACE
 * donc le référentiel en place : ne jamais le lancer sur le site réel.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hors administration (WP-CLI), les classes d'import ne sont pas chargées.
require_once NPD_PATH . 'database/class-npd-validateur.php';
require_once NPD_PATH . 'database/class-npd-importer.php';

/* -------------------------------------------------------------------------
 * Outils
 * ------------------------------------------------------------------------- */

$GLOBALS['npd_tests'] = [ 'ok' => 0, 'ko' => 0 ];

function npd_verifier( $condition, $libelle ) {
    if ( $condition ) {
        $GLOBALS['npd_tests']['ok']++;
        echo "  ✔ {$libelle}\n";
    } else {
        $GLOBALS['npd_tests']['ko']++;
        echo "  ✘ {$libelle}\n";
    }
}

function npd_titre( $t ) {
    echo "\n== {$t}\n";
}

function npd_compter( $table, $where = '1=1' ) {
    global $wpdb;
    return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NPD_Installer::table( $table ) . ' WHERE ' . $where );
}

function npd_id( $table, $colonne, $valeur ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . NPD_Installer::table( $table ) . " WHERE {$colonne} = %s", $valeur
    ) );
}

/** Copie récursive d'un dossier de fixtures, pour le modifier sans toucher l'original. */
function npd_copier( $source, $cible ) {
    if ( is_dir( $cible ) ) {
        npd_effacer( $cible );
    }
    mkdir( $cible, 0777, true );
    foreach ( scandir( $source ) as $f ) {
        if ( '.' === $f || '..' === $f ) {
            continue;
        }
        is_dir( "$source/$f" ) ? npd_copier( "$source/$f", "$cible/$f" ) : copy( "$source/$f", "$cible/$f" );
    }
}

function npd_effacer( $dossier ) {
    foreach ( scandir( $dossier ) as $f ) {
        if ( '.' === $f || '..' === $f ) {
            continue;
        }
        is_dir( "$dossier/$f" ) ? npd_effacer( "$dossier/$f" ) : unlink( "$dossier/$f" );
    }
    rmdir( $dossier );
}

function npd_json_modifier( $fichier, callable $modifier ) {
    $d = json_decode( file_get_contents( $fichier ), true );
    $d = $modifier( $d );
    file_put_contents( $fichier, wp_json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
}

$fixtures = dirname( __FILE__ ) . '/fixtures/referentiel-exemple';
$travail  = get_temp_dir() . 'npd-tests-' . getmypid();
$admin_id = get_current_user_id();

if ( ! $admin_id || ! current_user_can( NPD_Roles::CAP_GERER ) ) {
    echo "Lancer avec --user=<administrateur>.\n";
    return;
}

/* -------------------------------------------------------------------------
 * 1. Activation
 * ------------------------------------------------------------------------- */
npd_titre( 'Activation' );

global $wpdb;
$manquantes = [];
foreach ( NPD_Installer::TABLES as $t ) {
    if ( false === $wpdb->query( 'SELECT 1 FROM ' . NPD_Installer::table( $t ) . ' LIMIT 1' ) ) {
        $manquantes[] = $t;
    }
}
npd_verifier( ! $manquantes, count( NPD_Installer::TABLES ) . ' tables présentes' . ( $manquantes ? ' (manquent : ' . implode( ', ', $manquantes ) . ')' : '' ) );
npd_verifier( null !== get_role( NPD_Roles::ROLE ), 'rôle consultant créé' );
npd_verifier( current_user_can( NPD_Roles::CAP_GERER ) && current_user_can( NPD_Roles::CAP_MENER ), 'capacités ajoutées à l\'administrateur' );
npd_verifier( 12 === NPD_Reglages::duree_conservation(), 'durée de conservation par défaut : 12 mois' );
npd_verifier( (bool) wp_next_scheduled( NPD_Purge::EVENEMENT ), 'purge quotidienne planifiée' );
npd_verifier( get_option( NPD_Installer::OPT_EMPREINTE ) === NPD_Installer::empreinte(), 'empreinte du schéma à jour' );

/* -------------------------------------------------------------------------
 * 2. Import du référentiel d'exemple
 * ------------------------------------------------------------------------- */
npd_titre( 'Import' );

npd_copier( $fixtures, $travail );
$r = NPD_Importer::importer( $travail );
npd_verifier( $r['succes'], 'import réussi' . ( $r['erreurs'] ? ' — ' . implode( ' | ', $r['erreurs'] ) : '' ) );
npd_verifier( 'exemple-0.1' === $r['version'], 'version exemple-0.1 importée' );
npd_verifier( 3 === npd_compter( 'theme' ), '3 thèmes' );
npd_verifier( 3 === npd_compter( 'sous_theme' ), '3 sous-thèmes' );
npd_verifier( 4 === npd_compter( 'question' ), '4 questions' );
npd_verifier( 24 === npd_compter( 'niveau' ), '24 niveaux (6 par question)' );
npd_verifier( 3 === npd_compter( 'recommandation' ), '3 recommandations' );
npd_verifier( 5 === npd_compter( 'profil_question' ) && 17 === npd_compter( 'profil_choix' ), 'profilage : 5 questions, 17 choix' );
npd_verifier( 4 === npd_compter( 'reglementation' ) && 7 === npd_compter( 'regle' ) && 10 === npd_compter( 'exigence' ), 'réglementations : 4, règles : 7, exigences : 10' );
npd_verifier( npd_id( 'profil_choix', 'code', 'P01-energie' ) > 0, 'codes de choix préfixés (« P01-energie »)' );
npd_verifier( npd_id( 'regle', 'ref', 'NIS2-EE-R2' ) > 0, 'références de règles préfixées (« NIS2-EE-R2 »)' );

$reco = $wpdb->get_row( 'SELECT * FROM ' . NPD_Installer::table( 'recommandation' ) . " WHERE ref = 'G3-Q01-R1'" );
npd_verifier( $reco && (int) $reco->prestation_id === npd_id( 'prestation', 'code', 'EBIOS-RM' ) && (int) $reco->question_id === npd_id( 'question', 'ref', 'G3-Q01' ), 'recommandation reliée à sa question et à sa prestation' );
$reco_st = $wpdb->get_row( 'SELECT * FROM ' . NPD_Installer::table( 'recommandation' ) . " WHERE ref = 'G3-R1'" );
npd_verifier( $reco_st && null === $reco_st->question_id && null === $reco_st->prestation_id, 'recommandation de sous-thème, sans prestation' );
npd_verifier( '["ISO 27001 §6.1","ISO 27001 §8.2","NIST CSF GV.RM"]' === $wpdb->get_var( 'SELECT refs_normatives FROM ' . NPD_Installer::table( 'question' ) . " WHERE ref = 'G3-Q01'" ), 'références normatives en JSON' );

/* -------------------------------------------------------------------------
 * 3. Import rejoué : aucun doublon
 * ------------------------------------------------------------------------- */
npd_titre( 'Import rejoué' );

$r2 = NPD_Importer::importer( $travail );
$crees = 0;
foreach ( $r2['stats'] as $s ) {
    $crees += $s['crees'];
}
npd_verifier( $r2['succes'] && 0 === $crees, 'aucun élément créé au second passage' );
npd_verifier( 4 === npd_compter( 'question' ) && 24 === npd_compter( 'niveau' ) && 10 === npd_compter( 'exigence' ), 'mêmes volumes qu\'au premier passage' );
npd_verifier( 1 === npd_compter( 'referentiel', 'actif = 1' ), 'une seule version active' );

/* -------------------------------------------------------------------------
 * 4. Diagnostic de test
 * ------------------------------------------------------------------------- */
npd_titre( 'Diagnostic' );

$consultant_id = wp_insert_user( [
    'user_login' => 'npd_test_consultant_' . wp_rand(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'consultant-' . wp_rand() . '@example.com',
    'display_name' => 'Consultante Test',
    'role'       => 'subscriber',
] );
$autre_id = wp_insert_user( [
    'user_login' => 'npd_test_autre_' . wp_rand(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'autre-' . wp_rand() . '@example.com',
    'role'       => 'subscriber',
] );
get_userdata( $consultant_id )->add_role( NPD_Roles::ROLE );
get_userdata( $autre_id )->add_role( NPD_Roles::ROLE );

$diag_id = NPD_Diagnostics::creer( $consultant_id, [ 'client' => 'Client A' ] );
$diag    = NPD_Diagnostics::obtenir( $diag_id );
npd_verifier( $diag_id > 0 && 'brouillon' === $diag->statut, 'diagnostic créé en brouillon' );
npd_verifier( (int) $diag->referentiel_id === NPD_Diagnostics::referentiel_actif_id(), 'rattaché à la version active du référentiel' );
npd_verifier( NPD_Diagnostics::echeance( $diag->modifie_le, null, 12 ) === $diag->purge_le, 'échéance = dernière modification + 12 mois' );

npd_verifier( NPD_Diagnostics::peut_acceder( $diag_id, $consultant_id ), 'le consultant accède à son diagnostic' );
npd_verifier( ! NPD_Diagnostics::peut_acceder( $diag_id, $autre_id ), 'un autre consultant n\'y accède pas' );
npd_verifier( NPD_Diagnostics::peut_acceder( $diag_id, $admin_id ), 'l\'administrateur y accède' );

// Saisie : une réponse, un choix de profilage, une réglementation ressortie.
$maintenant = current_time( 'mysql' );
$q01        = npd_id( 'question', 'ref', 'G3-Q01' );
$wpdb->insert( NPD_Installer::table( 'reponse' ), [ 'diagnostic_id' => $diag_id, 'question_id' => $q01, 'niveau' => 2, 'modifie_le' => $maintenant ] );
$wpdb->insert( NPD_Installer::table( 'profil_reponse' ), [ 'diagnostic_id' => $diag_id, 'profil_choix_id' => npd_id( 'profil_choix', 'code', 'P01-energie' ), 'saisi_le' => $maintenant ] );
$wpdb->insert( NPD_Installer::table( 'diag_reglementation' ), [ 'diagnostic_id' => $diag_id, 'reglementation_id' => npd_id( 'reglementation', 'code', 'DORA' ), 'niveau_calcule' => 'obligatoire', 'decision' => 'retenue' ] );
$wpdb->insert( NPD_Installer::table( 'score' ), [ 'diagnostic_id' => $diag_id, 'portee' => 'global', 'code' => 'GLOBAL', 'score' => 2 ] );

/* -------------------------------------------------------------------------
 * 5. Retrait d'éléments : désactivés si utilisés, supprimés sinon
 * ------------------------------------------------------------------------- */
npd_titre( 'Retrait d\'éléments du référentiel' );

$q02 = npd_id( 'question', 'ref', 'G3-Q02' );

// On retire G3-Q01 (répondue) et G3-Q02 (jamais répondue), le choix
// P01-energie (coché) et P01-services_num (jamais coché), la réglementation DORA
// (ressortie) et NIS2-EE (jamais ressortie).
npd_json_modifier( "$travail/G_gouvernance/G3_risques.json", function ( $d ) {
    $d['questions'] = [ [
        'ref' => 'G3-Q03', 'enonce' => 'Nouvelle question', 'profondeur' => 'standard',
        'niveaux' => [ 'n0', 'n1', 'n2', 'n3', 'n4', 'n5' ],
    ] ];
    return $d;
} );
npd_json_modifier( "$travail/_profilage.json", function ( $d ) {
    $d['questions'][0]['choix'] = array_values( array_filter( $d['questions'][0]['choix'], function ( $c ) {
        return ! in_array( $c['code'], [ 'energie', 'services_num' ], true );
    } ) );
    return $d;
} );
npd_json_modifier( "$travail/_reglementations.json", function ( $d ) {
    $d['reglementations'] = [ $d['reglementations'][0] ]; // ne garde que SOCLE
    return $d;
} );

$r3 = NPD_Importer::importer( $travail );
npd_verifier( $r3['succes'], 'import réussi' . ( $r3['erreurs'] ? ' — ' . implode( ' | ', $r3['erreurs'] ) : '' ) );

$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NPD_Installer::table( 'question' ) . ' WHERE id = %d', $q01 ) );
npd_verifier( $q && '0' === (string) $q->actif, 'G3-Q01 (répondue) désactivée, pas supprimée' );
npd_verifier( 6 === npd_compter( 'niveau', 'question_id = ' . $q01 ), 'ses niveaux sont conservés' );
npd_verifier( 0 === npd_compter( 'question', 'id = ' . $q02 ) && 0 === npd_compter( 'niveau', 'question_id = ' . $q02 ), 'G3-Q02 (jamais répondue) supprimée avec ses niveaux' );
npd_verifier( 1 === npd_compter( 'reponse', 'diagnostic_id = ' . $diag_id ), 'la réponse du diagnostic est intacte' );
npd_verifier( 0 === npd_compter( 'recommandation', "ref = 'G3-Q01-R1'" ), 'recommandation retirée supprimée' );
npd_verifier( '1' === (string) $wpdb->get_var( 'SELECT actif FROM ' . NPD_Installer::table( 'prestation' ) . " WHERE code = 'EBIOS-RM'" ), 'prestation encore déclarée : conservée et active' );
npd_verifier( '0' === (string) $wpdb->get_var( 'SELECT actif FROM ' . NPD_Installer::table( 'profil_choix' ) . " WHERE code = 'P01-energie'" ), 'choix coché P01-energie désactivé' );
npd_verifier( 0 === npd_compter( 'profil_choix', "code = 'P01-services_num'" ), 'choix jamais coché P01-services_num supprimé' );
npd_verifier( '0' === (string) $wpdb->get_var( 'SELECT actif FROM ' . NPD_Installer::table( 'reglementation' ) . " WHERE code = 'DORA'" ), 'DORA (ressortie dans un diagnostic) désactivée' );
npd_verifier( 0 === npd_compter( 'regle', 'reglementation_id = ' . npd_id( 'reglementation', 'code', 'DORA' ) ) && 0 === npd_compter( 'exigence', 'reglementation_id = ' . npd_id( 'reglementation', 'code', 'DORA' ) ), 'DORA désactivée perd ses règles et exigences' );
npd_verifier( 0 === npd_compter( 'reglementation', "code = 'NIS2-EE'" ), 'NIS2-EE (jamais ressortie) supprimée' );
npd_verifier( 1 === npd_compter( 'diag_reglementation', 'diagnostic_id = ' . $diag_id ), 'l\'arbitrage du diagnostic est intact' );

// Retour à l'état initial : tout ce qui était désactivé redevient actif.
npd_copier( $fixtures, $travail );
$r4 = NPD_Importer::importer( $travail );
npd_verifier( $r4['succes'] && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT actif FROM ' . NPD_Installer::table( 'question' ) . ' WHERE id = %d', $q01 ) ), 'G3-Q01 réactivée quand elle réapparaît (même identifiant)' );
npd_verifier( '1' === (string) $wpdb->get_var( 'SELECT actif FROM ' . NPD_Installer::table( 'reglementation' ) . " WHERE code = 'DORA'" ) && 2 === npd_compter( 'exigence', 'reglementation_id = ' . npd_id( 'reglementation', 'code', 'DORA' ) ), 'DORA réactivée avec ses exigences' );
npd_verifier( 0 === npd_compter( 'question', "ref = 'G3-Q03'" ), 'G3-Q03 (retirée, jamais répondue) supprimée' );

/* -------------------------------------------------------------------------
 * 6. Import refusé : rien n'est modifié
 * ------------------------------------------------------------------------- */
npd_titre( 'Import refusé' );

$avant = npd_compter( 'question' ) . '/' . npd_compter( 'reglementation' ) . '/' . $wpdb->get_var( 'SELECT enonce FROM ' . NPD_Installer::table( 'question' ) . " WHERE ref = 'I6-Q01'" );
npd_json_modifier( "$travail/I_infrastructure/I6_sauvegardes.json", function ( $d ) {
    $d['questions'][0]['enonce'] = 'Énoncé modifié';
    unset( $d['questions'][0]['niveaux']['3'] );          // niveau manquant
    $d['questions'][0]['profondeur'] = 'approfondi';      // profondeur inconnue
    return $d;
} );
npd_json_modifier( "$travail/_reglementations.json", function ( $d ) {
    $d['reglementations'][1]['regles'][0]['conditions'] = [ 'P99' => [ 'x' ] ]; // question inconnue
    $d['reglementations'][1]['exigences']['Z9'] = [ 'profondeur' => 'standard', 'cible' => 3 ]; // sous-thème inconnu
    return $d;
} );
$r5 = NPD_Importer::importer( $travail );
npd_verifier( ! $r5['succes'] && count( $r5['erreurs'] ) >= 4, 'import refusé, ' . count( $r5['erreurs'] ) . ' erreurs signalées' );
foreach ( $r5['erreurs'] as $e ) {
    echo "      · {$e}\n";
}
$apres = npd_compter( 'question' ) . '/' . npd_compter( 'reglementation' ) . '/' . $wpdb->get_var( 'SELECT enonce FROM ' . NPD_Installer::table( 'question' ) . " WHERE ref = 'I6-Q01'" );
npd_verifier( $avant === $apres, 'la base est inchangée' );

// Panne de base en plein import : la transaction doit tout annuler.
npd_copier( $fixtures, $travail );
npd_json_modifier( "$travail/_referentiel.json", function ( $d ) {
    $d['themes'][0]['libelle'] = 'Gouvernance MODIFIÉE';
    return $d;
} );
$panne = function ( $sql ) {
    return ( false !== strpos( $sql, 'npd_niveau' ) && 0 === stripos( ltrim( $sql ), 'INSERT' ) ) ? 'INSERT INTO table_inexistante VALUES (1)' : $sql;
};
add_filter( 'query', $panne );
$wpdb->suppress_errors( true );
$r6 = NPD_Importer::importer( $travail );
$wpdb->suppress_errors( false );
remove_filter( 'query', $panne );
npd_verifier( ! $r6['succes'] && false !== strpos( implode( ' ', $r6['erreurs'] ), 'Import annulé' ), 'panne pendant l\'écriture : import annulé' );
npd_verifier( 'Gouvernance' === $wpdb->get_var( 'SELECT libelle FROM ' . NPD_Installer::table( 'theme' ) . " WHERE code = 'G'" ), 'les écritures déjà faites ont été annulées (transaction)' );
npd_verifier( 24 === npd_compter( 'niveau' ), 'les niveaux sont intacts' );

/* -------------------------------------------------------------------------
 * 7. Échéances et purge
 * ------------------------------------------------------------------------- */
npd_titre( 'Échéances et purge' );

// Un diagnostic finalisé il y a 7 mois : avec 12 mois il reste, avec 6 il est échu.
$vieux_id = NPD_Diagnostics::creer( $consultant_id, [ 'client' => 'Client ancien' ] );
$il_y_a_7_mois = ( new DateTime( current_time( 'mysql' ) ) )->modify( '-7 months' )->format( 'Y-m-d H:i:s' );
$wpdb->update( NPD_Installer::table( 'diagnostic' ), [
    'statut'      => 'finalise',
    'finalise_le' => $il_y_a_7_mois,
    'modifie_le'  => $il_y_a_7_mois,
    'purge_le'    => NPD_Diagnostics::echeance( $il_y_a_7_mois, $il_y_a_7_mois ),
], [ 'id' => $vieux_id ] );
$wpdb->insert( NPD_Installer::table( 'reponse' ), [ 'diagnostic_id' => $vieux_id, 'question_id' => $q01, 'niveau' => 4, 'modifie_le' => $il_y_a_7_mois ] );

npd_verifier( 0 === NPD_Purge::executer(), 'avec 12 mois, rien n\'est purgé' );

update_option( NPD_Reglages::OPTION, [ 'duree_conservation' => 6, 'admin_reattribution' => 0 ] );
npd_verifier( 6 === NPD_Reglages::duree_conservation(), 'durée passée à 6 mois' );
npd_verifier( NPD_Diagnostics::echeance( $il_y_a_7_mois, null, 6 ) === NPD_Diagnostics::obtenir( $vieux_id )->purge_le, 'échéances recalculées automatiquement' );

npd_verifier( 1 === NPD_Purge::executer(), 'avec 6 mois, le diagnostic ancien est purgé' );
npd_verifier( null === NPD_Diagnostics::obtenir( $vieux_id ) && 0 === npd_compter( 'reponse', 'diagnostic_id = ' . $vieux_id ), 'supprimé avec ses réponses' );
npd_verifier( null !== NPD_Diagnostics::obtenir( $diag_id ), 'le diagnostic récent est conservé' );

// Un brouillon jamais finalisé, oublié depuis 7 mois, est purgé lui aussi.
$brouillon_id = NPD_Diagnostics::creer( $consultant_id, [ 'client' => 'Brouillon oublié' ] );
$wpdb->update( NPD_Installer::table( 'diagnostic' ), [ 'modifie_le' => $il_y_a_7_mois ], [ 'id' => $brouillon_id ] );
NPD_Diagnostics::recalculer_echeances();
npd_verifier( 1 === NPD_Purge::executer() && null === NPD_Diagnostics::obtenir( $brouillon_id ), 'brouillon oublié purgé' );

// Une modification repousse l'échéance d'un brouillon.
$actif_id = NPD_Diagnostics::creer( $consultant_id, [ 'client' => 'Brouillon actif' ] );
$wpdb->update( NPD_Installer::table( 'diagnostic' ), [ 'modifie_le' => $il_y_a_7_mois ], [ 'id' => $actif_id ] );
NPD_Diagnostics::toucher( $actif_id );
NPD_Diagnostics::recalculer_echeances();
npd_verifier( 0 === NPD_Purge::executer() && null !== NPD_Diagnostics::obtenir( $actif_id ), 'une modification repousse l\'échéance' );
NPD_Diagnostics::supprimer( $actif_id );

update_option( NPD_Reglages::OPTION, [ 'duree_conservation' => 12, 'admin_reattribution' => 0 ] );

/* -------------------------------------------------------------------------
 * 8. Suppression définitive
 * ------------------------------------------------------------------------- */
npd_titre( 'Suppression définitive' );

$jetable = NPD_Diagnostics::creer( $consultant_id );
foreach ( [ 'reponse' => [ 'question_id' => $q01, 'modifie_le' => $maintenant ], 'score' => [ 'portee' => 'global', 'code' => 'GLOBAL' ] ] as $t => $champs ) {
    $wpdb->insert( NPD_Installer::table( $t ), array_merge( [ 'diagnostic_id' => $jetable ], $champs ) );
}
npd_verifier( NPD_Diagnostics::supprimer( $jetable ), 'suppression réussie' );
$restes = 0;
foreach ( array_merge( NPD_Diagnostics::TABLES_LIEES, [ 'diagnostic' ] ) as $t ) {
    $restes += npd_compter( $t, ( 'diagnostic' === $t ? 'id' : 'diagnostic_id' ) . ' = ' . $jetable );
}
npd_verifier( 0 === $restes, 'aucune ligne résiduelle dans les tables liées' );
npd_verifier( ! NPD_Diagnostics::supprimer( $jetable ), 'supprimer un diagnostic inexistant renvoie faux' );

/* -------------------------------------------------------------------------
 * 9. Réattribution à la suppression du consultant
 * ------------------------------------------------------------------------- */
npd_titre( 'Réattribution' );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $consultant_id );
$d = NPD_Diagnostics::obtenir( $diag_id );
npd_verifier( $d && (int) $d->consultant_id === NPD_Reglages::admin_reattribution(), 'diagnostic réattribué à l\'administrateur' );
npd_verifier( $d && 0 === strpos( (string) $d->consultant_initial, 'Consultante Test (' ), 'nom du consultant d\'origine conservé : ' . ( $d ? $d->consultant_initial : '' ) );
npd_verifier( $d && $d->reattribue_le, 'date de réattribution renseignée' );
npd_verifier( 1 === npd_compter( 'reponse', 'diagnostic_id = ' . $diag_id ), 'les réponses suivent le diagnostic' );

/* -------------------------------------------------------------------------
 * 10. Case « Consultant » sur la fiche utilisateur
 * ------------------------------------------------------------------------- */
npd_titre( 'Case « Consultant » de la fiche utilisateur' );

// On rejoue le vrai enregistrement de la fiche (edit_user), qui applique
// aussi le rôle choisi dans la liste déroulante.
$_POST = [
    'npd_role_nonce' => wp_create_nonce( 'npd_role_consultant' ),
    'npd_consultant' => '1',
    'role'           => 'subscriber',
    'email'          => get_userdata( $autre_id )->user_email,
    'nickname'       => 'autre',
];
get_userdata( $autre_id )->remove_role( NPD_Roles::ROLE );
edit_user( $autre_id );
$u = get_userdata( $autre_id );
npd_verifier( NPD_Roles::est_consultant( $u ) && in_array( 'subscriber', $u->roles, true ), 'case cochée : rôle ajouté, rôle principal conservé' );

unset( $_POST['npd_consultant'] );
$_POST['npd_role_nonce'] = wp_create_nonce( 'npd_role_consultant' );
edit_user( $autre_id );
$u = get_userdata( $autre_id );
npd_verifier( ! NPD_Roles::est_consultant( $u ) && in_array( 'subscriber', $u->roles, true ), 'case décochée : rôle retiré, rôle principal conservé' );
$_POST = [];

/* -------------------------------------------------------------------------
 * Nettoyage
 * ------------------------------------------------------------------------- */
NPD_Diagnostics::supprimer( $diag_id );
wp_delete_user( $autre_id );
npd_effacer( $travail );

$t = $GLOBALS['npd_tests'];
echo "\n" . ( $t['ko'] ? '✘' : '✔' ) . " {$t['ok']} vérification(s) réussie(s), {$t['ko']} échec(s).\n";
