(function(){
  'use strict';

  function postToken(){
    var fd = new FormData();
    fd.append('action', 'ripex_portal_standalone_token');
    fd.append('nonce', RIPEX_STANDALONE_PROTOTYPE.nonce || '');

    return fetch(RIPEX_STANDALONE_PROTOTYPE.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    }).then(function(response){
      return response.text();
    }).then(function(text){
      try {
        return JSON.parse(text);
      } catch (e) {
        return { success: false, data: { message: 'Respuesta inválida al generar el token.' } };
      }
    });
  }

  function install(){
    if (document.getElementById('rp-standalone-prototype')) return;
    if (typeof RIPEX_STANDALONE_PROTOTYPE === 'undefined') return;

    var container = document.querySelector('.rp-topbar-right');
    if (!container) return;

    var button = document.createElement('button');
    button.type = 'button';
    button.id = 'rp-standalone-prototype';
    button.className = 'rp-btn';
    button.textContent = 'Probar portal liviano';
    button.title = 'Abre el prototipo read-only sin theme, Elementor ni WooCommerce frontend.';

    button.addEventListener('click', function(){
      var popup = window.open('', '_blank', 'noopener');
      var original = button.textContent;
      button.disabled = true;
      button.textContent = 'Generando acceso...';

      postToken().then(function(result){
        button.disabled = false;
        button.textContent = original;

        if (!result || !result.success || !result.data || !result.data.token) {
          if (popup) popup.close();
          window.alert(result && result.data && result.data.message
            ? result.data.message
            : 'No se pudo abrir el prototipo liviano.');
          return;
        }

        var url = (result.data.standalone_url || RIPEX_STANDALONE_PROTOTYPE.standaloneUrl)
          + '#token=' + encodeURIComponent(result.data.token);

        if (popup) {
          popup.location = url;
        } else {
          window.location.href = url;
        }
      }).catch(function(){
        button.disabled = false;
        button.textContent = original;
        if (popup) popup.close();
        window.alert('No se pudo abrir el prototipo liviano.');
      });
    });

    container.insertBefore(button, container.firstChild);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', install);
  } else {
    install();
  }
})();
