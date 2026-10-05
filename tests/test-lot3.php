<?php
/**
 * Tests de bout en bout du lot 3 (espace consultant, profilage, arbitrage).
 *
 * À exécuter dans un WordPress de TEST où le plugin est activé :
 *
 *     wp eval-file tests/test-lot3.php --user=<administrateur>
 *
 * Le script importe le référentiel d'exemple (il REMPLACE le référentiel en
 * place : jamais sur le site réel), crée ses comptes et ses diagnostics, et
 * les supprime à la fin.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once NPD_PATH . 'database/class-npd-validateur.php';
require_once NPD_PATH . 'database/class-npd-importer.php';

$GLOBALS['npd_tests'] = [ 'ok' => 0, 'ko' => 0 ];

function npd_verifier( $condition, $libelle ) {
    $GLOBALS['npd_tests'][ $condition ? 'ok' : 'ko' ]++;
    echo ( $condition ? '  ✔ ' : '  ✘ ' ) . $libelle . "\n";
}

function npd_titre( $t ) {
    echo "\n== {$t}\n";
}

/** Réglementations d'un diagnostic, indexées par code : [ niveau, decision, origine ]. */
function npd_regs( $diag_id ) {
    $r = [];
    foreach ( NPD_Profilage::reglementations( $diag_id ) as $l ) {
        $r[ $l->code ] = [ $l->niveau_calcule, $l->decision, $l->origine ];
    }
    return $r;
}

/** Identifiant de réglementation par code. */
function npd_reg_id( $code ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NPD_Installer::table( 'reglementation' ) . ' WHERE code = %s', $code ) );
}

/** Vide les erreurs de formulaire entre deux appels de traitement. */
function npd_raz_erreurs() {
    $p = new ReflectionProperty( 'NPD_Espace', 'erreurs' );
    $p->setAccessible( true );
    $p->setValue( null, [] );
}

/** Codes calculés → niveau. */
function npd_calcul( array $reponses ) {
    $codes = [];
    foreach ( NPD_Profilage::calculer( $reponses ) as $id => $c ) {
        global $wpdb;
        $code           = $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . NPD_Installer::table( 'reglementation' ) . ' WHERE id = %d', $id ) );
        $codes[ $code ] = $c['niveau'];
    }
    ksort( $codes );
    return $codes;
}

$admin_id = get_current_user_id();
if ( ! $admin_id || ! current_user_can( NPD_Roles::CAP_GERER ) ) {
    echo "Lancer avec --user=<administrateur>.\n";
    return;
}

// Les traitements de formulaire ne redirigent pas pendant les tests.
add_filter( 'npd_espace_rediriger', '__return_false' );

$r = NPD_Importer::importer( dirname( __FILE__ ) . '/fixtures/referentiel-exemple' );
if ( ! $r['succes'] ) {
    echo "Import du référentiel d'exemple impossible :\n  " . implode( "\n  ", $r['erreurs'] ) . "\n";
    return;
}

/* -------------------------------------------------------------------------
 * 1. Page de l'espace
 * ------------------------------------------------------------------------- */
npd_titre( 'Page « Espace consultant »' );

$page = (int) get_option( NPD_Espace::OPT_PAGE );
npd_verifier( $page && 'publish' === get_post_status( $page ), 'page créée et publiée' );
npd_verifier( false !== strpos( NPD_Espace::url( 'profilage', 12 ), 'npd_vue=profilage' ) && false !== strpos( NPD_Espace::url( 'profilage', 12 ), 'npd_diag=12' ), 'URL d\'écran : npd_vue et npd_diag' );
npd_verifier( get_permalink( $page ) === NPD_Espace::url(), 'URL de la liste = permalien de la page' );
NPD_Espace::creer_page();
npd_verifier( $page === (int) get_option( NPD_Espace::OPT_PAGE ), 'création rejouée : pas de seconde page' );

/* -------------------------------------------------------------------------
 * 2. Conditions
 * ------------------------------------------------------------------------- */
npd_titre( 'Conditions' );

$rep = [ 'P01' => [ 'energie' ], 'P02' => [ 'eti' ], 'P14' => [ 'dns', 'aucune' ] ];
npd_verifier( NPD_Profilage::evaluer( [ 'toujours' => true ], [] ), 'toujours' );
npd_verifier( NPD_Profilage::evaluer( [ 'P01' => [ 'finance', 'energie' ] ], $rep ), 'feuille : un des choix' );
npd_verifier( ! NPD_Profilage::evaluer( [ 'P06' => [ 'etablissement' ] ], $rep ), 'feuille : question sans réponse = faux' );
npd_verifier( NPD_Profilage::evaluer( [ 'tous' => [ [ 'P01' => [ 'energie' ] ], [ 'P02' => [ 'eti', 'ge' ] ] ] ], $rep ), 'tous' );
npd_verifier( ! NPD_Profilage::evaluer( [ 'tous' => [ [ 'P01' => [ 'energie' ] ], [ 'P02' => [ 'pme' ] ] ] ], $rep ), 'tous : une fausse suffit' );
npd_verifier( NPD_Profilage::evaluer( [ 'un_parmi' => [ [ 'P02' => [ 'pme' ] ], [ 'P14' => [ 'dns' ] ] ] ], $rep ), 'un_parmi' );
npd_verifier( NPD_Profilage::evaluer( [ 'non' => [ 'P01' => [ 'autre' ] ] ], $rep ), 'non' );
npd_verifier( ! NPD_Profilage::evaluer( [ 'inconnu' => 1, 'autre' => 2 ], $rep ) && ! NPD_Profilage::evaluer( 'texte', $rep ), 'forme invalide = faux' );

/* -------------------------------------------------------------------------
 * 3. Nettoyage des réponses
 * ------------------------------------------------------------------------- */
npd_titre( 'Nettoyage des réponses soumises' );

$questions = NPD_Profilage::questions();
npd_verifier( 5 === count( $questions ) && isset( $questions['P15']['condition'] ), '5 questions actives, P15 conditionnelle' );

$propres = NPD_Profilage::nettoyer_reponses( [
    'P01' => 'autre',
    'P02' => [ 'pme', 'eti' ],         // choix unique : un seul gardé
    'P14' => [ 'dns', 'inexistant' ],   // choix inconnu ignoré
    'P15' => 'oui',                     // masquée car P01 = autre
    'P99' => 'x',                       // question inconnue
], $questions );
npd_verifier( [ 'autre' ] === $propres['P01'], 'choix unique conservé' );
npd_verifier( [ 'pme' ] === $propres['P02'], 'choix unique : un seul choix gardé' );
npd_verifier( [ 'dns' ] === $propres['P14'], 'choix inconnu écarté' );
npd_verifier( ! isset( $propres['P15'] ), 'question masquée (condition non remplie) ignorée' );
npd_verifier( ! isset( $propres['P99'] ), 'question inconnue ignorée' );
npd_verifier( [ 'P06' ] === NPD_Profilage::questions_sans_reponse( $propres, $questions ), 'questions sans réponse : P06 seulement (P15 masquée ne compte pas)' );

/* -------------------------------------------------------------------------
 * 4. Calcul des réglementations
 * ------------------------------------------------------------------------- */
npd_titre( 'Calcul' );

$base = [ 'P06' => [ 'aucun' ], 'P14' => [ 'aucune' ] ];
npd_verifier(
    [ 'ISO27001' => 'recommande', 'NIS2-EE' => 'obligatoire', 'SOCLE' => 'obligatoire' ] === npd_calcul( $base + [ 'P01' => [ 'energie' ], 'P02' => [ 'eti' ], 'P15' => [ 'non' ] ] ),
    'énergie + ETI : SOCLE et NIS2 obligatoires, ISO 27001 recommandée'
);
npd_verifier(
    [ 'ISO27001' => 'recommande', 'NIS2-EE' => 'a_verifier', 'SOCLE' => 'obligatoire' ] === npd_calcul( $base + [ 'P01' => [ 'energie' ], 'P02' => [ 'pme' ], 'P15' => [ 'ne_sait_pas' ] ] ),
    'énergie + PME + désignation inconnue : NIS2 à vérifier'
);
npd_verifier(
    'obligatoire' === npd_calcul( $base + [ 'P01' => [ 'energie' ], 'P02' => [ 'eti' ], 'P15' => [ 'ne_sait_pas' ] ] )['NIS2-EE'],
    'deux règles vraies : le niveau le plus fort l\'emporte (obligatoire sur à vérifier)'
);
npd_verifier(
    'obligatoire' === ( npd_calcul( [ 'P01' => [ 'autre' ], 'P02' => [ 'tpe' ], 'P06' => [ 'prestataire' ] ] )['DORA'] ?? '' ),
    'prestataire IT pour la finance : DORA obligatoire'
);
$calc = NPD_Profilage::calculer( $base + [ 'P01' => [ 'energie' ], 'P02' => [ 'eti' ] ] );
npd_verifier( 'Secteur de l\'annexe I et taille ETI ou grande entreprise.' === $calc[ npd_reg_id( 'NIS2-EE' ) ]['critere'], 'le « Pourquoi » vient de la règle déclenchée' );

/* -------------------------------------------------------------------------
 * 5. Formulaires : fiche mission
 * ------------------------------------------------------------------------- */
npd_titre( 'Fiche mission' );

$c1 = wp_insert_user( [ 'user_login' => 'npd_c1_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'c1-' . wp_rand() . '@example.com', 'display_name' => 'Consultant Un', 'role' => 'subscriber' ] );
$c2 = wp_insert_user( [ 'user_login' => 'npd_c2_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'c2-' . wp_rand() . '@example.com', 'display_name' => 'Consultant Deux', 'role' => 'subscriber' ] );
get_userdata( $c1 )->add_role( NPD_Roles::ROLE );
get_userdata( $c2 )->add_role( NPD_Roles::ROLE );

wp_set_current_user( $c1 );

npd_raz_erreurs();
NPD_Espace::traiter_mission( 0, [ 'client' => '   ', 'date_entretien' => '2026-02-30' ] );
$e = NPD_Espace::erreurs();
npd_verifier( 2 === count( $e ), 'client vide et date invalide refusés (' . implode( ' / ', $e ) . ')' );

npd_raz_erreurs();
NPD_Espace::traiter_mission( 0, [ 'client' => 'Client <b>Test</b>', 'perimetre' => "Siège\nUsine", 'interlocuteurs' => 'DSI', 'date_entretien' => '2026-11-03' ] );
global $wpdb;
$d1 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NPD_Installer::table( 'diagnostic' ) . ' WHERE consultant_id = %d ORDER BY id DESC LIMIT 1', $c1 ) );
$diag = NPD_Diagnostics::obtenir( $d1 );
npd_verifier( ! NPD_Espace::erreurs() && $d1 > 0, 'diagnostic créé par le consultant connecté' );
npd_verifier( 'Client Test' === $diag->client && "Siège\nUsine" === $diag->perimetre && '2026-11-03' === $diag->date_entretien, 'champs nettoyés (balises retirées, retours à la ligne gardés)' );
npd_verifier( 'brouillon' === $diag->statut, 'statut : brouillon' );

npd_raz_erreurs();
NPD_Espace::traiter_mission( $d1, [ 'client' => 'Client A', 'date_entretien' => '' ] );
npd_verifier( 'Client A' === NPD_Diagnostics::obtenir( $d1 )->client && null === NPD_Diagnostics::obtenir( $d1 )->date_entretien, 'mise à jour : client modifié, date effacée' );

/* -------------------------------------------------------------------------
 * 6. Cloisonnement
 * ------------------------------------------------------------------------- */
npd_titre( 'Cloisonnement' );

wp_set_current_user( $c2 );
npd_verifier( null === NPD_Espace::diagnostic_accessible( $d1 ), 'un autre consultant ne voit pas le diagnostic' );
npd_raz_erreurs();
NPD_Espace::traiter_mission( $d1, [ 'client' => 'Piratage' ] );
npd_verifier( [ 'Diagnostic introuvable.' ] === NPD_Espace::erreurs() && 'Client A' === NPD_Diagnostics::obtenir( $d1 )->client, 'il ne peut pas modifier la fiche mission' );
npd_raz_erreurs();
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'finance' ] ] );
npd_verifier( [ 'Diagnostic introuvable.' ] === NPD_Espace::erreurs() && ! NPD_Profilage::reponses( $d1 ), 'ni le profilage' );
npd_raz_erreurs();
NPD_Espace::traiter_suppression( $d1 );
npd_verifier( null !== NPD_Diagnostics::obtenir( $d1 ), 'ni le supprimer' );
npd_verifier( 0 === count( NPD_Diagnostics::lister( $c2 ) ) && 1 === count( NPD_Diagnostics::lister( $c1 ) ), 'chaque consultant ne liste que ses diagnostics' );
npd_verifier( count( NPD_Diagnostics::lister( $admin_id ) ) >= 1, 'l\'administrateur les liste tous' );

npd_verifier( NPD_Espace::url() === NPD_Espace::rediriger_apres_connexion( admin_url(), '', get_userdata( $c2 ) ), 'connexion d\'un consultant : arrivée sur son espace' );
npd_verifier( admin_url() === NPD_Espace::rediriger_apres_connexion( admin_url(), '', get_userdata( $admin_id ) ), 'connexion d\'un administrateur : inchangée' );

/* -------------------------------------------------------------------------
 * 7. Profilage puis arbitrage
 * ------------------------------------------------------------------------- */
npd_titre( 'Profilage et arbitrage' );

wp_set_current_user( $c1 );
npd_raz_erreurs();
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'aucun', 'P14' => [ 'aucune' ], 'P15' => 'ne_sait_pas' ] ] );
npd_verifier( 'en_cours' === NPD_Diagnostics::obtenir( $d1 )->statut, 'après profilage : statut en cours' );
npd_verifier( [ 'P01' => [ 'energie' ], 'P02' => [ 'pme' ], 'P06' => [ 'aucun' ], 'P14' => [ 'aucune' ], 'P15' => [ 'ne_sait_pas' ] ] === NPD_Profilage::reponses( $d1 ), 'réponses enregistrées' );
npd_verifier(
    [ 'ISO27001' => [ 'recommande', 'ecartee', 'calcul' ], 'NIS2-EE' => [ 'a_verifier', 'en_attente', 'calcul' ], 'SOCLE' => [ 'obligatoire', 'retenue', 'calcul' ] ] == npd_regs( $d1 ),
    'décisions par défaut : obligatoire retenue, à vérifier en attente, recommandé non retenu'
);
npd_verifier( 1 === NPD_Profilage::nb_en_attente( $d1 ), '1 réglementation à trancher' );

// Justifications.
$nis2 = npd_reg_id( 'NIS2-EE' );
$soc  = npd_reg_id( 'SOCLE' );
$iso  = npd_reg_id( 'ISO27001' );
npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [ $nis2 => [ 'decision' => 'retenue', 'justification' => '' ], $iso => [ 'decision' => 'retenue', 'justification' => '' ] ] ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ) && 'en_attente' === npd_regs( $d1 )['NIS2-EE'][1] && 'ecartee' === npd_regs( $d1 )['ISO27001'][1], 'à vérifier tranchée sans justification : refusé, RIEN enregistré' );

npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [ $soc => [ 'decision' => 'ecartee', 'justification' => '' ] ] ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ), 'obligatoire écartée sans justification : refusé' );

npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [ $soc => [ 'decision' => 'en_attente' ] ] ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ), 'obligatoire mise « en attente » : décision invalide' );

npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [
    $nis2 => [ 'decision' => 'retenue', 'justification' => 'Désignation confirmée par courrier de l\'autorité.' ],
    $iso  => [ 'decision' => 'retenue', 'justification' => '' ],
] ] );
npd_verifier( ! NPD_Espace::erreurs(), 'arbitrage justifié enregistré' );
npd_verifier( [ 'a_verifier', 'retenue', 'calcul' ] === npd_regs( $d1 )['NIS2-EE'] && 'retenue' === npd_regs( $d1 )['ISO27001'][1], 'NIS2 confirmée, ISO 27001 retenue comme objectif (sans justification)' );
npd_verifier( 0 === NPD_Profilage::nb_en_attente( $d1 ), 'plus rien à trancher' );

/* -------------------------------------------------------------------------
 * 8. Effet sur le questionnaire
 * ------------------------------------------------------------------------- */
npd_titre( 'Effet sur le questionnaire' );

$ex = NPD_Profilage::exigences( $d1 );
npd_verifier( [ 'G3', 'I6', 'A4' ] === array_keys( $ex ), 'sous-thèmes dans l\'ordre des thèmes (G, I, A)' );
npd_verifier( 'renforce' === $ex['G3']['profondeur'] && 4.0 === $ex['G3']['cible'] && 'NIS2 : entité essentielle' === $ex['G3']['reglementation'], 'G3 : renforcé, cible 4 fixée par NIS2 (maximum des retenues)' );
npd_verifier( 'standard' === $ex['I6']['profondeur'] && 3.0 === $ex['I6']['cible'], 'I6 : standard, cible 3' );
npd_verifier( 'standard' === $ex['A4']['profondeur'] && 3.0 === $ex['A4']['cible'] && 'ISO/IEC 27001' === $ex['A4']['reglementation'], 'A4 : fixé par ISO 27001 retenue' );

/* -------------------------------------------------------------------------
 * 9. Le profilage change : l'arbitrage est préservé
 * ------------------------------------------------------------------------- */
npd_titre( 'Profilage modifié' );

// La désignation est confirmée : NIS2 passe obligatoire, la décision reste.
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'aucun', 'P14' => [ 'aucune' ], 'P15' => 'oui' ] ] );
npd_verifier( [ 'obligatoire', 'retenue', 'calcul' ] === npd_regs( $d1 )['NIS2-EE'], 'NIS2 devenue obligatoire : décision « retenue » conservée' );
npd_verifier( 'retenue' === npd_regs( $d1 )['ISO27001'][1], 'ISO 27001 : choix du consultant conservé' );

// Retour à « ne sait pas » : NIS2 redevient à vérifier, mais la confirmation
// justifiée du consultant n'est pas remise « en attente ».
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'aucun', 'P14' => [ 'aucune' ], 'P15' => 'ne_sait_pas' ] ] );
npd_verifier( [ 'a_verifier', 'retenue', 'calcul' ] === npd_regs( $d1 )['NIS2-EE'], 'NIS2 redevenue à vérifier : confirmation justifiée conservée' );
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'aucun', 'P14' => [ 'aucune' ], 'P15' => 'oui' ] ] );

// Le client est en fait prestataire IT pour une banque : DORA apparaît.
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'prestataire', 'P14' => [ 'aucune' ], 'P15' => 'oui' ] ] );
npd_verifier( [ 'obligatoire', 'retenue', 'calcul' ] === ( npd_regs( $d1 )['DORA'] ?? null ), 'DORA ajoutée par le calcul, retenue par défaut' );
npd_verifier( 'DORA' === NPD_Profilage::exigences( $d1 )['I6']['reglementation'] && 'renforce' === NPD_Profilage::exigences( $d1 )['I6']['profondeur'], 'I6 passe en renforcé, cible fixée par DORA' );

// Correction : pas prestataire, et pas désigné. DORA (par défaut) disparaît ;
// NIS2 (retenue) n'est plus calculée mais reste, en forçage à justifier.
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'energie', 'P02' => 'pme', 'P06' => 'aucun', 'P14' => [ 'aucune' ], 'P15' => 'non' ] ] );
$regs = npd_regs( $d1 );
npd_verifier( ! isset( $regs['DORA'] ), 'DORA retirée : elle n\'était qu\'à sa valeur par défaut' );
npd_verifier( 'forcage' === $regs['NIS2-EE'][2] && 'retenue' === $regs['NIS2-EE'][1], 'NIS2 maintenue en forçage, pas retirée en silence' );

/* -------------------------------------------------------------------------
 * 10. Forçage
 * ------------------------------------------------------------------------- */
npd_titre( 'Forçage' );

$dora = npd_reg_id( 'DORA' );
npd_verifier( in_array( $dora, array_map( 'intval', wp_list_pluck( NPD_Profilage::reglementations_ajoutables( $d1 ), 'id' ) ), true ), 'DORA proposée à l\'ajout' );
npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'ajout' => $dora, 'ajout_justification' => '' ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ) && ! isset( npd_regs( $d1 )['DORA'] ), 'ajout sans justification refusé' );
npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'ajout' => $dora, 'ajout_justification' => 'Contrat d\'infogérance avec une banque, signé en 2026.' ] );
npd_verifier( [ 'recommande', 'retenue', 'forcage' ] === npd_regs( $d1 )['DORA'], 'DORA ajoutée en forçage, retenue' );

npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [ $nis2 => [ 'decision' => 'retenue', 'justification' => '' ] ] ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ), 'NIS2 en forçage sans justification : refusé' );

npd_raz_erreurs();
NPD_Espace::traiter_reglementations( $d1, [ 'reg' => [ $dora => [ 'decision' => 'ecartee', 'justification' => '' ] ] ] );
npd_verifier( ! NPD_Espace::erreurs() && ! isset( npd_regs( $d1 )['DORA'] ), 'écarter un forçage le retire' );

/* -------------------------------------------------------------------------
 * 11. Diagnostic finalisé : lecture seule
 * ------------------------------------------------------------------------- */
npd_titre( 'Diagnostic finalisé' );

NPD_Diagnostics::changer_statut( $d1, NPD_Diagnostics::STATUT_FINALISE );
$avant = NPD_Profilage::reponses( $d1 );
npd_raz_erreurs();
NPD_Espace::traiter_profilage( $d1, [ 'profil' => [ 'P01' => 'finance' ] ] );
npd_verifier( 1 === count( NPD_Espace::erreurs() ) && $avant === NPD_Profilage::reponses( $d1 ), 'profilage refusé' );
npd_raz_erreurs();
NPD_Espace::traiter_mission( $d1, [ 'client' => 'Autre' ] );
npd_verifier( 'Client A' === NPD_Diagnostics::obtenir( $d1 )->client, 'fiche mission refusée' );
NPD_Diagnostics::changer_statut( $d1, NPD_Diagnostics::STATUT_EN_COURS );

/* -------------------------------------------------------------------------
 * 12. Préavis et suppression
 * ------------------------------------------------------------------------- */
npd_titre( 'Préavis et suppression' );

npd_verifier( null === NPD_Espace::jours_avant_purge( NPD_Diagnostics::obtenir( $d1 ) ), 'échéance lointaine : pas de préavis' );
$bientot = ( new DateTime( current_time( 'mysql' ) ) )->modify( '+10 days' )->format( 'Y-m-d H:i:s' );
$wpdb->update( NPD_Installer::table( 'diagnostic' ), [ 'purge_le' => $bientot ], [ 'id' => $d1 ] );
$j = NPD_Espace::jours_avant_purge( NPD_Diagnostics::obtenir( $d1 ) );
npd_verifier( in_array( $j, [ 9, 10 ], true ), 'échéance à 10 jours : préavis affiché (' . var_export( $j, true ) . ' jours)' );

npd_raz_erreurs();
NPD_Espace::traiter_suppression( $d1 );
npd_verifier( null === NPD_Diagnostics::obtenir( $d1 ) && ! NPD_Profilage::reponses( $d1 ) && ! npd_regs( $d1 ), 'suppression par son consultant : diagnostic, profilage et arbitrage effacés' );

/* -------------------------------------------------------------------------
 * Nettoyage
 * ------------------------------------------------------------------------- */
wp_set_current_user( $admin_id );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( NPD_Diagnostics::lister( $admin_id ) as $l ) {
    if ( in_array( (int) $l->consultant_id, [ $c1, $c2 ], true ) ) {
        NPD_Diagnostics::supprimer( (int) $l->id );
    }
}
wp_delete_user( $c1 );
wp_delete_user( $c2 );

$t = $GLOBALS['npd_tests'];
echo "\n" . ( $t['ko'] ? '✘' : '✔' ) . " {$t['ok']} vérification(s) réussie(s), {$t['ko']} échec(s).\n";
