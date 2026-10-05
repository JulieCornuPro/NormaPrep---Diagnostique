<?php
/**
 * Gabarit de la page « Espace consultant ».
 *
 * Reprend la coquille de l'espace membre du quiz : en-tête et pied de page du
 * thème, barre latérale pleine hauteur, contenu au centre. L'écran affiché
 * dépend du paramètre npd_vue (voir NPD_Espace).
 *
 * Sans connexion, la page affiche la mire (adresse email et mot de passe).
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Visiteur non connecté : mire de connexion, sans barre latérale.
if ( ! is_user_logged_in() ) {
    get_header();
    echo '<div class="npd-app"><div class="npd-mire">';
    require NPD_PATH . 'public/vues/' . ( 'mdp_oublie' === NPD_Espace::vue_courante() ? 'mdp-oublie.php' : 'connexion.php' );
    echo '</div></div>';
    get_footer();
    return;
}

$npd_vue  = NPD_Espace::vue_courante();
$npd_id   = NPD_Espace::diagnostic_courant();
$npd_diag = $npd_id ? NPD_Espace::diagnostic_accessible( $npd_id ) : null;

// Entrée surlignée dans la barre latérale.
$npd_active = ( 'mission' === $npd_vue && ! $npd_id ) ? 'nouveau' : 'liste';

get_header();
?>

<div class="npd-app">
  <div class="shell" id="npdShell">

    <?php echo NPD_Espace::barre_laterale( $npd_active ); // déjà échappé ?>

    <main class="main">
      <?php
      if ( ! current_user_can( NPD_Roles::CAP_MENER ) ) {
          require NPD_PATH . 'public/vues/acces-refuse.php';
      } elseif ( $npd_id && ! $npd_diag ) {
          require NPD_PATH . 'public/vues/introuvable.php';
      } elseif ( 'liste' === $npd_vue || 'mdp_oublie' === $npd_vue ) {
          require NPD_PATH . 'public/vues/liste.php';
      } elseif ( 'mission' === $npd_vue ) {
          require NPD_PATH . 'public/vues/mission.php';
      } elseif ( ! $npd_diag ) {
          require NPD_PATH . 'public/vues/introuvable.php';
      } elseif ( 'profilage' === $npd_vue ) {
          require NPD_PATH . 'public/vues/profilage.php';
      } else {
          require NPD_PATH . 'public/vues/reglementations.php';
      }
      ?>
    </main>

  </div>
</div>

<?php
get_footer();
