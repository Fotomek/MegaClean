(function () {
  var form = document.getElementById('anfrage');
  if (!form) return;
  var status = document.getElementById('formular-status');
  var fields = {
    name: form.querySelector('#f-name'),
    email: form.querySelector('#f-email'),
    phone: form.querySelector('#f-telefon'),
    message: form.querySelector('#f-nachricht'),
    privacy: form.querySelector('#f-dsgvo')
  };

  function setStatus(text, kind) {
    status.textContent = text;
    status.className = 'formular__status' + (kind ? ' formular__status--' + kind : '');
  }

  function setError(input, text) {
    var slot = form.querySelector('[data-for="' + input.id + '"]');
    if (slot) slot.textContent = text || '';
    if (text) {
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', slot ? slot.id : '');
    } else {
      input.removeAttribute('aria-invalid');
    }
  }

  function clearErrors() {
    ['name', 'email', 'phone', 'message'].forEach(function (key) {
      setError(fields[key], '');
    });
    var privacy = form.querySelector('[data-for="f-dsgvo"]');
    if (privacy) privacy.textContent = '';
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
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 160) {
      bad(fields.email, 'Bitte eine gültige E-Mail-Adresse eintragen.');
    }
    var phone = fields.phone.value.trim();
    if (phone && (phone.length > 40 || !/^[0-9+\-\s()/]+$/.test(phone))) {
      bad(fields.phone, 'Die Telefonnummer bitte nur mit Ziffern, Leerzeichen und + / - ( ).');
    }
    if (!fields.message.value.trim()) bad(fields.message, 'Bitte eine Nachricht eintragen.');
    else if (fields.message.value.trim().length > 4000) bad(fields.message, 'Die Nachricht ist zu lang.');
    if (!fields.privacy.checked) {
      var slot = form.querySelector('[data-for="f-dsgvo"]');
      if (slot) slot.textContent = 'Bitte die Datenschutzhinweise bestätigen.';
      if (!first) first = fields.privacy;
    }
    if (first) {
      setStatus('Die Anfrage wurde nicht gesendet. Bitte die markierten Felder prüfen.', 'fehler');
      first.focus();
      return false;
    }
    return true;
  }

  var csrf = '';
  function loadToken() {
    return fetch('kontakt/token.php', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('token');
        return response.json();
      })
      .then(function (token) {
        if (!token || !token.csrf) throw new Error('token');
        csrf = token.csrf;
      });
  }
  loadToken().catch(function () { csrf = ''; });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!validate()) return;
    setStatus('Die Anfrage wird geprüft. Es ist noch nichts gesendet.', '');
    var data = new FormData(form);
    var ready = csrf ? Promise.resolve() : loadToken();
    ready.then(function () {
        if (!csrf) throw new Error('token');
        data.set('csrf', csrf);
        return fetch('kontakt/senden.php', {
          method: 'POST',
          body: data,
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        });
      })
      .then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body };
        }).catch(function () {
          return { ok: false, body: null };
        });
      })
      .then(function (result) {
        if (result.body && result.body.accepted === true && result.body.delivery === 'unconfirmed') {
          setStatus('Der Server hat die Anfrage angenommen. Das bestätigt nicht, dass die E-Mail angekommen ist.', 'ok');
          form.reset();
          return;
        }
        if (result.body && result.body.error === 'not_configured') {
          setStatus('Das Formular ist hier nicht freigeschaltet. Es wurde nichts gesendet. Bitte die E-Mail-Adresse nutzen.', 'fehler');
          return;
        }
        setStatus('Die Anfrage wurde nicht angenommen. Es wurde nichts gesendet.', 'fehler');
      })
      .catch(function () {
        setStatus('Der Server hat die Anfrage nicht angenommen. Es wurde nichts gesendet. Bitte die E-Mail-Adresse nutzen.', 'fehler');
      });
  });
})();
