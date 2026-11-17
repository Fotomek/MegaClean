/* Inhalte aus Sanity (Projekt pec5sp9n, Datensatz production).
   Die Seite ist vollständig als statisches HTML ausgeliefert. Dieses Skript holt
   nach dem Laden die veröffentlichten Texte und Bilder vom öffentlichen Sanity-CDN
   (apicdn.sanity.io, ohne Cookies, ohne Token) und ersetzt nur Stellen, deren Inhalt
   sich in Sanity tatsächlich geändert hat. Schlägt der Abruf fehl, bleibt das HTML
   unverändert stehen. Inhalte werden nur als Text gesetzt (kein innerHTML aus Sanity).
   Pflege: https://megaclean.sanity.studio */
(function () {
  'use strict';
  var PROJEKT = 'pec5sp9n', DATENSATZ = 'production', API = 'v2025-02-19';
  if (!window.fetch || !document.querySelector) return;

  var BILD = '{alt, hotspot, "ref": asset._ref, "url": asset->url}';
  var QUERY = '*[_id == "startseite"][0]{..., heroBild' + BILD +
    ', leistungen[]{..., kartenbild' + BILD + '}}';
  var URL = 'https://' + PROJEKT + '.apicdn.sanity.io/' + API + '/data/query/' + DATENSATZ +
    '?query=' + encodeURIComponent(QUERY);

  function norm(s) { return String(s == null ? '' : s).replace(/\u00ad/g, '').replace(/\s+/g, ' ').trim(); }
  function hat(v) { return typeof v === 'string' && norm(v) !== ''; }
  function setzeText(el, wert) {
    if (!el || !hat(wert)) return;
    if (norm(el.textContent) !== norm(wert)) el.textContent = wert;
  }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }

  /* Liste aus gleichartigen Kindelementen an ein Array anpassen; neue Einträge
     sind Kopien des ersten Eintrags. fill(el, wert, index) befüllt einen Eintrag. */
  function passeListeAn(container, selektor, werte, fill) {
    if (!container || !Array.isArray(werte) || !werte.length) return;
    var items = $$(selektor, container).filter(function (e) { return e.parentNode === container; });
    if (!items.length) return;
    var vorlage = items[0];
    while (items.length < werte.length) {
      var neu = vorlage.cloneNode(true);
      neu.removeAttribute('id');
      var letzter = items[items.length - 1];
      letzter.parentNode.insertBefore(neu, letzter.nextSibling);
      items.push(neu);
    }
    while (items.length > werte.length) { var weg = items.pop(); weg.parentNode.removeChild(weg); }
    werte.forEach(function (w, i) { fill(items[i], w, i); });
  }
  function textListe(container, werte) {
    passeListeAn(container, 'li', (werte || []).filter(hat), function (el, w) { setzeText(el, w); });
  }

  function bildUrl(url, breite) { return url + '?w=' + breite + '&fit=max&auto=format&q=80'; }
  function setzeBild(img, bild, breiten) {
    if (!img || !bild) return;
    if (typeof bild.alt === 'string' && img.getAttribute('alt') !== bild.alt && !img.closest('[aria-hidden="true"]')) img.setAttribute('alt', bild.alt);
    if (!bild.ref || !bild.url || bild.ref === img.getAttribute('data-cms-asset')) return;
    if (bild.url.indexOf('https://cdn.sanity.io/') !== 0) return;
    img.setAttribute('src', bildUrl(bild.url, breiten[Math.min(1, breiten.length - 1)]));
    img.setAttribute('srcset', breiten.map(function (b) { return bildUrl(bild.url, b) + ' ' + b + 'w'; }).join(', '));
    img.setAttribute('data-cms-asset', bild.ref);
    if (bild.hotspot && typeof bild.hotspot.x === 'number') {
      img.style.objectPosition = Math.round(bild.hotspot.x * 100) + '% ' + Math.round(bild.hotspot.y * 100) + '%';
    }
  }

  function slug(s) {
    return norm(s).toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'leistung';
  }

  function anwenden(d) {
    if (!d || typeof d !== 'object') return;

    $$('[data-cms]').forEach(function (el) { setzeText(el, d[el.getAttribute('data-cms')]); });

    var lb = $('[data-cms-laufband]');
    if (lb && hat(d.laufbandFett) && hat(d.laufbandText)) {
      var neuLb = false;
      $$('.laufband__eintrag', lb).forEach(function (e) {
        var st = e.querySelector('strong');
        var rest = norm(e.textContent).slice(norm(st ? st.textContent : '').length).trim();
        if (st && norm(st.textContent) === norm(d.laufbandFett) && rest === norm(d.laufbandText)) return;
        neuLb = true;
        e.textContent = '';
        var s = document.createElement('strong'); s.textContent = d.laufbandFett;
        e.appendChild(s); e.appendChild(document.createTextNode(' ' + d.laufbandText));
      });
      if (neuLb) lb.setAttribute('aria-label', norm(d.laufbandFett) + ' ' + norm(d.laufbandText));
    }

    setzeBild($('[data-cms-bild="heroBild"]'), d.heroBild, [800, 1280, 1672]);

    if (Array.isArray(d.ueberAbsaetze)) {
      var absaetze = d.ueberAbsaetze.filter(hat), ps = $$('[data-cms-absatz]');
      if (absaetze.length && ps.length) {
        while (ps.length < absaetze.length) {
          var np = ps[0].cloneNode(false); ps[ps.length - 1].parentNode.insertBefore(np, ps[ps.length - 1].nextSibling); ps.push(np);
        }
        while (ps.length > absaetze.length) { var x = ps.pop(); x.parentNode.removeChild(x); }
        absaetze.forEach(function (t, i) { setzeText(ps[i], t); });
      }
    }
    textListe($('[data-cms-liste="ueberPunkte"]'), d.ueberPunkte);
    textListe($('[data-cms-liste="bezirke"]'), d.bezirke);

    var cta = $('[data-cms-cta]');
    if (cta && hat(d.leistungenAbschluss)) {
      var tn = cta.firstChild;
      if (tn && tn.nodeType === 3 && norm(tn.nodeValue) !== norm(d.leistungenAbschluss)) tn.nodeValue = d.leistungenAbschluss + ' ';
    }
    if (cta) setzeText(cta.querySelector('a'), d.leistungenKnopf);

    /* Leistungen: Übersichtskarten und Kacheln */
    var leistungen = (d.leistungen || []).filter(function (l) { return l && hat(l.titel); });
    if (leistungen.length) {
      var symbole = {};
      $$('[data-cms-kacheln] .ico[data-cms-symbol]').forEach(function (e) {
        var k = e.getAttribute('data-cms-symbol'); if (!symbole[k]) symbole[k] = e.innerHTML;
      });
      var vergeben = {};
      leistungen.forEach(function (l) {
        var id = l.anker || slug(l.titel), n = 2, basis = id;
        while (vergeben[id]) id = basis + '-' + (n++);
        vergeben[id] = 1; l._id = id;
      });
      passeListeAn($('[data-cms-kacheln]'), 'article', leistungen, function (art, l) {
        if (art.id !== l._id) art.id = l._id;
        setzeText(art.querySelector('h3'), l.titel);
        setzeText(art.querySelector('.kachel__text > p'), l.kurztext);
        var ico = art.querySelector('.ico');
        if (ico && l.symbol && symbole[l.symbol] && ico.getAttribute('data-cms-symbol') !== l.symbol) {
          ico.innerHTML = symbole[l.symbol]; ico.setAttribute('data-cms-symbol', l.symbol);
        }
        var det = art.querySelector('details.kachel__mehr');
        if (det) {
          var punkte = (l.punkte || []).filter(hat);
          if (punkte.length) { det.hidden = false; textListe(det.querySelector('ul'), punkte); } else det.hidden = true;
        }
        var link = art.querySelector('.kachel__link');
        if (link) { var al = norm(l.titel) + ' anfragen'; if (link.getAttribute('aria-label') !== al) link.setAttribute('aria-label', al); }
      });
      passeListeAn($('[data-cms-karten]'), 'li', leistungen, function (li, l) {
        var a = li.querySelector('a');
        if (a && a.getAttribute('href') !== '#' + l._id) a.setAttribute('href', '#' + l._id);
        setzeText(li.querySelector('.karte__titel'), l.titel);
        setzeBild(li.querySelector('img'), l.kartenbild, [800, 1168]);
      });
    }

    /* Vorteile */
    var vorteile = (d.vorteile || []).filter(function (v) { return v && hat(v.titel); });
    if (vorteile.length) {
      var vsym = {};
      $$('[data-cms-vorteile] > li[data-cms-symbol]').forEach(function (e) {
        var k = e.getAttribute('data-cms-symbol'), s = e.querySelector('svg'); if (s && !vsym[k]) vsym[k] = s.outerHTML;
      });
      passeListeAn($('[data-cms-vorteile]'), 'li', vorteile, function (li, v) {
        setzeText(li.querySelector('h3'), v.titel);
        setzeText(li.querySelector('p'), v.text);
        var s = li.querySelector('svg');
        if (s && v.symbol && vsym[v.symbol] && li.getAttribute('data-cms-symbol') !== v.symbol) {
          var tmp = document.createElement('div'); tmp.innerHTML = vsym[v.symbol];
          li.replaceChild(tmp.firstChild, s); li.setAttribute('data-cms-symbol', v.symbol);
        }
      });
    }

    /* Ablauf */
    passeListeAn($('[data-cms-schritte]'), 'li', (d.ablauf || []).filter(function (s) { return s && hat(s.titel); }), function (li, s) {
      setzeText(li.querySelector('h3'), s.titel); setzeText(li.querySelector('p'), s.text);
    });

    /* FAQ */
    passeListeAn($('[data-cms-faq]'), 'details', (d.faq || []).filter(function (f) { return f && hat(f.frage); }), function (det, f) {
      setzeText(det.querySelector('summary'), f.frage); setzeText(det.querySelector('p'), f.antwort);
    });
  }

  var ctrl = window.AbortController ? new AbortController() : null;
  var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 6000) : null;
  fetch(URL, { credentials: 'omit', mode: 'cors', referrerPolicy: 'no-referrer', signal: ctrl ? ctrl.signal : undefined })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      if (timer) clearTimeout(timer);
      if (j && j.result) { try { anwenden(j.result); } catch (e) { /* HTML bleibt stehen */ } }
    })
    .catch(function () { /* offline oder gesperrt: HTML bleibt stehen */ });
})();
