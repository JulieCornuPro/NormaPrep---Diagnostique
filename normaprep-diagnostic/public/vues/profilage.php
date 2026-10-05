<?php
/**
 * Écran : profilage réglementaire.
 *
 * Les questions dont la condition d'affichage n'est pas remplie sont
 * masquées dans le navigateur (npd-espace.js) ET ignorées à l'enregistrement
 * (NPD_Profilage::nettoyer_reponses) : le serveur ne fait pas confiance au
 * navigateur.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$npd_modif     = NPD_Diagnostics::est_modifiable( $npd_diag );
$npd_questions = NPD_Profilage::questions();
$npd_reponses  = NPD_Profilage::reponses( (int) $npd_diag->id );
$npd_manque    = NPD_Profilage::questions_sans_reponse( $npd_reponses, $npd_questions );
?>
<div class="crumb">
  <a href="<?php echo esc_url( NPD_Espace::url() ); ?>">Diagnostics</a><span class="sep">/</span>
  <span class="cur"><?php echo esc_html( $npd_diag->client ); ?></span>
</div>
<h1 class="page-title">Profilage</h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::etapes( $npd_diag, 'profilage' ); // déjà échappé ?>
<?php echo NPD_Espace::messages(); // déjà échappé ?>

<p class="npd-texte">
  Ces questions déterminent les réglementations applicables au client, donc la
  profondeur du questionnaire et les niveaux de maturité visés. Vous pourrez
  ajuster le résultat à l'étape suivante.
</p>

<?php if ( ! $npd_questions ) : ?>
  <div class="npd-msg npd-msg-erreur">Aucune question de profilage : le référentiel n'est pas importé.</div>
<?php else : ?>

<form method="post" action="<?php echo esc_url( NPD_Espace::url( 'profilage', (int) $npd_diag->id ) ); ?>" class="npd-form npd-profilage" id="npdProfilage">
  <?php NPD_Espace::champs_formulaire( 'profilage', (int) $npd_diag->id ); ?>
  <fieldset <?php disabled( ! $npd_modif ); ?>>

  <?php
  $npd_n = 0;
  foreach ( $npd_questions as $npd_code => $npd_q ) :
      $npd_n++;
      $npd_multiple = 'choix_multiple' === $npd_q['type'];
      $npd_coches   = $npd_reponses[ $npd_code ] ?? [];
      ?>
    <div class="npd-question npd-carte<?php echo in_array( $npd_code, $npd_manque, true ) && $npd_reponses ? ' sans-reponse' : ''; ?>"
         data-npd-question="<?php echo esc_attr( $npd_code ); ?>"
         <?php if ( null !== $npd_q['condition'] ) : ?>data-npd-condition="<?php echo esc_attr( wp_json_encode( $npd_q['condition'] ) ); ?>"<?php endif; ?>>
      <div class="npd-q-entete">
        <span class="npd-q-code mono"><?php echo esc_html( $npd_code ); ?></span>
        <span class="npd-q-type mono"><?php echo $npd_multiple ? 'plusieurs réponses possibles' : 'une réponse'; ?></span>
      </div>
      <div class="npd-q-libelle" id="npd-q-<?php echo esc_attr( $npd_code ); ?>"><?php echo esc_html( $npd_q['libelle'] ); ?></div>
      <?php if ( '' !== $npd_q['aide'] ) : ?>
        <p class="npd-q-aide"><?php echo esc_html( $npd_q['aide'] ); ?></p>
      <?php endif; ?>

      <div class="npd-choix" role="<?php echo $npd_multiple ? 'group' : 'radiogroup'; ?>" aria-labelledby="npd-q-<?php echo esc_attr( $npd_code ); ?>">
        <?php foreach ( $npd_q['choix'] as $npd_c ) : ?>
          <label class="npd-choix-item">
            <input type="<?php echo $npd_multiple ? 'checkbox' : 'radio'; ?>"
              name="profil[<?php echo esc_attr( $npd_code ); ?>]<?php echo $npd_multiple ? '[]' : ''; ?>"
              value="<?php echo esc_attr( $npd_c['code'] ); ?>"
              <?php checked( in_array( $npd_c['code'], $npd_coches, true ) ); ?>>
            <span><?php echo esc_html( $npd_c['libelle'] ); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ( $npd_modif ) : ?>
    <div class="npd-boutons npd-boutons-collants">
      <span class="npd-progression mono" id="npdProgression" aria-live="polite"></span>
      <button type="submit" class="btn btn-primary">Enregistrer et calculer les réglementations</button>
    </div>
  <?php endif; ?>
  </fieldset>
</form>

<?php endif; ?>
