(function($){
  var modal, img, rect, colorSelect;
  var viewKey = null, viewFile = null;
  var dragging=false, resizing=false, startX=0, startY=0, handle=null;
  var imgNaturalW=0, imgNaturalH=0;
  var rectState = { x: 100, y: 100, w: 300, h: 300 };

  function px(n){ return Math.round(n) + 'px'; }
  function setRect(x,y,w,h){ rectState = {x:x,y:y,w:w,h:h}; rect.css({left:px(x),top:px(y),width:px(w),height:px(h)}); }
  function clampRect(){
    var wrap = $('.mg-pa-canvas-wrap');
    var bw = wrap.width(), bh = wrap.height();
    rectState.w = Math.max(10, Math.min(rectState.w, bw - rectState.x));
    rectState.h = Math.max(10, Math.min(rectState.h, bh - rectState.y));
    rectState.x = Math.max(0, Math.min(rectState.x, bw - rectState.w));
    rectState.y = Math.max(0, Math.min(rectState.y, bh - rectState.h));
    setRect(rectState.x, rectState.y, rectState.w, rectState.h);
  }
  function openModal(dataImages, defRect){
    modal = $('#mg-printarea-modal'); img = $('#mg-pa-image'); rect = $('#mg-pa-rect'); colorSelect = $('#mg-pa-color');
    colorSelect.empty();
    var keys = Object.keys(dataImages);
    if (!keys.length){ alert('Ehhez a nézethez nincs feltöltött mockup kép. Előbb tölts fel legalább egyet!'); return; }
    keys.forEach(function(slug){ $('<option>').val(slug).text(slug).appendTo(colorSelect); });
    colorSelect.off('change').on('change', function(){ img.attr('src', dataImages[this.value] || ''); });
    colorSelect.trigger('change');
    img.off('load').on('load', function(){
      imgNaturalW = this.naturalWidth; imgNaturalH = this.naturalHeight;
      var r = defRect || {x:100,y:100,w:300,h:300};
      // Ha a rect túl nagy, arányosan skálázzuk a megjelenített mérethez
      var dispW = img.width(), dispH = img.height();
      // induló rect a kép közepére
      if (!defRect){
        var rw = Math.min( Math.round(dispW*0.4), dispW-40 );
        var rh = Math.min( Math.round(dispH*0.4), dispH-40 );
        var rx = Math.round((dispW - rw)/2);
        var ry = Math.round((dispH - rh)/2);
        setRect(rx, ry, rw, rh);
      } else {
        // skálázás nat->display
        var sx = dispW / imgNaturalW;
        var sy = dispH / imgNaturalH;
        setRect(Math.round(r.x*sx), Math.round(r.y*sy), Math.round(r.w*sx), Math.round(r.h*sy));
      }
    });
    modal.removeClass('mg-pa-hidden').addClass('mg-pa-open');
  }
  function closeModal(){ $('#mg-printarea-modal').removeClass('mg-pa-open').addClass('mg-pa-hidden'); }
  function rectToNatural(){
    var dispW = img.width(), dispH = img.height();
    if (!imgNaturalW || !imgNaturalH || !dispW || !dispH) return null;
    var sx = imgNaturalW / dispW, sy = imgNaturalH / dispH;
    return { x: Math.round(rectState.x*sx), y: Math.round(rectState.y*sy), w: Math.round(rectState.w*sx), h: Math.round(rectState.h*sy) };
  }
  function applyRect(){
    var nat = rectToNatural(); if (!nat){ alert('Nem sikerült a méretarányt meghatározni.'); return; }
    try{
      var ta = $('#mg-views-json'); var views = JSON.parse(ta.val());
      for (var i=0;i<views.length;i++){
        if (views[i].key===viewKey || views[i].file===viewFile){ views[i].x=nat.x; views[i].y=nat.y; views[i].w=nat.w; views[i].h=nat.h; break; }
      }
      ta.val(JSON.stringify(views,null,2));
      closeModal(); alert('Print area beállítva ehhez a nézethez. Mentsd el az oldalt.');
    }catch(e){ alert('JSON frissítési hiba: '+e.message); }
  }

  // Drag/resize handlers
  $(document).on('mousedown', '#mg-pa-rect', function(e){
    if ($(e.target).hasClass('mg-pa-handle')){
      resizing=true; var cls=$(e.target).attr('class'); handle = (cls.split(' ')[1]||'').trim(); startX=e.pageX; startY=e.pageY;
    } else {
      dragging=true; startX=e.pageX-$(this).position().left; startY=e.pageY-$(this).position().top;
    }
    e.preventDefault();
  });
  $(document).on('mousemove', function(e){
    if (dragging){
      rectState.x = e.pageX - startX; rectState.y = e.pageY - startY; clampRect();
    } else if (resizing){
      var dx=e.pageX-startX, dy=e.pageY-startY;
      if (handle.indexOf('r')>=0) rectState.w += dx;
      if (handle.indexOf('l')>=0) { rectState.x += dx; rectState.w -= dx; }
      if (handle.indexOf('b')>=0) rectState.h += dy;
      if (handle.indexOf('t')>=0) { rectState.y += dy; rectState.h -= dy; }
      startX=e.pageX; startY=e.pageY; clampRect();
    }
  });
  $(document).on('mouseup', function(){ dragging=false; resizing=false; handle=null; });
  $(document).on('click', '.mg-pa-close, .mg-pa-cancel', function(e){ e.preventDefault(); closeModal(); });
  $(document).on('click', '.mg-pa-apply', function(e){ e.preventDefault(); applyRect(); });

  // Open from table header button
  $(document).on('click', '.mg-open-printarea', function(){
    viewKey = $(this).data('viewkey');
    viewFile = $(this).data('viewfile');
    var dataImages = {}; try{ dataImages = JSON.parse($(this).attr('data-images')||'{}'); }catch(e){ dataImages={}; }
    var defaults = window.MG_PA_DEFAULTS || []; var current=null;
    for (var i=0;i<defaults.length;i++){ if (defaults[i].key===viewKey || defaults[i].file===viewFile){ current=defaults[i]; break; } }
    openModal(dataImages, current);
  });
})(jQuery);
