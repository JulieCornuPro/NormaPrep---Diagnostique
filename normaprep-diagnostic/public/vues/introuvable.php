<?php
/**
 * Écran : diagnostic inexistant ou appartenant à un autre consultant.
 *
 * Les deux cas affichent le même message : on ne révèle pas l'existence d'un
 * diagnostic qu'on ne peut pas consulter.
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="crumb">Espace consultant</div>
<h1 class="page-title">Diagnostic introuvable</h1>
<div class="title-rule"></div>
<p class="npd-texte">Ce diagnostic n'existe pas, ou vous n'y avez pas accès.</p>
<p><a class="btn btn-ghost" href="<?php echo esc_url( NPD_Espace::url() ); ?>">Retour à mes diagnostics</a></p>
