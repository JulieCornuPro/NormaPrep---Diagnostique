<?php
/**
 * Écran : réglementations applicables et arbitrage du consultant.
 *
 * Le calcul propose, le consultant décide. Une justification est exigée
 * quand la décision s'écarte du calcul (voir
 * NPD_Profilage::justification_requise) ; le navigateur la signale, le
 * serveur la vérifie.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$npd_did     = (int) $npd_diag->id;
$npd_modif   = NPD_Diagnostics::est_modifiable( $npd_diag );
$npd_regs    = NPD_Profilage::reglementations( $npd_did );
$npd_attente = NPD_Profilage::nb_en_attente( $npd_did );
$npd_ajouts  = NPD_Profilage::reglementations_ajoutables( $npd_did );
$npd_profil  = NPD_Profilage::reponses( $npd_did );

// Valeurs soumises, si le formulaire revient en erreur.
$npd_soumis = NPD_Espace::erreurs() && isset( $_POST['npd_action'] ) && 'reglementations' === $_POST['npd_action']; // phpcs:ignore WordPress.Security.NonceVerification
$npd_post   = $npd_soumis ? wp_unslash( $_POST ) : []; // phpcs:ignore WordPress.Security.NonceVerification

// Groupes d'affichage.
$npd_groupes = [
    'obligatoire' => [ 'Obligations', 'Très probablement applicables au regard du profil déclaré.' ],
    'a_verifier'  => [ 'À vérifier', 'L\'application dépend de critères fins : à trancher avec le client.' ],
    'recommande'  => [ 'Référentiels recommandés', 'Non obligatoires. Retenez-les comme objectif si le client le souhaite : leurs exigences s\'appliqueront.' ],
    'forcage'     => [ 'Ajoutées par le consultant', 'Hors du calcul, ajoutées ou maintenues à votre initiative.' ],
];
$npd_par_groupe = array_fill_keys( array_keys( $npd_groupes ), [] );
foreach ( $npd_regs as $npd_r ) {
    $npd_par_groupe[ 'forcage' === $npd_r->origine ? 'forcage' : $npd_r->niveau_calcule ][] = $npd_r;
}

/**
 * Options de décision proposées pour une ligne.
 */
$npd_options = function ( $r ) {
    if ( 'forcage' === $r->origine ) {
        return [ 'retenue' => 'Retenue', 'ecartee' => 'Retirer' ];
    }
    switch ( $r->niveau_calcule ) {
        case 'obligatoire':
            return [ 'retenue' => 'Retenue', 'ecartee' => 'Écartée' ];
        case 'a_verifier':
            return [ 'en_attente' => 'À trancher', 'retenue' => 'Confirmée', 'ecartee' => 'Écartée' ];
        default:
            return [ 'retenue' => 'Retenue comme objectif', 'ecartee' => 'Non retenue' ];
    }
};
?>
<div class="crumb">
  <a href="<?php echo esc_url( NPD_Espace::url() ); ?>">Diagnostics</a><span class="sep">/</span>
  <span class="cur"><?php echo esc_html( $npd_diag->client ); ?></span>
</div>
<h1 class="page-title">Réglementations</h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::etapes( $npd_diag, 'reglementations' ); // déjà échappé ?>
<?php echo NPD_Espace::messages(); // déjà échappé ?>

<?php if ( ! $npd_profil ) : ?>
  <div class="npd-msg npd-msg-info">
    Le profilage n'a pas encore été rempli.
    <a href="<?php echo esc_url( NPD_Espace::url( 'profilage', $npd_did ) ); ?>">Commencer le profilage</a>
  </div>
<?php endif; ?>

<?php if ( $npd_attente ) : ?>
  <div class="npd-msg npd-msg-attention">
    <strong><?php echo (int) $npd_attente; ?> réglementation(s) à trancher</strong> avant de passer au questionnaire.
  </div>
<?php endif; ?>

<p class="npd-avertissement">
  Analyse indicative, établie à partir des informations déclarées : elle ne
  constitue pas un avis juridique.
</p>

<form method="post" action="<?php echo esc_url( NPD_Espace::url( 'reglementations', $npd_did ) ); ?>" class="npd-form" id="npdArbitrage">
  <?php NPD_Espace::champs_formulaire( 'reglementations', $npd_did ); ?>
  <fieldset <?php disabled( ! $npd_modif ); ?>>

  <?php foreach ( $npd_groupes as $npd_cle => $npd_g ) :
      if ( ! $npd_par_groupe[ $npd_cle ] ) {
          continue;
      }
      ?>
    <section class="npd-groupe npd-groupe-<?php echo esc_attr( $npd_cle ); ?>">
      <div class="sec-title"><?php echo esc_html( $npd_g[0] ); ?> <span class="npd-compte mono"><?php echo count( $npd_par_groupe[ $npd_cle ] ); ?></span></div>
      <p class="npd-groupe-intro"><?php echo esc_html( $npd_g[1] ); ?></p>

      <?php foreach ( $npd_par_groupe[ $npd_cle ] as $npd_r ) :
          $npd_rid      = (int) $npd_r->reglementation_id;
          $npd_decision = $npd_soumis ? ( $npd_post['reg'][ $npd_rid ]['decision'] ?? $npd_r->decision ) : $npd_r->decision;
          $npd_justif   = $npd_soumis ? ( $npd_post['reg'][ $npd_rid ]['justification'] ?? '' ) : (string) $npd_r->justification;
          $npd_exigent  = [];
          foreach ( array_keys( $npd_options( $npd_r ) ) as $npd_d ) {
              if ( NPD_Profilage::justification_requise( $npd_r->origine, $npd_r->niveau_calcule, $npd_d ) ) {
                  $npd_exigent[] = $npd_d;
              }
          }
          $npd_actions = $npd_r->actions ? (array) json_decode( $npd_r->actions, true ) : [];
          ?>
        <article class="npd-reg npd-carte decision-<?php echo esc_attr( $npd_decision ); ?>"
                 data-npd-justif="<?php echo esc_attr( implode( ',', $npd_exigent ) ); ?>">
          <header class="npd-reg-entete">
            <h3><?php echo esc_html( $npd_r->libelle ); ?></h3>
            <?php if ( 'forcage' !== $npd_r->origine ) : ?>
              <span class="npd-badge npd-badge-<?php echo esc_attr( $npd_r->niveau_calcule ); ?>"><?php echo esc_html( NPD_Espace::libelle_niveau( $npd_r->niveau_calcule ) ); ?></span>
            <?php endif; ?>
            <?php if ( '0' === (string) $npd_r->actif ) : ?>
              <span class="npd-badge npd-badge-retiree">retirée du référentiel</span>
            <?php endif; ?>
          </header>

          <?php if ( $npd_r->critere ) : ?>
            <p class="npd-reg-pourquoi"><strong>Pourquoi :</strong> <?php echo esc_html( $npd_r->critere ); ?></p>
          <?php endif; ?>
          <?php if ( $npd_r->description ) : ?>
            <p class="npd-reg-desc"><?php echo esc_html( $npd_r->description ); ?></p>
          <?php endif; ?>
          <?php if ( $npd_r->echeance ) : ?>
            <p class="npd-reg-echeance mono">Échéance : <?php echo esc_html( $npd_r->echeance ); ?></p>
          <?php endif; ?>
          <?php if ( $npd_actions ) : ?>
            <ul class="npd-reg-actions">
              <?php foreach ( $npd_actions as $npd_a ) : ?><li><?php echo esc_html( $npd_a ); ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div class="npd-decision" role="radiogroup" aria-label="Décision pour <?php echo esc_attr( $npd_r->libelle ); ?>">
            <?php foreach ( $npd_options( $npd_r ) as $npd_val => $npd_lib ) : ?>
              <label class="npd-choix-item">
                <input type="radio" name="reg[<?php echo $npd_rid; ?>][decision]" value="<?php echo esc_attr( $npd_val ); ?>" <?php checked( $npd_decision, $npd_val ); ?>>
                <span><?php echo esc_html( $npd_lib ); ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <label class="npd-justif">
            <span class="npd-justif-lbl">Justification <span class="requis" hidden>obligatoire pour cette décision</span></span>
            <textarea name="reg[<?php echo $npd_rid; ?>][justification]" rows="2"
              placeholder="Élément vérifié avec le client, source, raison de l'écart…"><?php echo esc_textarea( $npd_justif ); ?></textarea>
          </label>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if ( $npd_modif && $npd_ajouts ) : ?>
    <section class="npd-groupe">
      <div class="sec-title">Ajouter une réglementation</div>
      <p class="npd-groupe-intro">Si une réglementation s'applique alors que le calcul ne l'a pas proposée.</p>
      <div class="npd-carte npd-ajout">
        <label for="npd_ajout">Réglementation
          <select id="npd_ajout" name="ajout">
            <option value="0">— Aucune —</option>
            <?php foreach ( $npd_ajouts as $npd_a ) : ?>
              <option value="<?php echo (int) $npd_a->id; ?>" <?php selected( (int) ( $npd_post['ajout'] ?? 0 ), (int) $npd_a->id ); ?>><?php echo esc_html( $npd_a->libelle ); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label for="npd_ajout_justif">Justification
          <textarea id="npd_ajout_justif" name="ajout_justification" rows="2"
            placeholder="Pourquoi cette réglementation s'applique-t-elle ?"><?php echo esc_textarea( (string) ( $npd_post['ajout_justification'] ?? '' ) ); ?></textarea>
        </label>
      </div>
    </section>
  <?php endif; ?>

  <?php if ( $npd_modif && ( $npd_regs || $npd_ajouts ) ) : ?>
    <div class="npd-boutons">
      <button type="submit" class="btn btn-primary">Enregistrer l'arbitrage</button>
      <a class="btn btn-ghost" href="<?php echo esc_url( NPD_Espace::url( 'profilage', $npd_did ) ); ?>">Revoir le profilage</a>
    </div>
  <?php endif; ?>
  </fieldset>
</form>

<?php
// Effet des réglementations retenues sur le questionnaire.
$npd_exigences = NPD_Profilage::exigences( $npd_did );
if ( $npd_exigences ) :
    ?>
  <div class="sec-title npd-effet-titre">Effet sur le questionnaire</div>
  <p class="npd-groupe-intro">
    D'après les réglementations <strong>retenues</strong> et enregistrées : profondeur des
    questions posées et niveau de maturité visé, par sous-thème.
  </p>
  <div class="npd-table-defil">
  <table class="npd-effet">
    <thead>
      <tr><th>Sous-thème</th><th>Profondeur</th><th>Cible</th><th>Fixée par</th></tr>
    </thead>
    <tbody>
      <?php
      $npd_theme_courant = '';
      foreach ( $npd_exigences as $npd_e ) :
          if ( $npd_e['theme'] !== $npd_theme_courant ) :
              $npd_theme_courant = $npd_e['theme'];
              ?>
        <tr class="npd-ligne-theme npd-theme-<?php echo esc_attr( strtolower( $npd_e['theme'] ) ); ?>">
          <td colspan="4"><?php echo esc_html( $npd_e['theme_libelle'] ); ?></td>
        </tr>
          <?php endif; ?>
        <tr>
          <td><span class="mono npd-code"><?php echo esc_html( $npd_e['code'] ); ?></span> <?php echo esc_html( $npd_e['libelle'] ); ?></td>
          <td><span class="npd-profondeur npd-profondeur-<?php echo esc_attr( $npd_e['profondeur'] ); ?>"><?php echo esc_html( NPD_Espace::libelle_profondeur( $npd_e['profondeur'] ) ); ?></span></td>
          <td class="mono"><?php echo null === $npd_e['cible'] ? '&mdash;' : esc_html( number_format_i18n( $npd_e['cible'], 1 ) . ' / 5' ); ?></td>
          <td><?php echo $npd_e['reglementation'] ? esc_html( $npd_e['reglementation'] ) : '<span class="npd-sous-texte">aucune exigence</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
