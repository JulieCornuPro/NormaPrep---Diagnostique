<?php
/**
 * Écran : mire de connexion de l'espace consultant.
 *
 * Reprise de la mire de NormaPrep Quiz (adresse email + mot de passe).
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$npd_retour = NPD_Espace::url( NPD_Espace::vue_courante(), NPD_Espace::diagnostic_courant() );
$npd_email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="crumb">NormaPrep Diagnostic</div>
<h1 class="page-title">Espace consultant</h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::messages(); // déjà échappé ?>

<form method="post" action="<?php echo esc_url( $npd_retour ); ?>" class="npd-form npd-carte npd-carte-mire">
  <?php NPD_Espace::champs_formulaire( 'connexion', 0 ); ?>
  <input type="hidden" name="retour" value="<?php echo esc_url( $npd_retour ); ?>">

  <label for="npd_email">Adresse email
    <input type="email" id="npd_email" name="email" required autocomplete="username" value="<?php echo esc_attr( $npd_email ); ?>">
  </label>
  <label for="npd_mdp">Mot de passe
    <input type="password" id="npd_mdp" name="mdp" required autocomplete="current-password">
  </label>
  <label class="npd-case">
    <input type="checkbox" name="souvenir" value="1"> Rester connecté sur cet appareil
  </label>

  <div class="npd-boutons">
    <button type="submit" class="btn btn-primary">Se connecter</button>
    <a class="npd-lien-discret" href="<?php echo esc_url( NPD_Espace::url( 'mdp_oublie' ) ); ?>">Mot de passe oublié ?</a>
  </div>
</form>

<p class="npd-sous-texte">Les comptes consultants sont créés par l'administrateur de NormaPrep.</p>
