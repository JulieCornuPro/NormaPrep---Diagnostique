<?php
/**
 * Écran : liste des diagnostics du consultant (tous pour l'administrateur).
 *
 * @package NormaPrep_Diagnostic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$npd_admin  = current_user_can( NPD_Roles::CAP_GERER );
$npd_lignes = NPD_Diagnostics::lister( get_current_user_id() );

$npd_compte = [ 'brouillon' => 0, 'en_cours' => 0, 'finalise' => 0, 'echeance' => 0 ];
foreach ( $npd_lignes as $npd_l ) {
    if ( isset( $npd_compte[ $npd_l->statut ] ) ) {
        $npd_compte[ $npd_l->statut ]++;
    }
    if ( null !== NPD_Espace::jours_avant_purge( $npd_l ) ) {
        $npd_compte['echeance']++;
    }
}
?>
<div class="crumb">Espace consultant<span class="sep">/</span><span class="cur">Diagnostics</span></div>
<h1 class="page-title"><?php echo $npd_admin ? 'Diagnostics' : 'Mes diagnostics'; ?></h1>
<div class="title-rule"></div>

<?php echo NPD_Espace::messages(); // déjà échappé ?>

<?php if ( ! NPD_Diagnostics::referentiel_actif_id() ) : ?>
  <div class="npd-msg npd-msg-erreur">
    Aucun référentiel n'est encore importé : le profilage et le questionnaire seront vides.
    <?php if ( $npd_admin ) : ?>
      <a href="<?php echo esc_url( admin_url( 'admin.php?page=npd-referentiel' ) ); ?>">Importer le référentiel</a>
    <?php else : ?>
      Prévenez l'administrateur du site.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="cta-row">
  <a href="<?php echo esc_url( NPD_Espace::url( 'mission' ) ); ?>" class="btn btn-primary">Nouveau diagnostic</a>
</div>

<div class="stat-grid">
  <div class="stat-card"><div class="sc-label">En cours</div><div class="sc-val"><?php echo (int) $npd_compte['en_cours']; ?></div></div>
  <div class="stat-card"><div class="sc-label">Brouillons</div><div class="sc-val"><?php echo (int) $npd_compte['brouillon']; ?></div></div>
  <div class="stat-card"><div class="sc-label">Finalisés</div><div class="sc-val"><?php echo (int) $npd_compte['finalise']; ?></div></div>
  <div class="stat-card<?php echo $npd_compte['echeance'] ? ' alerte' : ''; ?>">
    <div class="sc-label">Suppression sous <?php echo (int) NPD_Espace::PREAVIS_JOURS; ?> jours</div>
    <div class="sc-val"><?php echo (int) $npd_compte['echeance']; ?></div>
  </div>
</div>

<div class="sec-title">Diagnostics</div>

<?php if ( ! $npd_lignes ) : ?>
  <p class="empty">Aucun diagnostic pour l'instant. Commencez par créer une fiche mission.</p>
<?php else : ?>

  <div class="npd-filtres" role="tablist" data-npd-filtres="npdTableDiags">
    <button type="button" class="npd-filtre actif" data-filtre="tous">Tous <span class="npd-filtre-nb"><?php echo count( $npd_lignes ); ?></span></button>
    <button type="button" class="npd-filtre" data-filtre="brouillon">Brouillons <span class="npd-filtre-nb"><?php echo (int) $npd_compte['brouillon']; ?></span></button>
    <button type="button" class="npd-filtre" data-filtre="en_cours">En cours <span class="npd-filtre-nb"><?php echo (int) $npd_compte['en_cours']; ?></span></button>
    <button type="button" class="npd-filtre" data-filtre="finalise">Finalisés <span class="npd-filtre-nb"><?php echo (int) $npd_compte['finalise']; ?></span></button>
  </div>

  <div class="npd-table-defil">
  <table id="npdTableDiags">
    <thead>
      <tr>
        <th>Client</th>
        <?php if ( $npd_admin ) : ?><th>Consultant</th><?php endif; ?>
        <th>Entretien</th>
        <th>Statut</th>
        <th>Modifié le</th>
        <th>Conservation</th>
        <th><span class="screen-reader-text">Actions</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ( $npd_lignes as $npd_l ) :
        $npd_jours = NPD_Espace::jours_avant_purge( $npd_l );
        $npd_suite = NPD_Diagnostics::STATUT_BROUILLON === $npd_l->statut ? 'profilage'
            : ( NPD_Diagnostics::STATUT_EN_COURS === $npd_l->statut ? 'reglementations' : 'mission' );
        ?>
      <tr data-statut="<?php echo esc_attr( $npd_l->statut ); ?>">
        <td>
          <a class="npd-lien-client" href="<?php echo esc_url( NPD_Espace::url( $npd_suite, (int) $npd_l->id ) ); ?>">
            <?php echo esc_html( $npd_l->client ); ?>
          </a>
          <?php if ( $npd_l->consultant_initial ) : ?>
            <div class="npd-sous-texte">Réattribué — mené par <?php echo esc_html( $npd_l->consultant_initial ); ?></div>
          <?php endif; ?>
        </td>
        <?php if ( $npd_admin ) : ?><td><?php echo esc_html( $npd_l->consultant_nom ); ?></td><?php endif; ?>
        <td class="mono"><?php echo $npd_l->date_entretien ? esc_html( mysql2date( 'd/m/Y', $npd_l->date_entretien ) ) : '&mdash;'; ?></td>
        <td><span class="npd-statut npd-statut-<?php echo esc_attr( $npd_l->statut ); ?>"><?php echo esc_html( NPD_Espace::libelle_statut( $npd_l->statut ) ); ?></span></td>
        <td class="mono"><?php echo esc_html( mysql2date( 'd/m/Y', $npd_l->modifie_le ) ); ?></td>
        <td class="mono">
          <?php if ( null !== $npd_jours ) : ?>
            <span class="npd-preavis" title="Suppression automatique prévue le <?php echo esc_attr( mysql2date( 'd/m/Y', $npd_l->purge_le ) ); ?>">
              Suppression le <?php echo esc_html( mysql2date( 'd/m/Y', $npd_l->purge_le ) ); ?>
            </span>
          <?php else : ?>
            jusqu'au <?php echo esc_html( mysql2date( 'd/m/Y', $npd_l->purge_le ) ); ?>
          <?php endif; ?>
        </td>
        <td class="npd-actions">
          <a class="row-action" href="<?php echo esc_url( NPD_Espace::url( $npd_suite, (int) $npd_l->id ) ); ?>">Ouvrir</a>
          <form method="post" action="<?php echo esc_url( NPD_Espace::url() ); ?>" class="npd-form-inline">
            <?php NPD_Espace::champs_formulaire( 'supprimer', (int) $npd_l->id ); ?>
            <button type="submit" class="row-action danger"
              data-npd-confirm="Le diagnostic « <?php echo esc_attr( $npd_l->client ); ?> » et toutes ses réponses seront supprimés définitivement. Cette action est irréversible."
              data-npd-confirm-title="Supprimer le diagnostic"
              data-npd-confirm-ok="Supprimer"
              data-npd-confirm-danger>Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="npd-table-vide" hidden>Aucun diagnostic dans cette catégorie.</p>

<?php endif; ?>
