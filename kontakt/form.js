(function () {
  var form = document.getElementById('anfrage');
  if (!form) return;
  var status = document.getElementById('formular-status');
  var tsField = form.querySelector('#f-ts');
  var fields = {
    name: form.querySelector('#f-name'),
    email: form.querySelector('#f-email'),
    phone: form.querySelector('#f-telefon'),
    message: form.querySelector('#f-nachricht')
  };
  var button = form.querySelector('button[type="submit"]');

  function stamp() {
    if (tsField) tsField.value = String(Math.floor(Date.now() / 1000));
  }

  function setStatus(text, kind) {
    if (!status) return;
    status.textContent = text;
    status.className = 'formular__status' + (kind ? ' formular__status--' + kind : '');
  }

  function setBusy(busy) {
    if (!button) return;
    button.disabled = !!busy;
    button.setAttribute('aria-disabled', busy ? 'true' : 'false');
  }

  function setError(input, text) {
    var slot = form.querySelector('[data-for="' + input.id + '"]');
    if (slot) slot.textContent = text || '';
    if (text) {
      input.setAttribute('aria-invalid', 'true');
      if (slot) input.setAttribute('aria-describedby', slot.id);
    } else {
      input.removeAttribute('aria-invalid');
    }
  }

  function clearErrors() {
    ['name', 'email', 'phone', 'message'].forEach(function (key) {
      if (fields[key]) setError(fields[key], '');
    });
  }

  function validate() {
    clearErrors();
    var first = null;
    function bad(input, text) {
      setError(input, text);
      if (!first) first = input;
    }
    if (!fields.name.value.trim()) bad(fields.name, 'Bitte einen Namen eintragen.');
    else if (fields.name.value.trim().length > 120) bad(fields.name, 'Der Name ist zu lang.');
    var email = fields.email.value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 160 || /[\r\n]/.test(email)) {
      bad(fields.email, 'Bitte eine gültige E-Mail-Adresse eintragen.');
    }
    var phone = fields.phone.value.trim();
    if (phone && (phone.length > 40 || !/^[0-9+\-\s()/]+$/.test(phone))) {
      bad(fields.phone, 'Die Telefonnummer bitte nur mit Ziffern, Leerzeichen und + / - ( ).');
    }
    if (!fields.message.value.trim()) bad(fields.message, 'Bitte eine Nachricht eintragen.');
    else if (fields.message.value.trim().length > 4000) bad(fields.message, 'Die Nachricht ist zu lang.');
    if (first) {
      setStatus('Die Anfrage wurde nicht gesendet. Bitte die markierten Felder prüfen.', 'fehler');
      first.focus();
      return false;
    }
    return true;
  }

  function errorText(code) {
    if (code === 'too_fast') return 'Bitte etwas warten und das Formular erneut senden.';
    if (code === 'expired' || code === 'ts') return 'Das Formular war zu lange geöffnet oder unvollständig. Bitte die Seite neu laden und erneut senden.';
    if (code === 'email') return 'Bitte eine gültige E-Mail-Adresse eintragen.';
    if (code === 'name') return 'Bitte einen Namen eintragen.';
    if (code === 'phone') return 'Die Telefonnummer bitte nur mit Ziffern, Leerzeichen und + / - ( ).';
    if (code === 'message') return 'Bitte eine Nachricht eintragen.';
    if (code === 'rate') return 'Es wurden zu viele Anfragen gesendet. Bitte später erneut versuchen oder uns per E-Mail schreiben.';
    return 'Die Anfrage konnte nicht übermittelt werden. Bitte schreiben Sie uns an info@megacleanberlin.de.';
  }

  stamp();
  setBusy(false);
  if (status && /freigeschaltet/i.test(status.textContent || '')) {
    setStatus('', '');
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!validate()) return;
    setBusy(true);
    setStatus('Ihre Anfrage wird gesendet …', '');
    var data = new FormData(form);
    fetch(form.getAttribute('action') || 'kontakt/senden.php', {
      method: 'POST',
      body: data,
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body };
        }).catch(function () {
          return { ok: false, body: null };
        });
      })
      .then(function (result) {
        if (result.body && result.body.ok === true) {
          setStatus('Vielen Dank. Ihre Anfrage wurde übermittelt. Wenn Sie nichts von uns hören, schreiben Sie uns bitte an info@megacleanberlin.de.', 'ok');
          form.reset();
          stamp();
          setBusy(false);
          return;
        }
        var code = result.body && result.body.error ? result.body.error : '';
        setStatus(errorText(code), 'fehler');
        setBusy(false);
      })
      .catch(function () {
        setStatus('Die Anfrage konnte nicht übermittelt werden. Bitte schreiben Sie uns an info@megacleanberlin.de.', 'fehler');
        setBusy(false);
      });
  });
})();
