<?php
/**
 * Écran : fiche mission (création ou modification).
 *
 * En cas d'erreur, le formulaire est réaffiché avec les valeurs saisies.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$npd_modif = $npd_diag ? NPD_Diagnostics::est_modifiable( $npd_diag ) : true;

// Valeurs : celles soumises (si erreur), sinon celles enregistrées.
$npd_soumis = NPD_Espace::erreurs() && isset( $_POST['npd_action'] ) && 'mission' === $_POST['npd_action']; // phpcs:ignore WordPress.Security.NonceVerification
$npd_val    = function ( $champ ) use ( $npd_soumis, $npd_diag ) {
    if ( $npd_soumis ) {
        return (string) wp_unslash( $_POST[ $champ ] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
    }
    return $npd_diag ? (string) $npd_diag->$champ : '';
};
?>
<div class="crumb">
  <a href="<?php echo esc_url( NPD_Espace::url() ); ?>">Diagnostics</a><span class="sep">/</span>
  <span class="cur"><?php echo $npd_diag ? esc_html( $npd_diag->client ) : 'Nouveau'; ?></span>
</div>
<h1 class="page-title"><?php echo $npd_diag ? 'Fiche mission' : 'Nouveau diagnostic'; ?></h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::etapes( $npd_diag, 'mission' ); // déjà échappé ?>
<?php echo NPD_Espace::messages(); // déjà échappé ?>

<?php if ( ! $npd_modif ) : ?>
  <div class="npd-msg npd-msg-info">Ce diagnostic est finalisé : sa fiche mission est en lecture seule.</div>
<?php endif; ?>

<div class="npd-confidentialite">
  <strong>Confidentialité.</strong>
  Ce diagnostic décrira les faiblesses de sécurité du client. Un pseudonyme suffit
  pour le désigner. Pour les interlocuteurs, indiquez leur fonction plutôt que
  leur nom. Ne saisissez jamais de mot de passe, d'adresse IP ou d'identifiant
  technique.
</div>

<form method="post" action="<?php echo esc_url( NPD_Espace::url( 'mission', $npd_diag ? (int) $npd_diag->id : 0 ) ); ?>" class="npd-carte npd-form">
  <?php NPD_Espace::champs_formulaire( 'mission', $npd_diag ? (int) $npd_diag->id : 0 ); ?>
  <fieldset <?php disabled( ! $npd_modif ); ?>>

    <label for="npd_client">Client <span class="requis">obligatoire</span>
      <input type="text" id="npd_client" name="client" maxlength="190" required
        value="<?php echo esc_attr( $npd_val( 'client' ) ); ?>"
        placeholder="Nom ou pseudonyme, ex. « Client A — PME services »">
    </label>

    <label for="npd_date">Date de l'entretien
      <input type="date" id="npd_date" name="date_entretien"
        value="<?php echo esc_attr( $npd_val( 'date_entretien' ) ); ?>">
    </label>

    <label for="npd_perimetre">Périmètre du diagnostic
      <textarea id="npd_perimetre" name="perimetre" rows="4"
        placeholder="Entités, sites, systèmes ou activités couverts ; exclusions éventuelles."><?php echo esc_textarea( $npd_val( 'perimetre' ) ); ?></textarea>
    </label>

    <label for="npd_interlocuteurs">Interlocuteurs
      <textarea id="npd_interlocuteurs" name="interlocuteurs" rows="3"
        placeholder="Fonctions rencontrées, ex. « DSI, responsable RH, développeur principal »."><?php echo esc_textarea( $npd_val( 'interlocuteurs' ) ); ?></textarea>
    </label>

    <?php if ( $npd_modif ) : ?>
      <div class="npd-boutons">
        <button type="submit" class="btn btn-primary"><?php echo $npd_diag ? 'Enregistrer' : 'Créer le diagnostic'; ?></button>
        <a class="btn btn-ghost" href="<?php echo esc_url( NPD_Espace::url() ); ?>">Annuler</a>
      </div>
    <?php endif; ?>
  </fieldset>
</form>

<?php if ( $npd_diag ) : ?>
  <p class="npd-sous-texte">
    Créé le <?php echo esc_html( mysql2date( 'd/m/Y', $npd_diag->cree_le ) ); ?>
    · modifié le <?php echo esc_html( mysql2date( 'd/m/Y à H:i', $npd_diag->modifie_le ) ); ?>
    · conservé jusqu'au <?php echo esc_html( mysql2date( 'd/m/Y', $npd_diag->purge_le ) ); ?>
  </p>
<?php endif; ?>
