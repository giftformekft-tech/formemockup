(function() {
  function getImageElement() {
    var gallery = document.querySelector('.woocommerce-product-gallery');
    if (!gallery) {
      return document.querySelector('.product img');
    }
    var img = gallery.querySelector('.woocommerce-product-gallery__image img');
    if (!img) {
      img = gallery.querySelector('img');
    }
    return img;
  }

  function updateImage() {
    if (!window.MG_GLOBAL_ATTRS) {
      return;
    }
    var typeSelect = document.getElementById('mg_global_type');
    var colorSelect = document.getElementById('mg_global_color');
    if (!typeSelect || !colorSelect) {
      return;
    }
    var typeId = typeSelect.value;
    var colorId = colorSelect.value;
    if (!typeId || !colorId) {
      return;
    }
    var baseUrl = (window.MG_GLOBAL_ATTRS.baseUrl || '').replace(/\/$/, '');
    if (!baseUrl) {
      return;
    }
    var sku = window.MG_GLOBAL_ATTRS.sku || '';
    if (!sku) {
      return;
    }
    var filename = sku + '_' + typeId + '_' + colorId + '.jpg';
    var url = baseUrl + '/' + encodeURIComponent(filename);
    var img = getImageElement();
    if (!img) {
      return;
    }
    img.src = url;
    img.srcset = url;
    img.setAttribute('data-src', url);
  }

  function bind() {
    var typeSelect = document.getElementById('mg_global_type');
    var colorSelect = document.getElementById('mg_global_color');
    var sizeSelect = document.getElementById('mg_global_size');
    if (!typeSelect || !colorSelect || !sizeSelect) {
      return;
    }
    typeSelect.addEventListener('change', updateImage);
    colorSelect.addEventListener('change', updateImage);
  }

  document.addEventListener('DOMContentLoaded', bind);
})();
