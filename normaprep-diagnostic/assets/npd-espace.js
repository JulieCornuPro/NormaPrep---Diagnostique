/**
 * NormaPrep Diagnostic — comportements de l'espace consultant.
 *
 * Tout est facultatif : sans JavaScript, les formulaires fonctionnent et le
 * serveur fait les mêmes contrôles (conditions d'affichage, justifications).
 */

/**
 * Repli de la barre latérale (repris de NormaPrep Quiz).
 * Le choix est mémorisé localement d'une page à l'autre.
 */
(function () {
    'use strict';

    var CLE = 'npd_sidebar_repliee';
    var shell = document.querySelector('.npd-app .shell');
    var bouton = document.getElementById('npdCollapseToggle');
    if (!shell) {
        return;
    }

    try {
        if (localStorage.getItem(CLE) === '1') {
            shell.classList.add('collapsed');
            majLibelle(true);
        }
    } catch (e) {
        // localStorage indisponible : on ignore.
    }

    if (bouton) {
        bouton.addEventListener('click', function () {
            var replie = shell.classList.toggle('collapsed');
            majLibelle(replie);
            try {
                localStorage.setItem(CLE, replie ? '1' : '0');
            } catch (e) {
                // Mémorisation impossible : le repli fonctionne quand même.
            }
        });
    }

    function majLibelle(replie) {
        if (!bouton) { return; }
        var lbl = bouton.querySelector('.lbl');
        if (lbl) {
            lbl.textContent = replie ? 'Déplier le menu' : 'Réduire le menu';
        }
        bouton.setAttribute('aria-expanded', replie ? 'false' : 'true');
    }
})();

/**
 * Filtres par statut de la liste des diagnostics.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-npd-filtres]').forEach(function (barre) {
        var table = document.getElementById(barre.getAttribute('data-npd-filtres'));
        if (!table) {
            return;
        }
        var vide = document.querySelector('.npd-table-vide');

        barre.addEventListener('click', function (e) {
            var bouton = e.target.closest('.npd-filtre');
            if (!bouton) {
                return;
            }
            var filtre = bouton.getAttribute('data-filtre');
            barre.querySelectorAll('.npd-filtre').forEach(function (b) {
                b.classList.toggle('actif', b === bouton);
                b.setAttribute('aria-selected', b === bouton ? 'true' : 'false');
            });

            var visibles = 0;
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                var montrer = filtre === 'tous' || tr.getAttribute('data-statut') === filtre;
                tr.hidden = !montrer;
                if (montrer) { visibles++; }
            });
            if (vide) { vide.hidden = visibles !== 0; }
            table.hidden = visibles === 0;
        });
    });
})();

/**
 * Profilage : conditions d'affichage et progression.
 *
 * Même grammaire que NPD_Profilage::evaluer() côté serveur. Une question
 * masquée a ses champs désactivés : ils ne sont pas envoyés.
 */
(function () {
    'use strict';

    var form = document.getElementById('npdProfilage');
    if (!form) {
        return;
    }
    var blocs = Array.prototype.slice.call(form.querySelectorAll('[data-npd-question]'));
    var progression = document.getElementById('npdProgression');

    function evaluer(c, reponses) {
        if (!c || typeof c !== 'object' || Array.isArray(c)) { return false; }
        var cles = Object.keys(c);
        if (cles.length !== 1) { return false; }
        var cle = cles[0], v = c[cle];
        if (cle === 'toujours') { return v === true; }
        if (cle === 'tous') { return Array.isArray(v) && v.length > 0 && v.every(function (s) { return evaluer(s, reponses); }); }
        if (cle === 'un_parmi') { return Array.isArray(v) && v.some(function (s) { return evaluer(s, reponses); }); }
        if (cle === 'non') { return !evaluer(v, reponses); }
        var donnees = reponses[cle] || [];
        return Array.isArray(v) && v.some(function (x) { return donnees.indexOf(x) !== -1; });
    }

    function majAffichage() {
        // On reconstruit les réponses dans l'ordre : une condition ne dépend
        // que des questions visibles qui la précèdent.
        var reponses = {};
        var visibles = 0, repondues = 0;

        blocs.forEach(function (bloc) {
            var code = bloc.getAttribute('data-npd-question');
            var brute = bloc.getAttribute('data-npd-condition');
            var visible = true;
            if (brute) {
                try { visible = evaluer(JSON.parse(brute), reponses); } catch (e) { visible = true; }
            }
            bloc.hidden = !visible;
            bloc.querySelectorAll('input').forEach(function (i) { i.disabled = !visible; });

            if (visible) {
                visibles++;
                var coches = Array.prototype.map.call(bloc.querySelectorAll('input:checked'), function (i) { return i.value; });
                if (coches.length) {
                    reponses[code] = coches;
                    repondues++;
                    bloc.classList.remove('sans-reponse');
                }
            }
        });

        if (progression) {
            progression.textContent = repondues + ' / ' + visibles + ' question' + (visibles > 1 ? 's' : '') + ' renseignée' + (repondues > 1 ? 's' : '');
        }
    }

    form.addEventListener('change', majAffichage);
    majAffichage();
})();

/**
 * Arbitrage : justification exigée selon la décision.
 *
 * Chaque réglementation porte data-npd-justif = décisions qui exigent une
 * justification (même règle que NPD_Profilage::justification_requise).
 */
(function () {
    'use strict';

    var form = document.getElementById('npdArbitrage');
    if (!form) {
        return;
    }

    function maj(carte) {
        var exigees = (carte.getAttribute('data-npd-justif') || '').split(',').filter(Boolean);
        var choisie = carte.querySelector('.npd-decision input:checked');
        var decision = choisie ? choisie.value : '';
        var exigee = exigees.indexOf(decision) !== -1;
        var bloc = carte.querySelector('.npd-justif');
        var mention = carte.querySelector('.npd-justif .requis');

        bloc.classList.toggle('exigee', exigee);
        if (mention) { mention.hidden = !exigee; }
        if (!exigee) { bloc.classList.remove('manquante'); }

        ['retenue', 'ecartee', 'en_attente'].forEach(function (d) {
            carte.classList.toggle('decision-' + d, d === decision);
        });
        return exigee;
    }

    var cartes = Array.prototype.slice.call(form.querySelectorAll('.npd-reg'));
    cartes.forEach(maj);
    form.addEventListener('change', function (e) {
        var carte = e.target.closest('.npd-reg');
        if (carte) { maj(carte); }
    });

    form.addEventListener('submit', function (e) {
        var premiere = null;
        cartes.forEach(function (carte) {
            var zone = carte.querySelector('.npd-justif textarea');
            var manque = maj(carte) && zone.value.trim() === '';
            carte.querySelector('.npd-justif').classList.toggle('manquante', manque);
            if (manque && !premiere) { premiere = zone; }
        });

        var ajout = form.querySelector('#npd_ajout');
        var ajoutJustif = form.querySelector('#npd_ajout_justif');
        if (ajout && ajout.value !== '0' && ajoutJustif && ajoutJustif.value.trim() === '') {
            ajoutJustif.closest('label').classList.add('manquante');
            if (!premiere) { premiere = ajoutJustif; }
        }

        if (premiere) {
            e.preventDefault();
            premiere.focus();
            premiere.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
