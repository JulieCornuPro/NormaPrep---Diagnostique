<?php
/**
 * Écran : mot de passe oublié.
 *
 * Envoie le lien de réinitialisation de WordPress. La réponse est la même
 * que l'adresse corresponde ou non à un compte consultant.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="crumb">
  <a href="<?php echo esc_url( NPD_Espace::url() ); ?>">Espace consultant</a><span class="sep">/</span>
  <span class="cur">Mot de passe oublié</span>
</div>
<h1 class="page-title">Mot de passe oublié</h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::messages(); // déjà échappé ?>

<form method="post" action="<?php echo esc_url( NPD_Espace::url( 'mdp_oublie' ) ); ?>" class="npd-form npd-carte npd-carte-mire">
  <?php NPD_Espace::champs_formulaire( 'mdp_oublie', 0 ); ?>
  <p class="npd-texte">Indiquez l'adresse email de votre compte consultant : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
  <label for="npd_email_oubli">Adresse email
    <input type="email" id="npd_email_oubli" name="email" required autocomplete="username">
  </label>
  <div class="npd-boutons">
    <button type="submit" class="btn btn-primary">Recevoir le lien</button>
    <a class="npd-lien-discret" href="<?php echo esc_url( NPD_Espace::url() ); ?>">Retour à la connexion</a>
  </div>
</form>
