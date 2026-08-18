(function(){
  'use strict';

  const cfg = window.RIPEX_VALIDACIONES || {};
  const cities = Array.isArray(cfg.cities) ? cfg.cities : [];
  const messages = cfg.messages || {};

  function normalizeText(value) {
    return (value || '').toString()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]/g, '');
  }

  function cleanRut(value) {
    return (value || '').toString().toUpperCase().replace(/[^0-9K]/g, '');
  }

  function validateRut(value) {
    const rut = cleanRut(value);
    if (rut.length < 2) return false;

    const body = rut.slice(0, -1);
    const dv = rut.slice(-1);

    if (!/^\d+$/.test(body)) return false;

    let sum = 0;
    let factor = 2;

    for (let i = body.length - 1; i >= 0; i--) {
      sum += Number(body[i]) * factor;
      factor = factor === 7 ? 2 : factor + 1;
    }

    let expected = 11 - (sum % 11);
    expected = expected === 11 ? '0' : expected === 10 ? 'K' : String(expected);

    return dv === expected;
  }

  function formatRut(value) {
    const rut = cleanRut(value);
    if (rut.length < 2) return value || '';

    let body = rut.slice(0, -1);
    const dv = rut.slice(-1);
    let out = '';

    while (body.length > 3) {
      out = '.' + body.slice(-3) + out;
      body = body.slice(0, -3);
    }

    return body + out + '-' + dv;
  }

  function ensureMessage(input) {
    if (!input) return null;

    let msg = input.parentNode.querySelector('.rpv-message[data-for="' + input.id + '"]');
    if (!msg) {
      msg = document.createElement('div');
      msg.className = 'rpv-message';
      msg.dataset.for = input.id;
      input.insertAdjacentElement('afterend', msg);
    }

    return msg;
  }

  function setState(input, type, message) {
    const msg = ensureMessage(input);
    input.classList.remove('rpv-is-valid', 'rpv-is-invalid');

    if (type === 'valid') input.classList.add('rpv-is-valid');
    if (type === 'invalid') input.classList.add('rpv-is-invalid');

    if (msg) {
      msg.className = 'rpv-message ' + (type ? 'rpv-' + type : '');
      msg.textContent = message || '';
    }
  }

  function initRut() {
    const input = document.querySelector(cfg.rutField || '#afreg_additional_42210');
    if (!input) return;

    input.setAttribute('autocomplete', 'off');

    function run(showEmpty) {
      const value = input.value.trim();

      if (!value) {
        setState(input, showEmpty ? 'invalid' : '', showEmpty ? (messages.rutRequired || 'Ingresa un RUT.') : '');
        return false;
      }

      if (!validateRut(value)) {
        setState(input, 'invalid', messages.rutInvalid || 'RUT inválido.');
        return false;
      }

      input.value = formatRut(value);
      setState(input, 'valid', messages.rutValid || 'RUT válido.');
      return true;
    }

    input.addEventListener('input', function(){
      input.classList.remove('rpv-is-valid');
      if (input.value.trim().length >= 7) run(false);
      else setState(input, '', '');
    });

    input.addEventListener('blur', function(){ run(false); });

    const form = input.closest('form');
    if (form) {
      form.addEventListener('submit', function(event){
        if (!run(true)) {
          event.preventDefault();
          input.focus();
        }
      });
    }
  }

  function cityCanonical(value) {
    const key = normalizeText(value);
    if (!key) return '';

    for (const city of cities) {
      if (normalizeText(city) === key) return city;
    }

    return '';
  }

  function filterCities(value) {
    const key = normalizeText(value);
    if (!key) return cities.slice(0, 12);

    return cities.filter(function(city){
      return normalizeText(city).includes(key);
    }).slice(0, 12);
  }

  function createCityDropdown(input) {
    let wrap = input.closest('.rpv-city-wrap');

    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'rpv-city-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
    }

    let dropdown = wrap.querySelector('.rpv-city-dropdown');
    if (!dropdown) {
      dropdown = document.createElement('div');
      dropdown.className = 'rpv-city-dropdown';
      dropdown.hidden = true;
      wrap.appendChild(dropdown);
    }

    return dropdown;
  }

  function initCity() {
    const input = document.querySelector(cfg.cityField || '#billing_city');
    if (!input || !cities.length) return;

    input.removeAttribute('list');
    input.setAttribute('autocomplete', 'off');

    const dropdown = createCityDropdown(input);

    function render() {
      const value = input.value.trim();
      const results = filterCities(value);
      dropdown.innerHTML = '';

      if (!results.length) {
        const item = document.createElement('div');
        item.className = 'rpv-city-empty';
        item.textContent = messages.noCityResults || 'No se encontraron ciudades.';
        dropdown.appendChild(item);
      } else {
        results.forEach(function(city){
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'rpv-city-option';
          item.textContent = city;
          item.addEventListener('mousedown', function(event){
            event.preventDefault();
            input.value = city;
            dropdown.hidden = true;
            setState(input, 'valid', messages.cityValid || 'Ciudad/comuna válida.');
          });
          dropdown.appendChild(item);
        });
      }

      dropdown.hidden = false;
    }

    function validate(showEmpty) {
      const value = input.value.trim();

      if (!value) {
        setState(input, showEmpty ? 'invalid' : '', showEmpty ? (messages.cityRequired || 'Selecciona una ciudad/comuna.') : '');
        return false;
      }

      const canonical = cityCanonical(value);

      if (!canonical) {
        setState(input, 'invalid', messages.cityInvalid || 'Ciudad/comuna no válida.');
        return false;
      }

      input.value = canonical;
      setState(input, 'valid', messages.cityValid || 'Ciudad/comuna válida.');
      return true;
    }

    input.addEventListener('focus', render);
    input.addEventListener('input', function(){
      setState(input, '', '');
      render();
    });

    input.addEventListener('blur', function(){
      setTimeout(function(){
        dropdown.hidden = true;
        validate(false);
      }, 150);
    });

    document.addEventListener('click', function(event){
      if (!event.target.closest('.rpv-city-wrap')) dropdown.hidden = true;
    });

    const form = input.closest('form');
    if (form) {
      form.addEventListener('submit', function(event){
        if (!validate(true)) {
          event.preventDefault();
          input.focus();
        }
      });
    }
  }

  function init() {
    initRut();
    initCity();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
