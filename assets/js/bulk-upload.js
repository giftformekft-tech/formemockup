(function($){
  function basename(name){ return name.replace(/\.[^.]+$/, ''); }
  function buildSubOptions(parentId){
    var opts = '<option value="0">— Nincs / kihagyom —</option>';
    var list = (MG_BULK && MG_BULK.subsByParent && MG_BULK.subsByParent[parentId]) ? MG_BULK.subsByParent[parentId] : [];
    list.forEach(function(it){ opts += '<option value="'+it.id+'">'+it.name+'</option>'; });
    return opts;
  }
  $('#mg-bulk-files').on('change', function(){
    var $tbody = $('#mg-bulk-table tbody');
    $tbody.empty();
    if (!this.files || !this.files.length){
      $tbody.append('<tr class="no-items"><td colspan="4">Még nincs kiválasztott fájl.</td></tr>');
      return;
    }
    Array.from(this.files).forEach(function(file, idx){
      var base = basename(file.name || '');
      var $row = $('<tr class="mg-row">');
      $row.append('<td>'+ (file.name || '') +'</td>');
      var $main = $('<td><input type="number" min="0" step="1" name="main_cat[]" class="small-text" placeholder="Fő kategória ID" /></td>');
      var $sub  = $('<td><select name="sub_cat[]">'+buildSubOptions(0)+'</select></td>');
      var $name = $('<td><input type="text" name="product_name[]" value="'+base+'" class="regular-text" /></td>');
      $main.on('change', 'input[name="main_cat[]"]', function(){
        var pid = parseInt($(this).val(),10) || 0;
        $sub.find('select').html(buildSubOptions(pid));
      });
      $row.append($main).append($sub).append($name);
      $tbody.append($row);
    });
  });
  $('form[action$="admin-post.php"]').on('submit', function(){ $('#mg-bulk-progress').show(); });
})(jQuery);